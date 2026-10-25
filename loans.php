<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('process_loans');
$page_title    = 'Loans';
$page_subtitle = 'Loans on record and released requests — new loans are issued at the Library Desk';
$staffUserId   = $_SESSION['user_id'];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_loan') {
        $requestId = (int)($_POST['request_id'] ?? 0);

        /* ---- RELEASE A HELD COPY (Borrow Request / Reservation) ---- */
        if ($requestId > 0) {
            try {
                $copyForBook = array_map('intval', (array)($_POST['copy_for_book'] ?? []));
                $r = release_borrow_request($requestId, (int)$staffUserId, $copyForBook);
                log_activity('Create', 'Loans', "Released Request #$requestId as Loan #{$r['loan_id']} ({$r['items']} item(s))", $r['loan_id']);
                flash('success', "Loan #{$r['loan_id']} recorded from Borrow Request #$requestId — {$r['items']} item(s) released.");
                redirect('loans.php?view=' . $r['loan_id']);
            } catch (Throwable $e) {
                flash('danger', 'Could not release: ' . user_message($e, 'The loan could not be recorded. Nothing was changed.'));
                redirect('borrow_requests.php?tab=ready');
            }
        }

        /* ---- New loans are no longer issued from this page: the Library Desk is the one form for reading and borrowing ---- */
        flash('info', 'New loans are issued at the Library Desk — one form for borrowing and reading here.');
        redirect('desk_form.php');
    }
}

$requestId = $_GET['request_id'] ?? null;
$prefillMember = null; $requestTypeForBanner = null;
$requestedBooksInfo = []; $requestBlockers = [];

if ($requestId) {
    cleanup_expired_holds();
    $reqInfo = fetch_one("SELECT * FROM borrow_request WHERE RequestID = ? AND ApprovalStatus = 'Approved'", [(int)$requestId]);
    if (!$reqInfo) { flash('danger', 'That Borrow Request is not approved, was already released, or its hold has expired.'); redirect('borrow_requests.php?tab=ready'); }
    $prefillMember = $reqInfo['UserID'];
    $requestTypeForBanner = $reqInfo['RequestType'];

    /* Fetch the reservation header so we can force the assigned copy */
    $reservationInfo = fetch_one(
        "SELECT AssignedCopyID, ExpiresAt FROM borrow_request WHERE RequestID = ?",
        [$requestId]
    );
    $assignedCopyId = $reservationInfo && $reservationInfo['AssignedCopyID']
        ? (int)$reservationInfo['AssignedCopyID']
        : null;

    $requestedBooksInfo = fetch_all(
        "SELECT b.BookID, b.Title, pol.LoanDays AS PolicyLoanDays
           FROM borrow_request_detail rd
           JOIN book b ON b.BookID = rd.BookID
           JOIN policy pol ON pol.PolicyID = b.PolicyID
          WHERE rd.RequestID = ? ORDER BY b.Title",
        [$requestId]
    );

    foreach ($requestedBooksInfo as &$rb) {
        $days = (int)$rb['PolicyLoanDays'] > 0 ? (int)$rb['PolicyLoanDays'] : DEFAULT_LOAN_DAYS;
        $rb['EffectiveLoanDays'] = $days;
        $rb['ProjectedDue'] = date('M d, Y', strtotime("+{$days} days"));

        if ($assignedCopyId) {
            /* The reservation already locked a specific copy — use it, no dropdown */
            $rb['AssignedCopy'] = fetch_one(
                "SELECT CopyID, AccessionNumber, Status FROM book_copy WHERE CopyID = ?",
                [$assignedCopyId]
            );
            if (!$rb['AssignedCopy']) {
                $requestBlockers[] = 'The assigned copy for "' . $rb['Title'] . '" was not found.';
            } elseif ($rb['AssignedCopy']['Status'] !== 'Reserved') {
                $requestBlockers[] = 'Copy ' . $rb['AssignedCopy']['AccessionNumber']
                                   . ' is not in Reserved status (currently "' . $rb['AssignedCopy']['Status'] . '").';
            }
            $rb['Copies'] = [];   // not used
        } else {
            /* Fallback: no copy assigned — show dropdown of available copies */
            $rb['AssignedCopy'] = null;
            $rb['Copies'] = fetch_all(
                "SELECT CopyID, AccessionNumber FROM book_copy
                  WHERE BookID = ? AND Status = 'Available' AND IsArchived = 0
                  ORDER BY AccessionNumber",
                [$rb['BookID']]
            );
            if (!$rb['Copies']) $requestBlockers[] = 'No available copy of "' . $rb['Title'] . '".';
        }
    }
    unset($rb);

    $limitCheckForRequest = check_borrowing_limit($prefillMember, count($requestedBooksInfo));
    if (!$limitCheckForRequest['ok']) $requestBlockers[] = $limitCheckForRequest['reason'];
}



