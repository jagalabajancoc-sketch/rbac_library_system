<?php
/**
 * MY FINES (read-only)
 * Scoped to the session's UserID.
 */
require_once __DIR__ . '/includes/member_auth.php';

$page_title = 'My Fines';
$member     = current_member();
$memberId   = current_member_id();

$listSearch=mb_substr(trim((string)($_GET['q']??'')),0,100);$fineSearch='';$listSearchParams=[$memberId];
if($listSearch!==''){$fineSearch=" AND (b.Title LIKE ? OR bc.AccessionNumber LIKE ? OR f.Reason LIKE ? OR CAST(ld.LoanID AS CHAR) LIKE ?)";array_push($listSearchParams,...array_fill(0,4,'%'.$listSearch.'%'));}
$fines = fetch_all(
    "SELECT f.FineID, f.FineAmount, f.Reason, f.FineDate, f.PaymentStatus,
            b.Title, bc.AccessionNumber, ld.DueDate, ld.LoanID,
            pol.PolicyName,
            rd.ReturnDate,
            (SELECT COALESCE(SUM(p.PaidAmount), 0) FROM payment p WHERE p.FineID = f.FineID) AS PaidSoFar
       FROM fine f
       JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
       JOIN loan l         ON l.LoanID        = ld.LoanID
       JOIN book_copy bc   ON bc.CopyID       = ld.CopyID
       JOIN book b         ON b.BookID        = bc.BookID
       JOIN policy pol     ON pol.PolicyID    = f.PolicyID
      LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE l.BorrowerUserID = ? $fineSearch
      ORDER BY f.PaymentStatus = 'Paid', f.FineID DESC",
    $listSearchParams
);

$totals=fetch_one("SELECT COALESCE(SUM(f.FineAmount),0) AS Assessed,COALESCE(SUM((SELECT COALESCE(SUM(p.PaidAmount),0) FROM payment p WHERE p.FineID=f.FineID)),0) AS Paid FROM fine f JOIN loan_detail ld ON ld.LoanDetailID=f.LoanDetailID JOIN loan l ON l.LoanID=ld.LoanID WHERE l.BorrowerUserID=?",[$memberId]);
$totalPaid=(float)$totals['Paid'];$totalUnpaid=max(0,(float)$totals['Assessed']-$totalPaid);

$payments = fetch_all(
    "SELECT p.PaymentID, p.PaymentDate, p.PaidAmount, p.PaymentMethod,
            p.FineID, b.Title
       FROM payment p
       JOIN fine f         ON f.FineID        = p.FineID
       JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
       JOIN loan l         ON l.LoanID        = ld.LoanID
       JOIN book_copy bc   ON bc.CopyID       = ld.CopyID
       JOIN book b         ON b.BookID        = bc.BookID
      WHERE l.BorrowerUserID = ? $fineSearch
      ORDER BY p.PaymentID DESC",
    $listSearchParams
);

require __DIR__ . '/includes/member_layout_top.php';
?>

<div class="page-toolbar"><form method="get" class="d-flex flex-wrap gap-2"><input type="search" name="q" class="form-control" value="<?= h($listSearch) ?>" placeholder="Search this list" aria-label="Search this list"><?php if(isset($view)):?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif;?><button class="btn btn-primary">Search</button></form></div><div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="summary-card bg-c8 d-flex justify-content-between align-items-center">
      <div>
        <div class="sc-value"><?= money($totalUnpaid) ?></div>
        <div class="sc-label">Outstanding Balance</div>
      </div>
      <i class="bi bi-exclamation-circle sc-icon"></i>
    </div>
  </div>
  <div class="col-md-6">
    <div class="summary-card bg-c6 d-flex justify-content-between align-items-center">
      <div>
        <div class="sc-value"><?= money($totalPaid) ?></div>
        <div class="sc-label">Settled To Date</div>
      </div>
      <i class="bi bi-check2-circle sc-icon"></i>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">My Fines</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th>#</th><th>Book</th><th>Reason</th><th>Charged</th>
        <th>Amount</th><th>Paid</th><th>Payment Status</th>
      </tr></thead>
      <tbody>
        <?php if (!$fines): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">You have no fines. Nicely done.</td></tr>
        <?php endif; ?>
        <?php foreach ($fines as $f): ?>
          <tr>
            <td>#<?= h($f['FineID']) ?></td>
            <td>
              <div class="fw-semibold"><?= h($f['Title']) ?></div>
              <div class="text-muted small">
                Transaction #<?= h($f['LoanID']) ?> · due <?= fmt_date($f['DueDate']) ?>
                <?= $f['ReturnDate'] ? ' · returned ' . fmt_date($f['ReturnDate']) : ' · not yet returned' ?>
              </div>
            </td>
            <td class="small"><?= h($f['Reason']) ?></td>
            <td class="small"><?= fmt_date($f['FineDate']) ?></td>
            <td class="fw-semibold"><?= money($f['FineAmount']) ?></td>
            <td><?= money($f['PaidSoFar']) ?></td>
            <td><?= status_badge($f['PaymentStatus']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">
    Fines are assessed by library staff. Settle any outstanding balance at the desk — unpaid fines block new requests.
  </div>
</div>

<div class="card">
  <div class="card-header">My Payments</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Receipt #</th><th>Fine #</th><th>Book</th><th>Date</th><th>Amount</th><th>Method</th></tr></thead>
      <tbody>
        <?php if (!$payments): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No payments recorded.</td></tr>
        <?php endif; ?>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td>#<?= h($p['PaymentID']) ?></td>
            <td>#<?= h($p['FineID']) ?></td>
            <td><?= h($p['Title']) ?></td>
            <td><?= fmt_datetime($p['PaymentDate']) ?></td>
            <td><?= money($p['PaidAmount']) ?></td>
            <td><?= h($p['PaymentMethod']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/member_layout_bottom.php'; ?>