<?php
require __DIR__.'/guard.php';
require __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/desk_form.php';
require __DIR__.'/../includes/activity.php';
if (($argv[1]??'')==='worker') {
    [$script,$mode,$card,$copy,$staff,$token,$start]=$argv;
    usleep(max(0,min(2000000,(int)(((float)$start-microtime(true))*1000000))));
    try {
        $r=desk_form_issue(['borrower_type'=>'Member','student_id'=>$card,'copy_ids'=>[(int)$copy], 'use'=>[(int)$copy=>'Borrow'],'photo_checked'=>true,'ack'=>true,'issue_token'=>$token],(int)$staff);
        echo 'OK '.$r['form_id'];
    } catch(LibraryException $e) { echo 'REFUSED '.$e->getMessage(); }
    exit;
}
$staff=(int)fetch_value("SELECT ua.UserID FROM user_account ua JOIN role r ON r.RoleID=ua.RoleID WHERE r.RoleName='Admin' LIMIT 1");
$role=(int)fetch_value("SELECT RoleID FROM role WHERE RoleName='Member'");
$policy=(int)fetch_value('SELECT PolicyID FROM policy WHERE IsBorrowable=1 LIMIT 1');
$cards=[];$prefix='RACE-'.bin2hex(random_bytes(4));
for($i=0;$i<3;$i++) {
    $cards[]=$card=$prefix.'-'.$i;
    run("INSERT INTO user_account (RoleID,FirstName,LastName,Username,PasswordHash,MembershipNumber) VALUES (?,'Concurrency','Test',?,?,?)",[$role,$card,password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT),$card]);
}
run('INSERT INTO book (Title,PolicyID) VALUES (?,?)',[$prefix,$policy]);$book=(int)last_id();$copies=[];
for($i=0;$i<4;$i++){run('INSERT INTO book_copy (BookID,AccessionNumber) VALUES (?,?)',[$book,$prefix.'-C'.$i]);$copies[]=(int)last_id();}
function race(array $jobs): array {
    $workers=[];$start=microtime(true)+0.5;
    foreach($jobs as $job){$proc=proc_open([PHP_BINARY,__FILE__,'worker',...$job,(string)$start],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$proc,$pipes];}
    $out=[];foreach($workers as [$proc,$pipes]){$out[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($err);}
    return $out;
}
$token=bin2hex(random_bytes(32));
$out=race([[$cards[0],(string)$copies[0],(string)$staff,$token],[$cards[0],(string)$copies[0],(string)$staff,$token]]);
if(!str_starts_with($out[0],'OK ') || $out[0]!==$out[1]) throw new RuntimeException('Repeated request race failed: '.json_encode($out));
echo "PASS simultaneous retries return one identical receipt\n";
$out=race([[$cards[1],(string)$copies[1],(string)$staff,bin2hex(random_bytes(32))],[$cards[2],(string)$copies[1],(string)$staff,bin2hex(random_bytes(32))]]);
if(count(array_filter($out,fn($s)=>str_starts_with($s,'OK ')))!==1 || count(array_filter($out,fn($s)=>str_starts_with($s,'REFUSED ')))!==1) throw new RuntimeException('Copy competition failed: '.json_encode($out));
$count=(int)fetch_value('SELECT COUNT(*) FROM loan_detail WHERE CopyID=?',[$copies[1]]);
if($count!==1)throw new RuntimeException('Duplicate physical-copy loan');
echo "PASS two staff competing for one copy produce exactly one loan\n";
