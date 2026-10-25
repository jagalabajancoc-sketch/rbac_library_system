<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('view_library_entries');
$page_title    = 'Library Entry';
$page_subtitle = 'Attendance — who scanned in at the entrance, and scans that were turned away';
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
    try {
        if ($action === 'manual_entry') {          // e.g. the scanner is down or the ID card was forgotten
            $r = entry_record_scan((string)($_POST['student_id'] ?? ''), 'Staff', $staffId);
            if ($r['ok']) {
                log_activity('Create', 'Library Entry', ($r['already'] ? 'Entry already recorded for ' : 'Manual entry recorded for ') . $r['name'], $r['entry_id']);
                flash($r['already'] ? 'info' : 'success', $r['name'] . ($r['already'] ? ' already has attendance recorded today.' : ' — attendance recorded at ' . date('g:i A') . '.'));
            } else {
                flash('danger', $r['message']);
            }
        }
    } catch (Throwable $e) {
        flash('danger', user_message($e, 'The action could not be completed.'));
    }
    redirect('library_visits.php' . (!empty($_POST['return_qs']) ? '?' . ltrim((string)$_POST['return_qs'], '?') : ''));
}

$tab    = 'log';   // attendance only: one list, no "inside now" / time-out tracking
$q      = trim($_GET['q'] ?? '');
$from   = $_GET['from'] ?? date('Y-m-d');
$to     = $_GET['to'] ?? date('Y-m-d');
$resF   = $_GET['result'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$sum = fetch_one(
    "SELECT SUM(Result = 'Allowed') AS allowed_today,
            SUM(Result = 'Denied') AS denied_today
       FROM library_entry WHERE DATE(EntryTime) = CURDATE()");

$where = []; $params = [];
$where[] = 'DATE(le.EntryTime) BETWEEN ? AND ?'; array_push($params, $from, $to);
if (in_array($resF, ['Allowed', 'Denied'], true)) { $where[] = 'le.Result = ?'; $params[] = $resF; }
if ($q !== '') {
    $where[] = "(ua.MembershipNumber LIKE ? OR ua.FirstName LIKE ? OR ua.LastName LIKE ? OR CONCAT(ua.FirstName,' ',ua.LastName) LIKE ?)";
    $like = "%$q%"; array_push($params, $like, $like, $like, $like);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$from_ = "FROM library_entry le LEFT JOIN user_account ua ON ua.UserID = le.MemberID";
$total = (int) fetch_value("SELECT COUNT(*) $from_ $whereSql", $params);
$pg = pagination_calc($total, 20, pagination_page());
$rows = fetch_all(
    "SELECT le.*, ua.FirstName, ua.LastName, ua.MembershipNumber
       $from_ $whereSql ORDER BY le.EntryTime DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
$pgQuery = $_GET;
$returnQs = http_build_query($_GET);
require __DIR__ . '/includes/layout_top.php';
?>

<div class="row g-3 mb-3">
  <?php foreach ([
    ['bi-box-arrow-in-right', 'Attendance today',         (int)$sum['allowed_today'], 'primary'],
    ['bi-shield-exclamation', 'Turned away today',        (int)$sum['denied_today'],  'danger'],
  ] as [$ic, $lb, $n, $tone]): ?>
    <div class="col-12 col-sm-6"><div class="card ilu-stat"><div class="card-body d-flex align-items-center gap-3">
      <span class="ilu-stat-ic text-bg-<?= $tone ?>"><i class="bi <?= $ic ?>"></i></span>
      <div><div class="fs-4 fw-bold lh-1"><?= $n ?></div><div class="small text-muted"><?= h($lb) ?></div></div>
    </div></div></div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="ilu-mode <?= ENTRY_GATE_ENABLED ? 'on' : 'off' ?>"><span class="dot"></span> Entrance scanner <?= ENTRY_GATE_ENABLED ? 'ON' : 'OFF' ?></span>
    <a href="entry.php" target="_blank" rel="noopener" class="btn btn-primary btn-sm"><i class="bi bi-display"></i> Open entrance screen</a>
    <form method="post" class="d-flex gap-2 ms-auto flex-wrap" autocomplete="off" data-loading>
      <?= csrf_field() ?><input type="hidden" name="action" value="manual_entry"><input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
      <input type="text" name="student_id" class="form-control form-control-sm" style="min-width:230px" maxlength="40" required
             placeholder="Manual entry: scan / type Student ID" aria-label="Student ID for manual entry">
      <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-person-check"></i> Record entry</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <div class="col-md-4"><label class="form-label small mb-1">Student ID or name</label>
        <input type="search" name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="Student ID or name"></div>
      <?php if (true): ?>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= h($from) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= h($to) ?>"></div>
        <div class="col-md-2"><label class="form-label small mb-1">Result</label>
          <select name="result" class="form-select form-select-sm"><option value="">All</option>
            <option value="Allowed" <?= $resF === 'Allowed' ? 'selected' : '' ?>>Allowed</option>
            <option value="Denied" <?= $resF === 'Denied' ? 'selected' : '' ?>>Turned away</option></select></div>
      <?php endif; ?>
      <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-search"></i> Filter</button></div>
    </form>
  </div>
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?>
</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 ilu-table">
      <thead><tr><th>Date &amp; time in</th><th>Student ID</th><th>Name</th><th>Result</th><th>Recorded</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><?= empty_row(5, 'bi-door-open', 'No attendance matches the filters.') ?><?php endif; ?>
      <?php foreach ($rows as $r):
          $denied = $r['Result'] === 'Denied';
      ?>
        <tr class="<?= $denied ? 'table-danger' : '' ?>">
          <td class="small"><?= fmt_datetime($r['EntryTime']) ?></td>
          <td class="small fw-semibold"><?= $r['MembershipNumber'] ? h($r['MembershipNumber']) : '<span class="text-muted">Unregistered</span>' ?></td>
          <td><?php if ($r['MemberID']): ?><a class="text-decoration-none fw-semibold" href="member_view.php?id=<?= h($r['MemberID']) ?>"><?= h($r['FirstName'] . ' ' . $r['LastName']) ?></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
          <td class="small"><?= $denied ? '<span class="badge text-bg-danger" title="' . h($r['DenyReason']) . '">Turned away</span> <span class="text-muted">' . h($r['DenyReason']) . '</span>' : '<span class="badge text-bg-primary">Allowed</span>' ?></td>
          <td class="small text-muted"><?= $r['Method'] === 'Staff' ? 'Staff' : 'ID scanner' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['total_pages'] > 1): ?><div class="card-footer"><?= pagination_links($pg, $pgQuery) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
