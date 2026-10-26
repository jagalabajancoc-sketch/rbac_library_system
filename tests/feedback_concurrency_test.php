<?php
require __DIR__.'/guard.php';require __DIR__.'/../includes/functions.php';
if(($argv[1]??'')==='worker') {
    $job=json_decode($argv[2],true);usleep(max(0,min(2000000,(int)(((float)$argv[3]-microtime(true))*1000000))));
    try{
        $value=match($job['mode']){'create'=>create_request_transaction($job['member'],$job['books'],$job['token'])['transaction_id'],'promote'=>promote_reservation_queue($job['book']),'release'=>release_request_items($job['ids'],$job['staff'])['loan_id']};
        echo 'OK '.$value;
    }catch(LibraryException $e){echo 'REFUSED';}exit;
}
function run_race(array $jobs):array {
    $workers=[];$start=microtime(true)+.5;
    foreach($jobs as $job){$proc=proc_open([PHP_BINARY,__FILE__,'worker',json_encode($job),(string)$start],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$proc,$pipes];}
    $out=[];foreach($workers as [$proc,$pipes]){$out[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($err);}return $out;
}
function verify_race(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$prefix='FBRACE-'.bin2hex(random_bytes(4));$role=(int)fetch_value("SELECT RoleID FROM role WHERE RoleName='Member'");$members=[];$books=[];
$staff=(int)fetch_value("SELECT u.UserID FROM user_account u JOIN role r ON r.RoleID=u.RoleID WHERE r.RoleName='Admin' LIMIT 1");
$policy=(int)fetch_value('SELECT PolicyID FROM policy WHERE IsBorrowable=1 LIMIT 1');
for($i=0;$i<2;$i++){run("INSERT INTO user_account (RoleID,FirstName,LastName,Username,PasswordHash,MembershipNumber,MembershipStatus,VerificationStatus) VALUES (?,'Race','Member',?,?,?,'Active','Approved')",[$role,$prefix.$i,password_hash('Test-only!',PASSWORD_DEFAULT),$prefix.$i]);$members[]=(int)last_id();}
for($i=0;$i<5;$i++){run('INSERT INTO book (Title,PolicyID) VALUES (?,?)',[$prefix.$i,$policy]);$books[]=(int)last_id();create_entered_copies(end($books),$i===4?1:3,implode("\n",array_map(fn($j)=>$prefix."-$i-$j",range(1,$i===4?1:3))));}
$job=['mode'=>'create','member'=>$members[0],'books'=>[$books[0],$books[1]],'token'=>bin2hex(random_bytes(32))];$out=run_race([$job,$job]);
verify_race(str_starts_with($out[0],'OK ') && $out[0]===$out[1] && (int)fetch_value('SELECT COUNT(*) FROM request_transaction WHERE UserID=?',[$members[0]])===1,'simultaneous basket retries create one transaction');
$out=run_race([['mode'=>'create','member'=>$members[1],'books'=>[$books[0],$books[1]],'token'=>bin2hex(random_bytes(32))],['mode'=>'create','member'=>$members[1],'books'=>[$books[2],$books[3]],'token'=>bin2hex(random_bytes(32))]]);
verify_race(count(array_filter($out,fn($s)=>str_starts_with($s,'OK ')))===1 && count_open_request_books($members[1])===2,'simultaneous baskets cannot exceed the member limit');
$a=create_borrow_request($members[0],$books[4]);$b=create_borrow_request($members[1],$books[4]);create_entered_copies($books[4],1,$prefix.'-spare');
$out=run_race([['mode'=>'promote','book'=>$books[4]],['mode'=>'promote','book'=>$books[4]]]);
verify_race(array_sum(array_map(fn($s)=>(int)substr($s,3),$out))===1 && fetch_value('SELECT ApprovalStatus FROM borrow_request WHERE RequestID=?',[$a['id']])==='Approved' && fetch_value('SELECT ApprovalStatus FROM borrow_request WHERE RequestID=?',[$b['id']])==='Pending','simultaneous queue promotions allocate one copy to the first reservation');
$ids=array_map('intval',array_column(fetch_all("SELECT RequestID FROM borrow_request WHERE UserID=? AND RequestType='Borrow' ORDER BY RequestID",[$members[0]]),'RequestID'));
decide_request_items($ids,'approve',$staff,true);
$out=run_race([['mode'=>'release','ids'=>$ids,'staff'=>$staff],['mode'=>'release','ids'=>$ids,'staff'=>$staff]]);
verify_race(str_starts_with($out[0],'OK ') && $out[0]===$out[1] && (int)fetch_value('SELECT COUNT(*) FROM loan WHERE BorrowerUserID=?',[$members[0]])===1,'simultaneous multi-book release creates one loan');
