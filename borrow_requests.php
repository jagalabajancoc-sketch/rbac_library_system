<?php
require_once __DIR__ . '/includes/auth.php';

$page_title    = 'Borrow Requests';
$page_subtitle = 'Review requests, hold copies and track the reservation queue';
$staffUserId   = (int)$_SESSION['user_id'];
$canDecide     = has_permission('approve_requests');
$canRelease    = has_permission('process_loans');

const REQUEST_TABS = ['review', 'ready', 'queue', 'history', 'all'];
$tab = $_GET['tab'] ?? 'review';
if (!in_array($tab, REQUEST_TABS, true)) $tab = 'review';

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */
const REQUEST_STATUS_FILTERS = ['', 'Pending', 'Approved', 'Fulfilled', 'Rejected', 'Cancelled', 'Expired'];

/** Clean up the list state (tab, search, dates, status, page) so it is safe to put in a URL. */
function sanitize_list_state(array $src): array
{
    $out = [];
    $tab = $src['tab'] ?? 'review';
    $out['tab'] = in_array($tab, REQUEST_TABS, true) ? $tab : 'review';
    $q = trim((string)($src['q'] ?? ''));
    if ($q !== '') $out['q'] = mb_substr($q, 0, 100);
    foreach (['from', 'to'] as $k) {
        $v = trim((string)($src[$k] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $out[$k] = $v;
    }
    $st = (string)($src['status'] ?? '');
    if ($st !== '' && in_array($st, REQUEST_STATUS_FILTERS, true)) $out['status'] = $st;
    $page = (int)($src['page'] ?? 1);
    if ($page > 1) $out['page'] = $page;
    return $out;
}

/** One borrower's overall status, worked out from the status of each of their books. */
function borrower_overall_status(array $statuses): string
{
    $set = array_unique($statuses);
    if (count($set) === 1) return $set[0];
    if (in_array('Pending', $set, true))  return 'Pending';     // still needs a decision
    if (in_array('Approved', $set, true)) return 'Approved';    // waiting to be released
    return 'Mixed';
}

/** Of the ids sent by the browser, keep only this borrower's requests that are currently $status. */
function owned_request_ids(int $userId, $ids, string $status): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    if (!$ids) return [];
    $mk = implode(',', array_fill(0, count($ids), '?'));
    return array_map('intval', array_column(fetch_all(
        "SELECT RequestID FROM borrow_request WHERE UserID = ? AND ApprovalStatus = ? AND RequestID IN ($mk) ORDER BY RequestDate, RequestID",
        array_merge([$userId, $status], $ids)), 'RequestID'));
}

/* ------------------------------------------------------------------ *
 * Actions — every action works on ONE request (= one book).
 * Nothing here can approve or reject more than the single request sent.
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action    = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);

    parse_str((string)($_POST['return_qs'] ?? ''), $rs);
    $state = sanitize_list_state(is_array($rs) ? $rs : []);
    $openUid = 0;

    try {
        if (!in_array($action, ['approve', 'reject', 'cancel', 'approve_borrower', 'reject_borrower', 'release_borrower', 'cancel_borrower'], true)) {
            throw new LibraryException('Unknown action.');
        }

        /* RELEASE several of one borrower's held books. Each book is released as its own request/loan
           (own transaction): one that fails a rule is skipped and reported, the others still go through. */
        if ($action === 'release_borrower') {
            require_permission('process_loans');
            $openUid = (int)($_POST['user_id'] ?? 0);
            if ($openUid < 1) throw new LibraryException('No borrower selected.');
            $ids = owned_request_ids($openUid, $_POST['request_ids'] ?? [], 'Approved');
            if (!$ids) throw new LibraryException('Select at least one held book first.');
            $released = 0; $loans = []; $skipped = [];
            foreach ($ids as $id) {
                try {
                    $r = release_borrow_request($id, $staffUserId);
                    log_activity('Create', 'Loans', "Released Request #$id as Loan #{$r['loan_id']} ({$r['items']} item(s))", $r['loan_id']);
                    $released++; $loans[] = '#' . $r['loan_id'];
                } catch (Throwable $e) {
                    $skipped[] = "Request #$id: " . user_message($e, 'could not be released');
                }
            }
            $msg = "$released book(s) released and recorded as borrowed (Loan " . implode(', ', $loans) . '), each with its own due date.';
            if ($skipped) $msg .= ' Not released (' . count($skipped) . '): ' . implode('; ', array_slice($skipped, 0, 5)) . '.';
            if (!$released) $msg = 'Nothing was released. ' . implode('; ', array_slice($skipped, 0, 5));
            flash($released > 0 ? ($skipped ? 'warning' : 'success') : 'danger', $msg, true);
            $state['open'] = $openUid;
            redirect('borrow_requests.php?' . http_build_query($state));
        }

        require_permission('approve_requests');
        if ($action === 'cancel_borrower') {
            $openUid = (int)($_POST['user_id'] ?? 0);
            if ($openUid < 1) throw new LibraryException('No borrower selected.');
            $ids = owned_request_ids($openUid, $_POST['request_ids'] ?? [], 'Approved');
            if (!$ids) throw new LibraryException('Select at least one held book first.');
            $done = 0; $skipped = [];
            foreach ($ids as $id) {
                try {
                    $title = cancel_borrow_request($id, $staffUserId, true, null);
                    log_activity('Cancel', 'Borrow Requests', "Borrow Request #$id for \"$title\" cancelled by staff", $id);
                    $done++;
                } catch (Throwable $e) {
                    $skipped[] = "Request #$id: " . user_message($e, 'could not be cancelled');
                }
            }
            $msg = "$done request(s) cancelled. Any held copies have been released.";
            if ($skipped) $msg .= ' Not cancelled: ' . implode('; ', array_slice($skipped, 0, 5)) . '.';
            flash($done > 0 ? ($skipped ? 'warning' : 'success') : 'danger', $msg, true);
            $state['open'] = $openUid;
            redirect('borrow_requests.php?' . http_build_query($state));
        }
        if ($action === 'reject_borrower') {
            $openUid = (int)($_POST['user_id'] ?? 0);
            if ($openUid < 1) throw new LibraryException('No borrower selected.');
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['request_ids'] ?? [])))));
            if (!$ids) throw new LibraryException('Select at least one book first.');
            $mk = implode(',', array_fill(0, count($ids), '?'));
            $own = array_map('intval', array_column(fetch_all(
                "SELECT RequestID FROM borrow_request WHERE UserID = ? AND ApprovalStatus = 'Pending' AND RequestID IN ($mk)",
                array_merge([$openUid], $ids)), 'RequestID'));
            $done = 0;
            foreach ($own as $id) {                      // each book rejected as its own record
                try { reject_borrow_request($id, (string)($_POST['reason'] ?? ''), $staffUserId); $done++; }
                catch (LibraryException $e) { if (str_contains($e->getMessage(), 'reason')) throw $e; }
            }
            log_activity('Reject', 'Borrow Requests', "Rejected $done book(s) for one borrower, each as its own request", null);
            flash($done ? 'warning' : 'danger', "$done book(s) rejected. The member can see the reason.", true);
            $state['open'] = $openUid;
            redirect('borrow_requests.php?' . http_build_query($state));
        }
        if ($action === 'approve_borrower') {
            // one borrower's listed books, each approved as its own record
            $openUid = (int)($_POST['user_id'] ?? 0);
            if ($openUid < 1) throw new LibraryException('No borrower selected.');
            $res = approve_borrower_requests($openUid, (array)($_POST['request_ids'] ?? []), $staffUserId);
            log_activity('Approve', 'Borrow Requests', "Approved {$res['approved']} book(s) for one borrower, each as its own request", null);
            $msg = $res['approved'] . ' book(s) approved — each has its own held copy and status. They become loans only when released.';
            if ($res['skipped']) $msg .= ' Not approved (' . count($res['skipped']) . '): ' . implode('; ', array_slice($res['skipped'], 0, 5)) . '.';
            flash($res['approved'] > 0 ? ($res['skipped'] ? 'warning' : 'success') : 'danger', $msg, true);
            $state['open'] = $openUid;
            redirect('borrow_requests.php?' . http_build_query($state));
        }
        if ($requestId < 1) throw new LibraryException('No request selected.');

        // keep the borrower's book list open after the action so the next book can be handled straight away
        $openUid = (int) fetch_value("SELECT UserID FROM borrow_request WHERE RequestID = ?", [$requestId]);

        if ($action === 'approve') {
            $copyId = (int)($_POST['copy_id'] ?? 0);
            if ($copyId < 1) throw new LibraryException('Please choose the copy to hold.');
            $r = approve_borrow_request($requestId, $copyId, $staffUserId);
            log_activity('Approve', 'Borrow Requests',
                "Borrow Request #$requestId approved — copy {$r['accession']} of \"{$r['title']}\" held for pickup", $requestId);
            flash('success', "“{$r['title']}” approved (request #$requestId). Copy {$r['accession']} is held for pickup — "
                . 'it is recorded as borrowed only when you release it.', true);
        } elseif ($action === 'reject') {
            $title = reject_borrow_request($requestId, (string)($_POST['reason'] ?? ''), $staffUserId);
            log_activity('Reject', 'Borrow Requests', "Borrow Request #$requestId for \"$title\" rejected", $requestId);
            flash('warning', "“{$title}” rejected (request #$requestId). The member can see the reason.", true);
        } else {
            $title = cancel_borrow_request($requestId, $staffUserId, true, trim((string)($_POST['reason'] ?? '')) ?: null);
            log_activity('Cancel', 'Borrow Requests', "Borrow Request #$requestId for \"$title\" cancelled by staff", $requestId);
            flash('success', "“{$title}” cancelled (request #$requestId). Any held copy has been released.", true);
        }
    } catch (Throwable $e) {
        flash('danger', user_message($e, 'The request could not be updated. Please try again.'), true);
    }
    if ($openUid > 0) $state['open'] = $openUid;
    redirect('borrow_requests.php?' . http_build_query($state));
}

