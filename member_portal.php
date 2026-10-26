<?php
require_once __DIR__ . '/includes/member_auth.php';

$page_title    = 'My Dashboard';
$page_subtitle = 'Your reading at a glance';
$member        = current_member();
$memberId      = current_member_id();

$borrowed = fetch_all(
    "SELECT ld.LoanDetailID, ld.DueDate, b.Title
       FROM loan l
       JOIN loan_detail ld ON ld.LoanID = l.LoanID
       JOIN book_copy bc   ON bc.CopyID = ld.CopyID
       JOIN book b         ON b.BookID  = bc.BookID
       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE l.BorrowerUserID = ?
        AND rd.ReturnDetailID IS NULL
      ORDER BY ld.DueDate ASC",
    [$memberId]
);

$overdueCount = 0;
foreach ($borrowed as $b) {
    if (is_overdue($b['DueDate'])) $overdueCount++;
}

$returnedCount = (int)fetch_value(
    "SELECT COUNT(*)
       FROM return_detail rd
       JOIN loan_detail ld ON ld.LoanDetailID = rd.LoanDetailID
       JOIN loan l         ON l.LoanID        = ld.LoanID
      WHERE l.BorrowerUserID = ?",
    [$memberId]
);

$pendingRequests = (int)fetch_value(
    "SELECT COUNT(*) FROM borrow_request WHERE UserID = ? AND ApprovalStatus = 'Pending'",
    [$memberId]
);

$unpaidTotal = (float)fetch_value(
    "SELECT COALESCE(SUM(f.FineAmount), 0)
       FROM fine f
       JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
       JOIN loan l         ON l.LoanID        = ld.LoanID
      WHERE l.BorrowerUserID = ? AND f.PaymentStatus = 'Unpaid'",
    [$memberId]
);

$recentRequests = fetch_all(
    "SELECT rq.RequestID, rq.RequestType, rq.RequestDate, rq.ApprovalStatus,
            (SELECT GROUP_CONCAT(b.Title SEPARATOR ', ')
               FROM borrow_request_detail rd JOIN book b ON b.BookID = rd.BookID
              WHERE rd.RequestID = rq.RequestID) AS Books
       FROM borrow_request rq
      WHERE rq.UserID = ?
      ORDER BY rq.RequestID DESC LIMIT 5",
    [$memberId]
);

$cards = [
    ['label' => 'Currently Borrowed', 'value' => count($borrowed),    'icon' => 'bi-journal-bookmark',    'bg' => 'bg-c1'],
    ['label' => 'Overdue Items',      'value' => $overdueCount,       'icon' => 'bi-exclamation-octagon', 'bg' => 'bg-c8'],
    ['label' => 'Books Returned',     'value' => $returnedCount,      'icon' => 'bi-check2-circle',       'bg' => 'bg-c6'],
    ['label' => 'Pending Requests',   'value' => $pendingRequests,    'icon' => 'bi-inbox',               'bg' => 'bg-c7'],
    ['label' => 'Unpaid Fines',       'value' => money($unpaidTotal), 'icon' => 'bi-cash-coin',           'bg' => 'bg-c3'],
];

$borrowStatus = member_borrow_status($memberId);

require __DIR__ . '/includes/member_layout_top.php';
?>

<?= render_borrow_alert($borrowStatus, true) ?>

<div class="page-intro">
  <div>
    <h2>Hello, <?= h($member['FirstName']) ?> 👋</h2>
    <p>Here's what's happening with your library account.</p>
  </div>
  <div class="quick-actions">
    <a href="member_catalog.php" class="btn btn-primary"><i class="bi bi-search"></i> Browse Books</a>
    <a href="my_borrowed_books.php" class="btn btn-outline-secondary"><i class="bi bi-journal-bookmark"></i> My Borrowed</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php foreach ($cards as $c): ?>
    <div class="col-6 col-md-4 col-xl-3">
      <div class="summary-card <?= $c['bg'] ?> d-flex justify-content-between align-items-center">
        <div>
          <div class="sc-value"><?= h($c['value']) ?></div>
          <div class="sc-label"><?= h($c['label']) ?></div>
        </div>
        <i class="bi <?= h($c['icon']) ?> sc-icon"></i>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>My Borrowed Books</span>
        <a href="my_borrowed_books.php" class="small">See all →</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Book</th><th>Due Date</th><th class="text-end">Status</th></tr></thead>
          <tbody>
            <?php if (!$borrowed): ?>
              <tr><td colspan="3" class="text-center text-muted py-4">
                You have no books out. <a href="member_catalog.php">Browse the catalog →</a>
              </td></tr>
            <?php endif; ?>
            <?php foreach ($borrowed as $b): ?>
              <tr>
                <td class="fw-semibold"><?= h($b['Title']) ?></td>
                <td class="text-muted small"><?= fmt_date($b['DueDate']) ?></td>
                <td class="text-end">
                  <?= is_overdue($b['DueDate']) ? status_badge('Overdue') : status_badge('Borrowed') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>My Recent Requests</span>
        <a href="member_requests.php" class="small">See all →</a>
      </div>
      <div class="list-group list-group-flush">
        <?php if (!$recentRequests): ?>
          <div class="text-center text-muted py-4 px-3">
            No requests yet. <a href="member_catalog.php">Browse the catalog →</a>
          </div>
        <?php endif; ?>
        <?php foreach ($recentRequests as $r): ?>
          <div class="list-group-item px-3 py-3">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div class="me-2 flex-grow-1">
                <div class="fw-semibold small mb-1">
                  Request #<?= h($r['RequestID']) ?>
                  <?= request_type_badge($r['RequestType']) ?>
                </div>
                <div class="small text-muted"><?= h($r['Books'] ?: '—') ?></div>
                <div class="small text-muted" style="font-size:.75rem;"><?= fmt_datetime($r['RequestDate']) ?></div>
              </div>
              <?= status_badge(request_display_status($r)) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/member_layout_bottom.php'; ?>