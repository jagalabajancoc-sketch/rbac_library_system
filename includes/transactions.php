<?php
/** Circulation state changes share a short database-scoped lock. Nested calls join it. */
function circulation_transaction(callable $fn) {
    static $depth = 0;
    $key = 'library-circulation-'.substr(hash('sha256', DB_NAME),0,30);
    if ($depth === 0 && (int)fetch_value('SELECT GET_LOCK(?,10)',[$key]) !== 1)
        throw new LibraryException('The circulation desk is busy. Please try again.');
    $depth++;
    try { return in_transaction($fn); }
    finally { if (--$depth === 0) fetch_value('SELECT RELEASE_LOCK(?)',[$key]); }
}
function transaction_ids(array $ids, int $max=60): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($id)=>$id>0)));
    if (!$ids || count($ids)>$max) throw new LibraryException("Select between 1 and $max items.");
    sort($ids); return $ids;
}
function request_transaction_ref(int $id): string { return 'RQ-'.str_pad((string)$id,6,'0',STR_PAD_LEFT); }
function return_batch_ref(int $id): string { return 'RT-'.str_pad((string)$id,6,'0',STR_PAD_LEFT); }

/** First eligible queued member has priority; ineligible members retain their place. */
function first_eligible_reservation(int $bookId): int {
    $rows=fetch_all("SELECT r.RequestID,r.UserID FROM borrow_request r JOIN borrow_request_detail d ON d.RequestID=r.RequestID
        WHERE d.BookID=? AND r.RequestType='Reservation' AND r.ApprovalStatus='Pending'
        ORDER BY r.RequestDate,r.RequestID",[$bookId]);
    foreach ($rows as $r) {
        $check=member_borrow_check((int)$r['UserID'],count_open_request_books((int)$r['UserID'],(int)$r['RequestID'])+1);
        if ($check['ok']) return (int)$r['RequestID'];
    }
    return 0;
}
function assert_reservation_turn(int $bookId, int $requestId=0): void {
    $first=first_eligible_reservation($bookId);
    if ($first && $first!==$requestId) throw new LibraryException('An earlier eligible reservation has priority for this title. Process the reservation queue first.');
}

/** One submitted basket, one header. Invalid baskets roll back in full. */
function create_request_transaction(int $memberId,array $bookIds,?string $token=null): array {
    $bookIds=transaction_ids($bookIds,20);
    if ($token!==null && !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new LibraryException('Invalid submission reference. Refresh the catalog.');
    return circulation_transaction(function()use($memberId,$bookIds,$token) {
        fetch_one('SELECT UserID FROM user_account WHERE UserID=? FOR UPDATE',[$memberId]);
        if ($token) {
            $old=fetch_one('SELECT TransactionID,UserID FROM request_transaction WHERE SubmissionToken=?',[$token]);
            if ($old) {
                if ((int)$old['UserID']!==$memberId) throw new LibraryException('Invalid submission reference.');
                $items=fetch_all('SELECT r.RequestID AS id,r.RequestType AS type,b.Title AS title,d.BookID FROM borrow_request r JOIN borrow_request_detail d ON d.RequestID=r.RequestID JOIN book b ON b.BookID=d.BookID WHERE r.TransactionID=? ORDER BY d.BookID',[$old['TransactionID']]);
                if (array_map('intval',array_column($items,'BookID'))!==$bookIds) throw new LibraryException('This submission was already used for a different selection. Refresh the catalog.');
                foreach($items as &$it)$it['position']=reservation_queue_position((int)$it['id']);unset($it);
                return ['transaction_id'=>(int)$old['TransactionID'],'items'=>$items,'repeated'=>true];
            }
        }
        $check=member_borrow_check($memberId,count_open_request_books($memberId)+count($bookIds));
        if (!$check['ok']) throw new LibraryException($check['reason']);
        run('INSERT INTO request_transaction (UserID,SubmissionToken) VALUES (?,?)',[$memberId,$token]);$tx=(int)last_id();$items=[];
        foreach($bookIds as $bid) {
            $check=check_borrow_request_eligibility($memberId,$bid);
            if (!$check['ok']) throw new LibraryException($check['reason']);
            $type=first_eligible_reservation($bid) ? 'Reservation' : $check['type'];
            run("INSERT INTO borrow_request (UserID,TransactionID,RequestType,ApprovalStatus) VALUES (?,?,?,'Pending')",[$memberId,$tx,$type]);$id=(int)last_id();
            run('INSERT INTO borrow_request_detail (RequestID,BookID) VALUES (?,?)',[$id,$bid]);
            $items[]=['id'=>$id,'type'=>$type,'title'=>$check['book']['Title'],'position'=>$type==='Reservation'?reservation_queue_position($id):0];
        }
        return ['transaction_id'=>$tx,'items'=>$items,'repeated'=>false];
    });
}

/** Selections always belong to one borrower; staff may explicitly span submissions. */
function selected_request_items(array $ids,?int $memberId=null,?int $transactionId=null,bool $acrossSubmissions=false): array {
    $ids=transaction_ids($ids);$rows=[];$owner=null;$batch=null;
    foreach($ids as $id) {
        $row=lock_borrow_request($id);
        $owner??=(int)$row['UserID'];$batch??=(int)$row['TransactionID'];
        if ((int)$row['UserID']!==$owner || (!$acrossSubmissions && (int)$row['TransactionID']!==$batch) || ($memberId!==null && $owner!==$memberId) || ($transactionId!==null && $batch!==$transactionId))
            throw new LibraryException('Select books belonging to the same borrower and request scope.');
        $rows[]=$row;
    }
    fetch_one('SELECT UserID FROM user_account WHERE UserID=? FOR UPDATE',[$owner]);
    return $rows;
}
function decide_request_items(array $ids,string $action,int $actor,bool $staff,string $reason='',?int $memberId=null,?int $transactionId=null,bool $acrossSubmissions=false): array {
    if (!in_array($action,['approve','reject','cancel'],true) || (!$staff && $action!=='cancel')) throw new LibraryException('Action not allowed.');
    if (!$staff) $memberId=$actor;
    return circulation_transaction(function()use($ids,$action,$actor,$staff,$reason,$memberId,$transactionId,$acrossSubmissions) {
        $rows=selected_request_items($ids,$memberId,$transactionId,$staff && $acrossSubmissions);
        foreach($rows as $r) {
            if ($action==='approve') {
                $copyId=(int)fetch_value("SELECT CopyID FROM book_copy WHERE BookID=? AND Status='Available' AND IsArchived=0 ORDER BY CopyID LIMIT 1 FOR UPDATE",[$r['BookID']]);
                if (!$copyId) throw new LibraryException('No available copy of '.$r['Title'].'. Nothing was approved.');
                approve_borrow_request((int)$r['RequestID'],$copyId,$actor);
            } elseif ($action==='reject') reject_borrow_request((int)$r['RequestID'],$reason,$actor);
            else cancel_borrow_request((int)$r['RequestID'],$actor,$staff,$reason);
        }
        return ['count'=>count($rows),'approved'=>$action==='approve'?count($rows):0,'skipped'=>[]];
    });
}

/** One release creates one loan header with a detail row for every selected copy. */
function release_request_items(array $ids,int $staffId,?int $transactionId=null,array $chosenCopies=[],bool $acrossSubmissions=false): array {
    return circulation_transaction(function()use($ids,$staffId,$transactionId,$chosenCopies,$acrossSubmissions) {
        $rows=selected_request_items($ids,null,$transactionId,$acrossSubmissions);$memberId=(int)$rows[0]['UserID'];
        $done=array_filter($rows,fn($r)=>$r['ApprovalStatus']==='Fulfilled' && $r['ReleasedLoanID']);
        if (count($done)===count($rows) && count(array_unique(array_column($done,'ReleasedLoanID')))===1) {
            $loan=(int)$done[0]['ReleasedLoanID'];return ['loan_id'=>$loan,'items'=>(int)fetch_value('SELECT COUNT(*) FROM loan_detail WHERE LoanID=?',[$loan]),'repeated'=>true];
        }
        $items=[];$titles=[];
        foreach($rows as $r) {
            if ($r['ApprovalStatus']!=='Approved' || $r['ReleasedLoanID']) throw new LibraryException('Only held, approved items may be released. Nothing was issued.');
            if ($r['ExpiresAt'] && strtotime($r['ExpiresAt'])<time()) throw new LibraryException('A selected hold has expired. Refresh the requests list.');
            $bookIds=array_map('intval',array_column(fetch_all('SELECT BookID FROM borrow_request_detail WHERE RequestID=? ORDER BY BookID',[$r['RequestID']]),'BookID'));
            foreach($bookIds as $bid) {
                if (isset($titles[$bid]) || member_holds_title($memberId,$bid)) throw new LibraryException('A member may borrow only one copy of a title at a time.');
                $titles[$bid]=true;$bk=validate_book_borrowability($bid);if(!$bk['ok'])throw new LibraryException($bk['reason']);
                $held=$r['AssignedCopyID'] ? fetch_one('SELECT * FROM book_copy WHERE CopyID=? FOR UPDATE',[$r['AssignedCopyID']]):null;
                $isHeld=$held && (int)$held['BookID']===$bid;
                $copy=$isHeld?$held:fetch_one('SELECT * FROM book_copy WHERE CopyID=? FOR UPDATE',[(int)($chosenCopies[$bid]??0)]);
                if (!$copy || (int)$copy['BookID']!==$bid || $copy['IsArchived'] || $copy['Status']!==($isHeld?'Reserved':'Available')) throw new LibraryException('A selected copy is unavailable. Nothing was issued.');
                if (!$isHeld) { assert_reservation_turn($bid,(int)$r['RequestID']);assert_keeps_copy_in_library($bid,$bk['book']['Title'],1); }
                $items[]=['copy'=>(int)$copy['CopyID'],'book'=>$bid];
            }
        }
        $check=member_borrow_check($memberId,count($items));if(!$check['ok'])throw new LibraryException($check['reason']);
        run('INSERT INTO loan (BorrowerUserID,ProcessedByUserID,RequestID) VALUES (?,?,?)',[$memberId,$staffId,count($rows)===1?$rows[0]['RequestID']:null]);$loan=(int)last_id();
        foreach($items as $it) {
            run("INSERT INTO loan_detail (LoanID,CopyID,DueDate,BookCondition) VALUES (?,?,?,'Good')",[$loan,$it['copy'],date('Y-m-d',strtotime('+'.resolve_loan_days($it['book']).' days'))]);
            run("UPDATE book_copy SET Status='Borrowed' WHERE CopyID=?",[$it['copy']]);
        }
        foreach($rows as $r) run("UPDATE borrow_request SET ApprovalStatus='Fulfilled',ReleasedLoanID=? WHERE RequestID=?",[$loan,$r['RequestID']]);
        return ['loan_id'=>$loan,'items'=>count($items),'repeated'=>false];
    });
}

/** Manual accession values are library identifiers, not auto-generated numbers. */
function create_entered_copies(int $bookId,int $count,string $input): array {
    $numbers=array_values(array_filter(array_map('trim',preg_split('/\R/u',$input)),fn($n)=>$n!==''));
    if ($count<1 || $count>COPY_BATCH_LIMIT || count($numbers)!==$count) throw new LibraryException('Enter exactly one accession number per copy, one per line.');
    foreach($numbers as $n) if(mb_strlen($n)>40 || preg_match('/[\x00-\x1f\x7f]/u',$n)) throw new LibraryException('Accession numbers must be 1–40 characters without control characters.');
    if(count(array_unique(array_map('strtolower',$numbers)))!==count($numbers))throw new LibraryException('Accession numbers cannot be repeated.');
    return in_transaction(function()use($bookId,$numbers) {
        foreach($numbers as $n) {
            if(fetch_value('SELECT CopyID FROM book_copy WHERE AccessionNumber=?',[$n]))throw new LibraryException('Accession number '.$n.' is already in use.');
            try { run("INSERT INTO book_copy (BookID,AccessionNumber,Status) VALUES (?,?,'Available')",[$bookId,$n]); }
            catch(PDOException $e) { if($e->getCode()==='23000')throw new LibraryException('An accession number is already in use. No copies were added.');throw $e; }
        }
        return $numbers;
    });
}

/** Return quotation and commit use exactly the same fine calculation. */
function return_quote(array $conditions): array {
    if(!$conditions || count($conditions)>80)throw new LibraryException('Select between 1 and 80 books to return.');
    ksort($conditions,SORT_NUMERIC);$items=[];$total=0;
    foreach($conditions as $id=>$condition) {
        if(!in_array($condition,['Good','Fair','Damaged','Lost'],true))throw new LibraryException('Invalid return condition.');
        $r=fetch_one("SELECT ld.*,l.BorrowerUserID,b.BookID,b.Title,b.PolicyID,b.AcquisitionCost,bc.AccessionNumber,bc.Status AS CopyStatus,
            p.PenaltyRate,CONCAT(m.FirstName,' ',m.LastName) AS Member
            FROM loan_detail ld JOIN loan l ON l.LoanID=ld.LoanID JOIN book_copy bc ON bc.CopyID=ld.CopyID
            JOIN book b ON b.BookID=bc.BookID LEFT JOIN policy p ON p.PolicyID=b.PolicyID
            JOIN user_account m ON m.UserID=l.BorrowerUserID WHERE ld.LoanDetailID=? FOR UPDATE",[(int)$id]);
        if(!$r || fetch_value('SELECT ReturnDetailID FROM return_detail WHERE LoanDetailID=?',[(int)$id]))throw new LibraryException('A selected item was already returned or does not exist. Refresh the list.');
        if(in_array($r['CopyStatus'],['Reserved','In Use'],true))throw new LibraryException('Copy '.$r['AccessionNumber'].' has a conflicting status. Review its record before returning it.');
        $days=max(0,(int)floor((strtotime(date('Y-m-d'))-strtotime($r['DueDate']))/86400));
        $fines=[];$late=round($days*max(0,(float)$r['PenaltyRate']),2);
        if($late>0)$fines[]=['amount'=>$late,'reason'=>"Overdue by $days day(s)"];
        $cost=max(0,(float)$r['AcquisitionCost']);
        if(in_array($condition,['Damaged','Lost'],true) && $cost<=0)
            throw new LibraryException('The replacement cost for “'.$r['Title'].'” is missing or zero. Update the book record before processing a damaged or lost return.');
        if(in_array($condition,['Damaged','Lost'],true) && $cost>0) {
            $fines[]=[
                'amount'=>round($cost,2),
                'reason'=>$condition==='Lost'
                    ? 'Book reported lost (full replacement cost)'
                    : 'Book returned damaged (full replacement cost)',
            ];
        }
        $r['condition']=$condition;$r['fines']=$fines;$r['fine_total']=array_sum(array_column($fines,'amount'));$total+=$r['fine_total'];$items[]=$r;
    }
    return ['items'=>$items,'total'=>round($total,2)];
}
function process_return_transaction(array $conditions,int $staffId): array {
    return circulation_transaction(function()use($conditions,$staffId) {
        $quote=return_quote($conditions);
        run('INSERT INTO return_batch (ProcessedByUserID) VALUES (?)',[$staffId]);$batch=(int)last_id();$headers=[];$books=[];
        foreach($quote['items'] as $r) {
            $loan=(int)$r['LoanID'];
            if(!isset($headers[$loan])) {
                run("INSERT INTO return_header (LoanID,ProcessedByUserID,ReturnStatus,ReturnBatchID) VALUES (?,?,'Partial',?)",[$loan,$staffId,$batch]);$headers[$loan]=(int)last_id();
            }
            run("INSERT INTO return_detail (ReturnID,LoanDetailID,ReturnCondition,ReturnStatus) VALUES (?,?,?,'Returned')",[$headers[$loan],$r['LoanDetailID'],$r['condition']]);
            $status=in_array($r['condition'],['Damaged','Lost'],true)?$r['condition']:'Available';
            run('UPDATE book_copy SET Status=? WHERE CopyID=?',[$status,$r['CopyID']]);
            if($status==='Available')$books[(int)$r['BookID']]=true;
            foreach($r['fines'] as $f)run("INSERT INTO fine (LoanDetailID,PolicyID,FineAmount,Reason,PaymentStatus) VALUES (?,?,?,?,'Unpaid')",[$r['LoanDetailID'],$r['PolicyID'],$f['amount'],$f['reason']]);
        }
        foreach($headers as $loan=>$header) {
            $left=(int)fetch_value('SELECT COUNT(*) FROM loan_detail ld LEFT JOIN return_detail rd ON rd.LoanDetailID=ld.LoanDetailID WHERE ld.LoanID=? AND rd.ReturnDetailID IS NULL',[$loan]);
            run('UPDATE return_header SET ReturnStatus=? WHERE ReturnID=?',[$left?'Partial':'Complete',$header]);
        }
        $held=0;foreach(array_keys($books)as $bid)$held+=promote_reservation_queue($bid);
        return ['batch_id'=>$batch,'returned'=>count($quote['items']),'loans'=>count($headers),'fine_total'=>$quote['total'],'held'=>$held];
    });
}
