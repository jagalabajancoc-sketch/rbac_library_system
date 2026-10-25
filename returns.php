<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('process_returns');
$page_title    = 'Returns';
$page_subtitle = 'Process returns — partial returns and multi-loan returns supported';
$staffUserId   = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'process_return') {
        $conditions = $_POST['return_conditions'] ?? [];

        if (!is_array($conditions) || !$conditions) {
            flash('danger', 'Select at least one book being returned.', true);
            redirect('returns.php');
        }

        $allowedConditions = ['Good', 'Fair', 'Damaged', 'Lost'];

        try {
            $result = in_transaction(function () use ($conditions, $allowedConditions, $staffUserId) {
                $returnedCount = 0; $skipped = []; $byLoan = []; $touchedBooks = [];

                foreach ($conditions as $detailId => $condition) {
                    $detailId  = (int)$detailId;
                    $condition = is_string($condition) ? $condition : 'Good';
                    if (!in_array($condition, $allowedConditions, true)) { $skipped[] = "Item #$detailId: invalid condition"; continue; }

                    /* Lock the loan item first: two staff members returning the same
                       item at once cannot both succeed. */
                    $detail = fetch_one(
                        "SELECT ld.LoanDetailID, ld.LoanID, ld.CopyID, ld.DueDate,
                                b.PolicyID, b.BookID, b.AcquisitionCost, b.Title,
                                bc.AccessionNumber, bc.Status AS CopyStatus
                           FROM loan_detail ld
                           JOIN book_copy bc ON bc.CopyID = ld.CopyID
                           JOIN book b ON b.BookID = bc.BookID
                          WHERE ld.LoanDetailID = ?
                          FOR UPDATE",
                        [$detailId]
                    );
                    if (!$detail) { $skipped[] = "Item #$detailId not found"; continue; }
                    if ((int) fetch_value("SELECT COUNT(*) FROM return_detail WHERE LoanDetailID = ?", [$detailId]) > 0) {
                        $skipped[] = $detail['Title'] . ' was already returned'; continue;
                    }
                    /* A copy that is on hold for someone else or being read in the library
                       cannot also be "out on loan" — returning it would overwrite that state. */
                    if (in_array($detail['CopyStatus'], ['Reserved', 'In Use'], true)) {
                        $skipped[] = $detail['Title'] . ' (' . $detail['AccessionNumber'] . ') has a conflicting status: ' . $detail['CopyStatus']; continue;
                    }

                    $loanId = (int)$detail['LoanID'];
                    if (!isset($byLoan[$loanId])) {
                        run("INSERT INTO return_header (LoanID, ProcessedByUserID, ReturnStatus) VALUES (?, ?, 'Partial')",
                            [$loanId, $staffUserId]);
                        $byLoan[$loanId] = (int) last_id();
                    }
                    $returnId = $byLoan[$loanId];

                    run("INSERT INTO return_detail (ReturnID, LoanDetailID, ReturnCondition, ReturnStatus)
                         VALUES (?, ?, ?, 'Returned')", [$returnId, $detailId, $condition]);
                    $returnedCount++;

                    /* Availability: good copies go back on the shelf (and are offered to the
                       reservation queue); damaged or lost copies are taken out of circulation. */
                    if (in_array($condition, ['Damaged', 'Lost'], true)) {
                        run("UPDATE book_copy SET Status = ? WHERE CopyID = ?", [$condition, $detail['CopyID']]);
                    } else {
                        run("UPDATE book_copy SET Status = 'Available' WHERE CopyID = ?", [$detail['CopyID']]);
                        $touchedBooks[(int)$detail['BookID']] = true;
                    }

                    /* Fine: overdue */
                    $policy = fetch_one("SELECT PenaltyRate FROM policy WHERE PolicyID = ?", [$detail['PolicyID']]);
                    $today  = date('Y-m-d');
                    if ($detail['DueDate'] < $today) {
                        $daysLate = (int)floor((strtotime($today) - strtotime($detail['DueDate'])) / 86400);
                        $rate = (float)($policy['PenaltyRate'] ?? 0);
                        if ($rate > 0 && $daysLate > 0) {
                            run("INSERT INTO fine (LoanDetailID, PolicyID, FineAmount, Reason, PaymentStatus) VALUES (?,?,?,?,'Unpaid')",
                                [$detailId, $detail['PolicyID'], round($daysLate * $rate, 2), "Overdue by $daysLate day(s)"]);
                        }
                    }

                    /* Fine: damage / loss */
                    $bookCost = (float)($detail['AcquisitionCost'] ?? 0);
                    if ($condition === 'Damaged' && $bookCost > 0) {
                        run("INSERT INTO fine (LoanDetailID, PolicyID, FineAmount, Reason, PaymentStatus) VALUES (?,?,?,?,'Unpaid')",
                            [$detailId, $detail['PolicyID'], round($bookCost * 0.5, 2), 'Book returned damaged']);
                    } elseif ($condition === 'Lost' && $bookCost > 0) {
                        run("INSERT INTO fine (LoanDetailID, PolicyID, FineAmount, Reason, PaymentStatus) VALUES (?,?,?,?,'Unpaid')",
                            [$detailId, $detail['PolicyID'], $bookCost, 'Book reported lost (replacement cost)']);
                    }
                }

                /* Header status: Complete once every item of the loan is back */
                foreach ($byLoan as $loanId => $returnId) {
                    $remaining = (int) fetch_value(
                        "SELECT COUNT(*) FROM loan_detail ld
                           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
                          WHERE ld.LoanID = ? AND rd.ReturnDetailID IS NULL", [$loanId]);
                    run("UPDATE return_header SET ReturnStatus = ? WHERE ReturnID = ?",
                        [$remaining === 0 ? 'Complete' : 'Partial', $returnId]);
                }

                $held = 0;
                foreach (array_keys($touchedBooks) as $bookId) $held += promote_reservation_queue($bookId);

                if ($returnedCount === 0) {
                    throw new LibraryException('No items were returned. ' . implode('; ', $skipped));
                }
                return ['returned' => $returnedCount, 'loans' => count($byLoan), 'skipped' => $skipped, 'held' => $held];
            });

            $msg = sprintf('Returned %d item(s) across %d loan(s).', $result['returned'], $result['loans']);
            if ($result['held'])   $msg .= ' ' . $result['held'] . ' copy(ies) now held for members waiting in the reservation queue.';
            if ($result['skipped']) $msg .= ' Skipped: ' . implode('; ', $result['skipped']) . '.';
            log_activity('Return', 'Returns', $msg, null);
            flash('success', $msg, true);
        } catch (Throwable $e) {
            flash('danger', 'Could not process return: ' . user_message($e, 'The return could not be saved. Nothing was changed.'), true);
        }
        redirect('returns.php');
    }
}

/* ---- Counts ---- */
$cntBorrowed = (int) fetch_value(
    "SELECT COUNT(*) FROM loan_detail ld
       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE rd.ReturnDetailID IS NULL"
);
$cntReturned = (int) fetch_value("SELECT COUNT(*) FROM return_detail");
$cntOverdue  = (int) fetch_value(
    "SELECT COUNT(*) FROM loan_detail ld
       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE rd.ReturnDetailID IS NULL AND ld.DueDate < CURDATE()"
);

$tab = ($_GET['tab'] ?? 'borrowed') === 'returned' ? 'returned' : 'borrowed';

/* ---- Filters ---- */
$memberFilter = $_GET['member_id'] ?? '';
$search       = trim($_GET['q'] ?? '');

/* Members who currently have at least one unreturned item */
$memberOptions = fetch_all(
    "SELECT ua.UserID AS id,
            CONCAT(ua.LastName, ', ', ua.FirstName) AS label,
            (SELECT COUNT(*) FROM loan_detail ld2
               JOIN loan l2 ON l2.LoanID = ld2.LoanID
               LEFT JOIN return_detail rd2 ON rd2.LoanDetailID = ld2.LoanDetailID
              WHERE l2.BorrowerUserID = ua.UserID AND rd2.ReturnDetailID IS NULL) AS active_count
       FROM user_account ua
       JOIN loan l ON l.BorrowerUserID = ua.UserID
       JOIN loan_detail ld ON ld.LoanID = l.LoanID
       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE rd.ReturnDetailID IS NULL
      GROUP BY ua.UserID, ua.FirstName, ua.LastName
      ORDER BY ua.LastName, ua.FirstName"
);

/* ---- Borrowed query (with filters) ---- */
$bw = ['rd.ReturnDetailID IS NULL'];
$bp = [];
if ($memberFilter !== '') { $bw[] = 'l.BorrowerUserID = ?'; $bp[] = $memberFilter; }
if ($search !== '') {
    $bw[] = '(b.Title LIKE ? OR bc.AccessionNumber LIKE ? OR m.FirstName LIKE ? OR m.LastName LIKE ?)';
    $like = "%$search%";
    array_push($bp, $like, $like, $like, $like);
}
$borrowedWhere = 'WHERE ' . implode(' AND ', $bw);

/* ---- Returned query (with filters) ---- */
$rw = ['1=1'];
$rp = [];
if ($memberFilter !== '') { $rw[] = 'l.BorrowerUserID = ?'; $rp[] = $memberFilter; }
if ($search !== '') {
    $rw[] = '(b.Title LIKE ? OR bc.AccessionNumber LIKE ? OR m.FirstName LIKE ? OR m.LastName LIKE ?)';
    $like = "%$search%";
    array_push($rp, $like, $like, $like, $like);
}
$returnedWhere = 'WHERE ' . implode(' AND ', $rw);

$countFiltered = (int) fetch_value(
    $tab === 'borrowed'
        ? "SELECT COUNT(DISTINCT l.BorrowerUserID) FROM loan_detail ld
             JOIN loan l ON l.LoanID = ld.LoanID
             JOIN user_account m ON m.UserID = l.BorrowerUserID
             JOIN book_copy bc ON bc.CopyID = ld.CopyID
             JOIN book b ON b.BookID = bc.BookID
             LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
           $borrowedWhere"
        : "SELECT COUNT(*) FROM return_detail rd
             JOIN return_header rh ON rh.ReturnID = rd.ReturnID
             JOIN loan_detail ld ON ld.LoanDetailID = rd.LoanDetailID
             JOIN loan l ON l.LoanID = ld.LoanID
             JOIN user_account m ON m.UserID = l.BorrowerUserID
             JOIN book_copy bc ON bc.CopyID = ld.CopyID
             JOIN book b ON b.BookID = bc.BookID
           $returnedWhere",
    $tab === 'borrowed' ? $bp : $rp
);

$pg = pagination_calc($countFiltered, 10, pagination_page());

/* ---- View single loan modal ---- */
$viewLoanId = $_GET['view_loan'] ?? null;
$viewLoan = null; $viewItems = [];
if ($viewLoanId) {
    $viewLoan = fetch_one(
        "SELECT l.LoanID, l.LoanDate, l.BorrowerUserID AS MemberID, l.ProcessedByUserID,
                m.FirstName, m.LastName, m.MembershipStatus, m.Email, m.Phone,
                s.FirstName AS StaffFirst, s.LastName AS StaffLast
           FROM loan l
           JOIN user_account m ON m.UserID = l.BorrowerUserID
           JOIN user_account s ON s.UserID = l.ProcessedByUserID
          WHERE l.LoanID = ?",
        [$viewLoanId]
    );
    if (!$viewLoan) { flash('danger', 'Loan not found.'); redirect('returns.php'); }

    $viewItems = fetch_all(
        "SELECT ld.LoanDetailID, ld.DueDate, ld.BookCondition,
                b.Title, bc.AccessionNumber, bc.Status AS CopyStatus,
                rd.ReturnDate, rd.ReturnCondition,
                rh.ReturnStatus AS HeaderStatus
           FROM loan_detail ld
           JOIN book_copy bc ON bc.CopyID = ld.CopyID
           JOIN book b ON b.BookID = bc.BookID
           LEFT JOIN return_detail rd ON rd.ReturnDetailID = (
               SELECT MAX(rd2.ReturnDetailID) FROM return_detail rd2 WHERE rd2.LoanDetailID = ld.LoanDetailID
           )
           LEFT JOIN return_header rh ON rh.ReturnID = rd.ReturnID
          WHERE ld.LoanID = ? ORDER BY ld.LoanDetailID",
        [$viewLoanId]
    );
    $closeQuery = $_GET; unset($closeQuery['view_loan']);
}

/* ---- Data for the two tabs ---- */
$borrowedItems = []; $returnedItems = [];

$groups = [];
if ($tab === 'borrowed') {
    /* Page through BORROWERS (overdue first); each borrower's books are loaded below. Display grouping only —
       every book stays its own loan_detail row and is returned on its own. */
    $joins = "FROM loan_detail ld
           JOIN loan l ON l.LoanID = ld.LoanID
           JOIN user_account m ON m.UserID = l.BorrowerUserID
           JOIN book_copy bc ON bc.CopyID = ld.CopyID
           JOIN book b ON b.BookID = bc.BookID
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID";
    $memberIds = array_map('intval', array_column(fetch_all(
        "SELECT l.BorrowerUserID AS uid $joins $borrowedWhere
          GROUP BY l.BorrowerUserID, m.LastName, m.FirstName
          ORDER BY MAX(ld.DueDate < CURDATE()) DESC, m.LastName, m.FirstName
          LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $bp), 'uid'));
    if ($memberIds) {
        $marks = implode(',', array_fill(0, count($memberIds), '?'));
        $borrowedItems = fetch_all(
            "SELECT ld.LoanDetailID, ld.LoanID, ld.DueDate,
                    l.LoanDate, l.BorrowerUserID AS MemberID,
                    m.FirstName, m.LastName, m.MembershipNumber, m.MembershipStatus,
                    bc.AccessionNumber, b.Title,
                    DATEDIFF(CURDATE(), ld.DueDate) AS DaysOverdue
               $joins $borrowedWhere AND l.BorrowerUserID IN ($marks)
              ORDER BY (ld.DueDate < CURDATE()) DESC, ld.DueDate ASC, ld.LoanDetailID DESC",
            array_merge($bp, $memberIds)
        );
        foreach ($memberIds as $uid) $groups[$uid] = ['uid' => $uid, 'rows' => []];
        foreach ($borrowedItems as $it) $groups[(int)$it['MemberID']]['rows'][] = $it;
        $groups = array_filter($groups, fn($g) => $g['rows']);
    }
} else {
    $returnedItems = fetch_all(
        "SELECT rd.ReturnDetailID, rd.ReturnDate, rd.ReturnCondition, rd.ReturnStatus,
                ld.LoanDetailID, ld.LoanID, ld.DueDate,
                l.LoanDate, l.BorrowerUserID AS MemberID,
                m.FirstName, m.LastName, bc.AccessionNumber, b.Title,
                rh.ReturnStatus AS HeaderStatus
           FROM return_detail rd
           JOIN return_header rh ON rh.ReturnID = rd.ReturnID
           JOIN loan_detail ld ON ld.LoanDetailID = rd.LoanDetailID
           JOIN loan l ON l.LoanID = ld.LoanID
           JOIN user_account m ON m.UserID = l.BorrowerUserID
           JOIN book_copy bc ON bc.CopyID = ld.CopyID
           JOIN book b ON b.BookID = bc.BookID
          $returnedWhere
          ORDER BY rd.ReturnDate DESC, rd.ReturnDetailID DESC
          LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
        $rp
    );
}

$pgQuery = $_GET; unset($pgQuery['view_loan']);
require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'borrowed' ? 'active' : '' ?>"
       href="returns.php?tab=borrowed<?= $memberFilter ? '&member_id=' . urlencode($memberFilter) : '' ?><?= $search ? '&q=' . urlencode($search) : '' ?>">
      <i class="bi bi-journal-arrow-up"></i> Currently Borrowed
      <span class="badge text-bg-light border ms-1"><?= h($cntBorrowed) ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'returned' ? 'active' : '' ?>"
       href="returns.php?tab=returned<?= $memberFilter ? '&member_id=' . urlencode($memberFilter) : '' ?><?= $search ? '&q=' . urlencode($search) : '' ?>">
      <i class="bi bi-check2-circle"></i> Already Returned
      <span class="badge text-bg-light border ms-1"><?= h($cntReturned) ?></span>
    </a>
  </li>
</ul>

<?php if ($tab === 'borrowed' && $cntOverdue > 0): ?>
  <div class="alert alert-danger d-flex align-items-start gap-2">
    <i class="bi bi-exclamation-octagon-fill mt-1"></i>
    <div class="small">
      <strong><?= h($cntOverdue) ?> item(s) are overdue.</strong> Fines auto-generate on return.
    </div>
  </div>
<?php endif; ?>

<!-- FILTER TOOLBAR -->
<div class="card mb-3 p-3">
  <form class="row g-2 align-items-end" method="get">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div class="col-md-4">
      <label class="form-label small mb-1">Filter by Member</label>
      <select name="member_id" class="form-select" onchange="this.form.submit()">
        <option value="">All Members</option>
        <?php foreach ($memberOptions as $m): ?>
          <option value="<?= h($m['id']) ?>" <?= (string)$memberFilter === (string)$m['id'] ? 'selected' : '' ?>>
            <?= h($m['label']) ?> (<?= h($m['active_count']) ?> out)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-5">
      <label class="form-label small mb-1">Search</label>
      <input type="search" name="q" class="form-control"
             placeholder="Book title, accession #, or member name…"
             value="<?= h($search) ?>">
    </div>
    <div class="col-md-2 d-grid">
      <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Apply</button>
    </div>
    <div class="col-md-1 d-grid">
      <a href="returns.php?tab=<?= h($tab) ?>" class="btn btn-outline-secondary" title="Clear filters">
        <i class="bi bi-x-lg"></i>
      </a>
    </div>
  </form>
</div>

<?php if ($tab === 'borrowed'): ?>
<!-- Holds the action + CSRF token; the condition selects in the Confirm Return dialog attach to it. -->
<form method="post" id="returnForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="process_return">
</form>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><?= pagination_info($pg) ?> borrower<?= $pg['total'] === 1 ? '' : 's' ?> · Return one book, a borrower's books, or tick books across borrowers</span>
    <span class="d-flex gap-2">
      <?php if (count($groups) > 1): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleAllGroups" data-state="closed">
          <i class="bi bi-arrows-expand"></i> <span>Expand all</span>
        </button>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-primary" id="returnTicked">
        <i class="bi bi-arrow-return-left"></i> Return Selected
      </button>
    </span>
  </div>

  <div class="table-responsive">
    <table class="table mb-0 align-middle req-table">
      <thead><tr>
        <th>Borrower</th>
        <th class="text-end">Total Books</th>
        <th class="d-none d-md-table-cell">Date Borrowed</th>
        <th>Status</th>
        <th class="text-end">Action</th>
      </tr></thead>

      <?php if (!$groups): ?>
        <tbody><?= empty_row(5, 'bi-journal-check',
            ($memberFilter || $search) ? 'No borrowed items match the current filters.' : 'Nothing currently out on loan.') ?></tbody>
      <?php endif; ?>

      <?php foreach ($groups as $uid => $g):
          $rows     = $g['rows'];
          $first    = $rows[0];
          $name     = $first['FirstName'] . ' ' . $first['LastName'];
          $nBooks   = count($rows);
          $nOver    = count(array_filter($rows, fn($r) => (int)$r['DaysOverdue'] > 0));
          $dates    = array_column($rows, 'LoanDate'); sort($dates);
          $d1 = $dates[0]; $d2 = end($dates);
          $dateText = date('Y-m-d', strtotime($d1)) === date('Y-m-d', strtotime($d2))
                        ? fmt_datetime($d1) : fmt_date($d1) . ' – ' . fmt_date($d2);
          $isOpen   = ($memberFilter !== '' && (string)$memberFilter === (string)$uid) || count($groups) === 1;
          $gid      = 'grp-' . $uid;
          $bulk     = $nBooks > 1;
          $gk       = (string)$uid;
      ?>
      <tbody class="req-group<?= $isOpen ? ' is-open' : '' ?>">
        <tr class="req-group-row">
          <td>
            <a href="member_view.php?id=<?= h($uid) ?>" class="text-decoration-none fw-semibold"><?= h($name) ?></a>
            <div class="small text-muted"><?= h($first['MembershipNumber'] ?: '—') ?> · <?= membership_badge($first['MembershipStatus']) ?></div>
            <div class="small text-muted d-md-none mt-1"><?= h($dateText) ?></div>
          </td>
          <td class="text-end"><span class="fw-semibold fs-6"><?= (int)$nBooks ?></span></td>
          <td class="small d-none d-md-table-cell"><?= h($dateText) ?></td>
          <td class="small">
            <?= $nOver > 0 ? status_badge('Overdue') : status_badge('Borrowed') ?>
            <?php if ($nOver > 0 && $nOver < $nBooks): ?>
              <div class="text-muted mt-1"><?= (int)$nOver ?> overdue · <?= (int)($nBooks - $nOver) ?> on time</div>
            <?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <button type="button" class="btn btn-sm btn-primary group-toggle"
                    data-bs-toggle="collapse" data-bs-target="#<?= h($gid) ?>"
                    aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-controls="<?= h($gid) ?>">
              <i class="bi bi-chevron-down toggle-icon"></i> <span class="toggle-label"><?= $isOpen ? 'Hide Books' : 'View Books' ?></span>
            </button>
          </td>
        </tr>
        <tr class="req-detail-row">
          <td colspan="5" class="p-0">
            <div class="collapse<?= $isOpen ? ' show' : '' ?>" id="<?= h($gid) ?>">
              <div class="book-list" role="list" aria-label="Books borrowed by <?= h($name) ?>" data-group="<?= h($gk) ?>">
                <div class="book-item book-head d-none d-md-grid" aria-hidden="true">
                  <div>Book</div><div></div><div>Borrow date / Due date</div><div>Status</div><div class="text-end">Action</div>
                </div>

                <?php foreach ($rows as $it):
                    $isOverdue = (int)$it['DaysOverdue'] > 0;
                    $rowData = [
                        'LoanID' => (int)$it['LoanID'], 'LoanDetailID' => (int)$it['LoanDetailID'], 'Member' => $name,
                        'Title' => $it['Title'], 'AccessionNumber' => $it['AccessionNumber'],
                        'DueDate' => fmt_date($it['DueDate']), 'DaysOverdue' => $isOverdue ? (int)$it['DaysOverdue'] : 0,
                    ];
                ?>
                <div class="book-item<?= $isOverdue ? ' is-overdue' : '' ?>" role="listitem" data-row='<?= h(json_encode($rowData, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                  <div class="book-main">
                    <div class="fw-semibold"><?= h($it['Title']) ?></div>
                    <div class="small text-muted">Loan #<?= h($it['LoanID']) ?></div>
                    <div class="small text-primary"><i class="bi bi-bookmark-check"></i> Copy <?= h($it['AccessionNumber']) ?></div>
                  </div>
                  <div class="book-pick">
                    <?php if ($bulk): ?>
                      <input type="checkbox" class="form-check-input pick-box" data-group="<?= h($gk) ?>"
                             aria-label="Select “<?= h($it['Title']) ?>” for bulk return">
                    <?php endif; ?>
                  </div>
                  <div class="book-dates small">
                    <div><span class="text-muted">Borrow date:</span> <?= fmt_datetime($it['LoanDate']) ?></div>
                    <div><span class="text-muted">Due date:</span> <span class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>"><?= fmt_date($it['DueDate']) ?></span></div>
                  </div>
                  <div class="book-status small">
                    <?= $isOverdue ? status_badge('Overdue') : status_badge('Borrowed') ?>
                    <?php if ($isOverdue): ?><div class="text-danger mt-1"><?= (int)$it['DaysOverdue'] ?> day(s) overdue</div><?php endif; ?>
                  </div>
                  <div class="book-actions">
                    <button type="button" class="btn btn-sm btn-primary return-one"><i class="bi bi-arrow-return-left"></i> Return</button>
                  </div>
                </div>
                <?php endforeach; ?>

                <?php if ($bulk): ?>
                <div class="book-foot" data-group="<?= h($gk) ?>">
                  <label class="form-check small mb-0 d-flex align-items-center gap-2 apply-all">
                    <input type="checkbox" class="form-check-input mt-0 pick-all" data-group="<?= h($gk) ?>"> Apply changes to all books.
                  </label>
                </div>
                <div class="book-bulkbar" data-group="<?= h($gk) ?>">
                  <div class="fw-semibold">Bulk Actions <span class="text-muted fw-normal">(<span class="bulk-count"><?= $nBooks ?></span> items)</span></div>
                  <div class="d-flex flex-wrap align-items-center justify-content-center gap-2">
                    <button type="button" class="btn btn-sm btn-primary bulk-return" data-group="<?= h($gk) ?>">
                      <i class="bi bi-arrow-return-left"></i> <span class="bulk-label" data-verb="Return">Return all <?= $nBooks ?> books</span>
                    </button>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </td>
        </tr>
      </tbody>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if ($pg['total_pages'] > 1): ?>
    <div class="card-footer"><?= pagination_style_03($pg, $pgQuery) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'returned'): ?>