$totalLoans = (int) fetch_value("SELECT COUNT(*) FROM loan");
$pg = pagination_calc($totalLoans, 10, pagination_page());

$loans = fetch_all(
    "SELECT l.LoanID, l.LoanDate, l.RequestID,
            m.FirstName, m.LastName, m.MembershipStatus, m.UserID AS MemberID,
            s.FirstName AS StaffFirst, s.LastName AS StaffLast,
            (SELECT COUNT(*) FROM loan_detail ld WHERE ld.LoanID = l.LoanID) AS TotalItems,
            (SELECT COUNT(*) FROM loan_detail ld
               LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
              WHERE ld.LoanID = l.LoanID AND rd.ReturnDetailID IS NULL) AS StillBorrowed
       FROM loan l
       JOIN user_account m ON m.UserID = l.BorrowerUserID
       JOIN user_account s ON s.UserID = l.ProcessedByUserID
       ORDER BY l.LoanID DESC
       LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
);

$viewId = $_GET['view'] ?? null;
$viewLoanMeta = null; $viewDetails = [];

if ($viewId) {
    $viewLoanMeta = fetch_one(
        "SELECT l.LoanID, l.LoanDate, l.RequestID,
                m.FirstName, m.LastName, m.UserID AS MemberID, m.MembershipStatus,
                s.FirstName AS StaffFirst, s.LastName AS StaffLast
           FROM loan l
           JOIN user_account m ON m.UserID = l.BorrowerUserID
           JOIN user_account s ON s.UserID = l.ProcessedByUserID
          WHERE l.LoanID = ?",
        [$viewId]
    );
    if (!$viewLoanMeta) { flash('danger', 'Loan not found.'); redirect('loans.php'); }

    $viewDetails = fetch_all(
        "SELECT ld.LoanDetailID, ld.DueDate, ld.BookCondition,
                b.Title, bc.AccessionNumber,
                (SELECT rd.ReturnDate FROM return_detail rd
                  WHERE rd.LoanDetailID = ld.LoanDetailID
                  ORDER BY rd.ReturnDetailID DESC LIMIT 1) AS ActualReturnDate,
                (SELECT rd.ReturnCondition FROM return_detail rd
                  WHERE rd.LoanDetailID = ld.LoanDetailID
                  ORDER BY rd.ReturnDetailID DESC LIMIT 1) AS ReturnCondition
           FROM loan_detail ld
           JOIN book_copy bc ON bc.CopyID = ld.CopyID
           JOIN book b ON b.BookID = bc.BookID
          WHERE ld.LoanID = ? ORDER BY ld.LoanDetailID",
        [$viewId]
    );
}

$pgQuery = $_GET; unset($pgQuery['view']);
require __DIR__ . '/includes/layout_top.php';
?>

<?php if ($requestId): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-start mb-3">
    <div>
      <strong>Releasing <?= $requestTypeForBanner === 'Reservation' ? 'Reservation' : 'Borrow Request' ?> #<?= h($requestId) ?></strong><br>
      <span class="small">The held copy is handed to the member now; the loan and due date are recorded when you confirm.</span>
    </div>
    <a href="borrow_requests.php?tab=ready" class="btn btn-sm btn-outline-secondary">Back</a>
  </div>
<?php else: ?>
  <div class="alert alert-light border d-flex align-items-start gap-2 mb-3">
    <i class="bi bi-info-circle-fill text-primary mt-1"></i>
    <div class="small">
      <strong>New loan:</strong> issue it at the <a href="desk_form.php">Library Desk</a> — one form for borrowing and reading here (the ID is kept only for books read in the library).<br>
      <strong>Approved Borrow Requests</strong> are released from <a href="borrow_requests.php?tab=ready">Borrow Requests → Ready for Release</a>.
    </div>
  </div>
