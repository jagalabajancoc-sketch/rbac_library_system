<?php
/** Edits preserve timestamps; identity corrections are explicit and audited atomically. */
function ilu_edit_version(array $row, ?array $form): string {
    $fields=['InLibraryUseID','CopyID','MemberID','BorrowerType','WalkInName','WalkInContact','Location','Remarks','Status','ConditionOut'];
    $state=[];foreach($fields as $field)$state[$field]=$row[$field]??null;
    $state['form']=$form?array_intersect_key($form,array_flip(['FormID','MemberID','WalkInName','WalkInContact','IdSlot','IdStatus','Status','LoanID'])):null;
    return hash('sha256',json_encode($state));
}
function ilu_edit_audit(int $actor,int $id,string $field,$before,$after): void {
    if($before===$after)return;
    $payload=json_encode(['field'=>$field,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $chunks=mb_str_split($payload,380);
    foreach($chunks as $i=>$chunk)run('INSERT INTO activity_log(UserID,ActionType,Module,Description,RecordID) VALUES (?,?,?,?,?)',
        [$actor,'Update','In-Library Use','Session #'.$id.' correction '.($i+1).'/'.count($chunks).': '.$chunk,$id]);
}
function ilu_edit_text(array $in,string $key,int $max): string {
    if(isset($in[$key])&&!is_scalar($in[$key]))throw new LibraryException('Invalid '.$key.'.');
    $value=trim((string)($in[$key]??''));
    if(mb_strlen($value)>$max)throw new LibraryException('The '.$key.' value is too long.');
    return $value;
}
function ilu_edit_session(int $id,array $in,int $actor,bool $correction=false): void {
    // Same lock as desk issuance prevents two forms from taking the same ID slot.
    $key='desk-issue-'.substr(hash('sha256',DB_NAME),0,32);
    if((int)fetch_value('SELECT GET_LOCK(?,5)',[$key])!==1)throw new LibraryException('The desk is busy. Please try again.');
    try { circulation_transaction(function()use($id,$in,$actor,$correction){
        $row=fetch_one('SELECT * FROM in_library_use WHERE InLibraryUseID=? FOR UPDATE',[$id]);
        if(!$row||$row['Status']!=='Active')throw new LibraryException('This session has ended. Reload the list.');
        $form=$row['SessionRef']?fetch_one('SELECT * FROM in_library_form WHERE FormNo=? FOR UPDATE',[$row['SessionRef']]):null;
        if(!hash_equals(ilu_edit_version($row,$form),ilu_edit_text($in,'version',64)))throw new LibraryException('This session changed while you were editing. Reopen it and review the latest details.');
        $group=$row['SessionRef']?fetch_all('SELECT * FROM in_library_use WHERE SessionRef=? ORDER BY InLibraryUseID FOR UPDATE',[$row['SessionRef']]):[$row];
        $audit=function($field,$before,$after)use($actor,$id){ilu_edit_audit($actor,$id,$field,$before,$after);};
        if(!$correction){
            $loc=ilu_edit_text($in,'location',100)?:null;$remarks=ilu_edit_text($in,'remarks',255)?:null;
            $audit('Location',$row['Location'],$loc);$audit('Remarks',$row['Remarks'],$remarks);
            run('UPDATE in_library_use SET Location=?,Remarks=? WHERE InLibraryUseID=?',[$loc,$remarks,$id]);
            if($row['BorrowerType']==='Walk-in'){
                $name=ilu_edit_text($in,'walk_in_name',150);$contact=ilu_edit_text($in,'walk_in_contact',100)?:null;
                if($name==='')throw new LibraryException('Enter the visitor name.');
                foreach($group as $other){
                    if($other['Status']!=='Active')continue;
                    ilu_edit_audit($actor,(int)$other['InLibraryUseID'],'WalkInName',$other['WalkInName'],$name);
                    ilu_edit_audit($actor,(int)$other['InLibraryUseID'],'WalkInContact',$other['WalkInContact'],$contact);
                    run('UPDATE in_library_use SET WalkInName=?,WalkInContact=? WHERE InLibraryUseID=?',[$name,$contact,$other['InLibraryUseID']]);
                }
                if($form)run('UPDATE in_library_form SET WalkInName=?,WalkInContact=? WHERE FormID=?',[$name,$contact,$form['FormID']]);
            }
            if($form && $form['IdStatus']==='Held'){
                $slot=ilu_edit_text($in,'slot',20);
                if(!preg_match('/^[A-Za-z0-9-]{1,20}$/D',$slot))throw new LibraryException('Enter an ID storage slot using letters, numbers or dashes.');
                if(fetch_value("SELECT COUNT(*) FROM in_library_form WHERE IdSlot=? AND IdStatus='Held' AND FormID<>?",[$slot,$form['FormID']]))throw new LibraryException('That ID storage slot already holds another ID.');
                $audit('ID storage slot',$form['IdSlot'],$slot);
                run('UPDATE in_library_form SET IdSlot=? WHERE FormID=?',[$slot,$form['FormID']]);
            }
            return;
        }
        $reason=ilu_edit_text($in,'reason',255);
        if($reason==='')throw new LibraryException('Enter a reason for the assignment correction.');
        if(empty($in['verified']))throw new LibraryException('Confirm that you checked the borrower ID and physical book.');
        $kind=ilu_edit_text($in,'correction_kind',20);
        if($kind==='book'){
            $code=ilu_edit_text($in,'accession',100);
            $new=fetch_one('SELECT * FROM book_copy WHERE AccessionNumber=? FOR UPDATE',[$code]);
            if(!$new||(int)$new['CopyID']===(int)$row['CopyID'])throw new LibraryException('Enter a different, valid accession number.');
            $old=fetch_one('SELECT * FROM book_copy WHERE CopyID=? FOR UPDATE',[$row['CopyID']]);
            $candidate=ilu_copy_rows('bc.CopyID=?',[$new['CopyID']],1);
            if(!$candidate||!ilu_copy_state($candidate[0])['ok'])throw new LibraryException('The replacement copy is not available for reading.');
            if(!$old || $old['Status']!=='In Use' || !empty($old['IsArchived']) || fetch_value("SELECT COUNT(*) FROM in_library_use WHERE CopyID=? AND Status='Active' AND InLibraryUseID<>?",[$old['CopyID'],$id]))throw new LibraryException('The original copy has a conflicting status. Review it before correcting.');
            foreach([$old['CopyID'],$new['CopyID']] as $copyId)if(fetch_value('SELECT COUNT(*) FROM loan_detail d WHERE CopyID=? AND NOT EXISTS(SELECT 1 FROM return_detail r WHERE r.LoanDetailID=d.LoanDetailID)',[$copyId]))throw new LibraryException('A copy has an outstanding borrowing record.');
            $condition=ilu_edit_text($in,'condition_out',20);
            if(!in_array($condition,['Good','Fair','Damaged'],true))throw new LibraryException('Choose the replacement book condition.');
            $audit('Reason',null,$reason);
            $audit('Book assignment',['copy_id'=>$old['CopyID'],'accession'=>$old['AccessionNumber']],['copy_id'=>$new['CopyID'],'accession'=>$new['AccessionNumber']]);
            $audit('ConditionOut',$row['ConditionOut'],$condition);
            run('UPDATE in_library_use SET CopyID=?,ConditionOut=? WHERE InLibraryUseID=?',[$new['CopyID'],$condition,$id]);
            run("UPDATE book_copy SET Status='Available' WHERE CopyID=?",[$old['CopyID']]);
            run("UPDATE book_copy SET Status='In Use' WHERE CopyID=?",[$new['CopyID']]);
            promote_reservation_queue((int)$old['BookID']);
        }elseif($kind==='student'){
            if($row['BorrowerType']!=='Member')throw new LibraryException('Use Edit session to correct a visitor name.');
            if($form && (!empty($form['LoanID']) || $form['IdStatus']!=='Held'))throw new LibraryException('This form has borrowing records or no longer holds an ID. Borrower reassignment is unavailable.');
            foreach($group as $other)if($other['Status']!=='Active'||$other['MemberID']!=$row['MemberID'])throw new LibraryException('This shared session includes completed or different borrower records. Borrower reassignment is unavailable.');
            $member=ilu_find_member_by_card(ilu_edit_text($in,'student_id',100));
            if(!$member || $member['UserID']==$row['MemberID'])throw new LibraryException('Enter a different, registered borrower ID.');
            fetch_one('SELECT UserID FROM user_account WHERE UserID=? FOR UPDATE',[$member['UserID']]);
            $look=desk_member_lookup((string)$member['MembershipNumber']);
            if(!empty($look['blocked']))throw new LibraryException($look['blocked']);
            if(DESK_REQUIRE_ENTRY && !$look['entryToday'])throw new LibraryException('Record the borrower library entry before correcting the assignment.');
            $audit('Reason',null,$reason);
            foreach($group as $other){
                ilu_edit_audit($actor,(int)$other['InLibraryUseID'],'MemberID',$other['MemberID'],$member['UserID']);
                run('UPDATE in_library_use SET MemberID=? WHERE InLibraryUseID=?',[$member['UserID'],$other['InLibraryUseID']]);
            }
            if($form){
                $idNumber=$member['MembershipNumber'];
                $audit('Held ID number',$form['IdNumber'],$idNumber);
                run("UPDATE in_library_form SET MemberID=?,IdNumber=?,IdType=?,IdPhotoChecked=1,IdVerifyMethod='Visual' WHERE FormID=?",[$member['UserID'],$idNumber,desk_member_kind((int)$member['UserID'])==='Teacher'?'Employee ID':'School ID',$form['FormID']]);
            }
        }else throw new LibraryException('Choose which assignment to correct.');
    }); } finally { fetch_value('SELECT RELEASE_LOCK(?)',[$key]); }
}
