<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('view_activity_log');
$page_title    = 'Activity Log';
$page_subtitle = 'Audit trail of user actions';

$search   = trim($_GET['q'] ?? '');
$userId   = $_GET['user_id'] ?? '';
$fromDate = trim($_GET['from'] ?? '');
$toDate   = trim($_GET['to'] ?? '');

$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(al.Description LIKE ? OR al.ActionType LIKE ? OR ua.FirstName LIKE ? OR ua.LastName LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($userId !== '')   { $where[] = 'al.UserID = ?';           $params[] = $userId; }
if ($fromDate !== '') { $where[] = 'DATE(al.CreatedAt) >= ?'; $params[] = $fromDate; }
if ($toDate   !== '') { $where[] = 'DATE(al.CreatedAt) <= ?'; $params[] = $toDate;   }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int) fetch_value(
    "SELECT COUNT(*) FROM activity_log al
       LEFT JOIN user_account ua ON ua.UserID = al.UserID
     $whereSql",
    $params
);
$pg = pagination_calc($total, PER_PAGE_DEFAULT, pagination_page());

$rows = fetch_all(
    "SELECT al.*, ua.FirstName, ua.LastName, r.RoleName
       FROM activity_log al
       LEFT JOIN user_account ua ON ua.UserID = al.UserID
       LEFT JOIN role         r  ON r.RoleID  = ua.RoleID
     $whereSql
     ORDER BY al.ActivityLogID DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$userOptions = fetch_all(
    "SELECT UserID AS id, CONCAT(FirstName, ' ', LastName) AS label
       FROM user_account ORDER BY LastName, FirstName"
);

require __DIR__ . '/includes/layout_top.php';
?>

<div class="card mb-3 p-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-md-4">
      <label class="form-label small mb-1">Search</label>
      <input type="search" name="q" class="form-control" value="<?= h($search) ?>"
             placeholder="Description, user, action…" oninput="debAct(this.form)">
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-1">User</label>
      <select name="user_id" class="form-select" onchange="this.form.submit()">
        <option value="">All Users</option>
        <?php foreach ($userOptions as $u): ?>
          <option value="<?= h($u['id']) ?>" <?= (string)$userId === (string)$u['id'] ? 'selected' : '' ?>>
            <?= h($u['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1">From</label>
      <input type="date" name="from" class="form-control"
             value="<?= h($fromDate) ?>" onchange="this.form.submit()">
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1">To</label>
      <input type="date" name="to" class="form-control"
             value="<?= h($toDate) ?>" onchange="this.form.submit()">
    </div>
    <?php if ($search !== '' || $userId !== '' || $fromDate !== '' || $toDate !== ''): ?>
      <div class="col-md-1 d-grid">
        <a href="activity_log.php" class="btn btn-outline-secondary" title="Clear">
          <i class="bi bi-x-lg"></i>
        </a>
      </div>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted">
    <?= pagination_info($pg) ?> <span class="ms-2">· newest first · read-only</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Date / Time</th>
          <th>User</th>
          <th>Module</th>
          <th>Action</th>
          <th>Description</th>
          <th class="text-center">Record</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No activity matches the current filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="small text-muted text-nowrap"><?= fmt_datetime($r['CreatedAt']) ?></td>
            <td class="small">
              <?php if ($r['FirstName']): ?>
                <div class="fw-semibold"><?= h($r['FirstName'] . ' ' . $r['LastName']) ?></div>
                <div class="text-muted"><?= h($r['RoleName'] ?: '—') ?></div>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><span class="badge text-bg-light border"><?= h(borrowing_activity_text($r['Module'])) ?></span></td>
            <td>
              <span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle">
                <?= h($r['ActionType']) ?>
              </span>
            </td>
            <td class="small"><?= h(borrowing_activity_text($r['Description'])) ?></td>
            <td class="text-center small text-muted">
              <?= $r['RecordID'] ? '#' . h($r['RecordID']) : '—' ?>
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

<script>
var _debAL;
function debAct(form){ clearTimeout(_debAL); _debAL = setTimeout(function(){ form.submit(); }, 450); }
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>