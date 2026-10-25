<?php
require_once __DIR__ . '/includes/member_auth.php';

$page_title = 'My Borrow Requests';
$page_subtitle = 'Track your requests and reservations';
$member     = current_member();
$memberId   = current_member_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!csrf_check()) {
        flash('danger', 'Your session expired. Please try again.');
        redirect('member_requests.php');
    }
    $requestId = (int)($_POST['request_id'] ?? 0);
    try {
        $title = cancel_borrow_request($requestId, $memberId, false);
        log_activity('Cancel', 'Borrow Requests', "Member cancelled request #$requestId for \"$title\"", $requestId);
        flash('success', "Request #$requestId cancelled.");
    } catch (Throwable $e) {
        flash('danger', user_message($e, 'The request could not be cancelled. Please try again.'));
    }
    redirect('member_requests.php');
}

$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['Pending','Approved','Fulfilled','Rejected','Cancelled','Expired'], true)) $statusFilter = '';
$statusSql = $statusFilter !== '' ? ' AND rq.ApprovalStatus = ?' : '';
$statusParams = $statusFilter !== '' ? [$statusFilter] : [];

/* one chip per status: how many of MY books are in it */
$statusCounts = [];
foreach (fetch_all("SELECT ApprovalStatus, COUNT(*) AS n FROM borrow_request WHERE UserID = ? GROUP BY ApprovalStatus", [$memberId]) as $c) {
    $statusCounts[$c['ApprovalStatus']] = (int)$c['n'];
}
$allCount = array_sum($statusCounts);

$totalRequests = (int) fetch_value(
    "SELECT COUNT(*) FROM borrow_request rq WHERE rq.UserID = ?" . $statusSql,
    array_merge([$memberId], $statusParams)
);
$pg = pagination_calc($totalRequests, 10, pagination_page());

$requests = fetch_all(
    "SELECT rq.RequestID, rq.RequestType, rq.RequestDate, rq.ApprovalStatus, rq.ApprovalDate,
            rq.AssignedCopyID, rq.ExpiresAt, rq.NotifiedAt, rq.DecisionReason,
            s.FirstName AS StaffFirst, s.LastName AS StaffLast,
            (SELECT COUNT(*) FROM borrow_request_detail rd WHERE rd.RequestID = rq.RequestID) AS BookCount,
            (SELECT GROUP_CONCAT(b.Title SEPARATOR ' | ')
               FROM borrow_request_detail rd JOIN book b ON b.BookID = rd.BookID
              WHERE rd.RequestID = rq.RequestID) AS Books,
            (SELECT l.LoanID FROM loan l WHERE l.RequestID = rq.RequestID LIMIT 1) AS LoanID
       FROM borrow_request rq
       LEFT JOIN user_account s ON s.UserID = rq.ApprovedByUserID
      WHERE rq.UserID = ?" . $statusSql . "
      ORDER BY rq.RequestID DESC
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    array_merge([$memberId], $statusParams)
);

require __DIR__ . '/includes/member_layout_top.php';
?>

<div class="page-toolbar">
  <div class="text-muted small">
    Staff review each Borrow Request. Once approved, a copy is held for you — it counts as borrowed only when you collect it.
  </div>
  <a href="member_catalog.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Borrow Request</a>
</div>

<?php if ($allCount > 0): ?>
<div class="d-flex flex-wrap gap-2 mb-3" aria-label="Filter by status">
  <a href="member_requests.php" class="btn btn-sm <?= $statusFilter === '' ? 'btn-primary' : 'btn-outline-secondary' ?>">All <span class="badge text-bg-light ms-1"><?= $allCount ?></span></a>
  <?php foreach (['Pending','Approved','Fulfilled','Rejected','Cancelled','Expired'] as $st): if (empty($statusCounts[$st])) continue; ?>
    <a href="member_requests.php?status=<?= h($st) ?>" class="btn btn-sm <?= $statusFilter === $st ? 'btn-primary' : 'btn-outline-secondary' ?>">
      <?= h($st === 'Approved' ? 'Ready for pickup' : $st) ?> <span class="badge text-bg-light ms-1"><?= (int)$statusCounts[$st] ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?> book request<?= $pg['total'] === 1 ? '' : 's' ?> — each book is decided separately</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th>#</th><th>Type</th><th>Books</th><th>Submitted</th>
        <th>Status</th><th>Decision</th><th class="text-end">Action</th>
      </tr></thead>
      <tbody>
        <?php if (!$requests): ?>
          <?= empty_row(7, 'bi-inbox', 'No Borrow Requests yet', '<a href="member_catalog.php">Browse the catalog</a> to request a book.') ?>
        <?php endif; ?>
        <?php foreach ($requests as $r): ?>
          <tr>
            <td>#<?= h($r['RequestID']) ?></td>
            <td><?= request_type_badge($r['RequestType']) ?></td>
            <td class="small">
              <?= h($r['Books'] ?: '—') ?>
              <span class="text-muted">(<?= h($r['BookCount']) ?>)</span>
            </td>
            <td class="small"><?= fmt_datetime($r['RequestDate']) ?></td>
            <td>
              <?= status_badge($r['ApprovalStatus']) ?>
              <?php if ($r['LoanID']): ?>
                <div class="small text-muted mt-1">Released as Loan #<?= h($r['LoanID']) ?></div>
              <?php elseif ($r['ApprovalStatus'] === 'Approved' && $r['ExpiresAt']): ?>
                <div class="small text-success mt-1">
                  <i class="bi bi-bookmark-check"></i> Ready for pickup — held until <?= fmt_datetime($r['ExpiresAt']) ?>
                </div>
              <?php elseif ($r['RequestType'] === 'Reservation' && $r['ApprovalStatus'] === 'Pending'): ?>
                <div class="small text-muted mt-1">Queue position <strong>#<?= reservation_queue_position((int)$r['RequestID']) ?></strong></div>
              <?php elseif (!empty($r['DecisionReason']) && in_array($r['ApprovalStatus'], ['Rejected','Cancelled','Expired'], true)): ?>
                <div class="small text-muted fst-italic mt-1">Reason: <?= h($r['DecisionReason']) ?></div>
              <?php endif; ?>
            </td>
            <td class="small">
              <?= $r['ApprovalDate'] ? fmt_date($r['ApprovalDate']) : '—' ?>
              <?php if ($r['StaffFirst']): ?>
                <div class="text-muted">by <?= h($r['StaffFirst'] . ' ' . $r['StaffLast']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if (in_array($r['ApprovalStatus'], ['Pending', 'Approved'], true)): ?>
                <form method="post" class="d-inline" data-confirm="Cancel this request? Any copy held for you will be released.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="request_id" value="<?= h($r['RequestID']) ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Cancel</button>
                </form>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['total_pages'] > 1): ?>
    <div class="card-footer"><?= pagination_links($pg, $_GET) ?></div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/member_layout_bottom.php'; ?>