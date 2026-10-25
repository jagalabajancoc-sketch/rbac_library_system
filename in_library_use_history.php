<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('view_in_library_use_history');
$page_title    = 'In-Library Use History';
$page_subtitle = 'Past in-library reading sessions';

$search   = trim($_GET['q'] ?? '');
$statusF  = $_GET['status'] ?? '';
$typeF    = $_GET['type'] ?? '';
$fromDate = trim($_GET['from'] ?? '');
$toDate   = trim($_GET['to'] ?? '');

$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = "(b.Title LIKE ? OR bc.AccessionNumber LIKE ?
                 OR ua.FirstName LIKE ? OR ua.LastName LIKE ? OR ilu.WalkInName LIKE ?
                 OR ua.MembershipNumber LIKE ? OR CONCAT(ua.FirstName, ' ', ua.LastName) LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}
if ($statusF !== '' && in_array($statusF, ['Active','Returned'], true)) {
    $where[] = 'ilu.Status = ?'; $params[] = $statusF;
}
if ($typeF !== '' && in_array($typeF, ['Member','Walk-in'], true)) {
    $where[] = 'ilu.BorrowerType = ?'; $params[] = $typeF;
}
if ($fromDate !== '') { $where[] = 'DATE(ilu.StartTime) >= ?'; $params[] = $fromDate; }
if ($toDate   !== '') { $where[] = 'DATE(ilu.StartTime) <= ?'; $params[] = $toDate;   }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int) fetch_value(
    "SELECT COUNT(*)
       FROM in_library_use ilu
       JOIN book_copy bc ON bc.CopyID = ilu.CopyID
       JOIN book b       ON b.BookID  = bc.BookID
       LEFT JOIN user_account ua ON ua.UserID = ilu.MemberID
     $whereSql", $params
);
$pg = pagination_calc($total, PER_PAGE_DEFAULT, pagination_page());

$rows = fetch_all(
    "SELECT ilu.*,
            b.Title, bc.AccessionNumber,
            ua.FirstName AS BorrowerFirst, ua.LastName AS BorrowerLast, ua.UserID AS BorrowerUserID, ua.MembershipNumber,
            st.FirstName AS StaffFirst, st.LastName AS StaffLast,
            rs.FirstName AS RetStaffFirst, rs.LastName AS RetStaffLast,
            sh.ShelfCode, sec.SectionName
       FROM in_library_use ilu
       JOIN book_copy bc ON bc.CopyID = ilu.CopyID
       JOIN book b       ON b.BookID  = bc.BookID
       LEFT JOIN user_account ua ON ua.UserID = ilu.MemberID
       LEFT JOIN user_account st ON st.UserID = ilu.UserID
       LEFT JOIN user_account rs ON rs.UserID = ilu.ReturnedByUserID
       LEFT JOIN shelf sh    ON sh.ShelfID    = bc.ShelfID
       LEFT JOIN section sec ON sec.SectionID = sh.SectionID
     $whereSql
     ORDER BY ilu.InLibraryUseID DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params
);

$pgQuery = $_GET;
require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link" href="in_library_use.php">
      <i class="bi bi-hourglass-split"></i> Currently In Use
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link active" href="in_library_use_history.php">
      <i class="bi bi-clock-history"></i> History
    </a>
  </li>
</ul>

<div class="card mb-3 p-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-md-3">
      <label class="form-label small mb-1">Search</label>
      <input type="search" name="q" class="form-control" value="<?= h($search) ?>"
             placeholder="Student ID, name, title, accession…" oninput="debH(this.form)">
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1">Status</label>
      <select name="status" class="form-select" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="Active"   <?= $statusF === 'Active'   ? 'selected' : '' ?>>Active</option>
        <option value="Returned" <?= $statusF === 'Returned' ? 'selected' : '' ?>>Completed</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1">Borrower</label>
      <select name="type" class="form-select" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="Member"  <?= $typeF === 'Member'  ? 'selected' : '' ?>>Member</option>
        <option value="Walk-in" <?= $typeF === 'Walk-in' ? 'selected' : '' ?>>Walk-in</option>
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
    <div class="col-md-1 d-grid">
      <a href="in_library_use_history.php" class="btn btn-outline-secondary">
        <i class="bi bi-x-lg"></i>
      </a>
    </div>
  </form>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>#</th><th>Student ID</th><th>Borrower</th><th>Book / Copy ID</th><th>Location</th>
          <th>Started</th><th>Ended</th><th>Duration</th><th>Recorded by</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <?= empty_row(10, 'bi-clock-history', 'No sessions match the filters.') ?>
        <?php endif; ?>
        <?php foreach ($rows as $r):
          $bName = $r['BorrowerType'] === 'Member'
                 ? ($r['BorrowerFirst'] . ' ' . $r['BorrowerLast'])
                 : $r['WalkInName'];
        ?>
          <tr>
            <td class="fw-semibold">#<?= h($r['InLibraryUseID']) ?></td>
            <td class="small fw-semibold"><?= $r['BorrowerType'] === 'Member' ? h($r['MembershipNumber'] ?: '—') : '<span class="badge text-bg-secondary">Walk-in</span>' ?></td>
            <td>
              <?php if ($r['BorrowerType'] === 'Member' && $r['BorrowerUserID']): ?>
                <a href="member_view.php?id=<?= h($r['BorrowerUserID']) ?>"><?= h($bName) ?></a>
              <?php else: ?>
                <?= h($bName) ?>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold"><?= h($r['Title']) ?></div>
              <div class="small text-muted">Copy <?= h($r['AccessionNumber']) ?></div>
            </td>
            <td class="small"><?= ($r['Location'] ?? '') !== '' ? h($r['Location']) : ($r['ShelfCode'] ? h($r['ShelfCode']) : '<span class="text-muted">—</span>') ?></td>
            <td class="small"><?= fmt_datetime($r['StartTime']) ?></td>
            <td class="small">
              <?= $r['ReturnTime'] ? fmt_datetime($r['ReturnTime']) : '<span class="text-muted">—</span>' ?>
            </td>
            <td class="small"><?= h(format_duration($r['StartTime'], $r['ReturnTime'])) ?></td>
            <td class="small">
              <?php if (($r['Source'] ?? 'Staff') === 'Kiosk'): ?><span class="badge text-bg-light border"><i class="bi bi-display"></i> Self-service</span>
              <?php else: ?><?= h(trim($r['StaffFirst'] . ' ' . $r['StaffLast'])) ?><?php endif; ?>
              <?php if ($r['ReturnTime']): ?>
                <div class="text-muted">ended by <?= (($r['ReturnedByUserID'] ?? null) && $r['ReturnedByUserID'] == ($r['MemberID'] ?? 0)) ? 'student' : h(trim($r['RetStaffFirst'] . ' ' . $r['RetStaffLast'])) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['Status'] === 'Active'): ?>
                <span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle">In Use</span>
              <?php else: ?>
                <span class="badge text-bg-success">Completed</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['total_pages'] > 1): ?>
    <div class="card-footer"><?= pagination_links($pg, $pgQuery) ?></div>
  <?php endif; ?>
</div>

<script>
var _debH;
function debH(form){ clearTimeout(_debH); _debH = setTimeout(function(){ form.submit(); }, 450); }
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>