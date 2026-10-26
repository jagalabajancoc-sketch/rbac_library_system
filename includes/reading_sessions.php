<?php
require_once __DIR__.'/session_edit.php';

/** One session identity shared by Library Desk and Read-Here Books. */
function reading_context(string $key, bool $lock = false): array {
    if (!preg_match('/^(form|session):([1-9][0-9]*)$/D', $key, $m)) throw new LibraryException('Invalid reading session.');
    $suffix = $lock ? ' FOR UPDATE' : '';
    $form = null; $seed = null;
    if ($m[1] === 'form') {
        $form = fetch_one('SELECT * FROM in_library_form WHERE FormID=?'.$suffix, [(int)$m[2]]);
        if (!$form) throw new LibraryException('Reading session not found.');
        $ref = $form['FormNo'];
    } else {
        $seed = fetch_one('SELECT * FROM in_library_use WHERE InLibraryUseID=?'.$suffix, [(int)$m[2]]);
        if (!$seed) throw new LibraryException('Reading session not found.');
        $ref = $seed['SessionRef'];
        if ($ref) $form = fetch_one('SELECT * FROM in_library_form WHERE FormNo=?'.$suffix, [$ref]);
    }
    $rows = $ref ? fetch_all('SELECT * FROM in_library_use WHERE SessionRef=? ORDER BY InLibraryUseID'.$suffix, [$ref]) : [$seed];
    $identity = $form ?: $seed ?: ($rows[0] ?? null);
    if (!$identity) throw new LibraryException('Reading session not found.');
    $active = array_values(array_filter($rows, fn($r)=>$r['Status']==='Active'));
    $member = !empty($identity['MemberID']) ? fetch_one('SELECT FirstName,LastName,MembershipNumber FROM user_account WHERE UserID=?',[$identity['MemberID']]) : null;
    $who = $identity['BorrowerType']==='Member' ? trim(($member['FirstName']??'').' '.($member['LastName']??'')) : $identity['WalkInName'];
    $version = hash('sha256', json_encode([$form, $rows]));
    foreach ($rows as &$r) {
        $copy = fetch_one('SELECT b.Title,c.AccessionNumber FROM book_copy c JOIN book b ON b.BookID=c.BookID WHERE c.CopyID=?',[$r['CopyID']]);
        $r['Title']=$copy['Title']??'Unavailable title'; $r['AccessionNumber']=$copy['AccessionNumber']??'';
    } unset($r);
    return ['key'=>$key,'ref'=>$ref,'form'=>$form,'identity'=>$identity,'rows'=>$rows,'active'=>$active,'who'=>$who,'card'=>$member['MembershipNumber']??'Walk-in borrower',
        'version'=>$version,'open'=>$form ? $form['Status']==='Open'&&$form['IdStatus']==='Held' : count($active)>0];
}

