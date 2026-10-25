<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('decide_use_requests');
$page_title    = 'Borrow Desk';
$page_subtitle = 'Verify the student and the book, then approve or reject — nothing is used or borrowed until you decide';
$staffId = (int)$_SESSION['user_id'];

if (!entry_schema_ready() || !ilu_schema_ready()) {
    require __DIR__ . '/includes/layout_top.php';
    echo '<div class="alert alert-warning"><strong>One-time database update needed.</strong> Back up your database, then in phpMyAdmin → Import, run '
       . '<code>database/migrate_self_service_kiosk.sql</code> and then <code>database/migrate_entry_and_permission.sql</code>. Reload this page afterwards.</div>';
    require __DIR__ . '/includes/layout_bottom.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';
    $focus = 0; $backUrl = null;
    try {
        if ($action === 'record_entry') {
            /* The student did not scan at the entrance (or the scanner failed): the librarian records the entry. */
            $sid = trim((string)($_POST['student_id'] ?? ''));
            $res = entry_record_scan($sid, 'Staff', $staffId);
            if ($res['ok']) {
                log_activity('Create', 'Library Entry', 'Entry recorded at the librarian desk for ' . $res['name'], $res['entry_id']);
                flash('success', $res['already'] ? $res['name'] . ' already has an entry for today.'
                                                  : 'Entry recorded for ' . $res['name'] . ' at ' . date('g:i A') . '.');
            } else {
                flash('danger', $res['message']);
            }
            $backUrl = desk_form_url($sid, (array)($_POST['c'] ?? []));
        }
        elseif ($action === 'desk_decide') {
            /* Librarian has verified the student and every book on screen and chose Approve / Reject per book. */
            $sid = trim((string)($_POST['student_id'] ?? ''));
            $codes = array_values(array_map(fn($x) => trim((string)$x), (array)($_POST['c'] ?? [])));
            $backUrl = desk_form_url($sid, $codes);
            $m = ilu_find_member_by_card($sid);
            if (!$m) throw new LibraryException('Student ID not found. Please check the ID and try again.');
            $rows = [];
            foreach ($codes as $i => $code) {
                $copy = fetch_one("SELECT CopyID FROM book_copy WHERE UPPER(AccessionNumber) = UPPER(?) AND IsArchived = 0", [$code]);
                if (!$copy) throw new LibraryException('Copy ' . $code . ' could not be found. Remove it and add it again.');
                $do = (string)($_POST['choice'][$i] ?? '');
                if (!in_array($do, ['approve', 'reject'], true)) throw new LibraryException('Choose Approve or Reject for every book.');
                $rows[] = ['copy_id' => (int)$copy['CopyID'], 'do' => $do, 'reason' => trim((string)($_POST['reason'][$i] ?? ''))];
            }
            $res = use_request_desk_record((int)$m['UserID'], $rows, $staffId);
            $bits = [];
            if ($res['borrowed']) $bits[] = $res['borrowed'] . ' approved for borrowing (Loan #' . $res['loan_id'] . ')';
            if ($res['rejected']) $bits[] = $res['rejected'] . ' rejected';
            log_activity('Create', 'Librarian Desk', use_request_ref($res['request_id']) . ' for ' . $m['FirstName'] . ' ' . $m['LastName'] . ': ' . implode(', ', $bits), $res['request_id']);
            $backUrl = 'use_requests.php?done=' . (int)$res['request_id'];
        }
        elseif ($action === 'decide') {
            $rid  = (int)($_POST['request_id'] ?? 0);
            $mode = $_POST['mode'] ?? 'submit';
            $focus = $rid;
            $pend = fetch_all("SELECT ItemID, RequestedUse FROM use_request_item WHERE UseRequestID = ? AND Decision = 'Pending'", [$rid]);
            $dec = [];
            foreach ($pend as $it) {
                $id = (int)$it['ItemID'];
                if ($mode === 'approve_all')      $dec[$id] = ['do' => 'approve', 'as' => $it['RequestedUse']];
                elseif ($mode === 'reject_all')   $dec[$id] = ['do' => 'reject', 'reason' => $_POST['reject_all_reason'] ?? ''];
                else {
                    $c = (string)($_POST['choice'][$id] ?? '');
                    if ($c === 'reject')                          $dec[$id] = ['do' => 'reject', 'reason' => $_POST['reason'][$id] ?? ''];
                    elseif (strpos($c, 'approve:') === 0)         $dec[$id] = ['do' => 'approve', 'as' => substr($c, 8)];
                }
            }
            $res = use_request_decide($rid, $dec, $staffId, trim($_POST['location'] ?? '') ?: null);
            $bits = [];
            if ($res['in_library']) $bits[] = $res['in_library'] . ' approved for in-library use';
            if ($res['borrowed'])   $bits[] = $res['borrowed'] . ' approved for borrowing (Loan #' . $res['loan_id'] . ')';
            if ($res['rejected'])   $bits[] = $res['rejected'] . ' rejected';
            log_activity('Update', 'Librarian Desk', use_request_ref($rid) . ': ' . implode(', ', $bits), $rid);
            flash('success', use_request_ref($rid) . ' — ' . implode(', ', $bits) . ($res['left'] ? '. ' . $res['left'] . ' book(s) still waiting for a decision.' : '.'));
            $focus = $res['left'] ? $rid : 0;
        }
    } catch (Throwable $e) {
        flash('danger', 'Not saved: ' . user_message($e, 'The decision could not be saved. Nothing was changed.'));
    }
    redirect($backUrl ?: 'use_requests.php' . ($focus ? '?focus=' . $focus : ''));
}


