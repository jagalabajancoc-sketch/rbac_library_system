<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('process_in_library_use');
$page_title    = 'In-Library Use';
$page_subtitle = 'Books being read inside the library — each one approved by the librarian';
$currentUserId = (int)$_SESSION['user_id'];

if (!ilu_schema_ready()) {
    require __DIR__ . '/includes/layout_top.php';
    echo '<div class="alert alert-warning"><strong>One-time database update needed.</strong> Back up your database, then in phpMyAdmin select the '
       . '<code>' . h(DB_NAME) . '</code> database → Import → <code>database/migrate_self_service_kiosk.sql</code>. Then reload this page.</div>';
    require __DIR__ . '/includes/layout_bottom.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    /* Staff-assisted start (walk-ins, or a student who cannot use the kiosk). Students normally use kiosk.php. */
    if ($action === 'start') {
        require_permission('decide_use_requests');     // only the librarian may grant permission to use a book
        $type    = $_POST['borrower_type'] ?? 'Member';
        $codes   = preg_split('/[\s,;]+/', trim($_POST['accessions'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $loc     = trim($_POST['location'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        try {
            if (!$codes) throw new LibraryException('Enter or scan at least one accession number.');
            $mk  = implode(',', array_fill(0, count($codes), '?'));
            $map = [];
            foreach (fetch_all("SELECT CopyID, AccessionNumber FROM book_copy WHERE AccessionNumber IN ($mk)", $codes) as $r) {
                $map[strtoupper($r['AccessionNumber'])] = (int)$r['CopyID'];
            }
            $missing = array_filter($codes, fn($c) => !isset($map[strtoupper($c)]));
            if ($missing) throw new LibraryException('No matching copy found for: ' . implode(', ', array_slice($missing, 0, 5)) . '.');

            $memberId = null;
            if ($type === 'Member') {
                $m = ilu_find_member_by_card($_POST['student_id'] ?? '');
                if (!$m) throw new LibraryException('Student ID not found. Please check the ID and try again.');
                $memberId = (int)$m['UserID'];
            }
            $res = start_in_library_session(array_values($map), $type, $memberId,
                    trim($_POST['walk_in_name'] ?? '') ?: null, trim($_POST['walk_in_contact'] ?? '') ?: null,
                    $currentUserId, $loc ?: null, $remarks ?: null, 'Staff');
            log_activity('Create', 'In-Library Use', 'Staff-assisted in-library session ' . $res['ref'] . ' (' . count($res['items']) . ' book(s), ' . $type . ')', $res['items'][0]['id']);
            flash('success', 'In-library session started for ' . count($res['items']) . ' book(s).');
        } catch (Throwable $e) {
            flash('danger', 'Could not start session: ' . user_message($e));
        }
        redirect('in_library_use.php');
    }

    if ($action === 'return') {
        $id = (int)($_POST['session_id'] ?? 0);
        $remarks = trim($_POST['return_remarks'] ?? '');
        try {
            end_in_library_use($id, $currentUserId, $remarks ?: null);
            log_activity('Return', 'In-Library Use', "Marked in-library session #$id as ended", $id);
            flash('success', "Session #$id ended. The copy is available again.");
        } catch (Throwable $e) {
            flash('danger', 'Could not end session: ' . user_message($e));
        }
        redirect('in_library_use.php');
    }

    if ($action === 'update') {            // correct a record: reading area / remarks of an active session
        $id  = (int)($_POST['session_id'] ?? 0);
        $loc = mb_substr(trim($_POST['location'] ?? ''), 0, 100);
        $rem = mb_substr(trim($_POST['remarks'] ?? ''), 0, 255);
        try {
            $n = run("UPDATE in_library_use SET Location = ?, Remarks = ? WHERE InLibraryUseID = ? AND Status = 'Active'",
                     [$loc ?: null, $rem ?: null, $id])->rowCount();
            if (!$n) throw new LibraryException('Session not found or already ended.');
            log_activity('Update', 'In-Library Use', "Corrected in-library session #$id (area / remarks)", $id);
            flash('success', "Session #$id updated.");
        } catch (Throwable $e) {
            flash('danger', 'Could not update session: ' . user_message($e));
        }
        redirect('in_library_use.php');
    }
}

/* ---------- filters ---------- */
$qStudent = trim($_GET['q'] ?? '');
$qBook    = trim($_GET['book'] ?? '');
$typeF    = $_GET['type'] ?? '';
$flagF    = $_GET['flag'] ?? '';
$where = ["ilu.Status = 'Active'"]; $params = [];
if ($qStudent !== '') {
    $where[] = "(ua.MembershipNumber LIKE ? OR ua.FirstName LIKE ? OR ua.LastName LIKE ?
                 OR CONCAT(ua.FirstName,' ',ua.LastName) LIKE ? OR ilu.WalkInName LIKE ?)";
    $like = '%' . $qStudent . '%'; array_push($params, $like, $like, $like, $like, $like);
}
if ($qBook !== '') {
    $where[] = "(b.Title LIKE ? OR bc.AccessionNumber LIKE ?)";
    $like = '%' . $qBook . '%'; array_push($params, $like, $like);
}
if (in_array($typeF, ['Member', 'Walk-in'], true)) { $where[] = 'ilu.BorrowerType = ?'; $params[] = $typeF; }
if ($flagF === 'long') { $where[] = 'TIMESTAMPDIFF(MINUTE, ilu.StartTime, NOW()) >= ' . (IN_LIBRARY_USE_LONG_HOURS * 60); }
$whereSql = 'WHERE ' . implode(' AND ', $where);
$from = "FROM in_library_use ilu
         JOIN book_copy bc ON bc.CopyID = ilu.CopyID
         JOIN book b ON b.BookID = bc.BookID
         LEFT JOIN user_account ua ON ua.UserID = ilu.MemberID";

$total = (int) fetch_value("SELECT COUNT(*) $from $whereSql", $params);
$pg = pagination_calc($total, 15, pagination_page());

$sessions = fetch_all(
    "SELECT ilu.*, b.Title, bc.AccessionNumber,
            ua.FirstName AS BFirst, ua.LastName AS BLast, ua.UserID AS BUserID, ua.MembershipNumber,
            st.FirstName AS StFirst, st.LastName AS StLast,
            sh.ShelfCode, sec.SectionName, dfm.IdSlot AS FormSlot, dfm.IdStatus AS FormIdStatus,
            TIMESTAMPDIFF(MINUTE, ilu.StartTime, NOW()) AS MinutesElapsed,
            (SELECT COUNT(*) FROM in_library_use x WHERE x.SessionRef = ilu.SessionRef AND x.Status = 'Active') AS Siblings
       $from
       LEFT JOIN user_account st ON st.UserID = ilu.UserID
       LEFT JOIN shelf sh ON sh.ShelfID = bc.ShelfID
       LEFT JOIN section sec ON sec.SectionID = sh.SectionID
       LEFT JOIN in_library_form dfm ON dfm.FormNo = ilu.SessionRef
     $whereSql ORDER BY ilu.StartTime ASC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);

$sum = fetch_one(
    "SELECT COUNT(DISTINCT COALESCE(SessionRef, CONCAT('L', InLibraryUseID))) AS sessions,
            COUNT(*) AS copies,
            COUNT(DISTINCT COALESCE(CAST(MemberID AS CHAR), CONCAT('W:', WalkInName))) AS people
       FROM in_library_use WHERE Status = 'Active'");
$cntHist = (int) fetch_value("SELECT COUNT(*) FROM in_library_use WHERE Status = 'Returned'");

$pgQuery = $_GET;
$hasFilter = $qStudent !== '' || $qBook !== '' || $typeF !== '' || $flagF !== '';
require __DIR__ . '/includes/layout_top.php';
?>

<!-- Self-service status + staff-assisted start -->
<div class="card mb-3 ilu-banner">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="ilu-mode <?= KIOSK_ENABLED ? 'on' : 'off' ?>"><span class="dot"></span> Self-service request kiosk <?= KIOSK_ENABLED ? 'ON' : 'OFF' ?></span>
    <div class="flex-grow-1 small text-muted">
      Procedure: the student goes to the librarian, hands over an ID and fills in the form; the librarian <strong>keeps the ID until the books are back</strong>.
      Books issued at the desk show the ID slot.
    </div>
    <a href="desk_form.php" class="btn btn-primary btn-sm"><i class="bi bi-person-vcard"></i> Library Desk (read here &amp; borrow)</a>
    <?php if (has_permission('decide_use_requests')): ?>
      <a href="use_requests.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-patch-check"></i> Librarian Desk</a>
    <?php endif; ?>
    <a href="kiosk.php" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="bi bi-display"></i> Open request kiosk</a>
    <?php if (has_permission('decide_use_requests')): ?>
    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#assistWrap">
      <i class="bi bi-person-plus"></i> Walk-in / manual session
    </button>
    <?php endif; ?>
  </div>
  <div id="assistWrap" class="collapse">
    <form method="post" class="card-body border-top" id="assistForm" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="start">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label small fw-semibold mb-1">Borrower</label>
          <div class="btn-group w-100">
            <input type="radio" class="btn-check" name="borrower_type" id="bt_m" value="Member" checked>
            <label class="btn btn-outline-primary btn-sm" for="bt_m"><i class="bi bi-person-badge"></i> Member</label>
            <input type="radio" class="btn-check" name="borrower_type" id="bt_w" value="Walk-in">
            <label class="btn btn-outline-secondary btn-sm" for="bt_w"><i class="bi bi-person-walking"></i> Walk-in</label>
          </div>
        </div>
        <div class="col-md-8" id="asMember">
          <label class="form-label small fw-semibold mb-1" for="as_sid">Student ID</label>
          <input type="text" id="as_sid" name="student_id" class="form-control" maxlength="40" placeholder="Enter or scan Student ID">
        </div>
        <div class="col-md-8 d-none" id="asWalk">
          <div class="row g-2">
            <div class="col-sm-7"><label class="form-label small fw-semibold mb-1" for="as_wn">Visitor name</label>
              <input type="text" id="as_wn" name="walk_in_name" class="form-control" maxlength="150" placeholder="Full name"></div>
            <div class="col-sm-5"><label class="form-label small fw-semibold mb-1" for="as_wc">Contact / ID note</label>
              <input type="text" name="walk_in_contact" id="as_wc" class="form-control" maxlength="50" placeholder="Optional"></div>
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold mb-1" for="as_acc">Accession numbers / barcodes</label>
          <textarea id="as_acc" name="accessions" class="form-control" rows="2" required placeholder="Scan or type one or more, e.g. BK-00004-01 DEMO-2026-000007"></textarea>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold mb-1" for="as_loc">Reading area</label>
          <input type="text" id="as_loc" name="location" class="form-control" maxlength="100" list="locList" placeholder="Optional">
          <datalist id="locList"><?php foreach (KIOSK_LOCATIONS as $l): ?><option value="<?= h($l) ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold mb-1" for="as_rem">Remarks</label>
          <input type="text" id="as_rem" name="remarks" class="form-control" maxlength="255" placeholder="Optional">
        </div>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-3">
        <span class="small text-muted"><i class="bi bi-shield-check text-success"></i> Recorded under your approval. No home loan or due date is created.</span>
        <button class="btn btn-primary btn-sm" id="asBtn"><i class="bi bi-play-circle"></i> Start session</button>
      </div>
    </form>
  </div>
</div>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item"><a class="nav-link active" href="in_library_use.php"><i class="bi bi-hourglass-split"></i> Currently In Use
    <span class="badge text-bg-light border ms-1"><?= h($sum['copies']) ?></span></a></li>
  <li class="nav-item"><a class="nav-link" href="in_library_use_history.php"><i class="bi bi-clock-history"></i> History
    <span class="badge text-bg-light border ms-1"><?= h($cntHist) ?></span></a></li>
</ul>

<!-- Summary -->
<div class="row g-3 mb-3">
  <?php foreach ([
      ['bi-collection',   'Active sessions',          $sum['sessions'], 'primary'],
      ['bi-book-half',    'Physical copies in use',   $sum['copies'],   'success'],
      ['bi-people',       'Students using books',     $sum['people'],   'info'],
  ] as [$ic, $lb, $n, $tone]): ?>
    <div class="col-12 col-sm-4">
      <div class="card ilu-stat"><div class="card-body d-flex align-items-center gap-3">
        <span class="ilu-stat-ic text-bg-<?= $tone ?>"><i class="bi <?= $ic ?>"></i></span>
        <div><div class="fs-4 fw-bold lh-1"><?= (int)$n ?></div><div class="small text-muted"><?= h($lb) ?></div></div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-3"><label class="form-label small mb-1">Student ID or name</label>
        <input type="search" name="q" class="form-control form-control-sm" value="<?= h($qStudent) ?>" placeholder="Student ID or name"></div>
      <div class="col-md-3"><label class="form-label small mb-1">Book title or accession no.</label>
        <input type="search" name="book" class="form-control form-control-sm" value="<?= h($qBook) ?>" placeholder="Title or BK-…"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Borrower</label>
        <select name="type" class="form-select form-select-sm"><option value="">All</option>
          <option value="Member" <?= $typeF === 'Member' ? 'selected' : '' ?>>Member</option>
          <option value="Walk-in" <?= $typeF === 'Walk-in' ? 'selected' : '' ?>>Walk-in</option></select></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
        <select name="flag" class="form-select form-select-sm"><option value="">All active</option>
          <option value="long" <?= $flagF === 'long' ? 'selected' : '' ?>>Long (<?= (int)IN_LIBRARY_USE_LONG_HOURS ?>h+)</option></select></div>
      <div class="col-md-2 d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary flex-fill"><i class="bi bi-search"></i> Filter</button>
        <?php if ($hasFilter): ?><a class="btn btn-sm btn-outline-secondary" href="in_library_use.php" title="Clear filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
      </div>
    </form>
  </div>
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 ilu-table">
      <thead><tr>
        <th>Student ID</th><th>Borrower</th><th>Book</th><th>Copy ID</th><th>Location</th>
        <th>Started</th><th>Duration</th><th>Status</th><th class="text-end">Action</th>
      </tr></thead>
      <tbody>
      <?php if (!$sessions): ?>
        <?= empty_row(9, 'bi-book', $hasFilter ? 'No active sessions match the filters.' : 'No books are currently in use.') ?>
      <?php endif; ?>
      <?php foreach ($sessions as $s):
          $long = (int)$s['MinutesElapsed'] >= IN_LIBRARY_USE_LONG_HOURS * 60;
          $isMember = $s['BorrowerType'] === 'Member';
          $bName = $isMember ? trim($s['BFirst'] . ' ' . $s['BLast']) : $s['WalkInName'];
          $where_ = $s['Location'] ?: ($s['ShelfCode'] ? $s['ShelfCode'] . ' · ' . $s['SectionName'] : '');
          $detail = [
              'id' => (int)$s['InLibraryUseID'], 'student' => $bName, 'card' => $isMember ? $s['MembershipNumber'] : 'Walk-in',
              'title' => $s['Title'], 'copy' => $s['AccessionNumber'], 'location' => $s['Location'] ?: '',
              'shelf' => $s['ShelfCode'] ? $s['ShelfCode'] . ' · ' . $s['SectionName'] : '', 'remarks' => $s['Remarks'] ?: '',
              'started' => fmt_datetime($s['StartTime']), 'source' => $s['Source'] === 'Kiosk' ? 'Student self-service kiosk' : 'Staff-assisted' . ($s['StFirst'] ? ' (' . $s['StFirst'] . ' ' . $s['StLast'] . ')' : ''),
              'ref' => $s['SessionRef'] ?: '', 'siblings' => (int)$s['Siblings'], 'contact' => $s['WalkInContact'] ?: '',
          ];
      ?>
        <tr class="<?= $long ? 'table-warning' : '' ?>">
          <td class="small fw-semibold"><?= $isMember ? h($s['MembershipNumber'] ?: '—') : '<span class="text-muted">Walk-in</span>' ?></td>
          <td><?php if ($isMember && $s['BUserID']): ?><a href="member_view.php?id=<?= h($s['BUserID']) ?>" class="text-decoration-none fw-semibold"><?= h($bName) ?></a>
              <?php else: ?><span class="fw-semibold"><?= h($bName) ?></span><?php endif; ?>
              <?php if ($s['Source'] === 'Kiosk'): ?><span class="badge text-bg-light border ms-1" title="Registered by the student at the kiosk"><i class="bi bi-display"></i> Self</span><?php endif; ?>
              <?php if (!empty($s['FormSlot'])): ?><a href="desk_form.php" class="badge text-bg-primary text-decoration-none ms-1" title="The librarian is holding this student's ID"><i class="bi bi-person-vcard"></i> ID · slot <?= h($s['FormSlot']) ?></a><?php endif; ?></td>
          <td class="fw-semibold"><?= h($s['Title']) ?></td>
          <td class="small"><?= h($s['AccessionNumber']) ?></td>
          <td class="small"><?= $where_ !== '' ? h($where_) : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= fmt_datetime($s['StartTime']) ?></td>
          <td class="small"><span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle live-dur"
                data-start="<?= (int)strtotime($s['StartTime']) ?>"><?= h(format_duration($s['StartTime'])) ?></span></td>
          <td class="small"><?= $long ? '<span class="badge text-bg-warning"><i class="bi bi-exclamation-triangle"></i> Long session</span>' : '<span class="badge text-bg-primary">In use</span>' ?></td>
          <td class="text-end text-nowrap">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-detail='<?= h(json_encode($detail, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
              <i class="bi bi-eye"></i> View Details</button>
            <form method="post" class="d-inline" data-confirm="End this in-library session? The copy becomes available again.">
              <?= csrf_field() ?><input type="hidden" name="action" value="return"><input type="hidden" name="session_id" value="<?= h($s['InLibraryUseID']) ?>">
              <button class="btn btn-sm btn-success"><i class="bi bi-stop-circle"></i> End</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['total_pages'] > 1): ?><div class="card-footer"><?= pagination_links($pg, $pgQuery) ?></div><?php endif; ?>
</div>

<!-- Details / correction -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="detailTitle">Session details</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="session_id" id="dId">
      <div class="modal-body">
        <dl class="row small mb-3">
          <dt class="col-4 text-muted">Student</dt><dd class="col-8" id="dStudent"></dd>
          <dt class="col-4 text-muted">Student ID</dt><dd class="col-8" id="dCard"></dd>
          <dt class="col-4 text-muted">Book</dt><dd class="col-8"><span id="dTitle" class="fw-semibold"></span><br><span class="text-muted">Copy <span id="dCopy"></span><span id="dShelf"></span></span></dd>
          <dt class="col-4 text-muted">Started</dt><dd class="col-8" id="dStarted"></dd>
          <dt class="col-4 text-muted">Duration</dt><dd class="col-8" id="dDur"></dd>
          <dt class="col-4 text-muted">Registered via</dt><dd class="col-8" id="dSource"></dd>
          <dt class="col-4 text-muted">Session</dt><dd class="col-8" id="dRef"></dd>
        </dl>
        <div class="row g-2">
          <div class="col-sm-5"><label class="form-label small fw-semibold mb-1" for="dLoc">Reading area</label>
            <input class="form-control form-control-sm" name="location" id="dLoc" maxlength="100" list="locList"></div>
          <div class="col-sm-7"><label class="form-label small fw-semibold mb-1" for="dRem">Remarks</label>
            <input class="form-control form-control-sm" name="remarks" id="dRem" maxlength="255"></div>
        </div>
        <div class="form-text">Correcting the area or remarks does not change who is using the book. To fix a wrong book or student, end this session and register it again.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-primary"><i class="bi bi-check2"></i> Save corrections</button></div>
    </form>
  </div></div>
</div>

<script>
/* runs after the page and Bootstrap (loaded at the bottom of the layout) are ready */
document.addEventListener('DOMContentLoaded', function () {
  /* live durations (server time offset so a wrong PC clock cannot distort them) */
  var skew = <?= time() ?> - Math.floor(Date.now() / 1000);
  function fmt(sec) { sec = Math.max(0, sec); var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60); return h ? h + 'h ' + m + 'm' : m + 'm'; }
  function tick() { document.querySelectorAll('.live-dur').forEach(function (e) { e.textContent = fmt(Math.floor(Date.now() / 1000) + skew - parseInt(e.dataset.start, 10)); }); }
  tick(); setInterval(tick, 30000);

  var modal = new bootstrap.Modal(document.getElementById('detailModal'));
  function $(i) { return document.getElementById(i); }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-detail]'); if (!b) return;
    var d = JSON.parse(b.dataset.detail), tr = b.closest('tr');
    $('dId').value = d.id; $('dStudent').textContent = d.student + (d.contact ? ' · ' + d.contact : ''); $('dCard').textContent = d.card;
    $('dTitle').textContent = d.title; $('dCopy').textContent = d.copy; $('dShelf').textContent = d.shelf ? ' · ' + d.shelf : '';
    $('dStarted').textContent = d.started; $('dDur').textContent = tr.querySelector('.live-dur').textContent;
    $('dSource').textContent = d.source; $('dRef').textContent = d.ref ? d.ref + (d.siblings > 1 ? ' · ' + d.siblings + ' books in this session' : '') : 'Single record';
    $('dLoc').value = d.location; $('dRem').value = d.remarks;
    modal.show();
  });

  function sync() {
    var m = document.getElementById('bt_m').checked;
    $('asMember').classList.toggle('d-none', !m); $('asWalk').classList.toggle('d-none', m);
    $('as_sid').required = m; $('as_wn').required = !m;
  }
  ['bt_m', 'bt_w'].forEach(function (i) { $(i).addEventListener('change', sync); }); sync();
  $('assistForm').addEventListener('submit', function () { var b = $('asBtn'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Starting…'; });
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
