<?php
require __DIR__.'/guard.php';
require __DIR__.'/../includes/desk_form.php';
require __DIR__.'/../includes/catalog.php';
require __DIR__.'/../includes/activity.php';
$passes=0;
function check(bool $ok,string $label): void { global $passes; if(!$ok) throw new RuntimeException('FAIL '.$label); echo 'PASS '.$label.PHP_EOL; $passes++; }
function rejects(callable $fn): bool { try{$fn();return false;}catch(LibraryException $e){return true;} }
$prefix='R1-'.bin2hex(random_bytes(4));
$admin=(int)fetch_value("SELECT ua.UserID FROM user_account ua JOIN role r ON r.RoleID=ua.RoleID WHERE r.RoleName='Admin' LIMIT 1");
$role=(int)fetch_value("SELECT RoleID FROM role WHERE RoleName='Member'");
run("INSERT INTO user_account (RoleID,FirstName,LastName,Username,Email,PasswordHash,MembershipNumber,RegistrationDate) VALUES (?, 'Release','Member',?,?,?,?,CURDATE())",[$role,$prefix,$prefix.'@example.test',password_hash('Test-Release1-Only!',PASSWORD_DEFAULT),$prefix]);
$member=(int)last_id();
run("INSERT INTO section (SectionName) VALUES (?)",[$prefix.' Section']);$section=(int)last_id();
run("INSERT INTO category (CategoryName) VALUES (?)",[$prefix.' Category']);$category=(int)last_id();
run("INSERT INTO publisher (PublisherName) VALUES (?)",[$prefix.' Publisher']);$publisher=(int)last_id();
run("INSERT INTO shelf (SectionID,ShelfCode) VALUES (?,?)",[$section,'QA-SHELF']);$shelf=(int)last_id();
run("INSERT INTO author (FirstName,LastName) VALUES ('Release',?)",[$prefix]);$author=(int)last_id();
$policy=(int)fetch_value('SELECT PolicyID FROM policy WHERE IsBorrowable=1 LIMIT 1');
$bookIds=[];db()->beginTransaction();
for($i=0;$i<2010;$i++) {
    run('INSERT INTO book (Title,CategoryID,SectionID,PublisherID,PolicyID,ISBN,Edition) VALUES (?,?,?,?,?,?,?)',[$prefix.' '.str_pad((string)$i,4,'0',STR_PAD_LEFT),$category,$section,$publisher,$policy,'978-123-'.str_pad((string)$i,5,'0',STR_PAD_LEFT),'1st Edition']);
    $bookIds[]=(int)last_id();
}
db()->commit();$last=end($bookIds);
run('INSERT INTO book_author VALUES (?,?)',[$last,$author]);
$copies=[];
for($i=0;$i<3;$i++){run('INSERT INTO book_copy (BookID,AccessionNumber,ShelfID) VALUES (?,?,?)',[$last,$prefix.'-COPY-'.$i,$shelf]);$copies[]=(int)last_id();}
$all=catalog_page(['q'=>$prefix],$member);
check($all['total']===2010 && count($all['books'])===12,'catalog searches all 2010 titles and returns only one page');
$tail=catalog_page(['q'=>$prefix.' 2009'],$member);
check(count($tail['books'])===1 && $tail['books'][0]['id']===$last,'title beyond the old 2000 cap is found');
check(str_contains($tail['books'][0]['locations'],'QA-SHELF'),'shelf location is included');
check($tail['books'][0]['avail']===2,'availability keeps the reference copy in the library');
$byAuthor=catalog_page(['q'=>$prefix,'author'=>['Release '.$prefix]],$member);
check($byAuthor['total']===1,'author filter is performed on the server');
check(catalog_page(['q'=>$prefix,'status'=>['available']],$member)['total']===1,'availability filter agrees with borrowing rules');
check(catalog_page(['q'=>$prefix.' 97812302009'],$member)['total']===1,'ISBN matches with or without hyphens');
check(catalog_page(['q'=>$prefix,'page'=>'99999'],$member)['page']===168,'out-of-range pages are clamped');
check(catalog_page(['q'=>"' OR 1=1 --",'sort'=>'b.Title; DROP TABLE book'],$member)['total']===0,'search and sorting reject SQL injection');
check(catalog_page(['q'=>'%'.$prefix],$member)['total']===0,'wildcard characters are treated literally');
$input=['borrower_type'=>'Member','student_id'=>$prefix,'photo_checked'=>true,'ack'=>true,'copy_ids'=>[$copies[0]],'use'=>[$copies[0]=>'Borrow'],'days'=>[$copies[0]=>7],'issue_token'=>bin2hex(random_bytes(32))];
$before=(int)fetch_value('SELECT COUNT(*) FROM loan');
$receipt=desk_form_issue($input,$admin);$repeat=desk_form_issue($input,$admin);
check($receipt['form_id']===$repeat['form_id'] && (int)fetch_value('SELECT COUNT(*) FROM loan')===$before+1,'repeated submission returns the same receipt without another loan');
check(fetch_value('SELECT DueDate FROM loan_detail WHERE LoanID=?',[$receipt['loan_id']])===date('Y-m-d',strtotime('+7 days')),'desk saves the selected due date');
check(fetch_value('SELECT IdStatus FROM in_library_form WHERE FormID=?',[$receipt['form_id']])==='NotRequired','borrow-only transactions do not hold the ID');
check(rejects(fn()=>desk_form_issue(array_replace($input,['issue_token'=>bin2hex(random_bytes(32))]),$admin)),'another transaction cannot borrow an already-borrowed copy');
check((int)fetch_value('SELECT COUNT(*) FROM loan')===$before+1,'failed transaction leaves the loan count unchanged');
$read=desk_form_issue(array_replace($input,['issue_token'=>bin2hex(random_bytes(32)),'copy_ids'=>[$copies[1]],'use'=>[$copies[1]=>'Read'],'slot'=>'R1TEST']),$admin);
check(fetch_value('SELECT IdStatus FROM in_library_form WHERE FormID=?',[$read['form_id']])==='Held','reading transaction holds an ID');
$sid=(int)fetch_value('SELECT InLibraryUseID FROM in_library_use WHERE SessionRef=?',[$read['form_no']]);
check(rejects(fn()=>desk_form_return($read['form_id'],[],true,null,$admin)),'ID cannot be released while a book is out');
$returned=desk_form_return($read['form_id'],[$sid=>'Good'],true,null,$admin);
check($returned['released'] && fetch_value('SELECT Status FROM book_copy WHERE CopyID=?',[$copies[1]])==='Available','return restores availability and releases the ID');
check(catalog_page(['q'=>$prefix.' 2009'],$member)['books'][0]['mine']==='holding','catalog identifies a title already borrowed by this member');
// A failed multi-copy operation must roll back every change, not leave a partial loan.
check(rejects(fn()=>desk_form_issue(array_replace($input,['issue_token'=>bin2hex(random_bytes(32)),'copy_ids'=>[$copies[1],$copies[2]],'use'=>[$copies[1]=>'Borrow',$copies[2]=>'Borrow']]),$admin)),'duplicate-title borrowing is rejected');
check(fetch_value('SELECT Status FROM book_copy WHERE CopyID=?',[$copies[1]])==='Available','failed mixed transaction preserves copy status');
echo "$passes checks passed. Fixture username: $prefix; password: Test-Release1-Only!\n";
file_put_contents(__DIR__.'/release1-fixture.json',json_encode(['username'=>$prefix,'member'=>$member,'admin'=>$admin,'book'=>$last,'copies'=>$copies,'prefix'=>$prefix]));
