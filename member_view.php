<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_members');

$id = (int)($_GET['id'] ?? 0);

$member = fetch_one(
    "SELECT ua.*, r.RoleName
       FROM user_account ua
       LEFT JOIN role r ON r.RoleID = ua.RoleID
      WHERE ua.UserID = ?",
    [$id]
);
if (!$member) {
    flash('danger', 'Borrower not found.');
    redirect('members.php');
}

$page_title = 'Borrower: ' . $member['FirstName'] . ' ' . $member['LastName'];

$loans = fetch_all(
    "SELECT l.LoanID, l.LoanDate,
        (SELECT COUNT(*) FROM loan_detail ld WHERE ld.LoanID = l.LoanID) AS TotalItems,
        (SELECT COUNT(*) FROM loan_detail ld
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
          WHERE ld.LoanID = l.LoanID AND rd.ReturnDetailID IS NULL) AS StillBorrowed
     FROM loan l WHERE l.BorrowerUserID = ? ORDER BY l.LoanID DESC",
    [$id]
);

$loanDetails = fetch_all(
    "SELECT ld.LoanDetailID, ld.LoanID, ld.DueDate, b.Title, bc.AccessionNumber,
            rd.ReturnDate, rd.ReturnCondition
     FROM loan_detail ld
     JOIN loan l ON l.LoanID = ld.LoanID
     JOIN book_copy bc ON bc.CopyID = ld.CopyID
     JOIN book b ON b.BookID = bc.BookID
     LEFT JOIN return_detail rd
            ON rd.ReturnDetailID = (
                SELECT MAX(rd2.ReturnDetailID) FROM return_detail rd2
                 WHERE rd2.LoanDetailID = ld.LoanDetailID
            )
     WHERE l.BorrowerUserID = ?
     ORDER BY (rd.ReturnDate IS NULL) DESC, ld.LoanDetailID DESC",
    [$id]
);

$requests = fetch_all(
    "SELECT rq.RequestID, rq.RequestDate, rq.ApprovalStatus, rq.RequestType,
        (SELECT GROUP_CONCAT(b.Title SEPARATOR ', ')
           FROM borrow_request_detail rd JOIN book b ON b.BookID = rd.BookID
          WHERE rd.RequestID = rq.RequestID) AS Books
     FROM borrow_request rq WHERE rq.UserID = ? ORDER BY rq.RequestID DESC",
    [$id]
);

$fines = fetch_all(
    "SELECT f.*, b.Title, bc.AccessionNumber
     FROM fine f
     JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
     JOIN loan l ON l.LoanID = ld.LoanID
     JOIN book_copy bc ON bc.CopyID = ld.CopyID
     JOIN book b ON b.BookID = bc.BookID
     WHERE l.BorrowerUserID = ? ORDER BY f.FineID DESC",
    [$id]
);

$borrowCheck = member_borrow_check($id);

$diagUnpaid = (int)fetch_value(
    "SELECT COUNT(*) FROM fine f
       JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
       JOIN loan l         ON l.LoanID        = ld.LoanID
      WHERE l.BorrowerUserID = ? AND f.PaymentStatus = 'Unpaid'",
    [$id]
);
$diagOverdue = (int)fetch_value(
    "SELECT COUNT(*) FROM loan_detail ld
       JOIN loan l ON l.LoanID = ld.LoanID
       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE l.BorrowerUserID = ?
        AND ld.DueDate < CURDATE()
        AND rd.ReturnDetailID IS NULL",
    [$id]
);
$diagHeld   = count_active_borrowed_books($id);
$diagLimit  = check_borrowing_limit($id, 0);
$diagStatus = $member['MembershipStatus'] ?? 'Active';
$diagOk     = $diagStatus === 'Active' && $diagUnpaid === 0 && $diagOverdue === 0 && $diagLimit['ok'];

require __DIR__ . '/includes/layout_top.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h4 class="mb-1">
      <?= h($member['FirstName'] . ' ' . $member['LastName']) ?>
      <span class="text-muted fs-6">#<?= h($member['UserID']) ?></span>
    </h4>
    <p class="small mb-1">Borrower type: <strong><?=h($member['MemberKind']??'Student')?></strong> · Borrower ID: <?=h($member['MembershipNumber']?:'Not assigned')?></p>
    <div class="text-muted small">
      <i class="bi bi-envelope"></i> <?= h($member['Email']) ?>
      &nbsp;·&nbsp; <i class="bi bi-telephone"></i> <?= h($member['Phone'] ?: '—') ?>
      &nbsp;·&nbsp; <i class="bi bi-geo-alt"></i> <?= h($member['Address'] ?: '—') ?>
      &nbsp;·&nbsp; Registered since <?= fmt_date($member['RegistrationDate']) ?>
    </div>
  </div>
  <a href="members.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back to Borrowers</a>
</div>