function reading_change(string $key, array $input, int $actor): string {
    $action = ilu_edit_text($input,'action',30);
    if (!in_array($action,['add','return','edit','correct'],true)) throw new LibraryException('Choose a reading session action.');
    $mutex='desk-issue-'.substr(hash('sha256',DB_NAME),0,32);
    if ((int)fetch_value('SELECT GET_LOCK(?,5)',[$mutex])!==1) throw new LibraryException('The desk is busy. Try again.');
    try {
        return circulation_transaction(function()use($key,$input,$actor,$action){
            $ctx=reading_context($key,true);
            if (!hash_equals($ctx['version'],ilu_edit_text($input,'session_version',64))) throw new LibraryException('This reading session changed. Review its latest details before trying again.');
            if (!$ctx['open']) throw new LibraryException('This reading session is closed. Start a new session at Library Desk.');
            if ($action==='edit'||$action==='correct') {
                $id=(int)($input['session_id']??0);
                if (!in_array($id,array_map('intval',array_column($ctx['active'],'InLibraryUseID')),true)) throw new LibraryException('Choose an active book in this session.');
                ilu_edit_session($id,$input,$actor,$action==='correct');
                return $action==='edit'?'Session changes saved.':'Assignment corrected and recorded in Activity Log.';
            }
            if ($action==='add') {
                if (!IN_LIBRARY_USE_ENABLED) throw new LibraryException('Reading in the library is currently unavailable.');
                $codes=preg_split('/[\s,;]+/',ilu_edit_text($input,'accessions',3000),-1,PREG_SPLIT_NO_EMPTY);
                if (!$codes || count($codes)>KIOSK_MAX_COPIES_PER_SESSION) throw new LibraryException('Enter between 1 and '.KIOSK_MAX_COPIES_PER_SESSION.' accession numbers.');
                if (count(array_unique(array_map('strtoupper',$codes)))!==count($codes)) throw new LibraryException('The same accession number is listed more than once. Remove the duplicate.');
                $condition=ilu_edit_text($input,'condition_out',20);
                if (!in_array($condition,['Good','Fair','Damaged'],true)) throw new LibraryException('Choose the condition of the additional books.');
                $ids=[];
                foreach($codes as $code) {
                    $copy=fetch_one('SELECT CopyID FROM book_copy WHERE AccessionNumber=? FOR UPDATE',[$code]);
                    if (!$copy) throw new LibraryException('Accession '.$code.' was not found. No books were added.');
                    $id=(int)$copy['CopyID'];
                    if (fetch_value('SELECT COUNT(*) FROM loan_detail d WHERE CopyID=? AND NOT EXISTS(SELECT 1 FROM return_detail r WHERE r.LoanDetailID=d.LoanDetailID)',[$id])) throw new LibraryException('A selected copy is still borrowed for home use. No books were added.');
                    $ids[]=$id;
                }
                $who=$ctx['identity'];
                if (!empty($who['MemberID'])) {
                    $m=fetch_one('SELECT VerificationStatus FROM user_account WHERE UserID=? FOR UPDATE',[$who['MemberID']]);
                    if (!$m||$m['VerificationStatus']==='Rejected') throw new LibraryException('This borrower is not eligible to receive more books.');
                    if (DESK_REQUIRE_ENTRY&&!member_entry_today((int)$who['MemberID'])) throw new LibraryException('Record the borrower library entry first.');
                }
                $ref=$ctx['ref'];
                if (!$ref) {
                    $ref='S'.date('ymd').strtoupper(bin2hex(random_bytes(3)));
                    run('UPDATE in_library_use SET SessionRef=? WHERE InLibraryUseID=?',[$ref,$who['InLibraryUseID']]);
                }
                $result=start_in_library_session($ids,$who['BorrowerType'],isset($who['MemberID'])?(int)$who['MemberID']:null,$who['WalkInName'],$who['WalkInContact'],
                    $actor,ilu_edit_text($input,'location',100)?:null,ilu_edit_text($input,'remarks',255)?:null,'Staff',$ref);
                foreach($result['items'] as $item) {
                    run('UPDATE in_library_use SET ConditionOut=? WHERE InLibraryUseID=?',[$condition,$item['id']]);
                    ilu_edit_audit($actor,$item['id'],'Book added to reading session',null,['session'=>$ref,'accession'=>$item['accession'],'borrower'=>$ctx['who']]);
                }
                return count($result['items']).' book(s) added to '.$ctx['who'].'’s existing reading session.';
            }
            $selected=array_values(array_unique(array_filter(array_map('intval',(array)($input['selected']??[])))));
            if (count($selected)>60) throw new LibraryException('Return at most 60 books at a time.');
            if (!$selected&&empty($input['release'])) throw new LibraryException('Select books to return or choose Give ID back.');
            // An ID-only release is valid after every book has been returned.
            $returns=[];
            foreach($selected as $id) {
                if (!in_array($id,array_map('intval',array_column($ctx['active'],'InLibraryUseID')),true)) throw new LibraryException('A selected book is no longer active in this session.');
                $condition=$input['conditions'][$id]??'';
                if (!is_string($condition)||!in_array($condition,['Good','Fair','Damaged','Lost'],true)) throw new LibraryException('Choose a return condition for every selected book.');
                $returns[$id]=$condition;
            }
            $release=!empty($input['release']);
            $note=ilu_edit_text($input,'note',255);
            if (array_intersect($returns,['Damaged','Lost'])&&$note==='') throw new LibraryException('Add a note for damaged or lost books.');
            if ($ctx['form']) {
                $r=desk_form_return((int)$ctx['form']['FormID'],$returns,$release,$note?:null,$actor);
                $message=$r['released']?'Books settled and ID returned. This reading session is closed.':$r['returned'].' book(s) returned. The ID stays in its storage slot.';
            } else {
                if ($release) throw new LibraryException('This session has no stored ID.');
                foreach($returns as $id=>$condition) {
                    $row=fetch_one('SELECT CopyID FROM in_library_use WHERE InLibraryUseID=?',[$id]);
                    if (in_array($condition,['Damaged','Lost'],true)) run('UPDATE book_copy SET Status=? WHERE CopyID=?',[$condition,$row['CopyID']]);
                    end_in_library_use($id,$actor,$note?:null);
                    run('UPDATE in_library_use SET ConditionIn=? WHERE InLibraryUseID=?',[$condition,$id]);
                }
                $message=count($returns).' book(s) returned.';
            }
            foreach($returns as $id=>$condition) ilu_edit_audit($actor,$id,'Book returned','Active',['condition'=>$condition,'note'=>$note]);
            return $message;
        });
    } finally { fetch_value('SELECT RELEASE_LOCK(?)',[$mutex]); }
}
