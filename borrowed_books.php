<?php
require_once __DIR__.'/includes/circulation_ui.php';
require_once __DIR__ . '/includes/auth.php';
require_permission('process_loans');
$page_title    = 'Borrowed Books';
$page_subtitle = 'Borrowing records and return status — issue books at the Library Desk';
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
                log_activity('Create', 'Borrowed Books', "Released Request #$requestId as Transaction #{$r['loan_id']} ({$r['items']} item(s))", $r['loan_id']);
                flash('success', "Transaction #{$r['loan_id']} recorded from Borrow Request #$requestId — {$r['items']} item(s) released.");
                redirect('borrowed_books.php?view=' . $r['loan_id']);
            } catch (Throwable $e) {
                flash('danger', 'Could not release: ' . user_message($e, 'The borrowing transaction could not be recorded. Nothing was changed.'));
                redirect('borrow_requests.php?tab=ready');
            }
        }

        /* ---- New loans are no longer issued from this page: the Library Desk is the one form for reading and borrowing ---- */
        flash('info', 'Books are issued at the Library Desk — one form for borrowing and reading here.');
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
        $days = (int)$rb['PolicyLoanDays'] > 0
            ? min(MAX_LOAN_DAYS, (int)$rb['PolicyLoanDays'])
            : DEFAULT_LOAN_DAYS;
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



$search=mb_substr(trim((string)($_GET['q']??'')),0,100);
require_once __DIR__ . '/includes/borrowed_books_list.php';
$grouped = borrowed_books_groups($search, pagination_page());
$pg = $grouped['pagination'];
$borrowerGroups = $grouped['groups'];

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
    if (!$viewLoanMeta) { flash('danger', 'Borrowing record not found.'); redirect('borrowed_books.php'); }

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
      <span class="small">The held copy is handed to the member now; the borrowing transaction and due date are recorded when you confirm.</span>
    </div>
    <a href="borrow_requests.php?tab=ready" class="btn btn-sm btn-outline-secondary">Back</a>
  </div>
<?php else: ?>
<p class="workflow-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Issue books at the <a href="desk_form.php">Library Desk</a> or release an approved <a href="borrow_requests.php?tab=ready">Borrow Request</a>.</p>
<?php endif; ?>