/** Link back to the desk form with the student and the books added so far (the form keeps no hidden state). */
function desk_form_url(string $sid, array $codes): string
{
    $codes = array_values(array_filter(array_map(fn($c) => trim((string)$c), $codes), 'strlen'));
    return 'use_requests.php?' . http_build_query(['sid' => $sid, 'c' => $codes]);
}

/* ---------- Desk form: add / remove a book, then re-draw (so a refresh never adds twice) ---------- */
$dSid   = trim((string)($_GET['sid'] ?? ''));
$dCodes = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x), (array)($_GET['c'] ?? [])), 'strlen')));
if ($dSid !== '' && (isset($_GET['add']) || isset($_GET['rm']))) {
    if (isset($_GET['rm'])) {
        $dCodes = array_values(array_filter($dCodes, fn($c) => strcasecmp($c, trim((string)$_GET['rm'])) !== 0));
    } else {
        $add = trim((string)$_GET['add']);
        if ($add !== '') {
            $found = fetch_one("SELECT AccessionNumber FROM book_copy WHERE UPPER(AccessionNumber) = UPPER(?) AND IsArchived = 0", [$add]);
            if (!$found) {
                flash('danger', 'No book copy with accession number “' . $add . '”. Check the label on the book and try again.');
            } elseif (count($dCodes) >= (int)KIOSK_MAX_COPIES_PER_SESSION) {
                flash('warning', 'That is the maximum number of books for one form.');
            } elseif (!in_array(strtoupper($found['AccessionNumber']), array_map('strtoupper', $dCodes), true)) {
                $dCodes[] = $found['AccessionNumber'];
            } else {
                flash('warning', 'That book is already on the form.');
            }
        }
    }
    redirect(desk_form_url($dSid, $dCodes));
}

$dMember = $dSid !== '' ? ilu_find_member_by_card($dSid) : null;
$deskRows = []; $dBlock = null; $dBase = ['ok' => true, 'reason' => '']; $dEntry = null; $dLim = null; $dMemberOk = false;
if ($dMember) {
    $dMid    = (int)$dMember['UserID'];
    $dBlock  = kiosk_member_block($dMember);
    $dEntry  = member_entry_today($dMid);
    $dLim    = check_borrowing_limit($dMid, 0);
    $dBase   = member_borrow_check($dMid, 0);                 // membership status, unpaid fines, overdue books
    $dMemberOk = !$dBlock && $dBase['ok'];
    $slots   = ((int)$dLim['max_books'] > 0) ? max(0, (int)$dLim['max_books'] - (int)$dLim['current_books']) : PHP_INT_MAX;
    /* Keep-a-copy-in-the-library rule: per title, how many copies are on the shelf and how many this form asks for. */
    $want = []; $spare = [];
    foreach ($dCodes as $code) { $x = ilu_copy_rows('UPPER(bc.AccessionNumber) = UPPER(?)', [$code], 1); if ($x) $want[(int)$x[0]['BookID']] = ($want[(int)$x[0]['BookID']] ?? 0) + 1; }
    foreach ($dCodes as $code) {
        $rows = ilu_copy_rows('UPPER(bc.AccessionNumber) = UPPER(?)', [$code], 1);
        if (!$rows) continue;
        $r = $rows[0]; $why = ''; $bid = (int)$r['BookID'];
        $shelfNow = book_shelf_copies($bid);
        $totalCopies = (int) fetch_value("SELECT COUNT(*) FROM book_copy WHERE BookID = ? AND IsArchived = 0", [$bid]);
        if (!isset($spare[$bid])) $spare[$bid] = $shelfNow - borrow_keep_copies();
        $ruleBlocked = false;
        if ((int)$r['InUse'] || $r['Status'] === 'In Use')  $why = 'This copy is being read inside the library right now.';
        elseif ($r['Status'] === 'Borrowed')                $why = 'This copy is already borrowed by another member.';
        elseif ($r['Status'] === 'Reserved')                $why = 'This copy is being held for another member.';
        elseif ($r['Status'] !== 'Available')               $why = 'This copy is not available (' . strtolower($r['Status']) . ').';
        elseif ((int)$r['IsBorrowable'] !== 1)              $why = 'This book is for library use only and cannot be borrowed.';
        elseif (borrow_keep_copies() >= 1 && $spare[$bid] < 1) { $why = copy_rule_message($r['Title'], $shelfNow, $want[$bid] ?? 1); $ruleBlocked = true; }
        $copyOk = ($why === '');
        $default = 'reject'; $suggest = $why;
        if ($copyOk && $dMemberOk && $slots > 0) { $default = 'approve'; $slots--; $spare[$bid]--; $suggest = ''; }
        elseif ($copyOk && !$dMemberOk)          { $suggest = $dBlock ?: $dBase['reason']; }
        elseif ($copyOk)                         { $suggest = 'The student has reached the borrowing limit.'; }
        $deskRows[] = ['r' => $r, 'copyOk' => $copyOk, 'why' => $why, 'canApprove' => $copyOk && $dMemberOk,
                       'shelf' => $shelfNow, 'total' => $totalCopies, 'ruleBlocked' => $ruleBlocked,
                       'default' => $default, 'suggest' => $suggest,
                       'due' => date('M d, Y', strtotime('+' . resolve_loan_days((int)$r['BookID']) . ' days')),
                       'days' => resolve_loan_days((int)$r['BookID'])];
    }
}

