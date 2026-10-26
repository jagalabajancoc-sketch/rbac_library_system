<?php
require __DIR__.'/guard.php';
require __DIR__.'/../includes/functions.php';
require __DIR__.'/../includes/reading_sessions.php';
function ensure_reading($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
db()->beginTransaction();
try{
    $prefix='LEG-'.bin2hex(random_bytes(4));
    $actor=(int)fetch_value("SELECT u.UserID FROM user_account u JOIN role r ON r.RoleID=u.RoleID WHERE r.RoleName='Admin' LIMIT 1");
    $book=(int)fetch_value('SELECT BookID FROM book WHERE IsArchived=0 LIMIT 1');$copies=[];
    foreach([0,1]as$n){run("INSERT INTO book_copy(BookID,AccessionNumber,Status) VALUES(?,?,'Available')",[$book,$prefix.'-'.$n]);$copies[]=(int)last_id();}
    $first=start_in_library_session([$copies[0]],'Walk-in',null,'Legacy Reader',null,$actor,null,null);
    $id=$first['items'][0]['id'];run('UPDATE in_library_use SET SessionRef=NULL WHERE InLibraryUseID=?',[$id]);
    $key='session:'.$id;$before=reading_context($key);$forms=(int)fetch_value('SELECT COUNT(*) FROM in_library_form');
    reading_change($key,['action'=>'add','session_version'=>$before['version'],'accessions'=>$prefix.'-1','condition_out'=>'Good'],$actor);
    $ctx=reading_context($key);
    ensure_reading(count($ctx['active'])===2&&$ctx['ref']&&$ctx['active'][0]['SessionRef']===$ctx['active'][1]['SessionRef'],'legacy record without a session reference can receive books under one shared identity');
    ensure_reading((int)fetch_value('SELECT COUNT(*) FROM in_library_form')===$forms&&!$ctx['form'],'legacy additions do not invent an ID custody record');
    $selected=array_column($ctx['active'],'InLibraryUseID');
    reading_change($key,['action'=>'return','session_version'=>$ctx['version'],'selected'=>$selected,'conditions'=>array_fill_keys($selected,'Good')],$actor);
    ensure_reading(!reading_context($key)['open']&&(int)fetch_value("SELECT COUNT(*) FROM book_copy WHERE CopyID IN (?,?) AND Status='Available'",$copies)===2,'legacy books return through the same workflow without ID release');
    echo '3 legacy-session checks passed.'.PHP_EOL;
}finally{db()->rollBack();}
