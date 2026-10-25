<?php
require_once __DIR__ . '/includes/auth.php';
$page_title    = 'Dashboard';
$page_subtitle = 'Library activity at a glance';

/* Every figure below is counted live from the transaction tables. */
$totalBooks      = (int) fetch_value("SELECT COUNT(*) FROM book WHERE IsArchived = 0");
$totalCopies     = (int) fetch_value("SELECT COUNT(*) FROM book_copy WHERE IsArchived = 0");
$availableCopies = (int) fetch_value("SELECT COUNT(*) FROM book_copy WHERE Status = 'Available' AND IsArchived = 0");
$borrowedCopies  = (int) fetch_value(
    "SELECT COUNT(*) FROM loan_detail ld
       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE rd.ReturnDetailID IS NULL"
);
$overdueCount = (int) fetch_value(
    "SELECT COUNT(*) FROM loan_detail ld
       JOIN book_copy bc ON bc.CopyID = ld.CopyID
       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE rd.ReturnDetailID IS NULL AND bc.Status = 'Borrowed' AND ld.DueDate < CURDATE()"
);
$pendingRequests = (int) fetch_value(
    "SELECT COUNT(*) FROM borrow_request WHERE ApprovalStatus = 'Pending' AND RequestType = 'Borrow'"
);
$activeReservations = (int) fetch_value(
    "SELECT COUNT(*) FROM borrow_request
      WHERE RequestType = 'Reservation' AND ApprovalStatus IN ('Pending','Approved')"
);
$readyForRelease = (int) fetch_value("SELECT COUNT(*) FROM borrow_request WHERE ApprovalStatus = 'Approved'");
$totalMembers = (int) fetch_value(
    "SELECT COUNT(*) FROM user_account ua JOIN role r ON r.RoleID = ua.RoleID WHERE r.RoleName = 'Member'"
);

$cards = [
    ['Total Books',            $totalBooks,         'bi-book',              'bg-c1', 'books.php',                     $totalCopies . ' physical copies'],
    ['Available Copies',       $availableCopies,    'bi-check2-circle',     'bg-c6', 'book_copies.php?status=Available', 'on the shelf now'],
    ['Currently Borrowed',     $borrowedCopies,     'bi-arrow-left-right',  'bg-c3', 'loans.php',                     'copies out on loan'],
    ['Overdue Books',          $overdueCount,       'bi-alarm',             'bg-c8', 'reports.php?report=loans_overdue', 'past their due date'],
    ['Pending Borrow Requests',$pendingRequests,    'bi-inbox',             'bg-c7', 'borrow_requests.php?tab=review', $readyForRelease . ' ready for release'],
    ['Active Reservations',    $activeReservations, 'bi-hourglass-split',   'bg-c2', 'borrow_requests.php?tab=queue', 'queued or held for pickup'],
    ['Registered Students',     $totalMembers,       'bi-people',            'bg-c5', 'members.php',                   ''],
];
if (has_permission('manage_finance')) {
    $unpaid = (int) fetch_value("SELECT COUNT(*) FROM fine WHERE PaymentStatus = 'Unpaid'");
    $cards[] = ['Unpaid Fines', $unpaid, 'bi-exclamation-circle', 'bg-c4', 'fines.php', 'awaiting payment'];
}

/* One feed for the four kinds of transaction instead of four near-identical tables */
$recent = fetch_all(
    "SELECT * FROM (
        SELECT 'Borrow Request' AS Kind, r.RequestID AS RefID, r.UserID AS MemberID, r.RequestDate AS At,
               r.ApprovalStatus AS Status,
               (SELECT GROUP_CONCAT(b.Title SEPARATOR ', ') FROM borrow_request_detail d JOIN book b ON b.BookID = d.BookID WHERE d.RequestID = r.RequestID) AS Detail,
               CONCAT('borrow_requests.php?tab=all') AS Link
          FROM borrow_request r
        UNION ALL
        SELECT 'Loan', l.LoanID, l.BorrowerUserID, l.LoanDate, 'Borrowed',
               (SELECT GROUP_CONCAT(b.Title SEPARATOR ', ') FROM loan_detail ld JOIN book_copy bc ON bc.CopyID = ld.CopyID JOIN book b ON b.BookID = bc.BookID WHERE ld.LoanID = l.LoanID),
               CONCAT('loans.php?view=', l.LoanID)
          FROM loan l
        UNION ALL
        SELECT 'Return', rh.ReturnID, l.BorrowerUserID,
               (SELECT MAX(rd.ReturnDate) FROM return_detail rd WHERE rd.ReturnID = rh.ReturnID), 'Returned',
               (SELECT GROUP_CONCAT(b.Title SEPARATOR ', ') FROM return_detail rd JOIN loan_detail ld ON ld.LoanDetailID = rd.LoanDetailID JOIN book_copy bc ON bc.CopyID = ld.CopyID JOIN book b ON b.BookID = bc.BookID WHERE rd.ReturnID = rh.ReturnID),
               CONCAT('returns.php')
          FROM return_header rh JOIN loan l ON l.LoanID = rh.LoanID
        UNION ALL
        SELECT 'In-Library Use', u.InLibraryUseID, u.MemberID, u.StartTime, u.Status, b.Title, 'in_library_use.php'
          FROM in_library_use u JOIN book_copy bc ON bc.CopyID = u.CopyID JOIN book b ON b.BookID = bc.BookID
     ) t
     LEFT JOIN user_account m ON m.UserID = t.MemberID
     ORDER BY t.At DESC LIMIT 12"
);