<?php endif; ?>

<div class="page-toolbar">
  <div class="text-muted small"><?= $requestId ? 'Borrow Request #' . h($requestId) . ' — ready to release.' : count($loans) . ' loan(s) on this page.' ?></div>
  <?php if (!$requestId): ?>
    <a class="btn btn-primary" href="desk_form.php"><i class="bi bi-person-vcard"></i> New loan — Library Desk</a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Loan #</th><th>Member</th><th>Loan Date</th><th>Staff</th><th class="text-center">Items</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$loans): ?><tr><td colspan="7" class="text-center text-muted py-4">No loans found.</td></tr><?php endif; ?>
        <?php foreach ($loans as $l): ?>
          <tr>
            <td class="fw-semibold">#<?= h($l['LoanID']) ?></td>
            <td>
              <a href="member_view.php?id=<?= h($l['MemberID']) ?>" class="text-decoration-none"><?= h($l['FirstName'] . ' ' . $l['LastName']) ?></a>
              <?php if ($l['MembershipStatus'] !== 'Active'): ?><div class="small"><?= membership_badge($l['MembershipStatus']) ?></div><?php endif; ?>
            </td>
            <td><?= fmt_datetime($l['LoanDate']) ?></td>
            <td><?= h($l['StaffFirst'] . ' ' . $l['StaffLast']) ?></td>
            <td class="text-center"><?= h($l['TotalItems']) ?></td>
            <td>
              <?php if ($l['StillBorrowed'] == 0): ?><?= status_badge('Complete') ?>
              <?php elseif ($l['StillBorrowed'] < $l['TotalItems']): ?><?= status_badge('Partial') ?> <span class="text-muted small"><?= h($l['StillBorrowed']) ?> left</span>
              <?php else: ?><?= status_badge('Borrowed') ?><?php endif; ?>
            </td>
            <td class="text-end"><a href="loans.php?view=<?= h($l['LoanID']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['total_pages'] > 1): ?><div class="card-footer"><?= pagination_style_03($pg, $pgQuery) ?></div><?php endif; ?>
</div>

<?php if ($viewId && $viewLoanMeta): ?>
<div class="modal fade" id="viewLoanModal" tabindex="-1" data-autoshow="1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header">
    <div>
      <h5 class="modal-title mb-0">Loan #<?= h($viewLoanMeta['LoanID']) ?> — Items</h5>
      <div class="small text-muted mt-1">
        <?= h($viewLoanMeta['FirstName'] . ' ' . $viewLoanMeta['LastName']) ?>
        · <?= fmt_date($viewLoanMeta['LoanDate']) ?>
        · by <?= h($viewLoanMeta['StaffFirst'] . ' ' . $viewLoanMeta['StaffLast']) ?>
      </div>
    </div>
    <a href="loans.php" class="btn-close"></a>
  </div>
  <div class="modal-body p-0">
    <div class="table-responsive"><table class="table table-hover mb-0">
      <thead><tr><th>Accession #</th><th>Book</th><th class="text-center">Days</th><th>Due Date</th><th>Returned</th><th>Condition</th><th class="text-end">Status</th></tr></thead>
      <tbody>
        <?php foreach ($viewDetails as $d):
          $isReturned = !empty($d['ActualReturnDate']);
          $isLate = $isReturned && strtotime($d['ActualReturnDate']) > strtotime($d['DueDate'] . ' 23:59:59');
        ?>
          <tr>
            <td class="fw-semibold"><?= h($d['AccessionNumber']) ?></td>
            <td><?= h($d['Title']) ?></td>
            <td class="text-center"><?= (int) round((strtotime($d['DueDate']) - strtotime(date('Y-m-d', strtotime($viewLoanMeta['LoanDate'])))) / 86400) ?></td>
            <td class="<?= $isLate ? 'text-danger fw-semibold' : '' ?>"><?= fmt_date($d['DueDate']) ?></td>
            <td><?= $isReturned ? fmt_date($d['ActualReturnDate']) : '<span class="text-muted">Not yet</span>' ?></td>
            <td><?= h($d['BookCondition']) ?></td>
            <td class="text-end">
              <?php if ($isReturned): ?><?= status_badge('Returned') ?>
              <?php elseif (is_overdue($d['DueDate'])): ?><?= status_badge('Overdue') ?>
              <?php else: ?><?= status_badge('Borrowed') ?><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="modal-footer"><a href="loans.php" class="btn btn-secondary">Close</a></div>