<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted">
    <?= pagination_info($pg) ?> · Each row is one returned copy.
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Member</th>
          <th>Loan #</th>
          <th>Book</th>
          <th>Date Borrowed</th>
          <th>Due Date</th>
          <th>Return Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$returnedItems): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">
            <?= $memberFilter || $search ? 'No returned items match the current filters.' : 'No books returned yet.' ?>
          </td></tr>
        <?php endif; ?>
        <?php foreach ($returnedItems as $it):
          $wasLate = $it['ReturnDate'] && $it['DueDate']
                     && strtotime($it['ReturnDate']) > strtotime($it['DueDate'] . ' 23:59:59');
          $returnLabel = $wasLate ? 'Returned Late' : 'Returned';
          $badgeClass  = $wasLate ? 'info' : 'success';
          $condition   = $it['ReturnCondition'] ?: 'Good';
          $viewQuery   = array_merge($_GET, ['view_loan' => (int)$it['LoanID']]);
        ?>
          <tr>
            <td>
              <a href="member_view.php?id=<?= h($it['MemberID']) ?>"
                 class="text-decoration-none fw-semibold">
                <?= h($it['FirstName'] . ' ' . $it['LastName']) ?>
              </a>
            </td>
            <td class="text-muted small">#<?= h($it['LoanID']) ?></td>
            <td>
              <div class="fw-semibold"><?= h($it['Title']) ?></div>
              <div class="small text-muted">Copy <?= h($it['AccessionNumber']) ?></div>
            </td>
            <td class="small"><?= fmt_datetime($it['LoanDate']) ?></td>
            <td class="small <?= $wasLate ? 'text-danger' : '' ?>"><?= fmt_date($it['DueDate']) ?></td>
            <td>
              <span class="badge text-bg-<?= $badgeClass ?>"><?= h($returnLabel) ?></span>
              <div class="small text-muted mt-1">
                Returned <?= fmt_date($it['ReturnDate']) ?>
                <?php if ($condition !== 'Good'): ?>
                  · <span class="text-danger"><?= h($condition) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="text-end text-nowrap">
              <a href="?<?= h(http_build_query($viewQuery)) ?>"
                 class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-eye"></i> View
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['total_pages'] > 1): ?>
    <div class="card-footer"><?= pagination_style_03($pg, $pgQuery) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- BATCH RETURN MODAL -->