/* ------------------------------------------------------------------ *
 * Filters
 * ------------------------------------------------------------------ */
$search = trim($_GET['q'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
if ($to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = '';
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, REQUEST_STATUS_FILTERS, true)) $statusFilter = '';
$openUid = (int)($_GET['open'] ?? 0);

$where = []; $params = [];
switch ($tab) {
    case 'review':  $where[] = "rq.ApprovalStatus = 'Pending' AND rq.RequestType = 'Borrow'"; break;
    case 'ready':   $where[] = "rq.ApprovalStatus = 'Approved'"; break;
    case 'queue':   $where[] = "rq.ApprovalStatus = 'Pending' AND rq.RequestType = 'Reservation'"; break;
    case 'history':
        if ($statusFilter !== '') { $where[] = 'rq.ApprovalStatus = ?'; $params[] = $statusFilter; }
        else $where[] = "rq.ApprovalStatus IN ('Fulfilled','Rejected','Cancelled','Expired')";
        break;
    case 'all':
        if ($statusFilter !== '') { $where[] = 'rq.ApprovalStatus = ?'; $params[] = $statusFilter; }
        break;
}
if ($search !== '') {
    $like = "%$search%";
    $where[] = "(CONCAT(m.FirstName, ' ', m.LastName) LIKE ? OR m.MembershipNumber LIKE ?
                 OR EXISTS (SELECT 1 FROM borrow_request_detail d JOIN book b ON b.BookID = d.BookID
                             WHERE d.RequestID = rq.RequestID AND b.Title LIKE ?))";
    array_push($params, $like, $like, $like);
}
if ($from !== '') { $where[] = 'DATE(rq.RequestDate) >= ?'; $params[] = $from; }
if ($to   !== '') { $where[] = 'DATE(rq.RequestDate) <= ?'; $params[] = $to; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* Tab counts (unfiltered, so the badges always show the real workload): books, not borrowers */
$counts = ['review' => 0, 'ready' => 0, 'queue' => 0];
foreach (fetch_all("SELECT ApprovalStatus, RequestType, COUNT(*) AS n FROM borrow_request
                     WHERE ApprovalStatus IN ('Pending','Approved') GROUP BY ApprovalStatus, RequestType") as $c) {
    if ($c['ApprovalStatus'] === 'Approved')                                   $counts['ready']  += (int)$c['n'];
    elseif ($c['RequestType'] === 'Borrow')                                    $counts['review'] += (int)$c['n'];
    else                                                                       $counts['queue']  += (int)$c['n'];
}

/* ------------------------------------------------------------------ *
 * Data: page through BORROWERS, then load each borrower's books.
 * Every book stays its own borrow_request row — grouping is display only.
 * ------------------------------------------------------------------ */
$baseFrom = "FROM borrow_request rq JOIN user_account m ON m.UserID = rq.UserID";
$isRecord = in_array($tab, ['history', 'all'], true);   // records: newest first; work queues: oldest first

$total = (int) fetch_value("SELECT COUNT(DISTINCT rq.UserID) $baseFrom $whereSql", $params);
$pg    = pagination_calc($total, 10, pagination_page());

$memberIds = array_map('intval', array_column(fetch_all(
    "SELECT rq.UserID $baseFrom $whereSql
      GROUP BY rq.UserID
      ORDER BY " . ($isRecord ? 'MAX(rq.RequestID) DESC' : 'MIN(rq.RequestDate) ASC, MIN(rq.RequestID) ASC') . "
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
), 'UserID'));

$groups = [];
if ($memberIds) {
    $marks = implode(',', array_fill(0, count($memberIds), '?'));
    $rows = fetch_all(
        "SELECT rq.*, m.FirstName, m.LastName, m.MembershipNumber, m.MembershipStatus,
                s.FirstName AS StaffFirst, s.LastName AS StaffLast,
                hc.AccessionNumber AS HeldAccession,
                (SELECT GROUP_CONCAT(b.Title SEPARATOR ', ')
                   FROM borrow_request_detail d JOIN book b ON b.BookID = d.BookID
                  WHERE d.RequestID = rq.RequestID) AS Books,
                (SELECT COUNT(*) FROM borrow_request_detail d WHERE d.RequestID = rq.RequestID) AS BookCount,
                (SELECT d.BookID FROM borrow_request_detail d WHERE d.RequestID = rq.RequestID ORDER BY d.BookID LIMIT 1) AS FirstBookID,
                (SELECT l.LoanID FROM loan l WHERE l.RequestID = rq.RequestID LIMIT 1) AS LoanID,
                (SELECT MIN(ld.DueDate) FROM loan l JOIN loan_detail ld ON ld.LoanID = l.LoanID
                  WHERE l.RequestID = rq.RequestID) AS LoanDueDate
           FROM borrow_request rq
           JOIN user_account m ON m.UserID = rq.UserID
           LEFT JOIN user_account s ON s.UserID = rq.ApprovedByUserID
           LEFT JOIN book_copy hc ON hc.CopyID = rq.AssignedCopyID
           " . ($whereSql ? $whereSql . " AND rq.UserID IN ($marks)" : "WHERE rq.UserID IN ($marks)") . "
          ORDER BY " . ($isRecord ? 'rq.RequestID DESC' : 'rq.RequestDate ASC, rq.RequestID ASC'),
        array_merge($params, $memberIds)
    );
    foreach ($memberIds as $uid) $groups[$uid] = ['uid' => $uid, 'rows' => []];   // keep the paged order
    foreach ($rows as $r) $groups[(int)$r['UserID']]['rows'][] = $r;
    $groups = array_filter($groups, fn($g) => $g['rows']);
}

/* Copies on the shelf for each book that still needs a copy (for the approve dialog) and the loan period */
$shelfCopies = []; $loanDays = [];
foreach ($groups as $g) foreach ($g['rows'] as $r) {
    $bid = (int)$r['FirstBookID'];
    if (!$bid) continue;
    if ($r['ApprovalStatus'] === 'Pending' && !isset($shelfCopies[$bid])) {
        $shelfCopies[$bid] = fetch_all(
            "SELECT CopyID, AccessionNumber FROM book_copy
              WHERE BookID = ? AND Status = 'Available' AND IsArchived = 0 ORDER BY AccessionNumber",
            [$bid]
        );
    }
    if (in_array($r['ApprovalStatus'], ['Pending', 'Approved'], true) && !isset($loanDays[$bid])) {
        $loanDays[$bid] = resolve_loan_days($bid);
    }
}

$pgQuery  = $_GET; unset($pgQuery['open']);
$tabLink  = fn(string $t) => 'borrow_requests.php?tab=' . $t;
$listState = sanitize_list_state(['tab' => $tab, 'q' => $search, 'from' => $from, 'to' => $to,
                                  'status' => $statusFilter, 'page' => $pg['page']]);
$returnQs = http_build_query($listState);
$hasFilter = $search !== '' || $from !== '' || $to !== '' || $statusFilter !== '';
require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills request-tabs mb-3 flex-wrap gap-1">
  <?php
  $tabs = [
      'review'  => ['To Review',          'bi-inbox',           $counts['review']],
      'ready'   => ['Ready for Release',  'bi-bag-check',       $counts['ready']],
      'queue'   => ['Reservation Queue',  'bi-hourglass-split', $counts['queue']],
      'history' => ['History',            'bi-clock-history',   null],
      'all'     => ['All',                'bi-list-ul',         null],
  ];
  foreach ($tabs as $key => [$label, $icon, $n]): ?>
    <li class="nav-item">
      <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= h($tabLink($key)) ?>">
        <i class="bi <?= h($icon) ?>"></i> <?= h($label) ?>
        <?php if ($n !== null): ?>
          <span class="badge rounded-pill <?= $tab === $key ? 'text-bg-light' : ($n > 0 ? 'text-bg-primary' : 'text-bg-secondary') ?> ms-1"
                title="<?= (int)$n ?> book<?= $n == 1 ? '' : 's' ?>"><?= (int)$n ?></span>
        <?php endif; ?>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<div class="alert alert-light border small d-flex gap-2 align-items-start mb-3">
  <i class="bi bi-info-circle-fill text-primary mt-1"></i>
  <div>
    <?php if ($tab === 'review'): ?>
      Each borrower appears once. Click <strong>View Books</strong> to decide <strong>every book separately</strong> —
      approving one never approves the others. <strong>Approve</strong> holds one copy for <?= BORROW_REQUEST_HOLD_HOURS ?> hours; the book is
      recorded as borrowed, with its due date, only when you <strong>release</strong> it from <em>Ready for Release</em>.
    <?php elseif ($tab === 'ready'): ?>
      Copies below are held for pickup. <strong>Release</strong> each book to record the loan and due date, or tick several books (or use <strong>Release all</strong>) to release them together — each still becomes its own loan. Holds not collected in time expire automatically.
    <?php elseif ($tab === 'queue'): ?>
      Members waiting for a book with no copy on the shelf. When a copy comes back it is held automatically for the first eligible member in line.
    <?php else: ?>
      Full record of every request, including who decided and why.
    <?php endif; ?>
  </div>
</div>

<div class="page-toolbar">
  <form class="d-flex flex-wrap gap-2 align-items-center" method="get">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <input type="search" name="q" class="form-control req-search" value="<?= h($search) ?>"
           placeholder="Search borrower, card no. or book" aria-label="Search borrower, card number or book">
    <?php if ($isRecord): ?>
      <select name="status" class="form-select req-status" onchange="this.form.submit()" aria-label="Filter by status">
        <option value="">Any status</option>
        <?php foreach (($tab === 'all' ? ['Pending','Approved'] : []) as $st): ?>
          <option value="<?= h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= h($st) ?></option>
        <?php endforeach; ?>
        <?php foreach (['Fulfilled','Rejected','Cancelled','Expired'] as $st): ?>
          <option value="<?= h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= h($st) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <input type="date" name="from" class="form-control req-date" value="<?= h($from) ?>" title="Requested from" aria-label="Requested from">
    <input type="date" name="to"   class="form-control req-date" value="<?= h($to) ?>"   title="Requested to"   aria-label="Requested to">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filter</button>
    <?php if ($hasFilter): ?>
      <a href="<?= h($tabLink($tab)) ?>" class="btn btn-link">Clear</a>
    <?php endif; ?>
  </form>
  <?php if (count($groups) > 1): ?>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleAllGroups" data-state="closed">
      <i class="bi bi-arrows-expand"></i> <span>Expand all</span>
    </button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?> borrower<?= $pg['total'] === 1 ? '' : 's' ?></div>
  <div class="table-responsive">
    <table class="table mb-0 align-middle req-table">
      <thead><tr>
        <th>Borrower</th>
        <th class="text-end">Total Books</th>
        <th class="d-none d-md-table-cell" title="Date the request was made">Borrow Date</th>
        <th>Status</th>
        <th class="text-end">Action</th>
      </tr></thead>

      <?php if (!$groups): ?>
        <tbody>
        <?php
        $emptyText = [
            'review'  => ['bi-inbox',           'No requests waiting for review', 'New Borrow Requests from members will appear here.'],
            'ready'   => ['bi-bag-check',       'Nothing is waiting for pickup',  'Approved requests and reservations with a held copy appear here.'],
            'queue'   => ['bi-hourglass-split', 'The reservation queue is empty', 'Members who reserve an unavailable book are listed here.'],
        ][$tab] ?? ['bi-search', 'No requests match your filters', 'Try a different search or date range.'];
        if ($hasFilter) $emptyText = ['bi-search', 'No borrowers match your filters', 'Try a different name, book or date range.'];
        echo empty_row(5, $emptyText[0], $emptyText[1], $emptyText[2]);
        ?>
        </tbody>
      <?php endif; ?>

      <?php foreach ($groups as $uid => $g):
          $rows      = $g['rows'];
          $first     = $rows[0];
          $bookTotal = array_sum(array_map(fn($r) => max(1, (int)$r['BookCount']), $rows));
          $statuses  = array_column($rows, 'ApprovalStatus');
          $overall   = borrower_overall_status($statuses);
          $nPendingBorrow = count(array_filter($rows, fn($r) => $r['ApprovalStatus'] === 'Pending' && $r['RequestType'] === 'Borrow'));
          $overallLabel = ($overall === 'Pending' && $nPendingBorrow > 1 && count(array_unique($statuses)) === 1)
                            ? 'Pending Global Approval' : $overall;
          $mix       = array_count_values($statuses);
          $dates     = array_column($rows, 'RequestDate'); sort($dates);
          $d1 = $dates[0]; $d2 = end($dates);
          $dateText  = date('Y-m-d', strtotime($d1)) === date('Y-m-d', strtotime($d2))
                         ? fmt_datetime($d1) : fmt_date($d1) . ' – ' . fmt_date($d2);
          $isOpen    = $openUid === $uid || count($groups) === 1;
          $gid       = 'grp-' . $uid;
          $name      = $first['FirstName'] . ' ' . $first['LastName'];
      ?>
      <tbody class="req-group<?= $isOpen ? ' is-open' : '' ?>">
        <tr class="req-group-row">
          <td>
            <a href="member_view.php?id=<?= h($uid) ?>" class="text-decoration-none fw-semibold"><?= h($name) ?></a>
            <div class="small text-muted"><?= h($first['MembershipNumber'] ?: '—') ?> · <?= membership_badge($first['MembershipStatus']) ?></div>
            <div class="small text-muted d-md-none mt-1"><?= h($dateText) ?></div>
          </td>
          <td class="text-end"><span class="fw-semibold fs-6"><?= (int)$bookTotal ?></span></td>
          <td class="small d-none d-md-table-cell"><?= h($dateText) ?></td>
          <td class="small">
            <?= status_badge($overallLabel) ?>
            <?php if (count($mix) > 1): ?>
              <div class="text-muted mt-1">
                <?php $parts = []; foreach ($mix as $st => $n) $parts[] = (int)$n . ' ' . strtolower($st); echo h(implode(' · ', $parts)); ?>
              </div>
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
              <?php
                $pendBorrow = array_values(array_filter($rows, fn($r) => $r['ApprovalStatus'] === 'Pending' && $r['RequestType'] === 'Borrow'
                                                                         && !empty($shelfCopies[(int)$r['FirstBookID']])));
                $heldRows  = array_values(array_filter($rows, fn($r) => $r['ApprovalStatus'] === 'Approved'));
                $bulkHeld  = ($canRelease || $canDecide) && count($heldRows) > 1;
                if ($canDecide && count($pendBorrow) > 1): ?>
              <div class="book-bulk d-flex flex-wrap align-items-center gap-2 px-3 pt-3 pb-1">
                <form method="post" data-confirm="Approve these <?= count($pendBorrow) ?> books for <?= h($name) ?>? Each book is approved on its own with its own held copy.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="approve_borrower">
                  <input type="hidden" name="user_id" value="<?= h($uid) ?>">
                  <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
                  <?php foreach ($pendBorrow as $pb): ?><input type="hidden" name="request_ids[]" value="<?= h($pb['RequestID']) ?>"><?php endforeach; ?>
                  <button class="btn btn-sm btn-success"><i class="bi bi-check2-all"></i> Approve all <?= count($pendBorrow) ?> books</button>
                </form>
                <span class="small text-muted">or decide each book below</span>
              </div>
              <?php endif; ?>
              <?php if ($canRelease && count($heldRows) > 1): ?>
              <div class="book-bulk d-flex flex-wrap align-items-center gap-2 px-3 pt-3 pb-1">
                <form method="post" data-confirm="Release these <?= count($heldRows) ?> books to <?= h($name) ?>? Each book is recorded as its own loan with its own due date.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="release_borrower">
                  <input type="hidden" name="user_id" value="<?= h($uid) ?>">
                  <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
                  <?php foreach ($heldRows as $hr): ?><input type="hidden" name="request_ids[]" value="<?= h($hr['RequestID']) ?>"><?php endforeach; ?>
                  <button class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-up-right"></i> Release all <?= count($heldRows) ?> books</button>
                </form>
                <span class="small text-muted">or decide each book below</span>
              </div>
              <?php endif; ?>
              <div class="book-list" role="list" aria-label="Books requested by <?= h($name) ?>">
                <div class="book-item book-head d-none d-md-grid" aria-hidden="true">
                  <div>Book</div><div></div><div>Borrow date / Due date</div><div>Status</div><div class="text-end">Action</div>
                </div>

                <?php foreach ($rows as $r):
                    $status   = $r['ApprovalStatus'];
                    $isPend   = $status === 'Pending';
                    $isHeld   = $status === 'Approved';
                    $bid      = (int)$r['FirstBookID'];
                    $copies   = $shelfCopies[$bid] ?? [];
                    $expiring = $isHeld && $r['ExpiresAt'] && strtotime($r['ExpiresAt']) - time() < 3600;
                    $deciderName = trim(($r['StaffFirst'] ?? '') . ' ' . ($r['StaffLast'] ?? ''));
                    $bookLabel = $r['Books'] ?: '—';
                ?>
                <div class="book-item<?= $expiring ? ' is-expiring' : '' ?>" role="listitem" id="request-<?= h($r['RequestID']) ?>">
                  <!-- book -->
                  <div class="book-main">
                    <div class="fw-semibold"><?= h($bookLabel) ?></div>
                    <div class="small text-muted">Request #<?= h($r['RequestID']) ?> · <?= request_type_badge($r['RequestType']) ?></div>
                    <?php if ($isHeld && $r['HeldAccession']): ?>
                      <div class="small text-primary"><i class="bi bi-bookmark-check"></i> Copy <?= h($r['HeldAccession']) ?></div>
                    <?php elseif ($isPend && $r['RequestType'] === 'Borrow'): ?>
                      <div class="small <?= $copies ? 'text-success' : 'text-muted' ?>">
                        <i class="bi <?= $copies ? 'bi-check-circle' : 'bi-hourglass' ?>"></i>
                        <?= $copies ? count($copies) . ' on the shelf' : 'No copy on the shelf' ?>
                      </div>
                    <?php endif; ?>
                  </div>

                  <!-- pick (bulk actions) -->
                  <div class="book-pick">
                    <?php if ($canDecide && $isPend && $r['RequestType'] === 'Borrow' && $copies && count($pendBorrow) > 1): ?>
                      <input type="checkbox" class="form-check-input pick-box" data-group="a-<?= h($uid) ?>" value="<?= h($r['RequestID']) ?>"
                             aria-label="Select “<?= h($bookLabel) ?>” for bulk actions">
                    <?php elseif ($isHeld && $bulkHeld): ?>
                      <input type="checkbox" class="form-check-input pick-box" data-group="r-<?= h($uid) ?>" value="<?= h($r['RequestID']) ?>"
                             aria-label="Select “<?= h($bookLabel) ?>” for bulk actions">
                    <?php endif; ?>
                  </div>

                  <!-- dates -->
                  <div class="book-dates small">
                    <div><span class="text-muted">Borrow date:</span> <?= fmt_datetime($r['RequestDate']) ?></div>
                    <div>
                      <span class="text-muted">Due date:</span>
                      <?php if ($r['LoanDueDate']): ?>
                        <?= fmt_date($r['LoanDueDate']) ?>
                      <?php elseif ($isPend || $isHeld): ?>
                        <span class="text-muted">set on release (<?= (int)($loanDays[$bid] ?? DEFAULT_LOAN_DAYS) ?> days)</span>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <!-- this book's own status -->
                  <div class="book-status small">
                    <?= status_badge($status) ?>
                    <?php if ($isPend && $r['RequestType'] === 'Reservation'): ?>
                      <div class="text-muted mt-1">Queue position <strong>#<?= reservation_queue_position((int)$r['RequestID']) ?></strong></div>
                    <?php elseif ($isHeld && $r['ExpiresAt']): ?>
                      <div class="mt-1 <?= $expiring ? 'text-danger fw-semibold' : 'text-muted' ?>">
                        Held until <?= fmt_datetime($r['ExpiresAt']) ?><?= $expiring ? ' — expiring soon' : '' ?>
                      </div>
                    <?php endif; ?>
                    <?php if (!$isPend && $r['ApprovalDate']): ?>
                      <div class="text-muted mt-1">
                        <?= fmt_date($r['ApprovalDate']) ?><?= $deciderName ? ' · ' . h($deciderName) : ($isHeld ? ' · automatic (queue)' : '') ?>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($r['DecisionReason']) && in_array($status, ['Rejected','Cancelled','Expired'], true)): ?>
                      <div class="text-muted fst-italic mt-1">“<?= h($r['DecisionReason']) ?>”</div>
                    <?php endif; ?>
                    <?php if ($r['LoanID']): ?>
                      <div class="mt-1"><a href="loans.php?view=<?= h($r['LoanID']) ?>">Loan #<?= h($r['LoanID']) ?></a></div>
                    <?php endif; ?>
                  </div>

                  <!-- this book's own actions -->
                  <div class="book-actions">
                    <?php if ($isPend && $canDecide && $r['RequestType'] === 'Borrow'): ?>
                      <?php if ($copies): ?>
                        <button type="button" class="btn btn-sm btn-success"
                                data-bs-toggle="modal" data-bs-target="#approveModal"
                                data-request="<?= h($r['RequestID']) ?>"
                                data-member="<?= h($name) ?>"
                                data-book="<?= h($bookLabel) ?>"
                                data-copies="<?= h(json_encode($copies)) ?>">
                          <i class="bi bi-check2-circle"></i> Approve
                        </button>
                      <?php else: ?>
                        <button class="btn btn-sm btn-outline-secondary" disabled title="No copy is on the shelf. Reject, or wait until one is returned.">
                          <i class="bi bi-hourglass-split"></i> No copy yet
                        </button>
                      <?php endif; ?>
                      <button type="button" class="btn btn-sm btn-outline-danger"
                              data-bs-toggle="modal" data-bs-target="#rejectModal"
                              data-request="<?= h($r['RequestID']) ?>"
                              data-member="<?= h($name) ?>"
                              data-book="<?= h($bookLabel) ?>">
                        <i class="bi bi-x-lg"></i> Reject
                      </button>

                    <?php elseif ($isHeld): ?>
                      <?php if ($canRelease): ?>
                        <a href="loans.php?request_id=<?= h($r['RequestID']) ?>" class="btn btn-sm btn-primary">
                          <i class="bi bi-box-arrow-up-right"></i> Release
                        </a>
                      <?php endif; ?>
                      <?php if ($canDecide): ?>
                        <form method="post" class="d-inline" data-confirm="Cancel this request? The held copy will be released.">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="cancel">
                          <input type="hidden" name="request_id" value="<?= h($r['RequestID']) ?>">
                          <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
                          <button class="btn btn-sm btn-outline-danger" title="Cancel request"><i class="bi bi-x-lg"></i> Cancel</button>
                        </form>
                      <?php endif; ?>

                    <?php elseif ($isPend && $canDecide && $r['RequestType'] === 'Reservation'): ?>
                      <form method="post" class="d-inline" data-confirm="Cancel this reservation? The member will lose their place in the queue.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="request_id" value="<?= h($r['RequestID']) ?>">
                        <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Cancel reservation"><i class="bi bi-x-lg"></i> Cancel</button>
                      </form>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>

                <?php if ($canDecide && count($pendBorrow) > 1): $gk = 'a-' . $uid; $n = count($pendBorrow); ?>
                <div class="book-foot" data-group="<?= h($gk) ?>">
                  <label class="form-check small mb-0 d-flex align-items-center gap-2 apply-all">
                    <input type="checkbox" class="form-check-input mt-0 pick-all" data-group="<?= h($gk) ?>"> Apply changes to all books.
                  </label>
                </div>
                <div class="book-bulkbar" data-group="<?= h($gk) ?>" data-uid="<?= h($uid) ?>" data-name="<?= h($name) ?>">
                  <div class="fw-semibold">Bulk Actions <span class="text-muted fw-normal">(<span class="bulk-count"><?= $n ?></span> items)</span></div>
                  <div class="d-flex flex-wrap align-items-center justify-content-center gap-2">
                    <form method="post" class="bulk-form d-inline" data-confirm=""
                          data-tpl="Approve {what} book(s) for <?= h($name) ?>? Each book is approved on its own with its own held copy.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="approve_borrower">
                      <input type="hidden" name="user_id" value="<?= h($uid) ?>">
                      <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
                      <span class="bulk-ids"></span>
                      <button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> <span class="bulk-label" data-verb="Approve">Approve all <?= $n ?> books</span></button>
                    </form>
                    <button type="button" class="btn btn-sm btn-outline-danger bulk-reject-btn" data-bs-toggle="modal" data-bs-target="#bulkRejectModal"
                            data-group="<?= h($gk) ?>" data-uid="<?= h($uid) ?>" data-name="<?= h($name) ?>">
                      <i class="bi bi-x-lg"></i> <span class="bulk-label" data-verb="Reject">Reject all <?= $n ?> books</span>
                    </button>
                  </div>
                </div>
                <?php endif; ?>

                <?php if ($bulkHeld): $gk = 'r-' . $uid; $n = count($heldRows); ?>
                <div class="book-foot" data-group="<?= h($gk) ?>">
                  <label class="form-check small mb-0 d-flex align-items-center gap-2 apply-all">
                    <input type="checkbox" class="form-check-input mt-0 pick-all" data-group="<?= h($gk) ?>"> Apply changes to all books.
                  </label>
                </div>
                <div class="book-bulkbar" data-group="<?= h($gk) ?>" data-uid="<?= h($uid) ?>" data-name="<?= h($name) ?>">
                  <div class="fw-semibold">Bulk Actions <span class="text-muted fw-normal">(<span class="bulk-count"><?= $n ?></span> items)</span></div>
                  <div class="d-flex flex-wrap align-items-center justify-content-center gap-2">
                    <?php if ($canRelease): ?>
                    <form method="post" class="bulk-form d-inline" data-confirm=""
                          data-tpl="Release {what} book(s) to <?= h($name) ?>? Each book is recorded as its own loan with its own due date.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="release_borrower">
                      <input type="hidden" name="user_id" value="<?= h($uid) ?>">
                      <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
                      <span class="bulk-ids"></span>
                      <button class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-up-right"></i> <span class="bulk-label" data-verb="Release">Release all <?= $n ?> books</span></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canDecide): ?>
                    <form method="post" class="bulk-form d-inline" data-confirm=""
                          data-tpl="Cancel {what} book(s) for <?= h($name) ?>? The held copies will be released.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="cancel_borrower">
                      <input type="hidden" name="user_id" value="<?= h($uid) ?>">
                      <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
                      <span class="bulk-ids"></span>
                      <button class="btn btn-sm btn-outline-danger bulk-reject-btn"><i class="bi bi-x-lg"></i> <span class="bulk-label" data-verb="Cancel">Cancel all <?= $n ?> books</span></button>
                    </form>
                    <?php endif; ?>
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

<?php if ($canDecide): ?>
<!-- REJECT several of one borrower's books (each rejected on its own, same reason) -->
<div class="modal fade" id="bulkRejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content" data-loading>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject_borrower">
      <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
      <input type="hidden" name="user_id" id="brj_user">
      <span id="brj_ids"></span>
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-x-circle"></i> Reject books</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small mb-3">Rejecting <strong id="brj_count">0</strong> book(s) for <strong id="brj_name"></strong>. Each book is rejected on its own; the same reason is shown for each.</p>
        <label class="form-label" for="brj_reason">Reason shown to the member <span class="text-danger">*</span></label>
        <textarea name="reason" id="brj_reason" class="form-control" rows="3" maxlength="255" required></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg"></i> Reject books</button>
      </div>
    </form>
  </div>
</div>

<?php endif; /* the bulk script below also serves release-only staff */ ?>
<script>
/* Bulk actions per borrower (Approve/Reject for pending books, Release/Cancel for held books).
   Ticked books are used if any are ticked, otherwise all of that group's books. */
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
    var what = ticked ? 'the ' + n + ' selected' : 'all ' + n;
    var ids = chosen(g);
    bar.querySelectorAll('.bulk-form').forEach(function (f) {
      f.setAttribute('data-confirm', f.dataset.tpl.replace('{what}', what));
      var holder = f.querySelector('.bulk-ids'); holder.innerHTML = '';
      ids.forEach(function (x) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'request_ids[]'; i.value = x.value; holder.appendChild(i); });
    });
  }
  document.querySelectorAll('.pick-box').forEach(function (x) { x.addEventListener('change', function () { refresh(x.dataset.group); }); });
  document.querySelectorAll('.pick-all').forEach(function (a) {
    a.addEventListener('change', function () { boxes(a.dataset.group).forEach(function (x) { x.checked = a.checked; }); refresh(a.dataset.group); });
  });
  document.querySelectorAll('.book-bulkbar').forEach(function (bar) { refresh(bar.dataset.group); });
  var m = document.getElementById('bulkRejectModal');
  if (m) m.addEventListener('show.bs.modal', function (ev) {
    var t = ev.relatedTarget, ids = chosen(t.dataset.group), holder = document.getElementById('brj_ids');
    holder.innerHTML = '';
    ids.forEach(function (x) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'request_ids[]'; i.value = x.value; holder.appendChild(i); });
    document.getElementById('brj_user').value = t.dataset.uid;
    document.getElementById('brj_count').textContent = ids.length;
    document.getElementById('brj_name').textContent = t.dataset.name;
    document.getElementById('brj_reason').value = '';
  });
});
</script>