$overdueList = fetch_all(
    "SELECT l.LoanID, ld.DueDate, m.FirstName, m.LastName, b.Title, DATEDIFF(CURDATE(), ld.DueDate) AS DaysLate
       FROM loan_detail ld
       JOIN loan l ON l.LoanID = ld.LoanID
       JOIN user_account m ON m.UserID = l.BorrowerUserID
       JOIN book_copy bc ON bc.CopyID = ld.CopyID
       JOIN book b ON b.BookID = bc.BookID
       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE rd.ReturnDetailID IS NULL AND bc.Status = 'Borrowed' AND ld.DueDate < CURDATE()
      ORDER BY ld.DueDate ASC LIMIT 6"
);

/* Borrowers with books waiting for review — one row per borrower, not per book */
$pendingList = fetch_all(
    "SELECT r.UserID, m.FirstName, m.LastName, COUNT(*) AS BookCount, MIN(r.RequestDate) AS RequestDate,
            GROUP_CONCAT(b.Title ORDER BY r.RequestDate, r.RequestID SEPARATOR ', ') AS Books
       FROM borrow_request r
       JOIN user_account m ON m.UserID = r.UserID
       JOIN borrow_request_detail d ON d.RequestID = r.RequestID
       JOIN book b ON b.BookID = d.BookID
      WHERE r.ApprovalStatus = 'Pending' AND r.RequestType = 'Borrow'
      GROUP BY r.UserID, m.FirstName, m.LastName
      ORDER BY MIN(r.RequestDate) ASC LIMIT 6"
);

require __DIR__ . '/includes/layout_top.php';
?>

<div class="row g-3 mb-4">
  <?php foreach ($cards as [$label, $value, $icon, $bg, $href, $sub]): ?>
    <div class="col-6 col-md-4 col-xl-3">
      <a href="<?= h($href) ?>" class="summary-link">
        <div class="summary-card <?= $bg ?> d-flex justify-content-between align-items-center">
          <div>
            <div class="sc-value"><?= h($value) ?></div>
            <div class="sc-label"><?= h($label) ?></div>
            <?php if ($sub !== ''): ?><div class="sc-sub"><?= h($sub) ?></div><?php endif; ?>
          </div>
          <i class="bi <?= h($icon) ?> sc-icon"></i>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header">Recent Transactions</div>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>When</th><th>Type</th><th>Member</th><th>Book(s)</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (!$recent): ?>
            <?= empty_row(5, 'bi-clock-history', 'No transactions yet', 'Requests, loans, returns and in-library use will appear here.') ?>
          <?php endif; ?>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td class="small text-nowrap"><?= fmt_datetime($r['At']) ?></td>
              <td class="small"><a href="<?= h($r['Link']) ?>" class="text-decoration-none"><?= h($r['Kind']) ?> #<?= h($r['RefID']) ?></a></td>
              <td class="small"><?= h(trim(($r['FirstName'] ?? '') . ' ' . ($r['LastName'] ?? '')) ?: 'Walk-in') ?></td>
              <td class="small text-truncate" style="max-width:220px;"><?= h($r['Detail'] ?: '—') ?></td>
              <td><?= status_badge($r['Status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between">
        <span>Borrow Requests to Review</span>
        <a href="borrow_requests.php?tab=review" class="small">View all</a>
      </div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <tbody>
          <?php if (!$pendingList): ?>
            <?= empty_row(2, 'bi-inbox', 'Nothing waiting for review') ?>
          <?php endif; ?>
          <?php foreach ($pendingList as $r): ?>
            <tr>
              <td class="small">
                <div class="fw-semibold"><?= h($r['FirstName'] . ' ' . $r['LastName']) ?>
                  <span class="badge text-bg-secondary ms-1"><?= (int)$r['BookCount'] ?> book<?= (int)$r['BookCount'] === 1 ? '' : 's' ?></span></div>
                <div class="text-muted text-truncate" style="max-width:260px;" title="<?= h($r['Books']) ?>"><?= h($r['Books'] ?: '—') ?></div>
                <span class="text-muted"><?= fmt_date($r['RequestDate']) ?></span></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="borrow_requests.php?tab=review&amp;open=<?= (int)$r['UserID'] ?>">View Books</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between">
        <span>Overdue Books</span>
        <a href="reports.php?report=loans_overdue" class="small">Full report</a>
      </div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <tbody>
          <?php if (!$overdueList): ?>
            <?= empty_row(1, 'bi-check2-circle', 'No overdue books') ?>
          <?php endif; ?>
          <?php foreach ($overdueList as $r): ?>
            <tr>
              <td class="small"><div class="fw-semibold"><?= h($r['Title']) ?></div>
                <span class="text-muted"><?= h($r['FirstName'] . ' ' . $r['LastName']) ?> · due <?= fmt_date($r['DueDate']) ?></span></td>
              <td class="text-end"><span class="badge text-bg-danger"><?= (int)$r['DaysLate'] ?> d late</span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
