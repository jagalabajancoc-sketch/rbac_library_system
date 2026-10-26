<?php
require_once __DIR__.'/includes/member_auth.php';
require_once __DIR__.'/includes/circulation_ui.php';
$isMemberView=true;$member=current_member();$memberId=(int)current_member_id();
$page_title='My Borrow Requests';$page_subtitle='Track pending requests, reservations and books ready for pickup';
if($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_guard();
    try {
        if(($_POST['action']??'')!=='cancel')throw new LibraryException('Action not allowed.');
        $tx=(int)($_POST['transaction_id']??0);if($tx<1)throw new LibraryException('Select a transaction.');
        $res=decide_request_items((array)($_POST['request_ids']??[]),'cancel',$memberId,false,(string)($_POST['reason']??''),$memberId,$tx);
        log_activity('Cancel','Borrow Requests','Member cancelled '.$res['count'].' item(s) in '.request_transaction_ref($tx),$tx);
        flash('success',$res['count'].' item(s) cancelled. Held copies were offered to the reservation queue.');
    }catch(Throwable $e){flash('danger',user_message($e,'The cancellation could not be saved.'));}
    redirect('member_requests.php?'.circulation_post_query());
}
require __DIR__.'/includes/request_transactions_view.php';