</div></div></div>
<?php endif; ?>


<?php if ($requestId): ?>
<div class="modal fade" id="issueFromRequestModal" tabindex="-1" data-autoshow="1"><div class="modal-dialog modal-lg modal-dialog-scrollable">
  <form method="post" class="modal-content">
    <?= csrf_field() ?>
    <div class="modal-header">
      <div><h5 class="modal-title mb-0"><i class="bi bi-check2-square"></i> Release Borrow Request #<?= h($requestId) ?></h5></div>
      <a href="borrow_requests.php?tab=ready" class="btn-close" aria-label="Close"></a>
    </div>
    <div class="modal-body">
      <input type="hidden" name="action" value="create_loan">
      <input type="hidden" name="request_id" value="<?= h($requestId) ?>">
      <input type="hidden" name="member_id" value="<?= h($prefillMember) ?>">
      <?php $memberRow = fetch_one("SELECT FirstName, LastName, Email, MembershipStatus FROM user_account WHERE UserID = ?", [$prefillMember]); ?>
      <div class="border rounded p-3 mb-3">
        <div class="text-uppercase text-muted small fw-bold mb-2">Member</div>
        <div class="fw-semibold"><?= h($memberRow['FirstName'] . ' ' . $memberRow['LastName']) ?> <?= membership_badge($memberRow['MembershipStatus']) ?></div>
        <div class="small text-muted"><?= h($memberRow['Email']) ?></div>
      </div>
      <?php if ($requestBlockers): ?>
        <div class="alert alert-warning small"><strong>Cannot issue:</strong>
          <ul class="mb-0 ps-3"><?php foreach ($requestBlockers as $b): ?><li><?= h($b) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <div class="border rounded mb-3">
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Book</th><th>Loan Days</th><th>Projected Due</th><th style="min-width:200px">Copy <span class="text-danger">*</span></th></tr></thead>
          <tbody>
            <?php foreach ($requestedBooksInfo as $rb): ?>
              <tr>
                <td class="fw-semibold"><?= h($rb['Title']) ?></td>
                <td><?= h($rb['EffectiveLoanDays']) ?> days</td>
                <td><?= h($rb['ProjectedDue']) ?></td>
                <td>
                  <?php if (!empty($rb['AssignedCopy'])): ?>
                    <!-- a copy is already held for this member — it is released as-is, no dropdown -->
                    <input type="hidden" name="copy_for_book[<?= h($rb['BookID']) ?>]" value="<?= h($rb['AssignedCopy']['CopyID']) ?>">
                    <span class="badge text-bg-primary">
                      <i class="bi bi-bookmark-check"></i> <?= h($rb['AssignedCopy']['AccessionNumber']) ?>
                    </span>
                    <div class="small text-muted mt-1">Held specifically for this member</div>
                  <?php elseif (!$rb['Copies']): ?>
                    <span class="badge text-bg-danger">No copies</span>
                  <?php else: ?>
                    <select name="copy_for_book[<?= h($rb['BookID']) ?>]" class="form-select form-select-sm" required>
                      <option value="">-- Select accession # --</option>
                      <?php foreach ($rb['Copies'] as $cp): ?>
                        <option value="<?= h($cp['CopyID']) ?>"><?= h($cp['AccessionNumber']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    </div>
    <div class="modal-footer">
      <a href="borrow_requests.php?tab=ready" class="btn btn-secondary">Back</a>
      <button type="submit" class="btn btn-primary" <?= $requestBlockers ? 'disabled' : '' ?>><i class="bi bi-check2-circle"></i> Confirm release</button>
    </div>
  </form>
</div></div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.modal[data-autoshow="1"]').forEach(function (m) { new bootstrap.Modal(m).show(); });

});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
