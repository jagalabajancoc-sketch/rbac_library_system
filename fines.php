<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_finance');
$page_title = 'Fines';

$statusFilter = $_GET['status'] ?? '';
$where = ''; $params = [];
if ($statusFilter !== '') { $where = 'WHERE f.PaymentStatus = ?'; $params[] = $statusFilter; }

$totalFines = (int) fetch_value("SELECT COUNT(*) FROM fine f $where", $params);
$pg = pagination_calc($totalFines, 10, pagination_page());

$fines = fetch_all(
    "SELECT f.*, b.Title, bc.AccessionNumber, m.FirstName, m.LastName, m.UserID AS MemberID,
        (SELECT COALESCE(SUM(p.PaidAmount),0) FROM payment p WHERE p.FineID = f.FineID) AS PaidSoFar
     FROM fine f
     JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
     JOIN book_copy bc ON bc.CopyID = ld.CopyID
     JOIN book b ON b.BookID = bc.BookID
     JOIN loan l ON l.LoanID = ld.LoanID
     JOIN user_account m ON m.UserID = l.BorrowerUserID
     $where
     ORDER BY f.FineID DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$pgQuery = $_GET;
require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-toolbar">
  <form class="d-flex" method="get">
    <select name="status" class="form-select" onchange="this.form.submit()">
      <option value="">All Statuses</option>
      <option value="Unpaid" <?= $statusFilter === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
      <option value="Paid" <?= $statusFilter === 'Paid' ? 'selected' : '' ?>>Paid</option>
    </select>
  </form>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>#</th><th>Member</th><th>Book</th><th>Reason</th><th>Amount</th><th>Paid</th><th>Date</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$fines): ?><tr><td colspan="9" class="text-center text-muted py-4">No fines found.</td></tr><?php endif; ?>
        <?php foreach ($fines as $f): ?>
          <tr>
            <td>#<?= h($f['FineID']) ?></td>
            <td><a href="member_view.php?id=<?= h($f['MemberID']) ?>"><?= h($f['FirstName'] . ' ' . $f['LastName']) ?></a></td>
            <td><?= h($f['Title']) ?> (<?= h($f['AccessionNumber']) ?>)</td>
            <td><?= h($f['Reason']) ?></td>
            <td><?= money($f['FineAmount']) ?></td>
            <td><?= money($f['PaidSoFar']) ?></td>
            <td><?= fmt_date($f['FineDate']) ?></td>
            <td><?= status_badge($f['PaymentStatus']) ?></td>
            <td class="text-end">
              <?php if ($f['PaymentStatus'] === 'Unpaid'): ?>
                <a href="payments.php?fine_id=<?= h($f['FineID']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-credit-card"></i> Pay Fine</a>
              <?php else: ?>
                <span class="text-muted small">Settled</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['total_pages'] > 1): ?><div class="card-footer"><?= pagination_style_03($pg, $pgQuery) ?></div><?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>