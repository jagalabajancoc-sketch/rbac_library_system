<?php
require_once __DIR__.'/includes/auth.php';
require_permission('process_in_library_use');
require_once __DIR__.'/includes/reading_sessions.php';
header('Cache-Control: no-store');
$key=is_string($_REQUEST['key']??null)?$_REQUEST['key']:'';
$origin=($_REQUEST['return_to']??'')==='desk_form.php'?'desk_form.php':'in_library_use.php';
parse_str(is_string($_REQUEST['return_query']??null)?$_REQUEST['return_query']:'',$originQuery);
$originQuery=array_filter(array_intersect_key($originQuery,array_flip(['q','book','type','page','tab'])),fn($v)=>is_scalar($v));
$view=in_array($_REQUEST['view']??'', ['add','detail','edit','correct'],true)?$_REQUEST['view']:'books';
$book=(int)($_REQUEST['book']??$_POST['session_id']??0);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if(!csrf_check()){http_response_code(403);echo 'Your session expired. Reload the page and try again.';exit;}
    $action=$_POST['action']??'';
    if (in_array($action,['add','edit','correct'],true)) require_permission('decide_use_requests');
    try {
        $message=reading_change($key,$_POST,(int)$_SESSION['user_id']);
        $_SESSION['reading_notice']=['key'=>$key,'tone'=>'success','text'=>$message];
        unset($_SESSION['reading_draft']); $view='books'; $book=0;
    } catch (Throwable $e) {
        $_SESSION['reading_notice']=['key'=>$key,'tone'=>'danger','text'=>user_message($e)];
        $_SESSION['reading_draft']=$_POST;
    }
    redirect($origin.'?'.http_build_query(array_merge($originQuery,['reading_session'=>$key,'reading_view'=>$view,'reading_book'=>$book])));
}
try {$ctx=reading_context($key);} catch(Throwable $e) {
    http_response_code(404); echo '<div class="alert alert-warning">'.h(user_message($e)).'</div>';exit;
}
$notice=$_SESSION['reading_notice']??null;
if (($notice['key']??'')===$key) unset($_SESSION['reading_notice']); else $notice=null;
$draft=$_SESSION['reading_draft']??[];
if (($draft['key']??'')===$key) unset($_SESSION['reading_draft']); else $draft=[];
$canEdit=has_permission('decide_use_requests');
$selected=null;foreach($ctx['rows'] as $r)if((int)$r['InLibraryUseID']===$book)$selected=$r;
if (in_array($view,['detail','edit','correct'],true)&&!$selected)$view='books';
if (in_array($view,['add','edit','correct'],true)&&(!$canEdit||!$ctx['open']))$view='books';
if (in_array($view,['edit','correct'],true)&&$selected['Status']!=='Active')$view='detail';
function reading_link(string $view='books',int $book=0):string {global $ctx,$origin,$originQuery;return 'reading_session.php?'.http_build_query(['key'=>$ctx['key'],'view'=>$view,'book'=>$book,'return_to'=>$origin,'return_query'=>http_build_query($originQuery)]);}
function reading_fields(string $action,int $id=0):void {global $ctx,$origin,$view,$originQuery;echo csrf_field();foreach(['action'=>$action,'key'=>$ctx['key'],'session_version'=>$ctx['version'],'return_to'=>$origin,'return_query'=>http_build_query($originQuery),'view'=>$view,'session_id'=>$id]as$n=>$v)echo '<input type="hidden" name="'.h($n).'" value="'.h($v).'">';}
function reading_value(string $key,$fallback=''):string {global $draft;return h(is_scalar($draft[$key]??null)?$draft[$key]:$fallback);}
$fragment=isset($_GET['fragment']);
if (!$fragment){$page_title='Reading session';require __DIR__.'/includes/layout_top.php';echo '<div class="card"><div class="card-body">';}
require __DIR__.'/includes/reading_session_view.php';
if (!$fragment){echo '</div></div>';require __DIR__.'/includes/reading_session_launcher.php';require __DIR__.'/includes/layout_bottom.php';}
