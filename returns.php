<?php
require_once __DIR__.'/includes/circulation_ui.php';
require_once __DIR__.'/includes/auth.php';
require_permission('process_returns');
require_once __DIR__.'/includes/member_circulation_lists.php';
$page_title='Returns';$page_subtitle='Return multiple books together and review penalties before confirming';
$staffUserId=(int)$_SESSION['user_id'];$quote=null;$reviewToken=null;
$listQuery=$_SERVER['REQUEST_METHOD']==='POST'?circulation_post_query():circulation_list_query();
$listUrl='returns.php'.($listQuery!==''?'?'.$listQuery:'');
if($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_guard();
    try {
        $action=(string)($_POST['action']??'');
        if($action==='preview_return') {
            $conditions=(array)($_POST['return_conditions']??[]);
            $quote=circulation_transaction(fn()=>return_quote($conditions));
            $reviewToken=bin2hex(random_bytes(24));
            $_SESSION['return_reviews'][$reviewToken]=['conditions'=>$conditions,'total'=>$quote['total'],'at'=>time(),'list_query'=>$listQuery];
            $_SESSION['return_reviews']=array_slice($_SESSION['return_reviews']??[],-15,null,true);
        }elseif($action==='process_return') {
            $reviewToken=(string)($_POST['review_token']??'');$review=$_SESSION['return_reviews'][$reviewToken]??null;
            if(!$review || $review['at']<time()-1800)throw new LibraryException('Review the return again before confirming.');
            $listQuery=$review['list_query']??$listQuery;
            if(isset($review['batch']))redirect('returns.php?'.($listQuery!==''?$listQuery.'&':'').'receipt='.$review['batch']);
            $result=circulation_transaction(function()use($review,$staffUserId){
                $latest=return_quote($review['conditions']);
                if(abs($latest['total']-$review['total'])>.001)throw new LibraryException('The penalty changed since review. Review the return again.');
                return process_return_transaction($review['conditions'],$staffUserId);
            });
            $_SESSION['return_reviews'][$reviewToken]['batch']=$result['batch_id'];
            log_activity('Return','Returns',return_batch_ref($result['batch_id']).': '.$result['returned'].' books, penalties '.money($result['fine_total']),$result['batch_id']);
            flash('success',$result['returned'].' book(s) returned together. Penalties assessed: '.money($result['fine_total']).'.',true);
            redirect('returns.php?'.($listQuery!==''?$listQuery.'&':'').'receipt='.$result['batch_id']);
        }else throw new LibraryException('Unknown action.');
    }catch(Throwable $e){flash('danger',user_message($e,'The return could not be saved. Nothing changed.'),true);redirect($listUrl);}
}
$search=mb_substr(trim((string)($_GET['q']??'')),0,100);$tab=($_GET['tab']??'borrowed')==='returned'?'returned':'borrowed';
$memberFilter=(int)($_GET['member_id']??0);$loanFilter=(int)($_GET['view_loan']??0);$receiptId=(int)($_GET['receipt']??0);
$where=[];$params=[];
if($memberFilter){$where[]='l.BorrowerUserID=?';$params[]=$memberFilter;}
if($loanFilter){$where[]='l.LoanID=?';$params[]=$loanFilter;}
if($search!==''){$where[]="(CAST(l.LoanID AS CHAR) LIKE ? OR CONCAT(m.FirstName,' ',m.LastName) LIKE ? OR m.MembershipNumber LIKE ? OR EXISTS(SELECT 1 FROM loan_detail d JOIN book_copy c ON c.CopyID=d.CopyID JOIN book b ON b.BookID=c.BookID WHERE d.LoanID=l.LoanID AND (b.Title LIKE ? OR c.AccessionNumber LIKE ?)))";array_push($params,...array_fill(0,5,'%'.$search.'%'));}
$whereSql=$where?' AND '.implode(' AND ',$where):'';
$groupedReturns=return_member_groups($search,pagination_page(),$memberFilter,$loanFilter);
$pg=$groupedReturns['pagination'];$returnGroups=$groupedReturns['groups'];
$history=[];$histPg=null;
if($tab==='returned') {
    $histWhere=$whereSql;
    if($search!=='' && preg_match('/^RT-0*(\d+)$/i',$search,$ref)){$histWhere=' AND rb.ReturnBatchID=?';$params=[(int)$ref[1]];}
    $histBase="FROM return_batch rb WHERE EXISTS(SELECT 1 FROM return_header rh JOIN loan l ON l.LoanID=rh.LoanID JOIN user_account m ON m.UserID=l.BorrowerUserID WHERE rh.ReturnBatchID=rb.ReturnBatchID $histWhere)";
    $histPg=pagination_calc((int)fetch_value('SELECT COUNT(*) '.$histBase,$params),10,pagination_page());
    $history=fetch_all("SELECT rb.*,(SELECT COUNT(*) FROM return_header rh JOIN return_detail d ON d.ReturnID=rh.ReturnID WHERE rh.ReturnBatchID=rb.ReturnBatchID) AS Books $histBase ORDER BY rb.ReturnBatchID DESC LIMIT {$histPg['per_page']} OFFSET {$histPg['offset']}",$params);
}
require __DIR__.'/includes/layout_top.php';
?>
<?php if($quote):?>
<section class="card transaction-card p-3 mb-3"><h2 class="h5">Review return and penalties</h2><p class="small">Penalties are assessed when you confirm. Payments are recorded separately by authorized staff. A lost or damaged book must be replaced with a new copy or paid for at the full recorded acquisition cost. After verifying a replacement, staff can settle the fine using the “Book replaced” payment method.</p>
<div class="table-responsive"><table class="table"><thead><tr><th>Transaction #</th><th>Book / accession</th><th>Condition</th><th>Penalty details</th><th>Amount</th></tr></thead><tbody>
<?php foreach(return_rows_by_member($quote['items']) as $reviewMemberId=>$memberItems):?><tr class="return-member-heading"><th colspan="5" scope="rowgroup"><?= h($memberItems[0]['Member']) ?> <span class="small text-muted">· Member #<?= (int)$reviewMemberId ?></span></th></tr><?php foreach($memberItems as $it):?><tr><td>#<?= (int)$it['LoanID'] ?></td><td><?= h($it['Title']) ?><br><?= h($it['AccessionNumber']) ?></td><td><?= h($it['condition']) ?></td><td><?php foreach($it['fines']as $fine):?><div><?= h($fine['reason']) ?>: <?= money($fine['amount']) ?></div><?php endforeach;?><?php if(!$it['fines']):?>No penalty<?php endif;?></td><td><?= money($it['fine_total']) ?></td></tr><?php endforeach;endforeach;?></tbody></table></div><p class="fw-semibold">Total penalties: <?= money($quote['total']) ?></p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="list_query" value="<?= h($listQuery) ?>"><input type="hidden" name="review_token" value="<?= h($reviewToken) ?>"><button class="btn btn-primary" name="action" value="process_return">Confirm return and penalties</button> <a class="btn btn-outline-secondary" href="<?= h($listUrl) ?>">Back without saving</a></form></section>
<?php endif;?>
<?php if($receiptId):$receipt=fetch_one('SELECT * FROM return_batch WHERE ReturnBatchID=?',[$receiptId]);if($receipt):
$receiptRows=fetch_all("SELECT rd.*,ld.LoanID,l.BorrowerUserID,b.Title,c.AccessionNumber,CONCAT(m.FirstName,' ',m.LastName) AS Member FROM return_header rh JOIN return_detail rd ON rd.ReturnID=rh.ReturnID JOIN loan_detail ld ON ld.LoanDetailID=rd.LoanDetailID JOIN loan l ON l.LoanID=ld.LoanID JOIN user_account m ON m.UserID=l.BorrowerUserID JOIN book_copy c ON c.CopyID=ld.CopyID JOIN book b ON b.BookID=c.BookID WHERE rh.ReturnBatchID=? ORDER BY l.LoanID,rd.ReturnDetailID",[$receiptId]);?>
<section class="card transaction-card p-3 mb-3"><h2 class="h5">Return receipt <?= h(return_batch_ref($receiptId)) ?></h2><p><?= fmt_datetime($receipt['CreatedAt']) ?> · <?= count($receiptRows) ?> book(s)</p><div class="table-responsive"><table class="table"><thead><tr><th>Transaction #</th><th>Book / accession</th><th>Condition</th><th>Penalties and payment</th></tr></thead><tbody><?php foreach(return_rows_by_member($receiptRows) as $receiptMemberId=>$memberItems):?><tr class="return-member-heading"><th colspan="4" scope="rowgroup"><?= h($memberItems[0]['Member']) ?> <span class="small text-muted">· Member #<?= (int)$receiptMemberId ?></span></th></tr><?php foreach($memberItems as $it):?><tr><td>#<?= (int)$it['LoanID'] ?></td><td><?= h($it['Title'].' / '.$it['AccessionNumber']) ?></td><td><?= h($it['ReturnCondition']) ?></td><td><?php $fines=fetch_all('SELECT * FROM fine WHERE LoanDetailID=?',[$it['LoanDetailID']]);foreach($fines as $f):?><div><?= h($f['Reason']) ?>: <?= money($f['FineAmount']) ?> <?= status_badge($f['PaymentStatus']) ?> <?php if($f['PaymentStatus']==='Unpaid'&&has_permission('record_payments')):?><a href="payments.php?fine_id=<?= (int)$f['FineID'] ?>">Record payment</a><?php endif;?></div><?php endforeach;if(!$fines):?>No penalty<?php endif;?></td></tr><?php endforeach;endforeach;?></tbody></table></div></section>
<?php endif;endif;?>
<?php if($quote===null && empty($receipt)):?>
<?php if($tab==='borrowed'): circulation_overview(['Borrowers on this page'=>count($returnGroups),'Outstanding books'=>array_sum(array_map(fn($g)=>count($g['items']),$returnGroups)),'Overdue books'=>array_sum(array_map(fn($g)=>count(array_filter($g['items'],fn($item)=>is_overdue($item['DueDate']))),$returnGroups))]); endif;?>
<div class="page-toolbar circulation-toolbar"><form method="get" class="circulation-filters">
<div class="filter-search"><label for="returnSearch">Search returns</label><input id="returnSearch" type="search" class="form-control" name="q" value="<?= h($search) ?>" placeholder="Borrower, book, accession or reference" aria-label="Search return transactions"></div>
<div class="filter-field"><label for="returnTab">Show</label><select id="returnTab" class="form-select" name="tab" aria-label="Return list"><option value="borrowed">Outstanding books</option><option value="returned" <?= $tab==='returned'?'selected':'' ?>>Return history</option></select></div>
<div class="filter-actions"><button class="btn btn-primary">Search</button><a class="btn btn-outline-secondary" href="returns.php">Clear</a></div></form></div>
<?php if($tab==='borrowed'):?>
<div class="list-heading"><div><h2>Books to return</h2><p><?= pagination_info($pg) ?> members · Select books, check their condition, then review.</p></div><button hidden type="button" class="btn btn-outline-secondary btn-sm" data-group-toggle>Expand members</button></div>
<form method="post" data-return-form><?= csrf_field() ?><input type="hidden" name="list_query" value="<?= h($listQuery) ?>"><input type="hidden" name="action" value="preview_return">
<?php if(!$returnGroups):?><div class="card transaction-card empty-state"><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>No matching outstanding borrowing records.</strong><span>Try another search or check the return history.</span></div><?php endif;?>
<?php foreach($returnGroups as $returnMemberId=>$group):$person=$group['member'];?>
<details class="card transaction-card borrower-group mb-3" data-member-id="<?= (int)$returnMemberId ?>" <?= $search!==''||$loanFilter||$memberFilter?'open':'' ?>>
<summary class="p-3"><span class="borrower-heading"><strong><?= h($person['Member']) ?></strong><span class="small text-muted">Member <?= h($person['MembershipNumber']?:'#'.$returnMemberId) ?></span></span><span class="borrower-totals"><span><?= count($group['items']) ?> outstanding book(s)</span><?php $dueDates=array_filter(array_column($group['items'],'DueDate')); circulation_due_summary(count(array_filter($group['items'],fn($item)=>is_overdue($item['DueDate']))),$dueDates?min($dueDates):null); ?></span><span class="small text-decoration-underline">View books<span class="visually-hidden"> for <?= h($person['Member']) ?></span></span></summary>
<div class="px-3 pb-2 d-flex flex-wrap align-items-center gap-2"><button type="button" class="btn btn-outline-primary btn-sm" data-select-all><?= 'Select all ('.count($group['items']).')' ?><span class="visually-hidden"> books for <?= h($person['Member']) ?></span></button><button type="button" class="btn btn-outline-secondary btn-sm" data-select-none>Clear <span class="visually-hidden">selection for <?= h($person['Member']) ?></span></button><span class="small text-muted">Check a book to choose its return condition.</span></div>
<div class="px-3 table-responsive"><table class="table align-middle"><thead><tr><th>Select</th><th>Book / accession</th><th>Transaction / borrowed on</th><th>Due date</th><th>Return condition<span class="d-block small fw-normal text-muted">Enabled after you check the book</span></th></tr></thead><tbody>
<?php foreach($group['items'] as $it):?><tr>
<td><input type="checkbox" class="form-check-input return-pick" data-condition="condition-<?= (int)$it['LoanDetailID'] ?>" aria-label="Return <?= h($it['Title'].' / '.$it['AccessionNumber']) ?>"></td>
<td><?= h($it['Title']) ?><div class="small text-muted"><?= h($it['AccessionNumber']) ?></div></td>
<td>#<?= (int)$it['LoanID'] ?><div class="small text-muted"><?= fmt_datetime($it['LoanDate']) ?></div></td>
<td><?= fmt_date($it['DueDate']) ?> <?= is_overdue($it['DueDate'])?status_badge('Overdue'):'' ?></td>
<td><select disabled class="form-select form-select-sm" id="condition-<?= (int)$it['LoanDetailID'] ?>" name="return_conditions[<?= (int)$it['LoanDetailID'] ?>]" aria-label="Condition of <?= h($it['Title'].' / '.$it['AccessionNumber']) ?>"><?php foreach(['Good','Fair','Damaged','Lost']as $condition):?><option><?= h($condition) ?></option><?php endforeach;?></select></td>
</tr><?php endforeach;?></tbody></table></div></details><?php endforeach;?>
<?php if($returnGroups):?><div class="selection-bar"><div><strong data-selected-count aria-live="polite">0 books selected</strong><span>Review conditions and penalties before saving.</span></div><div class="selection-actions"><button type="button" class="btn btn-outline-secondary" data-clear-selection disabled>Clear selection</button><button class="btn btn-primary" data-review-returns>Review selected returns and penalties <i class="bi bi-arrow-right" aria-hidden="true"></i></button></div></div><noscript><p>Enable JavaScript to select books for return.</p></noscript><?php endif;?></form>
<?= pagination_links($pg,$_GET) ?>
<?php else:?><p class="small"><?= pagination_info($histPg) ?> return transactions</p><div class="card transaction-card table-responsive"><table class="table"><thead><tr><th>Return reference</th><th>Date</th><th>Books returned</th><th>Action</th></tr></thead><tbody><?php foreach($history as $h):?><tr><td><?= h(return_batch_ref((int)$h['ReturnBatchID'])) ?></td><td><?= fmt_datetime($h['CreatedAt']) ?></td><td><?= (int)$h['Books'] ?></td><td><a href="returns.php?<?= h($listQuery) ?>&amp;receipt=<?= (int)$h['ReturnBatchID'] ?>">View receipt and penalties</a></td></tr><?php endforeach;?><?php if(!$history):?><tr><td colspan="4">No matching returns.</td></tr><?php endif;?></tbody></table></div><?= pagination_links($histPg,$_GET) ?><?php endif;?>
<?php else:?>
<?php if(!empty($receipt)):?><a class="btn btn-outline-secondary" href="<?= h($listUrl) ?>">Back to results</a><?php endif;?>
<?php endif;?>

<script data-circulation-user="<?= (int)($_SESSION['user_id']??0) ?>" src="assets/js/circulation.js?v=<?= h(filemtime(__DIR__.'/assets/js/circulation.js')) ?>" defer></script>
<?php require __DIR__.'/includes/layout_bottom.php'; ?>