<?php if ($canDecide): ?>
<!-- APPROVE one book: choose the copy to hold -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveTitle" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content" data-loading>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="approve">
      <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
      <input type="hidden" name="request_id" id="ap_request_id">
      <div class="modal-header">
        <h5 class="modal-title" id="approveTitle"><i class="bi bi-check2-circle"></i> Approve this book</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small mb-3">Hold a copy of <strong id="ap_book"></strong> for <strong id="ap_member"></strong>
           for <?= BORROW_REQUEST_HOLD_HOURS ?> hours. Their other books are not affected.</p>
        <label class="form-label" for="ap_copy">Copy to hold</label>
        <select name="copy_id" id="ap_copy" class="form-select" required></select>
        <div class="form-text">The copy is marked <strong>Reserved</strong>. It becomes <strong>Borrowed</strong> only when you release it.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle"></i> Approve &amp; hold</button>
      </div>
    </form>
  </div>
</div>

<!-- REJECT one book: a reason is required and shown to the member -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectTitle" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content" data-loading>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
      <input type="hidden" name="request_id" id="rj_request_id">
      <div class="modal-header">
        <h5 class="modal-title" id="rejectTitle"><i class="bi bi-x-circle"></i> Reject this book</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small mb-3">Rejecting <strong id="rj_book"></strong> for <strong id="rj_member"></strong>. Their other books are not affected.</p>
        <label class="form-label" for="rj_preset">Common reasons</label>
        <select id="rj_preset" class="form-select mb-2">
          <option value="">— choose or type your own below —</option>
          <option>The member has overdue books or unpaid fines.</option>
          <option>The member has reached the borrowing limit.</option>
          <option>This title is reserved for in-library use at this time.</option>
          <option>The copy is not available for loan.</option>
        </select>
        <label class="form-label" for="rj_reason">Reason shown to the member <span class="text-danger">*</span></label>
        <textarea name="reason" id="rj_reason" class="form-control" rows="3" maxlength="255" required></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg"></i> Reject book</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var ap = document.getElementById('approveModal');
  ap.addEventListener('show.bs.modal', function (ev) {
    var b = ev.relatedTarget;
    document.getElementById('ap_request_id').value = b.dataset.request;
    document.getElementById('ap_member').textContent = b.dataset.member;
    document.getElementById('ap_book').textContent = b.dataset.book;
    var sel = document.getElementById('ap_copy');
    sel.innerHTML = '';
    JSON.parse(b.dataset.copies).forEach(function (c) {
      var o = document.createElement('option'); o.value = c.CopyID; o.textContent = c.AccessionNumber; sel.appendChild(o);
    });
  });
  var rj = document.getElementById('rejectModal');
  rj.addEventListener('show.bs.modal', function (ev) {
    var b = ev.relatedTarget;
    document.getElementById('rj_request_id').value = b.dataset.request;
    document.getElementById('rj_member').textContent = b.dataset.member;
    document.getElementById('rj_book').textContent = b.dataset.book;
    document.getElementById('rj_reason').value = '';
    document.getElementById('rj_preset').value = '';
  });
  document.getElementById('rj_preset').addEventListener('change', function () {
    if (this.value) document.getElementById('rj_reason').value = this.value;
  });
});
</script>
<?php endif; ?>

<script>
/* View Books: button label follows the open / closed state of each borrower's list */
document.addEventListener('DOMContentLoaded', function () {
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
  var all = document.getElementById('toggleAllGroups');
  if (all) all.addEventListener('click', function () {
    var openIt = all.dataset.state === 'closed';
    panels.forEach(function (p) {
      var c = bootstrap.Collapse.getOrCreateInstance(p, { toggle: false });
      openIt ? c.show() : c.hide();
    });
    all.dataset.state = openIt ? 'open' : 'closed';
    all.querySelector('span').textContent = openIt ? 'Collapse all' : 'Expand all';
    all.querySelector('i').className = 'bi ' + (openIt ? 'bi-arrows-collapse' : 'bi-arrows-expand');
  });
  /* scroll the borrower we just worked on into view */
  var open = document.querySelector('.req-group.is-open');
  if (open && location.search.indexOf('open=') > -1) open.scrollIntoView({ block: 'nearest' });
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