<div class="modal fade" id="batchReturnModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-arrow-return-left"></i> Confirm Return
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-light border small mb-3">
          <i class="bi bi-info-circle text-primary"></i>
          Each item gets its own Return Condition. You can return items from
          <strong>different members and different loans</strong> in one go.
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Member</th>
                <th>Loan #</th>
                <th>Book</th>
                <th>Due Date</th>
                <th style="min-width:170px">Return Condition</th>
              </tr>
            </thead>
            <tbody id="batchReturnRows"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('returnForm').submit()">
          <i class="bi bi-check2-circle"></i> Confirm Return
        </button>
      </div>
    </div>
  </div>
</div>

<!-- SINGLE LOAN VIEW MODAL -->
<?php if ($viewLoanId && $viewLoan): ?>
<?php
  $closeUrl = 'returns.php' . (empty($closeQuery) ? '' : '?' . http_build_query($closeQuery));
  $totalItems = count($viewItems);
  $returnedCount = 0;
  foreach ($viewItems as $vi) if (!empty($vi['ReturnDate'])) $returnedCount++;
  $stillOut = $totalItems - $returnedCount;
  $loanStatus = $stillOut === 0 ? 'Complete' : ($returnedCount > 0 ? 'Partial' : 'Borrowed');
?>
<div class="modal fade" id="loanViewerModal" tabindex="-1" data-autoshow="1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">
            Loan #<?= h($viewLoan['LoanID']) ?>
            <span class="ms-1"><?= status_badge($loanStatus) ?></span>
          </h5>
          <div class="small text-muted mt-1">
            <?= h($viewLoan['FirstName'] . ' ' . $viewLoan['LastName']) ?>
            · borrowed <?= fmt_datetime($viewLoan['LoanDate']) ?>
            · by <?= h($viewLoan['StaffFirst'] . ' ' . $viewLoan['StaffLast']) ?>
          </div>
        </div>
        <a href="<?= h($closeUrl) ?>" class="btn-close"></a>
      </div>
      <div class="modal-body">
        <div class="border rounded p-3 mb-3">
          <div class="text-uppercase text-muted small fw-bold mb-2">Borrower</div>
          <div class="row g-2 small">
            <div class="col-md-6">
              <div><strong>Name:</strong> <?= h($viewLoan['FirstName'] . ' ' . $viewLoan['LastName']) ?></div>
              <div><strong>Member #:</strong> <?= h($viewLoan['MemberID']) ?></div>
            </div>
            <div class="col-md-6">
              <div><strong>Email:</strong> <?= h($viewLoan['Email'] ?: '—') ?></div>
              <div><strong>Phone:</strong> <?= h($viewLoan['Phone'] ?: '—') ?></div>
            </div>
            <div class="col-12">
              <div class="mt-1"><strong>Membership:</strong> <?= membership_badge($viewLoan['MembershipStatus']) ?></div>
            </div>
          </div>
        </div>
        <div class="border rounded">
          <div class="d-flex justify-content-between align-items-center px-3 py-2 bg-light border-bottom">
            <span class="text-uppercase text-muted small fw-bold">Items on Loan</span>
            <span class="small text-muted"><?= $returnedCount ?> returned · <?= $stillOut ?> still out</span>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead>
                <tr>
                  <th>Accession #</th>
                  <th>Book</th>
                  <th>Due Date</th>
                  <th>Returned</th>
                  <th>Condition</th>
                  <th class="text-end">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($viewItems as $vi):
                  $isReturned = !empty($vi['ReturnDate']);
                  $isLate = $isReturned && $vi['DueDate']
                            && strtotime($vi['ReturnDate']) > strtotime($vi['DueDate'] . ' 23:59:59');
                ?>
                  <tr>
                    <td class="fw-semibold"><?= h($vi['AccessionNumber']) ?></td>
                    <td><?= h($vi['Title']) ?></td>
                    <td class="<?= $isLate ? 'text-danger fw-semibold' : '' ?>"><?= fmt_date($vi['DueDate']) ?></td>
                    <td><?= $isReturned ? fmt_date($vi['ReturnDate']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $isReturned ? h($vi['ReturnCondition'] ?: 'Good') : h($vi['BookCondition'] ?: '—') ?></td>
                    <td class="text-end">
                      <?php if ($isReturned): ?><?= status_badge('Returned') ?>
                      <?php elseif (is_overdue($vi['DueDate'])): ?><?= status_badge('Overdue') ?>
                      <?php else: ?><?= status_badge('Borrowed') ?><?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <div class="small text-muted">
          <i class="bi bi-info-circle"></i> Partial returns stay visible here.
        </div>
        <a href="<?= h($closeUrl) ?>" class="btn btn-secondary">Close</a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
var conditionOptions = [
  { value: 'Good',    label: 'Good — return to shelf' },
  { value: 'Fair',    label: 'Fair — return to shelf' },
  { value: 'Damaged', label: 'Damaged — damage fine' },
  { value: 'Lost',    label: 'Lost — replacement charge' }
];

function buildConditionSelect(detailId) {
  var html = '<select class="form-select form-select-sm" name="return_conditions[' + detailId + ']" form="returnForm">';
  conditionOptions.forEach(function (o) {
    html += '<option value="' + o.value + '">' + o.label + '</option>';
  });
  return html + '</select>';
}

function escapeHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function rowOf(el) { return JSON.parse(el.closest('[data-row]').dataset.row || '{}'); }

function openBatchReturnModal(rows) {
  if (!rows.length) {
    if (typeof swalNotice === 'function') swalNotice('Select at least one item.', 'Nothing selected');
    else alert('Select at least one item.');
    return;
  }
  renderReturnModal(rows);
}

function renderReturnModal(rows) {
  var tbody = document.getElementById('batchReturnRows');
  tbody.innerHTML = '';

  /* Sort rows by member then loan so it's easier to review */
  rows.sort(function (a, b) {
    if (a.Member !== b.Member) return a.Member.localeCompare(b.Member);
    return a.LoanID - b.LoanID;
  });

  rows.forEach(function (r) {
    var tr = document.createElement('tr');
    var overdueNote = (r.DaysOverdue > 0)
      ? ' <span class="badge text-bg-danger ms-1">' + r.DaysOverdue + 'd overdue</span>'
      : '';
    tr.innerHTML =
      '<td class="small">' + escapeHtml(r.Member) + '</td>' +
      '<td class="small text-muted">#' + escapeHtml(r.LoanID) + '</td>' +
      '<td>' +
        '<div class="fw-semibold small">' + escapeHtml(r.Title) + '</div>' +
        '<div class="text-muted" style="font-size:.72rem;">Copy ' + escapeHtml(r.AccessionNumber) + '</div>' +
      '</td>' +
      '<td class="small">' + escapeHtml(r.DueDate) + overdueNote + '</td>' +
      '<td>' + buildConditionSelect(r.LoanDetailID) + '</td>';
    tbody.appendChild(tr);
  });

  new bootstrap.Modal(document.getElementById('batchReturnModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
  function boxes(g) { return Array.prototype.slice.call(document.querySelectorAll('.pick-box[data-group="' + g + '"]')); }
  function chosen(g) { var b = boxes(g), c = b.filter(function (x) { return x.checked; }); return c.length ? c : b; }
  function refresh(g) {
    var b = boxes(g), ticked = b.filter(function (x) { return x.checked; }).length;
    var bar = document.querySelector('.book-bulkbar[data-group="' + g + '"]'); if (!bar) return;
    var n = ticked || b.length, word = ticked ? n + ' selected' : 'all ' + n + ' books';
    bar.querySelector('.bulk-count').textContent = n;
    bar.querySelectorAll('.bulk-label').forEach(function (l) { l.textContent = l.dataset.verb + ' ' + word; });
    var all = document.querySelector('.pick-all[data-group="' + g + '"]');
    if (all) all.checked = ticked === b.length;
  }
  document.querySelectorAll('.pick-box').forEach(function (x) { x.addEventListener('change', function () { refresh(x.dataset.group); }); });
  document.querySelectorAll('.pick-all').forEach(function (a) {
    a.addEventListener('change', function () { boxes(a.dataset.group).forEach(function (x) { x.checked = a.checked; }); refresh(a.dataset.group); });
  });

  /* every Return button opens the same Confirm Return dialog, where each book gets its condition */
  document.querySelectorAll('.return-one').forEach(function (b) {
    b.addEventListener('click', function () { openBatchReturnModal([rowOf(b)]); });
  });
  document.querySelectorAll('.bulk-return').forEach(function (b) {
    b.addEventListener('click', function () { openBatchReturnModal(chosen(b.dataset.group).map(rowOf)); });
  });
  var rt = document.getElementById('returnTicked');
  if (rt) rt.addEventListener('click', function () {
    openBatchReturnModal(Array.prototype.slice.call(document.querySelectorAll('.pick-box:checked')).map(rowOf));
  });

  /* View Books: label follows open/closed state */
  function sync(panel) {
    var group = panel.closest('.req-group'); if (!group) return;
    var open = panel.classList.contains('show');
    group.classList.toggle('is-open', open);
    var lbl = group.querySelector('.toggle-label'); if (lbl) lbl.textContent = open ? 'Hide Books' : 'View Books';
    var btn = group.querySelector('.group-toggle'); if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  var panels = Array.prototype.slice.call(document.querySelectorAll('.req-detail-row .collapse'));
  panels.forEach(function (p) {
    p.addEventListener('shown.bs.collapse',  function () { sync(p); });
    p.addEventListener('hidden.bs.collapse', function () { sync(p); });
  });
  var allBtn = document.getElementById('toggleAllGroups');
  if (allBtn) allBtn.addEventListener('click', function () {
    var openIt = allBtn.dataset.state === 'closed';
    panels.forEach(function (p) {
      var c = bootstrap.Collapse.getOrCreateInstance(p, { toggle: false });
      openIt ? c.show() : c.hide();
    });
    allBtn.dataset.state = openIt ? 'open' : 'closed';
    allBtn.querySelector('span').textContent = openIt ? 'Collapse all' : 'Expand all';
    allBtn.querySelector('i').className = 'bi ' + (openIt ? 'bi-arrows-collapse' : 'bi-arrows-expand');
  });

  document.querySelectorAll('.modal[data-autoshow="1"]').forEach(function (m) {
    new bootstrap.Modal(m).show();
  });
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>