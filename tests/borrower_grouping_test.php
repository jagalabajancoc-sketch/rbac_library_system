<?php
require __DIR__.'/guard.php';
require __DIR__.'/../includes/functions.php';
require __DIR__.'/../includes/borrowed_books_list.php';
$checks=0;
function verify_group($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$checks++;}
$pdo=db();$pdo->beginTransaction();
try {
 $prefix='GROUP-'.bin2hex(random_bytes(4));
 $admin=(int)fetch_value("SELECT u.UserID FROM user_account u JOIN role r ON r.RoleID=u.RoleID WHERE r.RoleName='Admin' LIMIT 1");
 $role=(int)fetch_value("SELECT RoleID FROM role WHERE RoleName='Member' LIMIT 1");
 $members=[];$records=[];
 for($i=0;$i<12;$i++){
  run("INSERT INTO user_account (RoleID,FirstName,LastName,Username,Email,PasswordHash,MembershipNumber,RegistrationDate,MembershipStatus,VerificationStatus) VALUES (?,?,?,?,?,?,?,CURDATE(),'Active','Approved')",[$role,$prefix,$i<2?'Same':'Member'.$i,$prefix.$i,$prefix.$i.'@example.test','unused',$prefix.$i]);
  $id=(int)last_id();$members[]=$id;
  for($j=0;$j<($i===0?25:1);$j++){
   run('INSERT INTO loan (BorrowerUserID,ProcessedByUserID) VALUES (?,?)',[$id,$admin]);$records[$id][]=(int)last_id();
  }
 }
 $one=borrowed_books_groups($prefix,1);$two=borrowed_books_groups($prefix,2);
 verify_group($one['pagination']['total']===12,'pagination counts members instead of transactions');
 verify_group(count($one['groups'])===10 && count($two['groups'])===2,'member pagination splits groups across two pages');
 verify_group(!array_intersect(array_keys($one['groups']),array_keys($two['groups'])),'no member is repeated across pages');
 $same=borrowed_books_groups($prefix.' Same',1);
 verify_group(count($same['groups'])===2,'identical names with different member IDs stay separate');
 verify_group(count($same['groups'][$members[0]]['records'])===25,'all 25 transactions remain in one member group');
 $match=borrowed_books_groups((string)$records[$members[0]][0],1);
 verify_group(isset($match['groups'][$members[0]]) && count($match['groups'][$members[0]]['records'])===25,'transaction search includes the complete matching member history');
 verify_group(!borrowed_books_groups($prefix.'-absent',1)['groups'],'empty search produces no unrelated groups');
 $book=(int)fetch_value('SELECT BookID FROM book LIMIT 1');$detailIds=[];
 foreach([-10,-2,0,5] as $i=>$days){
  run("INSERT INTO book_copy(BookID,AccessionNumber,Status) VALUES (?,?,'Borrowed')",[$book,$prefix.'-due-'.$i]);$copy=(int)last_id();
  run("INSERT INTO loan_detail(LoanID,CopyID,DueDate,BookCondition) VALUES (?,?,?,'Good')",[$records[$members[0]][$i],$copy,date('Y-m-d',strtotime("$days days"))]);$detailIds[]=(int)last_id();
 }
 run('INSERT INTO return_header(LoanID,ProcessedByUserID) VALUES (?,?)',[$records[$members[0]][0],$admin]);$rid=(int)last_id();
 run("INSERT INTO return_detail(ReturnID,LoanDetailID,ReturnCondition,ReturnStatus) VALUES (?,?,'Good','Returned')",[$rid,$detailIds[0]]);
 $dueGroup=borrowed_books_groups($prefix.' Same',1)['groups'][$members[0]];
 verify_group($dueGroup['overdue']===1,'overdue count excludes returned books and books due today');
 verify_group($dueGroup['earliest_due']===date('Y-m-d',strtotime('-2 days')),'earliest due date uses outstanding books only');
 require __DIR__.'/../includes/circulation_ui.php';
 verify_group(circulation_list_query(['q'=>'A & B','page'=>2,'receipt'=>3,'url'=>'https://invalid.test'])==='q=A+%26+B&page=2','list context safely encodes filters and discards destinations and receipts');
 verify_group(circulation_list_query(['q'=>['bad'],'tab'=>'all'])==='tab=all','malformed list context cannot inject array values');
 echo "$checks grouping checks passed.\n";
}finally{$pdo->rollBack();}
