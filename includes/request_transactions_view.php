<?php
require_once __DIR__.'/circulation_ui.php';
// Staff lists paginate members; member lists remain scoped to their own submissions.
require_once __DIR__.'/member_circulation_lists.php';
$search=mb_substr(trim((string)($_GET['q']??'')),0,100);
$tab=(string)($_GET['tab']??($isMemberView?'active':'all'));
$filters=['all'=>'All','review'=>'To review','ready'=>'Ready for pickup','queue'=>'Reservation queue','history'=>'History'];
if($isMemberView)$filters=['active'=>'Active requests','review'=>'Pending approval','ready'=>'Ready for pickup','queue'=>'Reservation queue','history'=>'Request history'];
if(!isset($filters[$tab]))$tab=$isMemberView?'active':'all';
$status=(string)($_GET['status']??'');
if(!in_array($status,['Pending','Approved','Fulfilled','Rejected','Cancelled','Expired'],true))$status='';
$where=[];$params=[];
if($isMemberView){$where[]='t.UserID=?';$params[]=$memberId;}
if($search!=='') {
    $like='%'.$search.'%';$where[]="(CONCAT('RQ-',LPAD(t.TransactionID,6,'0')) LIKE ? OR CONCAT(m.FirstName,' ',m.LastName) LIKE ? OR m.MembershipNumber LIKE ? OR EXISTS(SELECT 1 FROM borrow_request x JOIN borrow_request_detail d ON d.RequestID=x.RequestID JOIN book b ON b.BookID=d.BookID LEFT JOIN book_copy c ON c.CopyID=x.AssignedCopyID WHERE x.TransactionID=t.TransactionID AND (b.Title LIKE ? OR c.AccessionNumber LIKE ?)))";
    array_push($params,$like,$like,$like,$like,$like);
}
$itemWhere=match($tab){'review'=>"x.ApprovalStatus='Pending' AND x.RequestType='Borrow'",'ready'=>"x.ApprovalStatus='Approved'",'queue'=>"x.ApprovalStatus='Pending' AND x.RequestType='Reservation'",'active'=>"x.ApprovalStatus IN ('Pending','Approved')",'history'=>$isMemberView?"x.ApprovalStatus IN ('Rejected','Cancelled','Expired')":"x.ApprovalStatus IN ('Fulfilled','Rejected','Cancelled','Expired')",default=>'1=1'};
if($isMemberView && $status==='Fulfilled')$status='';
if($status!==''){$itemWhere.=' AND x.ApprovalStatus=?';$params[]=$status;}
$where[]="EXISTS(SELECT 1 FROM borrow_request x WHERE x.TransactionID=t.TransactionID AND $itemWhere)";
$from=trim((string)($_GET['from']??''));$to=trim((string)($_GET['to']??''));
foreach(['from'=>$from,'to'=>$to]as $key=>$date)if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)){$where[]='DATE(t.SubmittedAt)'.($key==='from'?'>=':'<=').'?';$params[]=$date;}
$base='FROM request_transaction t JOIN user_account m ON m.UserID=t.UserID WHERE '.implode(' AND ',$where);
if($isMemberView){
    $pg=pagination_calc((int)fetch_value('SELECT COUNT(*) '.$base,$params),10,pagination_page());
    $transactions=fetch_all("SELECT t.*,m.FirstName,m.LastName,m.MembershipNumber $base ORDER BY t.TransactionID DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",$params);
    $requestGroups=$transactions?[$memberId=>['transactions'=>$transactions]]:[];
}else{
    $groupedRequests=request_member_groups($base,$params,pagination_page());
    $pg=$groupedRequests['pagination'];$requestGroups=$groupedRequests['groups'];
}
$visibleRequestTransactions=[];
foreach($requestGroups as $visibleGroup)foreach($visibleGroup['transactions'] as $visibleTransaction)$visibleRequestTransactions[]=(int)$visibleTransaction['TransactionID'];
$requestOverview=['Pending'=>0,'Ready'=>0];
if($visibleRequestTransactions){
    $overviewMarks=implode(',',array_fill(0,count($visibleRequestTransactions),'?'));
    $requestOverview=fetch_one("SELECT COALESCE(SUM(ApprovalStatus='Pending'),0) AS Pending,COALESCE(SUM(ApprovalStatus='Approved'),0) AS Ready FROM borrow_request WHERE TransactionID IN ($overviewMarks)",$visibleRequestTransactions);
}
$requestModals=[];
$requestListUrl=$isMemberView?'member_requests.php':'borrow_requests.php';
require __DIR__.($isMemberView?'/member_layout_top.php':'/layout_top.php');
?>
<?php if(!$isMemberView && has_permission('decide_use_requests')):?><div class="d-flex flex-wrap gap-2 mb-3"><a class="btn btn-primary" href="borrow_requests.php" aria-current="page">Portal requests</a><a class="btn btn-outline-primary" href="borrow_requests.php?source=kiosk">Kiosk requests</a></div><?php endif;?>
<?php circulation_overview([$isMemberView?'Your submissions':'Borrowers on this page'=>$isMemberView?count($transactions):count($requestGroups),'Pending requests'=>$requestOverview['Pending'],'Ready for pickup'=>$requestOverview['Ready']]); ?>
<div class="page-toolbar circulation-toolbar"><form method="get" class="circulation-filters">
<?php if($status!==''):?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif;?><div class="filter-search"><label for="requestSearch" class="form-label small">Search requests</label><input id="requestSearch" name="q" class="form-control" type="search" value="<?= h($search) ?>" placeholder="Borrower, book, accession or RQ reference"></div>
<div class="filter-field"><label for="requestTab" class="form-label small">Show</label><select id="requestTab" name="tab" class="form-select"><?php foreach($filters as $value=>$label):?><option value="<?= h($value) ?>" <?= $tab===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach;?></select></div>
<div class="filter-field"><label for="requestFrom" class="form-label small">From</label><input id="requestFrom" type="date" class="form-control" name="from" value="<?= h($from) ?>"></div><div class="filter-field"><label for="requestTo" class="form-label small">To</label><input id="requestTo" type="date" class="form-control" name="to" value="<?= h($to) ?>"></div>
<div class="filter-actions"><button class="btn btn-primary">Search</button><a class="btn btn-outline-secondary" href="<?= h($requestListUrl) ?>">Clear</a></div></form>
<?php if($isMemberView):?><a class="btn btn-primary" href="member_catalog.php">Request books</a><?php endif;?></div>
<?php if($isMemberView):?><p class="small text-muted">Active requests are awaiting approval or pickup. Cancelled, rejected and expired requests are in Request history. Issued books are in <a href="my_borrowed_books.php">Borrowed &amp; Returned</a>.</p><?php endif;?>
<div class="list-heading"><div><h2><?= $isMemberView?($tab==='history'?'Request history':'Active requests'):'Borrower requests' ?></h2><p><?= pagination_info($pg) ?> <?= $isMemberView?'submissions':'borrowers' ?> · <?= $isMemberView?'Open a submission to review its books.':'Select books directly below, including books from separate submissions.' ?></p></div><?php if(!$isMemberView):?><button hidden type="button" class="btn btn-outline-secondary btn-sm" data-group-toggle>Expand members</button><?php endif;?></div>
<?php if(!$requestGroups):?><div class="card transaction-card empty-state"><i class="bi bi-inbox" aria-hidden="true"></i><strong>No matching transactions.</strong><span>Try another search or adjust the date and status filters.</span></div><?php endif;?>
<?php if(!$isMemberView): require __DIR__.'/request_borrower_list.php'; else: ?>
<?php foreach($requestGroups as $requestMemberId=>$requestGroup): ?>
<?php if(!$isMemberView):$requestPerson=$requestGroup['member'];?>
<details class="card transaction-card borrower-group mb-3" data-member-id="<?= (int)$requestMemberId ?>" <?= $search!==''?'open':'' ?>>
<summary class="p-3"><span class="borrower-heading"><strong><?= h($requestPerson['FirstName'].' '.$requestPerson['LastName']) ?></strong><span class="small text-muted">Member <?= h($requestPerson['MembershipNumber']?:'#'.$requestMemberId) ?></span></span><span class="borrower-totals"><?= count($requestGroup['transactions']) ?> matching submission(s)</span><span class="small text-decoration-underline">View requests<span class="visually-hidden"> for <?= h($requestPerson['FirstName'].' '.$requestPerson['LastName']) ?></span></span></summary><div class="px-3 pb-1">
<?php endif;?>
<?php foreach($requestGroup['transactions'] as $t):
$items=fetch_all("SELECT r.*,c.AccessionNumber,(SELECT GROUP_CONCAT(b.Title SEPARATOR ', ') FROM borrow_request_detail d JOIN book b ON b.BookID=d.BookID WHERE d.RequestID=r.RequestID) AS Books FROM borrow_request r LEFT JOIN book_copy c ON c.CopyID=r.AssignedCopyID WHERE r.TransactionID=? ORDER BY r.RequestID",[$t['TransactionID']]);
// Filter individual books too: a partially processed submission must not
// bring issued or cancelled siblings back into the active list.
if($isMemberView){
    $items=array_values(array_filter($items,function($it)use($tab,$status){
        $allowed=match($tab){'history'=>['Rejected','Cancelled','Expired'],'ready'=>['Approved'],'review','queue'=>['Pending'],default=>['Pending','Approved']};
        return in_array($it['ApprovalStatus'],$allowed,true)
            && ($status==='' || $it['ApprovalStatus']===$status)
            && ($tab!=='review' || $it['RequestType']==='Borrow')
            && ($tab!=='queue' || $it['RequestType']==='Reservation');
    }));
}
$statuses=array_unique(array_column($items,'ApprovalStatus'));$overall=request_display_summary($items);
?>
<div class="card transaction-card request-submission mb-3">
<button type="button" class="request-open" data-bs-toggle="modal" data-bs-target="#requestModal<?= (int)$t['TransactionID'] ?>" aria-haspopup="dialog" aria-controls="requestModal<?= (int)$t['TransactionID'] ?>" aria-label="Open <?= h(request_transaction_ref((int)$t['TransactionID'])) ?> books and actions">
<strong><?= h(request_transaction_ref((int)$t['TransactionID'])) ?></strong><span class="small text-muted"><?= fmt_datetime($t['SubmittedAt']) ?> · <?= count($items) ?> book(s)</span><?= status_badge($overall) ?><span class="request-open-label">View books and actions <i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
</button></div>
<?php ob_start(); ?>
<div class="modal fade request-modal" id="requestModal<?= (int)$t['TransactionID'] ?>" tabindex="-1" aria-labelledby="requestTitle<?= (int)$t['TransactionID'] ?>" aria-hidden="true">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
<div class="modal-header"><div><h2 class="modal-title h5" id="requestTitle<?= (int)$t['TransactionID'] ?>"><?= h(request_transaction_ref((int)$t['TransactionID'])) ?> · Request details</h2><p class="small text-muted mb-0 mt-1"><?= h($t['FirstName'].' '.$t['LastName']) ?> · <?= fmt_datetime($t['SubmittedAt']) ?> · <?= count($items) ?> book(s)</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close request details"></button></div>
<div class="modal-body">
<form method="post" class="request-action-form">
<?= csrf_field() ?><input type="hidden" name="list_query" value="<?= h(circulation_list_query()) ?>"><input type="hidden" name="transaction_id" value="<?= (int)$t['TransactionID'] ?>">
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Select</th><th>Book / copy</th><th>Type</th><th>Status</th><th>Details</th></tr></thead><tbody>
<?php foreach($items as $it):$open=in_array($it['ApprovalStatus'],['Pending','Approved'],true);?><tr>
<td><?php if($open):?><input type="checkbox" class="form-check-input" name="request_ids[]" data-request-status="<?= h($it['ApprovalStatus']) ?>" value="<?= (int)$it['RequestID'] ?>" aria-label="Select <?= h($it['Books']) ?>"><?php else:?>—<?php endif;?></td>
<td><?= h($it['Books']) ?><div class="small text-muted"><?= h($it['AccessionNumber']?:'Copy assigned on approval') ?></div></td><td><?= request_type_badge($it['RequestType']) ?></td><td><?= status_badge(request_display_status($it)) ?></td>
<td class="small"><?php if($it['ReleasedLoanID']):?>Transaction #<?= (int)$it['ReleasedLoanID'] ?><?php elseif($it['ApprovalStatus']==='Approved'):?>Held until <?= fmt_datetime($it['ExpiresAt']) ?><?php elseif($it['RequestType']==='Reservation' && $it['ApprovalStatus']==='Pending'):?>Queue #<?= reservation_queue_position((int)$it['RequestID']) ?><?php endif;?><div><?= h($it['DecisionReason']??'') ?></div></td></tr><?php endforeach;?>
</tbody></table></div>
<?php if(array_intersect($statuses,['Pending','Approved'])):?>
<div class="request-actions"><strong class="selection-count" data-selected-count aria-live="polite">0 books selected</strong><span class="small text-muted action-hint" data-action-hint>Select books to see available actions.</span><label class="small flex-grow-1" data-request-reason><span data-reason-label>Reason (required for rejection)</span><input class="form-control" name="reason" maxlength="255"></label>
<?php if(!$isMemberView && has_permission('approve_requests')):?><button class="btn btn-primary" name="action" value="approve">Approve selected</button><button class="btn btn-outline-danger" name="action" value="reject">Reject selected</button><?php endif;?>
<?php if(!$isMemberView && has_permission('process_loans')):?><button class="btn btn-success" name="action" value="release">Release selected together</button><?php endif;?>
<?php if($isMemberView || has_permission('approve_requests')):?><button class="btn btn-outline-secondary" name="action" value="cancel">Cancel selected</button><?php endif;?></div>
<?php else:?><p class="small text-muted mb-0">This submission has no pending or approved books to process.</p><?php endif;?></form>
</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
</div></div></div>
<?php $requestModals[]=ob_get_clean(); endforeach;?>
<?php if(!$isMemberView):?></div></details><?php endif;?>
<?php endforeach;?>
<?php endif;?>
<?= pagination_links($pg,$_GET) ?>
<?= implode("\n",$requestModals) ?>
<script>document.querySelectorAll('.request-action-form').forEach(function(form){form.addEventListener('submit',function(event){var count=form.querySelectorAll('input[name="request_ids[]"]:checked').length;var action=event.submitter?event.submitter.value:'update';if(!count){event.preventDefault();alert('Select at least one book.');return;}if(!confirm(action.charAt(0).toUpperCase()+action.slice(1)+' '+count+' selected book(s)?'))event.preventDefault();});});</script>
<script data-circulation-user="<?= (int)($_SESSION['user_id']??0) ?>" src="assets/js/circulation.js?v=<?= h(filemtime(__DIR__.'/../assets/js/circulation.js')) ?>" defer></script>
<?php require __DIR__.($isMemberView?'/member_layout_bottom.php':'/layout_bottom.php'); ?>