/* ---------- "Done" slip after the librarian confirms ---------- */
$done = (int)($_GET['done'] ?? 0); $slip = null;
if ($done > 0) {
    $hdr = fetch_one("SELECT q.*, ua.FirstName, ua.LastName, ua.MembershipNumber, d.FirstName AS DF, d.LastName AS DL
                        FROM use_request q JOIN user_account ua ON ua.UserID = q.MemberID
                        LEFT JOIN user_account d ON d.UserID = q.DecidedByUserID WHERE q.UseRequestID = ?", [$done]);
    if ($hdr) {
        $slip = ['h' => $hdr, 'items' => fetch_all(
            "SELECT i.Decision, i.RejectReason, i.LoanID, b.Title, bc.AccessionNumber,
                    (SELECT ld.DueDate FROM loan_detail ld WHERE ld.LoanID = i.LoanID AND ld.CopyID = i.CopyID LIMIT 1) AS DueDate
               FROM use_request_item i JOIN book_copy bc ON bc.CopyID = i.CopyID JOIN book b ON b.BookID = bc.BookID
              WHERE i.UseRequestID = ? ORDER BY i.ItemID", [$done])];
    }
}

$tab   = ($_GET['tab'] ?? 'pending') === 'history' ? 'history' : 'pending';
$focus = (int)($_GET['focus'] ?? 0);
$q     = trim($_GET['q'] ?? '');
$pendingCount = (int) fetch_value("SELECT COUNT(*) FROM use_request WHERE Status = 'Pending'");
$locations = KIOSK_LOCATIONS;

$requests = [];
if ($tab === 'pending') {
    $reqs = fetch_all(
        "SELECT q.*, ua.FirstName, ua.LastName, ua.MembershipNumber, ua.MembershipStatus, ua.VerificationStatus,
                cr.FirstName AS CrFirst, cr.LastName AS CrLast
           FROM use_request q JOIN user_account ua ON ua.UserID = q.MemberID
           LEFT JOIN user_account cr ON cr.UserID = q.CreatedByUserID
          WHERE q.Status = 'Pending' ORDER BY q.RequestedAt ASC LIMIT 50");
    foreach ($reqs as $rq) {
        $mid = (int)$rq['MemberID'];
        $items = fetch_all(
            "SELECT i.ItemID, i.CopyID, i.RequestedUse FROM use_request_item i
              WHERE i.UseRequestID = ? AND i.Decision = 'Pending' ORDER BY i.ItemID", [$rq['UseRequestID']]);
        $copyRows = [];
        if ($items) {
            $ids = array_map('intval', array_column($items, 'CopyID'));
            foreach (ilu_copy_rows('bc.CopyID IN (' . implode(',', $ids) . ')', [], 50) as $cr) $copyRows[(int)$cr['CopyID']] = $cr;
        }
        $nBorrow = count(array_filter($items, fn($i) => $i['RequestedUse'] === 'Borrow'));
        $lim = check_borrowing_limit($mid, 0);
        $requests[] = [
            'r' => $rq, 'items' => $items, 'copies' => $copyRows,
            'entry' => member_entry_today($mid),
            'inUse' => (int) fetch_value("SELECT COUNT(*) FROM in_library_use WHERE MemberID = ? AND Status = 'Active'", [$mid]),
            'borrowed' => (int)$lim['current_books'], 'max' => (int)$lim['max_books'],
            'borrowCheck' => member_borrow_check($mid, max(1, $nBorrow)),
            'block' => kiosk_member_block(['IsActive' => 1, 'MembershipStatus' => $rq['MembershipStatus'], 'VerificationStatus' => $rq['VerificationStatus']]),
        ];
    }
}

$hist = []; $pg = null; $pgQuery = $_GET;
if ($tab === 'history') {
    $where = ["(i.Decision <> 'Pending' OR q.Status = 'Cancelled')"]; $params = [];
    if ($q !== '') {
        $where[] = "(ua.MembershipNumber LIKE ? OR CONCAT(ua.FirstName,' ',ua.LastName) LIKE ? OR b.Title LIKE ? OR bc.AccessionNumber LIKE ?)";
        $like = "%$q%"; array_push($params, $like, $like, $like, $like);
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $base = "FROM use_request_item i JOIN use_request q ON q.UseRequestID = i.UseRequestID
             JOIN user_account ua ON ua.UserID = q.MemberID
             JOIN book_copy bc ON bc.CopyID = i.CopyID JOIN book b ON b.BookID = bc.BookID
             LEFT JOIN user_account d ON d.UserID = q.DecidedByUserID";
    $pg = pagination_calc((int) fetch_value("SELECT COUNT(*) $base $whereSql", $params), 15, pagination_page());
    $hist = fetch_all(
        "SELECT i.*, q.UseRequestID AS RID, q.Status AS ReqStatus, q.RequestedAt, q.DecidedAt, q.Source,
                ua.FirstName, ua.LastName, ua.MembershipNumber, b.Title, bc.AccessionNumber,
                d.FirstName AS DFirst, d.LastName AS DLast
           $base $whereSql ORDER BY COALESCE(q.DecidedAt, q.RequestedAt) DESC, i.ItemID DESC
          LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
}
require __DIR__ . '/includes/layout_top.php';
?>

<div class="alert alert-light border d-flex gap-2 small">
  <i class="bi bi-info-circle text-primary mt-1"></i>
  <div><strong>Library procedure:</strong> students scan their ID at the entrance, then ask the librarian before using or borrowing a book.
    Scanning an ID never approves a book. <?php if (IN_LIBRARY_USE_ENABLED): ?>Approve a book for <em>in-library use</em> (no loan, no due date) or for <em>borrowing</em>
    (loan with the policy's due date), or reject it with a reason.<?php else: ?>Approve the book for <em>borrowing</em> (loan with the policy's due date), or reject it with a reason.<?php endif; ?></div>
</div>

<style>
  .desk-step{display:flex;align-items:center;gap:.6rem;font-weight:600;margin-bottom:.6rem}
  .desk-step .n{display:inline-flex;align-items:center;justify-content:center;width:1.7rem;height:1.7rem;border-radius:50%;background:var(--bs-primary,#0d6efd);color:#fff;font-size:.85rem}
  .desk-step.off .n{background:#adb5bd}
  .desk-sec{padding:1rem 0;border-top:1px solid var(--bs-border-color,#dee2e6)}
  .desk-sec:first-child{border-top:0;padding-top:0}
  @media print{.no-print,.sidebar,header,.nav,form.desk-hide{display:none!important}}
</style>

<?php if ($slip): $H = $slip['h']; ?>
<div class="card mb-3 border-success" id="slip">
  <div class="card-header bg-success-subtle d-flex flex-wrap align-items-center gap-2">
    <strong><i class="bi bi-check-circle-fill text-success"></i> Saved — <?= h(use_request_ref($done)) ?></strong>
    <span class="ms-auto d-flex gap-2 no-print">
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print slip</button>
      <a href="use_requests.php" class="btn btn-sm btn-primary"><i class="bi bi-person-plus"></i> Next student</a>
    </span>
  </div>
  <div class="card-body">
    <div class="mb-2"><strong><?= h($H['FirstName'] . ' ' . $H['LastName']) ?></strong> <span class="text-muted">· ID <?= h($H['MembershipNumber']) ?></span>
      <div class="small text-muted">Decided by <?= h(trim(($H['DF'] ?? '') . ' ' . ($H['DL'] ?? '')) ?: '—') ?> · <?= fmt_datetime($H['DecidedAt'] ?: $H['RequestedAt']) ?></div></div>
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Book</th><th>Copy</th><th>Result</th><th>Due date</th></tr></thead>
      <tbody>
      <?php foreach ($slip['items'] as $it): ?>
        <tr>
          <td class="fw-semibold"><?= h($it['Title']) ?></td><td class="small"><?= h($it['AccessionNumber']) ?></td>
          <td><?php if ($it['Decision'] === 'Approved'): ?><span class="badge text-bg-success">Approved · borrowed</span> <span class="small text-muted">Loan #<?= (int)$it['LoanID'] ?></span>
              <?php elseif ($it['Decision'] === 'Rejected'): ?><span class="badge text-bg-danger">Rejected</span> <span class="small text-muted"><?= h($it['RejectReason']) ?></span>
              <?php else: ?><span class="badge text-bg-secondary"><?= h($it['Decision']) ?></span><?php endif; ?></td>
          <td class="small"><?= $it['DueDate'] ? h(date('M d, Y', strtotime($it['DueDate']))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card mb-3 no-print">
  <div class="card-header fw-semibold d-flex align-items-center">
    <span><i class="bi bi-clipboard2-check"></i> Borrowing form — student at the desk</span>
    <?php if ($dSid !== ''): ?><a class="ms-auto small" href="use_requests.php">Start over</a><?php endif; ?>
  </div>
  <div class="card-body">

    <!-- STEP 1: the student's ID -->
    <div class="desk-sec">
      <div class="desk-step"><span class="n">1</span> Student's ID <span class="small fw-normal text-muted">— the student hands over the ID; scan it or type the number</span></div>
      <?php if (!$dMember): ?>
        <form method="get" class="row g-2" autocomplete="off">
          <div class="col-md-6 col-lg-4"><input name="sid" class="form-control" maxlength="40" value="<?= h($dSid) ?>" placeholder="Scan or type Student ID" autofocus required aria-label="Student ID"></div>
          <div class="col-auto"><button class="btn btn-primary"><i class="bi bi-search"></i> Find student</button></div>
        </form>
        <?php if ($dSid !== ''): ?><div class="text-danger small mt-2"><i class="bi bi-x-octagon"></i> “<?= h($dSid) ?>” is not a registered member ID. Check the ID and try again.</div><?php endif; ?>
      <?php else: ?>
        <div class="d-flex flex-wrap align-items-center gap-3">
          <span class="avatar-lg"><?= h(strtoupper(mb_substr($dMember['FirstName'], 0, 1) . mb_substr($dMember['LastName'], 0, 1))) ?></span>
          <div class="flex-grow-1">
            <div class="fw-bold"><a class="text-decoration-none" href="member_view.php?id=<?= (int)$dMember['UserID'] ?>"><?= h($dMember['FirstName'] . ' ' . $dMember['LastName']) ?></a>
              <span class="text-muted fw-normal small">· ID <?= h($dMember['MembershipNumber']) ?></span></div>
            <div class="small d-flex flex-wrap gap-2 mt-1">
              <span class="badge text-bg-<?= $dMember['MembershipStatus'] === 'Active' ? 'success' : 'danger' ?>"><?= h($dMember['MembershipStatus']) ?></span>
              <?php if ($dEntry): ?><span class="badge text-bg-success-subtle text-success-emphasis border"><i class="bi bi-door-open"></i> Entered <?= date('g:i A', strtotime($dEntry['EntryTime'])) ?></span>
              <?php else: ?><span class="badge text-bg-warning"><i class="bi bi-exclamation-triangle"></i> No entry scan today</span><?php endif; ?>
              <?php if ((int)$dLim['max_books'] > 0): ?><span class="badge text-bg-light border"><i class="bi bi-box-arrow-up-right"></i> Borrowed <?= (int)$dLim['current_books'] ?> of <?= (int)$dLim['max_books'] ?></span><?php endif; ?>
            </div>
            <?php if ($dBlock): ?><div class="small text-danger mt-1"><i class="bi bi-x-octagon"></i> <?= h($dBlock) ?></div>
            <?php elseif (!$dBase['ok']): ?><div class="small text-danger mt-1"><i class="bi bi-slash-circle"></i> Cannot borrow: <?= h($dBase['reason']) ?></div>
            <?php else: ?><div class="small text-success mt-1"><i class="bi bi-check-circle"></i> Eligible to borrow.</div><?php endif; ?>
          </div>
          <?php if (!$dEntry): ?>
          <form method="post" class="no-print"><?= csrf_field() ?><input type="hidden" name="action" value="record_entry">
            <input type="hidden" name="student_id" value="<?= h($dMember['MembershipNumber']) ?>">
            <?php foreach ($dCodes as $c): ?><input type="hidden" name="c[]" value="<?= h($c) ?>"><?php endforeach; ?>
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-door-open"></i> Record entry now</button></form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- STEP 2: the books -->
    <div class="desk-sec">
      <div class="desk-step <?= $dMember ? '' : 'off' ?>"><span class="n">2</span> Books the student brought <span class="small fw-normal text-muted">— scan the barcode / type the accession number on each book</span></div>
      <?php if ($dMember): ?>
        <form method="get" class="row g-2 mb-3" autocomplete="off">
          <input type="hidden" name="sid" value="<?= h($dMember['MembershipNumber']) ?>">
          <?php foreach ($dCodes as $c): ?><input type="hidden" name="c[]" value="<?= h($c) ?>"><?php endforeach; ?>
          <div class="col-md-6 col-lg-4"><input name="add" class="form-control" maxlength="60" placeholder="Scan or type accession number" aria-label="Accession number" <?= $dCodes ? '' : 'autofocus' ?>></div>
          <div class="col-auto"><button class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> Add book</button></div>
        </form>

        <?php if ($deskRows): ?>
        <form method="post" id="deskDecide" autocomplete="off" data-loading>
          <?= csrf_field() ?><input type="hidden" name="action" value="desk_decide">
          <input type="hidden" name="student_id" value="<?= h($dMember['MembershipNumber']) ?>">
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Book</th><th>Copy</th><th>Availability</th><th>Loan period</th><th style="min-width:300px">3 · Your decision</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($deskRows as $i => $R): $r = $R['r']; ?>
              <tr>
                <td class="fw-semibold"><?= h($r['Title']) ?><div class="small text-muted fw-normal"><?= h($r['Authors'] ?: '') ?></div></td>
                <td class="small"><?= h($r['AccessionNumber']) ?><div class="text-muted"><?= h($r['ShelfCode'] ?: '') ?></div>
                  <input type="hidden" name="c[<?= $i ?>]" value="<?= h($r['AccessionNumber']) ?>"></td>
                <td class="small"><?php if ($R['copyOk']): ?><span class="badge text-bg-success"><i class="bi bi-check2"></i> Can be borrowed</span>
                  <?php elseif ($R['ruleBlocked']): ?><span class="badge text-bg-warning"><i class="bi bi-bookshelf"></i> Last copy stays in library</span><div class="text-danger mt-1"><?= h($R['why']) ?></div>
                  <?php else: ?><span class="badge text-bg-danger"><i class="bi bi-x-lg"></i> Not available</span><div class="text-danger mt-1"><?= h($R['why']) ?></div><?php endif; ?>
                  <div class="text-muted mt-1"><i class="bi bi-bookshelf"></i> On shelf: <?= (int)$R['shelf'] ?> of <?= (int)$R['total'] ?> <?= (int)$R['total'] === 1 ? 'copy' : 'copies' ?></div></td>
                <td class="small"><?= (int)$R['days'] ?> days<div class="text-muted">Due <?= h($R['due']) ?></div></td>
                <td>
                  <div class="btn-group btn-group-sm w-100" role="group" aria-label="Decision for <?= h($r['Title']) ?>">
                    <input type="radio" class="btn-check dk-choice" name="choice[<?= $i ?>]" id="ap<?= $i ?>" value="approve" data-i="<?= $i ?>" <?= $R['default'] === 'approve' ? 'checked' : '' ?> <?= $R['canApprove'] ? '' : 'disabled' ?>>
                    <label class="btn btn-outline-success" for="ap<?= $i ?>"><i class="bi bi-check2-circle"></i> Approve</label>
                    <input type="radio" class="btn-check dk-choice" name="choice[<?= $i ?>]" id="rj<?= $i ?>" value="reject" data-i="<?= $i ?>" <?= $R['default'] === 'reject' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-danger" for="rj<?= $i ?>"><i class="bi bi-x-circle"></i> Reject</label>
                  </div>
                  <input type="text" class="form-control form-control-sm mt-1" name="reason[<?= $i ?>]" id="reason<?= $i ?>" maxlength="255" list="dkReasons"
                         value="<?= h($R['suggest']) ?>" placeholder="Reason for rejecting (required)" <?= $R['default'] === 'reject' ? 'required' : 'hidden' ?>>
                </td>
                <td class="text-end"><a class="btn btn-sm btn-link text-danger" title="Remove this book" aria-label="Remove <?= h($r['Title']) ?>"
                      href="<?= h('use_requests.php?' . http_build_query(['sid' => $dMember['MembershipNumber'], 'c' => $dCodes, 'rm' => $r['AccessionNumber']])) ?>"><i class="bi bi-trash"></i></a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <datalist id="dkReasons">
            <option value="The student has reached the borrowing limit.">
            <option value="The student has unpaid fines or overdue books.">
            <option value="This book is for library use only.">
            <option value="This copy is not available.">
          </datalist>
          <?php if (!$dEntry): ?><div class="alert alert-warning small mt-3 mb-0"><i class="bi bi-exclamation-triangle"></i> This student has no entry scan today. You may still decide, or use <em>Record entry now</em> above first.</div><?php endif; ?>
          <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
            <a href="use_requests.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-success" id="dkConfirm"><i class="bi bi-check2-all"></i> Confirm decisions</button>
          </div>
          <div class="small text-muted text-end mt-1">Nothing is saved until you confirm. Approved books are recorded as borrowed with the due date shown.</div>
        </form>
        <?php else: ?>
          <div class="text-muted small"><i class="bi bi-upc-scan"></i> No books added yet.</div>
        <?php endif; ?>
      <?php else: ?>
        <div class="text-muted small">Find the student first.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.dk-choice').forEach(function (rb) {
    rb.addEventListener('change', function () {
      var r = document.getElementById('reason' + rb.dataset.i), rej = rb.value === 'reject';
      r.hidden = !rej; r.required = rej;
      if (rej) { r.focus(); } else { r.value = ''; }
    });
  });
});
</script>

<ul class="nav nav-pills mb-3 gap-2">
  <li class="nav-item"><a class="nav-link <?= $tab === 'pending' ? 'active' : '' ?>" href="use_requests.php"><i class="bi bi-hourglass-split"></i> Waiting for decision
    <span class="badge text-bg-<?= $pendingCount ? 'danger' : 'light border' ?> ms-1"><?= $pendingCount ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>" href="use_requests.php?tab=history"><i class="bi bi-clock-history"></i> Decided</a></li>
</ul>

<?php if ($tab === 'pending'): ?>
  <?php if (!$requests): ?>
    <div class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-patch-check"></i><div class="fw-semibold">No requests are waiting.</div>
      <div class="small">Student requests from the kiosk appear here automatically — refresh to check.</div></div></div></div>
  <?php endif; ?>

  <?php foreach ($requests as $R):
      $rq = $R['r']; $rid = (int)$rq['UseRequestID']; $name = $rq['FirstName'] . ' ' . $rq['LastName'];
      $allOk = true;
  ?>
  <form method="post" class="card mb-3 ur-card<?= $focus === $rid ? ' ur-focus' : '' ?>" id="req-<?= $rid ?>" data-loading>
    <?= csrf_field() ?><input type="hidden" name="action" value="decide"><input type="hidden" name="request_id" value="<?= $rid ?>"><input type="hidden" name="mode" value="submit" class="ur-mode">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      <strong><?= h(use_request_ref($rid)) ?></strong>
      <span class="badge text-bg-<?= $rq['Source'] === 'Kiosk' ? 'info' : 'secondary' ?>"><?= $rq['Source'] === 'Kiosk' ? 'Sent from kiosk' : 'Entered at desk' ?></span>
      <span class="small text-muted ms-auto">Requested <?= fmt_datetime($rq['RequestedAt']) ?></span>
    </div>
    <div class="card-body">
      <!-- 1. VERIFY THE STUDENT -->
      <div class="ur-student">
        <span class="avatar-lg"><?= h(strtoupper(mb_substr($rq['FirstName'], 0, 1) . mb_substr($rq['LastName'], 0, 1))) ?></span>
        <div class="flex-grow-1">
          <div class="fw-bold"><a class="text-decoration-none" href="member_view.php?id=<?= h($rq['MemberID']) ?>"><?= h($name) ?></a>
            <span class="text-muted fw-normal small">· ID <?= h($rq['MembershipNumber']) ?></span></div>
          <div class="small d-flex flex-wrap gap-2 mt-1">
            <span class="badge text-bg-<?= $rq['MembershipStatus'] === 'Active' ? 'success' : 'danger' ?>"><?= h($rq['MembershipStatus']) ?></span>
            <?php if ($R['entry']): ?><span class="badge text-bg-success-subtle text-success-emphasis border"><i class="bi bi-door-open"></i> Entered <?= date('g:i A', strtotime($R['entry']['EntryTime'])) ?></span>
            <?php else: ?><span class="badge text-bg-warning"><i class="bi bi-exclamation-triangle"></i> No entry scan today</span><?php endif; ?>
            <?php if (IN_LIBRARY_USE_ENABLED): ?><span class="badge text-bg-light border"><i class="bi bi-book"></i> <?= (int)$R['inUse'] ?> in use now</span><?php endif; ?>
            <?php if ($R['max'] > 0): ?><span class="badge text-bg-light border"><i class="bi bi-box-arrow-up-right"></i> Borrowed <?= (int)$R['borrowed'] ?> of <?= (int)$R['max'] ?></span><?php endif; ?>
          </div>
          <?php if ($R['block']): ?><div class="small text-danger mt-1"><i class="bi bi-x-octagon"></i> <?= h($R['block']) ?></div><?php endif; ?>
          <?php if (!$R['borrowCheck']['ok'] && !$R['block']): ?><div class="small text-danger mt-1"><i class="bi bi-slash-circle"></i> Cannot borrow: <?= h($R['borrowCheck']['reason']) ?> (in-library use is still possible)</div><?php endif; ?>
        </div>
      </div>

      <!-- 2. VERIFY EACH BOOK AND DECIDE -->
      <div class="table-responsive mt-3">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Book</th><th>Copy ID</th><th>Availability now</th><th>Student asked to</th><th style="min-width:260px">Your decision</th></tr></thead>
          <tbody>
          <?php foreach ($R['items'] as $it):
              $iid = (int)$it['ItemID']; $cp = $R['copies'][(int)$it['CopyID']] ?? null;
              $st = $cp ? ilu_copy_state($cp) : ['ok' => false, 'label' => 'Not found', 'reason' => ''];
              $canBorrow = $cp && (int)$cp['IsBorrowable'] === 1 && $R['borrowCheck']['ok'] && !$R['block'] && (borrow_keep_copies() < 1 || book_copies_borrowable_now((int)$cp['BookID']) >= 1);
              $canUse = $st['ok'] && !$R['block'];
              if (!$canUse) $allOk = false;
              $due = $cp ? date('M d, Y', strtotime('+' . resolve_loan_days((int)$cp['BookID']) . ' days')) : '';
          ?>
            <tr>
              <td class="fw-semibold"><?= h($cp['Title'] ?? '—') ?><div class="small text-muted fw-normal"><?= h($cp ? ($cp['Authors'] ?: '') : '') ?></div></td>
              <td class="small"><?= h($cp['AccessionNumber'] ?? '') ?><div class="text-muted"><?= h($cp['ShelfCode'] ?? '') ?></div></td>
              <td class="small"><span class="badge text-bg-<?= $st['ok'] ? 'success' : 'secondary' ?>"><?= h($st['label']) ?></span>
                <?php if ($cp && (int)$cp['IsBorrowable'] !== 1): ?><div class="text-muted">Library use only</div>
                <?php elseif ($cp && borrow_keep_copies() >= 1 && book_copies_borrowable_now((int)$cp['BookID']) < 1): ?><div class="text-danger">Last spare copy stays in the library — cannot be borrowed</div><?php endif; ?></td>
              <td class="small"><span class="badge text-bg-<?= $it['RequestedUse'] === 'Borrow' ? 'warning' : 'info' ?>"><?= $it['RequestedUse'] === 'Borrow' ? 'Borrow' : 'Read in library' ?></span></td>
              <td>
                <select class="form-select form-select-sm ur-choice" name="choice[<?= $iid ?>]" data-item="<?= $iid ?>" aria-label="Decision for <?= h($cp['Title'] ?? 'book') ?>">
                  <option value="">— Choose —</option>
                  <?php if (IN_LIBRARY_USE_ENABLED): ?><option value="approve:In-Library" <?= $canUse ? '' : 'disabled' ?>>Approve · read in library</option><?php endif; ?>
                  <option value="approve:Borrow" <?= ($canUse && $canBorrow) ? '' : 'disabled' ?>>Approve · borrow (due <?= h($due) ?>)</option>
                  <option value="reject">Reject…</option>
                </select>
                <input type="text" class="form-control form-control-sm mt-1 ur-reason" name="reason[<?= $iid ?>]" id="reason-<?= $iid ?>" maxlength="255" placeholder="Reason for rejecting (required)" hidden>
                <?php if (!$st['ok']): ?><div class="small text-danger mt-1"><?= h($st['reason']) ?></div><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="row g-2 align-items-end mt-2">
        <?php if (IN_LIBRARY_USE_ENABLED): ?>
        <div class="col-md-4">
          <label class="form-label small fw-semibold mb-1" for="loc-<?= $rid ?>">Reading area <span class="text-muted fw-normal">(for in-library use, optional)</span></label>
          <input type="text" id="loc-<?= $rid ?>" name="location" class="form-control form-control-sm" maxlength="100" list="locList" placeholder="e.g. Reading Room 1">
        </div>
        <?php endif; ?>
        <div class="col-md-8 d-flex flex-wrap gap-2 justify-content-md-end">
          <input type="text" class="form-control form-control-sm" style="max-width:220px" name="reject_all_reason" maxlength="255" placeholder="Reason (only for “Reject all”)">
          <button type="button" class="btn btn-sm btn-outline-danger ur-submit" data-mode="reject_all" data-confirm-text="Reject every book in <?= h(use_request_ref($rid)) ?>?"><i class="bi bi-x-lg"></i> Reject all</button>
          <?php if ($allOk): ?><button type="button" class="btn btn-sm btn-success ur-submit" data-mode="approve_all" data-confirm-text="Approve every book in <?= h(use_request_ref($rid)) ?> as the student requested?"><i class="bi bi-check2-all"></i> Approve all as requested</button><?php endif; ?>
          <button type="button" class="btn btn-sm btn-primary ur-submit" data-mode="submit"><i class="bi bi-check2"></i> Save decisions</button>
        </div>
      </div>
    </div>
  </form>
  <?php endforeach; ?>
  <datalist id="locList"><?php foreach ($locations as $l): ?><option value="<?= h($l) ?>"><?php endforeach; ?></datalist>

<?php else: ?>
  <div class="card">
    <div class="px-3 py-2 border-bottom"><form class="row g-2" method="get"><input type="hidden" name="tab" value="history">
      <div class="col-md-5"><input type="search" name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="Student ID, name, book title or accession no."></div>
      <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-search"></i> Search</button></div></form></div>
    <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0 ilu-table">
      <thead><tr><th>Request</th><th>Student</th><th>Book / Copy ID</th><th>Asked to</th><th>Decision</th><th>Decided by</th><th>When</th></tr></thead>
      <tbody>
      <?php if (!$hist): ?><?= empty_row(7, 'bi-clock-history', 'No decided requests yet.') ?><?php endif; ?>
      <?php foreach ($hist as $r): $cancelled = $r['ReqStatus'] === 'Cancelled'; ?>
        <tr>
          <td class="small fw-semibold"><?= h(use_request_ref((int)$r['RID'])) ?><div class="text-muted fw-normal"><?= $r['Source'] === 'Kiosk' ? 'Kiosk' : 'Desk' ?></div></td>
          <td class="small"><?= h($r['FirstName'] . ' ' . $r['LastName']) ?><div class="text-muted"><?= h($r['MembershipNumber']) ?></div></td>
          <td style="white-space:normal"><span class="fw-semibold"><?= h($r['Title']) ?></span><div class="small text-muted">Copy <?= h($r['AccessionNumber']) ?></div></td>
          <td class="small"><?= $r['RequestedUse'] === 'Borrow' ? 'Borrow' : 'Read in library' ?></td>
          <td class="small" style="white-space:normal">
            <?php if ($cancelled): ?><span class="badge text-bg-secondary">Cancelled by student</span>
            <?php elseif ($r['Decision'] === 'Approved'): ?><span class="badge text-bg-success">Approved</span>
              <span class="text-muted"><?= $r['ApprovedAs'] === 'Borrow' ? 'to borrow' . ($r['LoanID'] ? ' · Loan #' . (int)$r['LoanID'] : '') : 'for in-library use' ?></span>
            <?php else: ?><span class="badge text-bg-danger">Rejected</span> <span class="text-muted"><?= h($r['RejectReason']) ?></span><?php endif; ?></td>
          <td class="small"><?= $cancelled ? '—' : h(trim(($r['DFirst'] ?? '') . ' ' . ($r['DLast'] ?? ''))) ?></td>
          <td class="small"><?= fmt_datetime($r['DecidedAt'] ?: $r['RequestedAt']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php if ($pg && $pg['total_pages'] > 1): ?><div class="card-footer"><?= pagination_links($pg, $pgQuery) ?></div><?php endif; ?>
  </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.ur-choice').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var r = document.getElementById('reason-' + sel.dataset.item), rej = sel.value === 'reject';
      r.hidden = !rej; r.required = rej; if (rej) r.focus();
    });
  });
  document.querySelectorAll('.ur-submit').forEach(function (b) {
    b.addEventListener('click', function () {
      var form = b.closest('form'), mode = b.dataset.mode;
      if (mode === 'submit') {
        var any = Array.prototype.some.call(form.querySelectorAll('.ur-choice'), function (s) { return s.value; });
        if (!any) { if (window.swalNotice) swalNotice('Choose Approve or Reject for at least one book first.', 'No decision yet'); else alert('Choose Approve or Reject for at least one book first.'); return; }
      }
      if (mode === 'reject_all' && !form.elements['reject_all_reason'].value.trim()) { form.elements['reject_all_reason'].focus(); alert('Please type a reason for rejecting.'); return; }
      if (b.dataset.confirmText && !confirm(b.dataset.confirmText)) return;
      form.querySelector('.ur-mode').value = mode;
      form.querySelectorAll('.ur-reason').forEach(function (r) { if (mode !== 'submit') r.required = false; });
      if (form.reportValidity()) form.requestSubmit ? form.requestSubmit() : form.submit();
    });
  });
  var f = document.querySelector('.ur-focus'); if (f) f.scrollIntoView({ block: 'center' });
});
</script>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
