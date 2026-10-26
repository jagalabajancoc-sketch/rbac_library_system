<?php
require __DIR__.'/guard.php';
require __DIR__.'/../includes/functions.php';
require __DIR__.'/../includes/member_circulation_lists.php';
function ensure_group($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
db()->beginTransaction();
try{
 $prefix='ML-'.bin2hex(random_bytes(4));$members=[];$records=[];$details=[];
 $admin=(int)fetch_value("SELECT u.UserID FROM user_account u JOIN role r ON r.RoleID=u.RoleID WHERE r.RoleName='Admin' LIMIT 1");
 $role=(int)fetch_value("SELECT RoleID FROM role WHERE RoleName='Member' LIMIT 1");$book=(int)fetch_value('SELECT BookID FROM book LIMIT 1');
 for($i=0;$i<12;$i++){
  run("INSERT INTO user_account (RoleID,FirstName,LastName,Username,Email,PasswordHash,MembershipNumber,RegistrationDate,MembershipStatus,VerificationStatus) VALUES (?,?,?,?,?,?,?,CURDATE(),'Active','Approved')",[$role,$prefix,$i<2?'Same':'Person'.$i,$prefix.$i,$prefix.$i.'@example.test','unused',$prefix.$i]);$id=(int)last_id();$members[]=$id;
  for($j=0;$j<($i===0?12:1);$j++){
   run('INSERT INTO loan (BorrowerUserID,ProcessedByUserID) VALUES (?,?)',[$id,$admin]);$lid=(int)last_id();$records[$id][]=$lid;
   run("INSERT INTO book_copy (BookID,AccessionNumber,Status) VALUES (?,?,'Borrowed')",[$book,$prefix.'-'.$i.'-'.$j]);$copy=(int)last_id();
   run("INSERT INTO loan_detail (LoanID,CopyID,DueDate,BookCondition) VALUES (?,?,DATE_ADD(CURDATE(),INTERVAL 7 DAY),'Good')",[$lid,$copy]);$details[$id][]=(int)last_id();
   run('INSERT INTO request_transaction (UserID) VALUES (?)',[$id]);$tx=(int)last_id();
   run("INSERT INTO borrow_request (UserID,TransactionID,RequestType,ApprovalStatus) VALUES (?,?,'Borrow',?)",[$id,$tx,$j===11?'Rejected':'Pending']);
  }
 }
 $a=return_member_groups($prefix,1);$b=return_member_groups($prefix,2);
 ensure_group($a['pagination']['total']===12 && count($a['groups'])===10 && count($b['groups'])===2,'Returns pages count members and keep groups intact');
 ensure_group(!array_intersect(array_keys($a['groups']),array_keys($b['groups'])),'Returns never repeats a member across pages');
 $same=return_member_groups($prefix.' Same',1);
 ensure_group(count($same['groups'])===2 && count($same['groups'][$members[0]]['items'])===12,'Returns separates identical names and includes all 12 outstanding transactions');
 $match=return_member_groups($prefix.'-0-0',1);
 ensure_group(count($match['groups'])===1 && count($match['groups'][$members[0]]['items'])===12,'Accession search includes all outstanding books for the matching member');
 ensure_group(count(return_member_groups('',1,0,$records[$members[0]][0])['groups'][$members[0]]['items'])===12,'Transaction links open the full member group');
 run('INSERT INTO return_header (LoanID,ProcessedByUserID) VALUES (?,?)',[$records[$members[0]][0],$admin]);$rid=(int)last_id();
 run("INSERT INTO return_detail (ReturnID,LoanDetailID,ReturnCondition,ReturnStatus) VALUES (?,?,'Good','Returned')",[$rid,$details[$members[0]][0]]);
 ensure_group(count(return_member_groups('',1,$members[0])['groups'][$members[0]]['items'])===11,'Returned books are excluded from selection');
 ensure_group(!return_member_groups($prefix.'missing',1)['groups'],'No-match return searches are empty');
 $base="FROM request_transaction t JOIN user_account m ON m.UserID=t.UserID WHERE m.FirstName=? AND EXISTS(SELECT 1 FROM borrow_request x WHERE x.TransactionID=t.TransactionID AND x.ApprovalStatus='Pending')";
 $a=request_member_groups($base,[$prefix],1);$b=request_member_groups($base,[$prefix],2);
 ensure_group($a['pagination']['total']===12 && count($a['groups'])===10 && count($b['groups'])===2,'Requests pages count members instead of submissions');
 ensure_group(!array_intersect(array_keys($a['groups']),array_keys($b['groups'])),'Requests never splits one member across pages');
 $group=request_member_groups($base.' AND m.LastName=?',[$prefix,'Same'],1)['groups'];
 ensure_group(count($group)===2 && count($group[$members[0]]['transactions'])===11,'Same-name requesters stay separate and filters exclude rejected submissions');
 ensure_group(!request_member_groups($base.' AND DATE(t.SubmittedAt)<?',[$prefix,'2000-01-01'],1)['groups'],'Request date filters remain effective');
 $rows=[['BorrowerUserID'=>1,'Member'=>'Same'],['BorrowerUserID'=>2,'Member'=>'Same'],['BorrowerUserID'=>1,'Member'=>'Same']];
 ensure_group(count(return_rows_by_member($rows))===2 && count(return_rows_by_member($rows)[1])===2,'Reviews and receipts group by member ID, not name');
 echo "12 member-list checks passed.\n";
}finally{db()->rollBack();}