<?php circulation_overview(['Borrowers on this page'=>count($borrowerGroups),'Borrowing transactions'=>array_sum(array_map(fn($g)=>count($g['records']),$borrowerGroups)),'Outstanding books'=>array_sum(array_column($borrowerGroups,'outstanding'))]); ?>
<div class="page-toolbar circulation-toolbar"><form method="get" class="circulation-filters">
<div class="filter-search"><label for="borrowSearch">Search borrowing records</label><input id="borrowSearch" type="search" class="form-control" name="q" value="<?= h($search) ?>" placeholder="Borrower, book, accession or transaction number" aria-label="Search borrowed books"></div>
<div class="filter-actions"><button class="btn btn-primary">Search</button><a class="btn btn-outline-secondary" href="borrowed_books.php">Clear</a></div>
<?php if (!$requestId): ?><a class="btn btn-primary issue-action" href="desk_form.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> Issue books</a><?php endif;?>
</form></div>
<div class="list-heading"><div><h2>Borrowing records</h2><p><?= pagination_info($pg) ?> members<?php if ($search !== ''): ?> · All transactions for matching members<?php endif;?></p></div><button hidden type="button" class="btn btn-outline-secondary btn-sm" data-group-toggle>Expand members</button></div>
<?php if (!$borrowerGroups): ?><div class="card transaction-card empty-state"><i class="bi bi-search" aria-hidden="true"></i><strong>No matching borrowing records found.</strong><span>Try a different member name, book title or accession number.</span></div><?php endif; ?>
<?php foreach ($borrowerGroups as $memberId => $group): $borrower = $group['member']; ?>
<details class="card transaction-card borrower-group mb-3" data-member-id="<?= (int)$memberId ?>" <?= $search !== '' || ($viewLoanMeta && (int)$viewLoanMeta['MemberID'] === $memberId) ? 'open' : '' ?>>
  <summary class="p-3">
    <span class="borrower-heading"><strong><?= h($borrower['FirstName'].' '.$borrower['LastName']) ?></strong><span class="small text-muted">Member <?= h($borrower['MembershipNumber'] ?: '#'.$memberId) ?></span></span>
    <span class="borrower-totals"><span><?= $group['outstanding'] ?> outstanding book(s)</span><?php circulation_due_summary($group['overdue'],$group['earliest_due']); ?></span>
    <span class="small text-decoration-underline borrower-expand">View transactions</span>
  </summary>
  <div class="px-3 pb-3">
    <div class="mb-3"><a href="member_view.php?id=<?= (int)$memberId ?>">View member profile</a><?php if ($borrower['MembershipStatus'] !== 'Active'): ?> · <?= membership_badge($borrower['MembershipStatus']) ?><?php endif; ?></div>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Transaction #</th><th>Borrowed on</th><th>Issued by</th><th>Books</th><th>Return status</th><th>Details</th></tr></thead>
      <tbody><?php foreach ($group['records'] as $l): ?><tr data-transaction-id="<?= (int)$l['LoanID'] ?>">
        <td class="fw-semibold">#<?= (int)$l['LoanID'] ?></td>
        <td><?= fmt_datetime($l['LoanDate']) ?></td>
        <td><?= h(trim(($l['StaffFirst'] ?? '').' '.($l['StaffLast'] ?? '')) ?: '—') ?></td>
        <td><?= (int)$l['TotalItems'] ?></td>
        <td><?php if ((int)$l['StillBorrowed'] === 0): ?><?= status_badge('Returned') ?><?php elseif ($l['StillBorrowed'] < $l['TotalItems']): ?><span class="text-warning-emphasis fw-semibold">Partially returned</span><div class="small text-muted"><?= (int)$l['StillBorrowed'] ?> outstanding</div><?php else: ?><?= status_badge('Borrowed') ?><?php endif; ?></td>
        <td><a class="btn btn-sm btn-outline-secondary" href="borrowed_books.php?<?= h(http_build_query(array_merge($pgQuery, ['view'=>(int)$l['LoanID']]))) ?>" aria-label="View books in transaction <?= (int)$l['LoanID'] ?>">View books</a></td>
      </tr><?php endforeach; ?></tbody>
    </table></div>
  </div>
</details>
<?php endforeach; ?>
<?= pagination_style_03($pg, $pgQuery) ?>

<?php if ($viewId && $viewLoanMeta): ?>
<div class="modal fade" id="viewLoanModal" tabindex="-1" data-autoshow="1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header">
    <div>
      <h5 class="modal-title mb-0">Transaction #<?= h($viewLoanMeta['LoanID']) ?> — Items</h5>
      <div class="small text-muted mt-1">
        <?= h($viewLoanMeta['FirstName'] . ' ' . $viewLoanMeta['LastName']) ?>
        · <?= fmt_date($viewLoanMeta['LoanDate']) ?>
        · by <?= h($viewLoanMeta['StaffFirst'] . ' ' . $viewLoanMeta['StaffLast']) ?>
      </div>
    </div>
    <a href="borrowed_books.php?<?= h(http_build_query($pgQuery)) ?>" class="btn-close" aria-label="Close"></a>
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
  <div class="modal-footer"><a href="borrowed_books.php?<?= h(http_build_query($pgQuery)) ?>" class="btn btn-secondary">Close</a></div>
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
        <div class="text-uppercase text-muted small fw-bold mb-2">Borrower</div>
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
          <thead><tr><th>Book</th><th>Borrowing Days</th><th>Projected Due</th><th style="min-width:200px">Copy <span class="text-danger">*</span></th></tr></thead>
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

<script data-circulation-user="<?= (int)($_SESSION['user_id']??0) ?>" src="assets/js/circulation.js?v=<?= h(filemtime(__DIR__.'/assets/js/circulation.js')) ?>" defer></script>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
