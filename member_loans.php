<?php
require_once __DIR__ . '/includes/member_auth.php';

$page_title = 'My Borrowed Books';
$page_subtitle = 'Current loans and return history';
$member     = current_member();
$memberId   = current_member_id();

$totalCurrent = (int) fetch_value(
    "SELECT COUNT(*)
       FROM loan l
       JOIN loan_detail ld ON ld.LoanID = l.LoanID
       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE l.BorrowerUserID = ? AND rd.ReturnDetailID IS NULL",
    [$memberId]
);
$pgCurrent = pagination_calc($totalCurrent, 10, pagination_page('page'));

$totalReturned = (int) fetch_value(
    "SELECT COUNT(*)
       FROM return_detail rd
       JOIN loan_detail ld ON ld.LoanDetailID = rd.LoanDetailID
       JOIN loan l         ON l.LoanID        = ld.LoanID
      WHERE l.BorrowerUserID = ?",
    [$memberId]
);
$pgReturned = pagination_calc($totalReturned, 10, pagination_page('rpage'));

$current = fetch_all(
    "SELECT ld.LoanDetailID, ld.LoanID, ld.DueDate, ld.BookCondition,
            l.LoanDate,
            b.Title, bc.AccessionNumber, bc.Status AS CopyStatus,
            (SELECT COALESCE(SUM(f.FineAmount), 0)
               FROM fine f
              WHERE f.LoanDetailID = ld.LoanDetailID AND f.PaymentStatus = 'Unpaid') AS UnpaidFine
       FROM loan l
       JOIN loan_detail ld ON ld.LoanID = l.LoanID
       JOIN book_copy bc   ON bc.CopyID = ld.CopyID
       JOIN book b         ON b.BookID  = bc.BookID
       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE l.BorrowerUserID = ?
        AND rd.ReturnDetailID IS NULL
      ORDER BY ld.DueDate ASC, ld.LoanDetailID DESC
      LIMIT {$pgCurrent['per_page']} OFFSET {$pgCurrent['offset']}",
    [$memberId]
);

$finished = fetch_all(
    "SELECT rd.ReturnDetailID, rd.ReturnDate, rd.ReturnCondition,
            ld.LoanDetailID, ld.LoanID, ld.DueDate,
            l.LoanDate,
            b.Title, bc.AccessionNumber,
            (SELECT COALESCE(SUM(f.FineAmount), 0) FROM fine f WHERE f.LoanDetailID = ld.LoanDetailID) AS FineTotal
       FROM return_detail rd
       JOIN loan_detail ld ON ld.LoanDetailID = rd.LoanDetailID
       JOIN loan l         ON l.LoanID        = ld.LoanID
       JOIN book_copy bc   ON bc.CopyID       = ld.CopyID
       JOIN book b         ON b.BookID        = bc.BookID
      WHERE l.BorrowerUserID = ?
      ORDER BY rd.ReturnDate DESC, rd.ReturnDetailID DESC
      LIMIT {$pgReturned['per_page']} OFFSET {$pgReturned['offset']}",
    [$memberId]
);

require __DIR__ . '/includes/member_layout_top.php';
?>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Currently Borrowed</span>
    <span class="badge text-bg-light border"><?= h($totalCurrent) ?></span>
  </div>
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pgCurrent) ?></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Book</th><th>Loan #</th><th>Borrowed</th><th>Due Date</th><th>Status</th><th>Fine</th></tr></thead>
      <tbody>
        <?php if (!$current): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">
            <?php if ($totalCurrent === 0): ?>
              You have nothing checked out. <a href="member_catalog.php">Browse the catalog</a>.
            <?php else: ?>No records on this page.<?php endif; ?>
          </td></tr>
        <?php endif; ?>
        <?php foreach ($current as $i): ?>
          <?php $late = is_overdue($i['DueDate']); ?>
          <tr class="<?= $late ? 'table-danger' : '' ?>">
            <td>
              <div class="fw-semibold"><?= h($i['Title']) ?></div>
              <div class="text-muted small">Copy <?= h($i['AccessionNumber']) ?></div>
            </td>
            <td>#<?= h($i['LoanID']) ?></td>
            <td><?= fmt_date($i['LoanDate']) ?></td>
            <td>
              <?= fmt_date($i['DueDate']) ?>
              <?php if ($late): ?>
                <div class="small text-danger fw-semibold">
                  <?= (int)floor((time() - strtotime($i['DueDate'])) / 86400) ?> day(s) late
                </div>
              <?php endif; ?>
            </td>
            <td><?= $late ? status_badge('Overdue') : status_badge($i['CopyStatus']) ?></td>
            <td><?= $i['UnpaidFine'] > 0 ? '<span class="text-danger fw-semibold">' . money($i['UnpaidFine']) . '</span>' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pgCurrent['total_pages'] > 1): ?>
    <div class="card-footer"><?= pagination_links($pgCurrent, $_GET, 'page') ?></div>
  <?php endif; ?>
  <div class="card-footer small text-muted">
    Return books at the desk. Partial returns are allowed — the rest stay listed here.
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Already Returned</span>
    <div class="d-flex align-items-center gap-2">
      <span class="badge text-bg-light border"><?= h($totalReturned) ?></span>
    </div>
  </div>
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pgReturned) ?></div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Book</th><th>Loan #</th><th>Due Date</th><th>Returned</th><th>Condition</th><th>Fine</th></tr></thead>
      <tbody>
        <?php if (!$finished): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">
            <?php if ($totalReturned === 0): ?>Nothing returned yet.
            <?php else: ?>No records on this page.<?php endif; ?>
          </td></tr>
        <?php endif; ?>
        <?php foreach ($finished as $i): ?>
          <tr>
            <td><?= h($i['Title']) ?></td>
            <td>#<?= h($i['LoanID']) ?></td>
            <td><?= fmt_date($i['DueDate']) ?></td>
            <td><?= fmt_date($i['ReturnDate']) ?></td>
            <td><?= h($i['ReturnCondition'] ?: '—') ?></td>
            <td><?= $i['FineTotal'] > 0 ? money($i['FineTotal']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pgReturned['total_pages'] > 1): ?>
    <div class="card-footer"><?= pagination_links($pgReturned, $_GET, 'rpage') ?></div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/member_layout_bottom.php'; ?>