<div class="card mb-3 <?= $diagOk ? 'border-success' : 'border-warning' ?>">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Borrowing Status</span>
    <?= $diagOk
          ? '<span class="badge text-bg-success">CLEAR — may borrow</span>'
          : '<span class="badge text-bg-warning">BLOCKED</span>' ?>
  </div>
  <div class="card-body">
    <div class="row g-3 small">
      <div class="col-md-6 col-lg-3">
        <div class="text-muted">Membership</div>
        <div class="mt-1">
          <?= membership_badge($diagStatus) ?>
          <?= $diagStatus === 'Active'
                ? '<i class="bi bi-check-circle-fill text-success ms-1"></i>'
                : '<i class="bi bi-x-circle-fill text-danger ms-1"></i> <span class="text-danger">blocks borrowing</span>' ?>
        </div>
      </div>
      <div class="col-md-6 col-lg-3">
        <div class="text-muted">Unpaid fines</div>
        <div class="mt-1">
          <span class="fw-semibold"><?= h($diagUnpaid) ?></span>
          <?= $diagUnpaid === 0
                ? '<i class="bi bi-check-circle-fill text-success ms-1"></i>'
                : '<i class="bi bi-x-circle-fill text-danger ms-1"></i> <span class="text-danger">blocks borrowing</span>' ?>
        </div>
      </div>
      <div class="col-md-6 col-lg-3">
        <div class="text-muted">Books out &amp; past due</div>
        <div class="mt-1">
          <span class="fw-semibold"><?= h($diagOverdue) ?></span>
          <?= $diagOverdue === 0
                ? '<i class="bi bi-check-circle-fill text-success ms-1"></i>'
                : '<i class="bi bi-x-circle-fill text-danger ms-1"></i> <span class="text-danger">blocks borrowing</span>' ?>
        </div>
      </div>
      <div class="col-md-6 col-lg-3">
        <div class="text-muted">Books held vs limit</div>
        <div class="mt-1">
          <span class="fw-semibold"><?= h($diagHeld) ?> / <?= h($diagLimit['max_books']) ?></span>
          <span class="text-muted small">(<?= h($diagLimit['borrower_type']) ?>)</span>
          <?= $diagLimit['ok']
                ? '<i class="bi bi-check-circle-fill text-success ms-1"></i>'
                : '<i class="bi bi-x-circle-fill text-danger ms-1"></i> <span class="text-danger">blocks borrowing</span>' ?>
        </div>
      </div>
    </div>

    <?php if (!$borrowCheck['ok']): ?>
      <div class="alert alert-warning mt-3 mb-0 py-2 small">
        <i class="bi bi-exclamation-triangle"></i>
        <strong>Why this member is blocked:</strong> <?= h($borrowCheck['reason']) ?>
      </div>
    <?php else: ?>
      <div class="alert alert-success mt-3 mb-0 py-2 small">
        <i class="bi bi-check-circle"></i>
        All conditions met — this member can be issued books right now.
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">Borrowing History</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Transaction #</th><th>Date</th><th>Items</th><th>Status</th></tr></thead>
      <tbody>
        <?php if (!$loans): ?><tr><td colspan="4" class="text-center text-muted py-3">No borrowing records yet.</td></tr><?php endif; ?>
        <?php foreach ($loans as $l): ?>
          <tr>
            <td><a href="borrowed_books.php?view=<?= h($l['LoanID']) ?>">#<?= h($l['LoanID']) ?></a></td>
            <td><?= fmt_datetime($l['LoanDate']) ?></td>
            <td><?= h($l['TotalItems']) ?></td>
            <td><?= $l['StillBorrowed'] > 0 ? status_badge('Borrowed') . ' (' . h($l['StillBorrowed']) . ' remaining)' : status_badge('Complete') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">Borrowed Copies (Detail)</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Transaction #</th><th>Book</th><th>Accession #</th><th>Due Date</th><th>Returned</th><th>Item Status</th></tr></thead>
      <tbody>
        <?php if (!$loanDetails): ?><tr><td colspan="6" class="text-center text-muted py-3">No items on record.</td></tr><?php endif; ?>
        <?php foreach ($loanDetails as $d): ?>
          <tr>
            <td>#<?= h($d['LoanID']) ?></td>
            <td><?= h($d['Title']) ?></td>
            <td><?= h($d['AccessionNumber']) ?></td>
            <td><?= fmt_date($d['DueDate']) ?></td>
            <td>
              <?= $d['ReturnDate']
                    ? fmt_date($d['ReturnDate']) . ' <span class="text-muted small">(' . h($d['ReturnCondition']) . ')</span>'
                    : '<span class="text-muted">—</span>' ?>
            </td>
            <td>
              <?php if ($d['ReturnDate']): ?>
                <?= status_badge('Returned') ?>
              <?php elseif (is_overdue($d['DueDate'])): ?>
                <?= status_badge('Overdue') ?>
              <?php else: ?>
                <?= status_badge('Borrowed') ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">Borrow Request History</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>#</th><th>Type</th><th>Date</th><th>Book(s)</th><th>Status</th></tr></thead>
      <tbody>
        <?php if (!$requests): ?><tr><td colspan="5" class="text-center text-muted py-3">No requests on record.</td></tr><?php endif; ?>
        <?php foreach ($requests as $r): ?>
          <tr>
            <td>#<?= h($r['RequestID']) ?></td>
            <td><?= request_type_badge($r['RequestType']) ?></td>
            <td><?= fmt_datetime($r['RequestDate']) ?></td>
            <td><?= h($r['Books'] ?: '—') ?></td>
            <td><?= status_badge($r['ApprovalStatus']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header">Fines</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>#</th><th>Book</th><th>Reason</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
        <?php if (!$fines): ?><tr><td colspan="6" class="text-center text-muted py-3">No fines on record.</td></tr><?php endif; ?>
        <?php foreach ($fines as $f): ?>
          <tr>
            <td>#<?= h($f['FineID']) ?></td>
            <td><?= h($f['Title']) ?> (<?= h($f['AccessionNumber']) ?>)</td>
            <td><?= h($f['Reason']) ?></td>
            <td><?= money($f['FineAmount']) ?></td>
            <td><?= fmt_date($f['FineDate']) ?></td>
            <td><?= status_badge($f['PaymentStatus']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>