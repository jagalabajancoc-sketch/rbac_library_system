<?php
require_once __DIR__.'/includes/auth.php';
if(($_GET['source']??'')==='kiosk'){require __DIR__.'/use_requests.php';exit;}
require_once __DIR__.'/includes/circulation_ui.php';
$isMemberView=false;
$page_title='Borrow Requests';
$page_subtitle='Review request transactions, release books together and manage reservations';
$staffUserId=(int)$_SESSION['user_id'];
if($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_guard();
    try {
        $action=(string)($_POST['action']??'');$ids=(array)($_POST['request_ids']??[]);$tx=(int)($_POST['transaction_id']??0);
        $borrower=(int)($_POST['borrower_id']??0);
        if($borrower>0) {
            require_permission($action==='release'?'process_loans':'approve_requests');
            $result=circulation_transaction(function()use($ids,$action,$staffUserId,$borrower){
                selected_request_items($ids,$borrower,null,true);
                return $action==='release'
                    ? release_request_items($ids,$staffUserId,null,[],true)
                    : decide_request_items($ids,$action,$staffUserId,true,(string)($_POST['reason']??''),$borrower,null,true);
            });
            log_activity(ucfirst($action),'Borrow Requests',ucfirst($action).' '.($result['count']??$result['items']).' selected book(s) for borrower #'.$borrower,$borrower);
            flash('success',($result['count']??$result['items']).' selected book(s) '.($action==='approve'?'approved':($action==='release'?'released together':'updated')).'.',true);
            redirect('borrow_requests.php?'.circulation_post_query());
        }
        if($tx<1)throw new LibraryException('Select a request transaction.');
        if($action==='release') {
            require_permission('process_loans');$result=release_request_items($ids,$staffUserId,$tx);
            log_activity('Create','Borrowed Books','Released '.request_transaction_ref($tx).' as Transaction #'.$result['loan_id'],$result['loan_id']);
            flash('success','Transaction #'.$result['loan_id'].' contains '.$result['items'].' book(s).',true);
            if(isset($_POST['list_query']))redirect('borrow_requests.php?'.circulation_post_query());
            redirect('borrowed_books.php?view='.$result['loan_id']);
        }
        require_permission('approve_requests');
        $result=decide_request_items($ids,$action,$staffUserId,true,(string)($_POST['reason']??''),null,$tx);
        log_activity(ucfirst($action),'Borrow Requests',ucfirst($action).' '.$result['count'].' item(s) in '.request_transaction_ref($tx),$tx);
        flash('success',$result['count'].' item(s) updated together.',true);
    } catch(Throwable $e) { flash('danger',user_message($e,'No changes were saved. Please try again.'),true); }
    redirect('borrow_requests.php?'.circulation_post_query());
}
require __DIR__.'/includes/request_transactions_view.php';
