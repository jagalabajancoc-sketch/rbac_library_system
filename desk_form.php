<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('process_in_library_use');
require_once __DIR__.'/includes/reading_sessions.php';
$page_title    = 'Library Desk';
$page_subtitle = 'One form for reading here and borrowing — the ID is kept only for books read in the library';
$staffId   = (int)$_SESSION['user_id'];
$canRead   = IN_LIBRARY_USE_ENABLED && has_permission('decide_use_requests');   // only the librarian grants permission to read here (ID custody)
$canIssue  = $canRead || has_permission('process_loans');                        // assistants may still issue borrow-only forms

/* ---------- read-only lookups used by the form (JSON) ---------- */
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (!ilu_schema_ready() || !desk_schema_ready() || !desk_borrow_ready()) throw new LibraryException('Database update needed.');
        $q = trim((string)($_GET['q'] ?? ''));
        if ($_GET['api'] === 'member') { echo json_encode(desk_member_lookup($q)); }
        elseif ($_GET['api'] === 'copy') { echo json_encode(ilu_search_copies($q, 8)); }
        else { http_response_code(400); echo json_encode(['error' => 'Unknown request']); }
    } catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => user_message($e)]); }
    exit;
}

if (!ilu_schema_ready() || !desk_schema_ready() || !desk_borrow_ready()) {
    $missing = [];
    if (!ilu_schema_ready())   $missing[] = 'database/migrate_self_service_kiosk.sql';
    if (!desk_schema_ready())  $missing[] = 'database/migrate_id_custody.sql';
    if (!desk_borrow_ready()) { $missing[] = 'database/migrate_desk_borrow.sql'; $missing[] = 'database/migrate_release1.sql'; }
    require __DIR__ . '/includes/layout_top.php';
    echo '<div class="alert alert-warning"><strong>One-time database update needed.</strong> Back up your database, then in phpMyAdmin select the '
       . '<code>' . h(DB_NAME) . '</code> database → Import, in this order, only the ones still missing: <ol class="mb-1 mt-2">';
    foreach ($missing as $f) echo '<li><code>' . h($f) . '</code></li>';
    echo '</ol>Make sure each import finishes without a red error, then reload this page. Teachers need '
       . '<code>database/migrate_desk_teacher.sql</code> as well, but it is not required for this page to open.</div>';
    require __DIR__ . '/includes/layout_bottom.php';
    exit;
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'issue') {
        $wantsRead = false;
        foreach ((array)($_POST['copy_ids'] ?? []) as $cid) { if ((($_POST['use'][$cid] ?? 'Read')) !== 'Borrow') $wantsRead = true; }
        require_permission($wantsRead ? 'decide_use_requests' : 'process_loans');
        try {
            $token = is_string($_POST['issue_token'] ?? null) ? $_POST['issue_token'] : '';
            if (!isset($_SESSION['desk_tokens'][$token])) throw new LibraryException('This form has expired. Review the restored entries and try again.');
            $res = desk_form_issue([
                'issue_token' => $token,
                'borrower_type' => $_POST['borrower_type'] ?? 'Member', 'student_id' => $_POST['student_id'] ?? '',
                'walk_in_name' => $_POST['walk_in_name'] ?? '', 'walk_in_contact' => $_POST['walk_in_contact'] ?? '',
                'id_type' => $_POST['id_type'] ?? '', 'id_number' => $_POST['id_number'] ?? '',
                'verify_method' => $_POST['verify_method'] ?? 'Typed', 'photo_checked' => !empty($_POST['photo_checked']),
                'copy_ids' => $_POST['copy_ids'] ?? [], 'use' => (array)($_POST['use'] ?? []), 'cond_out' => $_POST['cond_out'] ?? [],
                'purpose' => $_POST['purpose'] ?? 'Reading', 'purpose_note' => $_POST['purpose_note'] ?? '',
                'days' => (array)($_POST['days'] ?? []), 'location' => $_POST['location'] ?? '', 'ack' => !empty($_POST['ack']), 'slot' => $_POST['slot'] ?? '',
            ], $staffId);
            unset($_SESSION['desk_draft']);
            $msg = 'Form ' . $res['form_no'] . ' issued.';
            if ($res['slot'] !== null) $msg .= ' Keep the ID in storage slot ' . $res['slot'] . '.';
            if ($res['borrowed']) $msg .= ' ' . count($res['borrowed']) . ' book(s) borrowed (Transaction #' . $res['loan_id'] . ') — no ID kept for those.';
            flash('success', $msg, false);
            redirect('desk_form.php?slip=' . urlencode($res['form_no']));
        } catch (Throwable $e) {
            $_SESSION['desk_draft'] = array_intersect_key($_POST, array_flip(['borrower_type','student_id','walk_in_name','walk_in_contact','id_type','id_number','verify_method','photo_checked','copy_ids','use','cond_out','purpose','purpose_note','days','location','ack','slot','mode','issue_token']));
            flash('danger', 'Form not issued. Your entries have been kept: ' . user_message($e));
            redirect('desk_form.php');
        }
    }

    if ($action === 'return') {
        flash('warning','Open Manage session to use the updated return controls.');
        redirect('desk_form.php');
    }

}

$draft = $_SESSION['desk_draft'] ?? [];
unset($_SESSION['desk_draft']);
$_SESSION['desk_tokens'] = array_filter($_SESSION['desk_tokens'] ?? [], fn($issued) => $issued > time()-7200);
$issueToken = is_string($draft['issue_token'] ?? null) && isset($_SESSION['desk_tokens'][$draft['issue_token']]) ? $draft['issue_token'] : bin2hex(random_bytes(32));
$_SESSION['desk_tokens'][$issueToken] = time();
$_SESSION['desk_tokens'] = array_slice($_SESSION['desk_tokens'], -20, null, true);
$draftItems = [];
$draftIds = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($draft['copy_ids'] ?? []))))),0,60);
if ($draftIds) {
    foreach (ilu_copy_rows('bc.CopyID IN ('.implode(',',$draftIds).')', [], 60) as $row) {
        $state = ilu_copy_state($row);
        $id=(int)$row['CopyID'];
        $draftItems[] = ['copyId'=>$id, 'bookId'=>(int)$row['BookID'], 'title'=>$row['Title'], 'accession'=>$row['AccessionNumber'],
            'shelf'=>$row['ShelfCode']??'', 'ok'=>$state['ok'], 'label'=>$state['label'],
            'borrowable'=>(bool)$row['IsBorrowable'], 'loanDays'=>resolve_loan_days((int)$row['BookID']),
            'use'=>($draft['use'][$id]??'Read')==='Borrow'?'Borrow':'Read', 'days'=>(int)($draft['days'][$id]??resolve_loan_days((int)$row['BookID'])),
            'condition'=>in_array($draft['cond_out'][$id]??'', ['Good','Fair','Damaged'],true)?$draft['cond_out'][$id]:'Good'];
    }
}

/* ---------- data ---------- */
$tab  = ($_GET['tab'] ?? '') === 'history' ? 'history' : 'desk';
$held = desk_forms_held();
$booksOut = array_sum(array_map(fn($f) => $f['Active'], $held));
$pastClosing = count(array_filter($held, fn($f) => $f['PastClosing']));
$freeSlots = desk_free_slots();
$cntHistory = (int) fetch_value("SELECT COUNT(*) FROM in_library_form WHERE Status = 'Closed'");
$slip = isset($_GET['slip']) ? desk_form_get((string)$_GET['slip']) : null;
$nextSlot = $canIssue ? desk_next_slot() : null;

$hist = []; $pg = null; $q = trim($_GET['q'] ?? '');
if ($tab === 'history') {
    $where = ["f.Status = 'Closed'"]; $params = [];
    if ($q !== '') {
        $where[] = "(f.FormNo LIKE ? OR f.IdNumber LIKE ? OR f.WalkInName LIKE ? OR CONCAT(ua.FirstName,' ',ua.LastName) LIKE ?)";
        $like = '%' . $q . '%'; array_push($params, $like, $like, $like, $like);
    }
    $from = "FROM in_library_form f LEFT JOIN user_account ua ON ua.UserID = f.MemberID
             LEFT JOIN user_account iss ON iss.UserID = f.IssuedByUserID LEFT JOIN user_account rb ON rb.UserID = f.IdReturnedByUserID
             WHERE " . implode(' AND ', $where);
    $pg = pagination_calc((int) fetch_value("SELECT COUNT(*) $from", $params), 15, pagination_page());
    $hist = fetch_all(
        "SELECT f.*, ua.FirstName, ua.LastName, iss.FirstName AS IssFirst, iss.LastName AS IssLast, rb.FirstName AS RetFirst, rb.LastName AS RetLast,
                (SELECT GROUP_CONCAT(b.Title ORDER BY b.Title SEPARATOR '; ') FROM in_library_use x JOIN book_copy c ON c.CopyID = x.CopyID
                   JOIN book b ON b.BookID = c.BookID WHERE x.SessionRef = f.FormNo) AS Titles,
                (SELECT COUNT(*) FROM in_library_use x WHERE x.SessionRef = f.FormNo) AS Books,
                (SELECT COUNT(*) FROM loan_detail x2 WHERE x2.LoanID = f.LoanID) AS Borrowed
         $from ORDER BY COALESCE(f.IdReturnedAt, f.IssuedAt) DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
}

require __DIR__ . '/includes/layout_top.php';
?>
<style>
  .dk-slot{min-width:3.4rem;height:3.4rem;border-radius:.75rem;display:grid;place-items:center;font-weight:700;font-size:1.25rem;
           background:var(--bs-primary-bg-subtle,#e7f1ff);color:var(--bs-primary-text-emphasis,#052c65);border:1px solid var(--bs-primary-border-subtle,#9ec5fe)}
  .dk-slot small{display:block;font-size:.55rem;font-weight:600;letter-spacing:.04em;margin-top:-.35rem;text-transform:uppercase;opacity:.75}
  .dk-held{border-left:4px solid var(--bs-primary,#0d6efd)} .dk-held.late{border-left-color:var(--bs-danger,#dc3545)} .dk-held.done{border-left-color:var(--bs-success,#198754)}
  .dk-step{display:flex;gap:.6rem;align-items:center;font-weight:700} .dk-step i{width:1.6rem;height:1.6rem;border-radius:50%;display:grid;place-items:center;
           background:var(--bs-primary,#0d6efd);color:#fff;font-size:.8rem;font-style:normal}
  .dk-res{max-height:240px;overflow:auto} .dk-res button{text-align:left}
  .dk-who{border:1px dashed var(--bs-border-color,#dee2e6);border-radius:.6rem;padding:.6rem .8rem}
  #slipPrint{display:none}
  @media print{ body *{visibility:hidden} #slipPrint,#slipPrint *{visibility:visible} #slipPrint{display:block;position:absolute;inset:0;padding:1.2rem;color:#000;background:#fff} }
</style>

<?php if ($pastClosing > 0): ?>
  <div class="alert alert-danger d-flex gap-2 align-items-center py-2"><i class="bi bi-alarm fs-5"></i>
    <div><strong><?= (int)$pastClosing ?> ID<?= $pastClosing > 1 ? 's are' : ' is' ?> still held after closing time (<?= h(date('g:i A', strtotime(DESK_CLOSING_TIME))) ?>).</strong>
    Find the borrower, or follow your library’s rule for unclaimed IDs.</div></div>
<?php endif; ?>

<ul class="nav nav-pills mb-3 gap-2">
  <li class="nav-item"><a class="nav-link <?= $tab === 'desk' ? 'active' : '' ?>" href="desk_form.php"><i class="bi bi-person-vcard"></i> Desk
      <span class="badge text-bg-light border ms-1"><?= count($held) ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>" href="desk_form.php?tab=history"><i class="bi bi-clock-history"></i> Form history
      <span class="badge text-bg-light border ms-1"><?= $cntHistory ?></span></a></li>
  <li class="nav-item ms-auto"><a class="nav-link border" href="in_library_use.php"><i class="bi bi-book-half"></i> Books in use</a></li>
</ul>

<div class="row g-3 mb-3">
  <?php foreach ([['bi-person-vcard', 'IDs held now', count($held), 'primary'], ['bi-book-half', 'Books out', $booksOut, 'success'],
                  ['bi-alarm', 'Held past closing', $pastClosing, $pastClosing ? 'danger' : 'secondary'], ['bi-grid-3x3-gap', 'Free ID storage slots', $freeSlots, 'info']] as [$ic, $lb, $n, $tone]): ?>
    <div class="col-6 col-lg-3"><div class="card ilu-stat"><div class="card-body d-flex align-items-center gap-3">
      <span class="ilu-stat-ic text-bg-<?= $tone ?>"><i class="bi <?= $ic ?>"></i></span>
      <div><div class="fs-4 fw-bold lh-1"><?= (int)$n ?></div><div class="small text-muted"><?= h($lb) ?></div></div></div></div></div>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'history'): ?>
<div class="card">
  <div class="px-3 py-2 border-bottom"><form method="get" class="row g-2 align-items-end"><input type="hidden" name="tab" value="history">
    <div class="col-md-5"><label class="form-label small mb-1">Borrower, ID number or form number</label>
      <input type="search" name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="Search"></div>
    <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-outline-secondary flex-fill"><i class="bi bi-search"></i> Filter</button>
      <?php if ($q !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="desk_form.php?tab=history"><i class="bi bi-x-lg"></i></a><?php endif; ?></div></form></div>
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Form</th><th>Borrower</th><th>Books</th><th>ID storage slot</th><th>Issued</th><th>ID returned</th><th>Time</th><th>Note</th></tr></thead><tbody>
    <?php if (!$hist) echo empty_row(8, 'bi-person-vcard', $q !== '' ? 'No forms match.' : 'No closed forms yet.'); ?>
    <?php foreach ($hist as $r): $who = $r['BorrowerType'] === 'Member' ? trim($r['FirstName'] . ' ' . $r['LastName']) : $r['WalkInName']; ?>
      <tr>
        <td class="small fw-semibold"><?= h($r['FormNo']) ?></td>
        <td><div class="fw-semibold"><?= h($who) ?></div><div class="small text-muted"><?= h($r['IdType']) ?> · <?= h($r['IdNumber']) ?></div></td>
        <td class="small"><?php if ((int)$r['Books']): ?><span class="badge text-bg-light border"><i class="bi bi-book"></i> <?= (int)$r['Books'] ?> read</span><?php endif; ?> <?php if ((int)$r['Borrowed']): ?><span class="badge text-bg-warning"><i class="bi bi-box-arrow-up-right"></i> <?= (int)$r['Borrowed'] ?> borrowed<?= $r['LoanID'] ? ' · Transaction #' . (int)$r['LoanID'] : '' ?></span><?php endif; ?> <?= h(mb_strimwidth((string)$r['Titles'], 0, 60, '…')) ?></td>
        <td><?= $r['IdSlot'] !== null && $r['IdSlot'] !== '' ? '<span class="badge text-bg-secondary">' . h($r['IdSlot']) . '</span>' : '<span class="text-muted small">no ID kept</span>' ?></td>
        <td class="small"><?= fmt_datetime($r['IssuedAt']) ?><div class="text-muted">by <?= h(trim($r['IssFirst'] . ' ' . $r['IssLast'])) ?></div></td>
        <td class="small"><?= $r['IdReturnedAt'] ? fmt_datetime($r['IdReturnedAt']) . '<div class="text-muted">by ' . h(trim($r['RetFirst'] . ' ' . $r['RetLast'])) . '</div>' : '—' ?></td>
        <td class="small"><?= $r['IdReturnedAt'] ? h(format_duration($r['IssuedAt'], $r['IdReturnedAt'])) : '—' ?></td>
        <td class="small text-muted"><?= h($r['IdReturnNote'] ?: '—') ?></td>
      </tr><?php endforeach; ?></tbody></table></div>
  <?php if ($pg['total_pages'] > 1): ?><div class="card-footer"><?= pagination_links($pg, $_GET) ?></div><?php endif; ?>
</div>

<?php else: ?>
<div class="row g-3">
  <!-- ===================== NEW FORM ===================== -->
  <div class="col-xl-7">
  <?php if ($canIssue): ?>
    <form method="post" id="deskForm" class="card" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="issue">
      <input type="hidden" name="issue_token" value="<?= h($issueToken) ?>">
      <div class="card-header bg-transparent"><div class="dk-step"><i class="bi bi-1-circle-fill" style="background:none;color:inherit"></i> New desk form — read here and/or borrow</div></div>
      <div class="card-body">
        <div class="btn-group w-100 mb-3" role="group" aria-label="Form type">
          <input type="radio" class="btn-check" name="mode" id="md_b" value="Borrow" checked>
          <label class="btn btn-outline-warning fw-semibold" for="md_b"><i class="bi bi-box-arrow-up-right"></i> Borrow — take the book home</label>
          <input type="radio" class="btn-check" name="mode" id="md_r" value="Read" <?= $canRead ? '' : 'disabled' ?>>
          <label class="btn btn-outline-primary fw-semibold" for="md_r"><i class="bi bi-book"></i> Read here — ID is kept</label>
        </div>
        <!-- 1 borrower + ID -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div class="dk-step"><i>1</i> <span id="whoLbl">Borrower details</span></div>
          <div class="btn-group btn-group-sm"><input type="radio" class="btn-check" name="borrower_type" id="bt_m" value="Member" checked>
            <label class="btn btn-outline-primary" for="bt_m"><i class="bi bi-person-badge"></i> Registered borrower</label>
            <input type="radio" class="btn-check" name="borrower_type" id="bt_w" value="Walk-in">
            <label class="btn btn-outline-secondary" id="bt_w_lbl" for="bt_w"><i class="bi bi-person-walking"></i> Walk-in borrower</label></div>
        </div>
        <div id="pMember">
          <label class="form-label small fw-semibold mb-1" for="sid">Borrower ID (scan the card or type the number)</label>
          <div class="input-group"><input type="text" id="sid" name="student_id" class="form-control" maxlength="40" placeholder="School or employee ID number" autofocus>
            <button type="button" class="btn btn-outline-primary" id="sidGo"><i class="bi bi-search"></i> Check</button></div>
          <div id="sidRes" class="mt-2" role="status" aria-live="polite"></div>
        </div>
        <div id="pWalk" class="row g-2 d-none">
          <div class="col-sm-6"><label class="form-label small fw-semibold mb-1" for="wn">Visitor name</label><input class="form-control" id="wn" name="walk_in_name" maxlength="150"></div>
          <div class="col-sm-6"><label class="form-label small fw-semibold mb-1" for="wc">Contact (optional)</label><input class="form-control" id="wc" name="walk_in_contact" maxlength="100"></div>
          <div class="col-sm-6"><label class="form-label small fw-semibold mb-1" for="wt">ID being left</label>
            <select class="form-select" id="wt" name="id_type"><?php foreach (DESK_WALKIN_ID_TYPES as $t): ?><option><?= h($t) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-6"><label class="form-label small fw-semibold mb-1" for="wi">ID number</label><input class="form-control" id="wi" name="id_number" maxlength="60"></div>
        </div>
        <div class="row g-2 mt-1 align-items-center">
          <div class="col-sm-5"><label class="form-label small fw-semibold mb-1" for="vm">How was the ID read?</label>
            <select class="form-select form-select-sm" id="vm" name="verify_method"><option value="Scanned">Scanned (barcode / QR)</option><option value="Typed" selected>Typed in</option><option value="Visual">Looked at only</option></select></div>
          <div class="col-sm-7"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="photo" name="photo_checked" value="1" required>
            <label class="form-check-label small" for="photo">I compared the photo and name on the ID with the person at the desk.</label></div></div>
        </div>
        <hr>
        <!-- 2 books -->
        <div class="dk-step mb-2"><i>2</i> Books</div>
        <label class="form-label small fw-semibold mb-1" for="bk">Scan the accession number, or type a title — <span id="bkHint">the days borrowed and the return date are set for each book</span></label>
        <input type="text" id="bk" class="form-control" maxlength="80" placeholder="BK-00004-01 or part of the title">
        <div id="bkRes" class="list-group mt-2 dk-res" role="status" aria-live="polite"></div>
        <div id="bkList" class="mt-2"></div>
        <div id="bkEmpty" class="small text-muted mt-1">No books added yet.</div>
        <div class="form-text text-primary"><i class="bi bi-info-circle"></i> Borrowing period: <strong><?= (int)DESK_MIN_LOAN_DAYS ?> to <?= (int)DESK_MAX_LOAN_DAYS ?> days only</strong>. The due date cannot be set beyond <?= (int)DESK_MAX_LOAN_DAYS ?> days.</div>
        <div id="bkWarn" class="alert alert-warning py-2 small mt-2 mb-0 d-none" role="alert"></div>
        <div id="bkSummary" class="small text-muted mt-2"></div>
        <hr>
        <!-- 3 purpose (only for books read here) -->
        <div id="readBlock" class="d-none">
        <div class="dk-step mb-2"><i>3</i> Reading details <span class="small text-muted fw-normal">(for the books read here)</span></div>
        <div class="row g-2">
          <div class="col-sm-4"><label class="form-label small fw-semibold mb-1" for="pu">Purpose</label>
            <select class="form-select" id="pu" name="purpose"><?php foreach (DESK_PURPOSES as $p): ?><option><?= h($p) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-4"><label class="form-label small fw-semibold mb-1" for="lo">Reading area (optional)</label>
            <input class="form-control" id="lo" name="location" maxlength="100" list="dkLoc"><datalist id="dkLoc"><?php foreach (KIOSK_LOCATIONS as $l): ?><option value="<?= h($l) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-sm-4"><label class="form-label small fw-semibold mb-1" for="pn">Note (optional)</label><input class="form-control" id="pn" name="purpose_note" maxlength="255"></div>
        </div>
        <hr>
        </div>
        <!-- 4 rules + ID storage slot -->
        <div class="dk-step mb-2"><i>4</i> Rules<span id="slotLbl"> and ID storage slot</span></div>
        <div id="rulesRead" class="d-none"><div class="small fw-semibold">Reading in the library</div>
          <ul class="small mb-2 ps-3"><?php foreach (DESK_RULES as $r): ?><li><?= h($r) ?></li><?php endforeach; ?></ul></div>
        <div id="rulesBorrow" class="d-none"><div class="small fw-semibold">Borrowing</div>
          <ul class="small mb-2 ps-3"><?php foreach (DESK_BORROW_RULES as $r): ?><li><?= h($r) ?></li><?php endforeach; ?></ul></div>
        <div class="row g-2 align-items-center">
          <div class="col-sm-8"><div class="form-check"><input class="form-check-input" type="checkbox" id="ack" name="ack" value="1" required>
            <label class="form-check-label small fw-semibold" for="ack">The borrower has read and accepts these rules.</label></div></div>
          <div class="col-sm-4" id="slotBlock"><div class="input-group input-group-sm"><label class="input-group-text" for="slot">ID storage slot</label>
            <input class="form-control" name="slot" id="slot" value="<?= h((string)$nextSlot) ?>" maxlength="20" title="The numbered compartment where the physical ID is kept. The next free storage slot is suggested."></div></div>
        </div>
      </div>
      <div class="card-footer bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="small text-muted" id="footNote"><i class="bi bi-shield-check text-success"></i> Add the books first.</span>
        <button class="btn btn-primary" id="issueBtn"><i class="bi bi-person-vcard"></i> <span id="issueLbl">Issue form</span></button>
      </div>
    </form>
  <?php else: ?>
    <div class="card"><div class="card-body text-muted"><i class="bi bi-lock"></i> Only a librarian can issue a form. You can take books back and hand IDs back from the list.</div></div>
  <?php endif; ?>
  </div>

  <!-- ===================== IDs HELD ===================== -->
  <div class="col-xl-5">
    <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0"><i class="bi bi-person-vcard"></i> IDs in the librarian’s custody</h2>
      <span class="small text-muted"><?= count($held) ?> held</span></div>
    <div class="small text-muted mb-2">Only books <em>read here</em> keep the ID. Borrowed books go home with the borrower and are taken back on the <a href="returns.php">Returns</a> page.</div>
    <?php if (!$held): ?>
      <div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-inboxes fs-1"></i><div class="fw-semibold mt-2">No IDs are being held.</div>
        <div class="small">When a form is issued, the borrower’s ID appears here until the books are returned.</div></div></div>
    <?php endif; ?>
    <?php $readingOrigin='desk_form.php';foreach($held as $f):$readingCard=reading_context('form:'.$f['FormID']);require __DIR__.'/includes/reading_session_card.php';endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($slip): $sl = $slip; $hasId = !empty($sl['IdSlot']); ?>
<!-- ===================== PRINTABLE SLIP ===================== -->
<div class="modal fade" id="slipModal" tabindex="-1" aria-labelledby="slipTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="slipTitle"><i class="bi bi-check-circle-fill text-success"></i> Form issued</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body text-center">
      <?php if ($hasId): ?>
        <div class="small text-muted">Keep the ID in</div><div class="dk-slot mx-auto my-1" style="width:5rem;height:5rem;font-size:2rem"><div><?= h($sl['IdSlot']) ?><small>ID storage</small></div></div>
      <?php else: ?>
        <div class="alert alert-success py-2 small"><i class="bi bi-person-check"></i> <strong>No ID is kept</strong> — everything on this form is borrowed.</div>
      <?php endif; ?>
      <div class="fw-semibold"><?= h($sl['Borrower']) ?></div>
      <div class="small text-muted"><?= h($sl['FormNo']) ?> · <?= count($sl['Items']) ?> read here · <?= count($sl['Borrowed']) ?> borrowed</div>
      <?php if ($sl['Borrowed']): ?>
        <div class="text-start small mt-3"><div class="fw-semibold mb-1"><i class="bi bi-calendar-check"></i> Borrowed — return by</div>
          <?php foreach ($sl['Borrowed'] as $bi): ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= h($bi['Title']) ?> <span class="text-muted">(<?= (int)$bi['LoanDays'] ?> days)</span></span><strong><?= h(date('M d, Y', strtotime($bi['DueDate']))) ?></strong></div><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($hasId && $sl['ExpectedReturnAt']): ?><div class="small mt-2">Return the books read here before <strong><?= h(date('g:i A', strtotime($sl['ExpectedReturnAt']))) ?></strong> to get the ID back.</div><?php endif; ?>
    </div>
    <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print slip for the borrower</button></div>
  </div></div>
</div>
<div id="slipPrint">
  <h2 style="margin:0">Library Desk Slip</h2><div style="color:#444">Form <?= h($sl['FormNo']) ?> · <?= h(fmt_datetime($sl['IssuedAt'])) ?></div><hr>
  <p><strong>Name:</strong> <?= h($sl['Borrower']) ?><br>
     <?php if ($hasId): ?><strong>ID held:</strong> <?= h($sl['IdType']) ?> — <?= h($sl['IdNumber']) ?> (ID storage slot <?= h($sl['IdSlot']) ?>)<br><strong>Purpose:</strong> <?= h($sl['Purpose']) ?><?= $sl['Location'] ? ' · ' . h($sl['Location']) : '' ?><br><?php endif; ?>
     <strong>Issued by:</strong> <?= h(trim($sl['IssFirst'] . ' ' . $sl['IssLast'])) ?></p>
  <?php if ($sl['Items']): ?>
  <h3 style="margin:.6rem 0 .2rem">Read here — return to the desk before closing</h3>
  <table style="width:100%;border-collapse:collapse" border="1" cellpadding="6"><tr><th align="left">Book</th><th align="left">Copy</th><th>Condition</th></tr>
    <?php foreach ($sl['Items'] as $i): ?><tr><td><?= h($i['Title']) ?></td><td><?= h($i['AccessionNumber']) ?></td><td align="center"><?= h($i['ConditionOut']) ?></td></tr><?php endforeach; ?></table>
  <?php if ($sl['ExpectedReturnAt']): ?><p><strong>Return before <?= h(date('g:i A', strtotime($sl['ExpectedReturnAt']))) ?>.</strong></p><?php endif; ?>
  <ul><?php foreach (DESK_RULES as $r): ?><li><?= h($r) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if ($sl['Borrowed']): ?>
  <h3 style="margin:.6rem 0 .2rem">Borrowed — take home (no ID kept)</h3>
  <table style="width:100%;border-collapse:collapse" border="1" cellpadding="6"><tr><th align="left">Book</th><th align="left">Copy</th><th>Days</th><th>Return on or before</th></tr>
    <?php foreach ($sl['Borrowed'] as $bi): ?><tr><td><?= h($bi['Title']) ?></td><td><?= h($bi['AccessionNumber']) ?></td><td align="center"><?= (int)$bi['LoanDays'] ?></td><td align="center"><strong><?= h(date('M d, Y', strtotime($bi['DueDate']))) ?></strong></td></tr><?php endforeach; ?></table>
  <ul><?php foreach (DESK_BORROW_RULES as $r): ?><li><?= h($r) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <p style="margin-top:2rem">Borrower signature: ______________________ &nbsp;&nbsp; Librarian: ______________________</p>
</div>
<?php endif; ?>

<div class="modal fade" id="deskReview" tabindex="-1" aria-labelledby="deskReviewTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="deskReviewTitle">Review desk transaction</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p id="reviewWho" class="fw-semibold"></p><div id="reviewBooks"></div><p id="reviewCustody" class="mt-3 mb-0"></p></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back to editing</button><button type="button" class="btn btn-primary" id="confirmDesk">Confirm and issue receipt</button></div>
  </div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var $ = function (i) { return document.getElementById(i); };
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; };

  /* live durations (server time offset) */
  var skew = <?= time() ?> - Math.floor(Date.now() / 1000);
  function fmt(sec) { sec = Math.max(0, sec); var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60); return h ? h + 'h ' + m + 'm' : m + 'm'; }
  function tick() { document.querySelectorAll('.live-dur').forEach(function (e) { e.textContent = fmt(Math.floor(Date.now() / 1000) + skew - parseInt(e.dataset.start, 10)); }); }
  tick(); setInterval(tick, 30000);

  <?php if ($slip): ?>new bootstrap.Modal($('slipModal')).show();<?php endif; ?>

  if (!$('deskForm')) return;

  var READ_OK = <?= $canRead ? 'true' : 'false' ?>;
  var mode = 'Borrow', chosen = {}, borrowInfo = { blocked: null, left: -1, max: 0, borrowed: 0 }, kind = 'Student';
  var MIN_D = <?= (int)DESK_MIN_LOAN_DAYS ?>, MAX_D = <?= (int)DESK_MAX_LOAN_DAYS ?>;
  var MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  function dueOn(days) { var d = new Date(<?= json_encode(date('Y-m-d').'T12:00:00') ?>); d.setDate(d.getDate() + days); return MON[d.getMonth()] + ' ' + (d.getDate() < 10 ? '0' : '') + d.getDate() + ', ' + d.getFullYear(); }

  /* ---- form type: Borrow (take home, no ID kept) or Read here (ID kept) ---- */
  function setMode() {
    mode = (READ_OK && $('md_r').checked) ? 'Read' : 'Borrow';
    var b = mode === 'Borrow';
    $('bt_w_lbl').classList.toggle('d-none', b);            /* visitors cannot borrow */
    if (b) { $('bt_m').checked = true; }
    $('bkHint').textContent = b ? 'the days borrowed and the return date are set for each book' : 'the librarian keeps the ID until the book is back';
    var dropped = [];
    Object.keys(chosen).forEach(function (k) {
      var c = chosen[k];
      if (b && borrowReason(c)) { dropped.push(c.title); delete chosen[k]; } else { c.use = mode; }
    });
    sync();
    if (dropped.length) { $('bkWarn').textContent = 'Removed (cannot be borrowed): ' + dropped.join(', '); $('bkWarn').classList.remove('d-none'); }
  }
  ['md_b', 'md_r'].forEach(function (i) { $(i).addEventListener('change', setMode); });

  /* ---- borrower / visitor switch ---- */
  function sync() {
    var m = $('bt_m').checked;
    $('pMember').classList.toggle('d-none', !m); $('pWalk').classList.toggle('d-none', m);
    $('sid').required = m; $('wn').required = !m; $('wi').required = !m;
    if (!m) { Object.keys(chosen).forEach(function (k) { chosen[k].use = 'Read'; }); }
    if (!m && mode === 'Borrow') { $('md_r').checked = READ_OK; mode = READ_OK ? 'Read' : 'Borrow'; }
    renderList();
    $('vm').value = m ? 'Typed' : 'Visual'; $('vm').disabled = !m;
  }
  ['bt_m', 'bt_w'].forEach(function (i) { $(i).addEventListener('change', sync); }); sync();

  /* ---- verify the borrower ID ---- */
  var studentOk = false, borrowerName = '', lookupSequence = 0;
  function lookup() {
    var requestId = ++lookupSequence;
    var q = $('sid').value.trim(); studentOk = false; kind = 'Student'; borrowInfo = { blocked: null, left: -1, max: 0, borrowed: 0 }; if (q.length < 3) { $('sidRes').innerHTML = ''; renderList(); return; }
    $('sidRes').innerHTML = '<div class="small text-muted"><span class="spinner-border spinner-border-sm"></span> Checking…</div>';
    fetch('desk_form.php?api=member&q=' + encodeURIComponent(q), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { if (!r.ok || r.redirected) throw new Error('Lookup failed'); return r.json(); }).then(function (d) {
      if (requestId !== lookupSequence || $('sid').value.trim() !== q) return;
      borrowerName = d.name || '';
      if (d.error) { $('sidRes').innerHTML = '<div class="alert alert-danger py-2 mb-0 small">' + esc(d.error) + '</div>'; return; }
      if (!d.found) { $('sidRes').innerHTML = '<div class="alert alert-danger py-2 mb-0 small"><i class="bi bi-x-octagon"></i> This ID is not registered. Use “Walk-in borrower” for an unregistered visitor.</div>'; return; }
      studentOk = !d.blocked; kind = d.kind === 'Teacher' ? 'Teacher' : 'Student';
      borrowInfo = { blocked: d.borrowBlocked || null, left: d.maxBooks > 0 ? d.borrowLeft : -1, max: d.maxBooks, borrowed: d.borrowed };
      var w = (d.warnings || []).map(function (x) { return '<div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> ' + esc(x) + '</div>'; }).join('');
      $('sidRes').innerHTML = '<div class="dk-who ' + (d.blocked ? 'border-danger' : 'border-success') + '"><div class="fw-semibold">' + esc(d.name) +
        ' <span class="badge text-bg-' + (d.blocked ? 'danger' : 'success') + '">' + (d.blocked ? 'Cannot proceed' : 'Registered') + '</span> <span class="badge text-bg-' + (kind === 'Teacher' ? 'info' : 'secondary') + '">' + kind + '</span></div>' +
        '<div class="small text-muted">' + esc(d.card) + ' · ' + esc(d.status) + (d.entryToday ? ' · scanned in ' + esc(d.entryToday) : '') + '</div>' +
        (d.blocked ? '<div class="small text-danger mt-1">' + esc(d.blocked) + '</div>' : '') + w +
        (!d.blocked ? (d.borrowBlocked ? '<div class="small text-danger mt-1"><i class="bi bi-slash-circle"></i> Cannot borrow: ' + esc(d.borrowBlocked) + ' (reading here is still possible)</div>'
          : '<div class="small text-success mt-1"><i class="bi bi-box-arrow-up-right"></i> Can borrow ' + (d.maxBooks > 0 ? d.borrowLeft + ' more book(s) (has ' + d.borrowed + ' of ' + d.maxBooks + ')' : 'books') + '</div>') : '') + '</div>';
      renderList();
    }).catch(function () { if (requestId !== lookupSequence) return; $('sidRes').innerHTML = '<div class="alert alert-danger py-2 mb-0 small">Could not check the ID. Try again.</div>'; });
  }
  $('sidGo').addEventListener('click', lookup);
  $('sid').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); lookup(); $('bk').focus(); } });
  $('sid').addEventListener('change', lookup);
  $('sid').addEventListener('input', function () { lookupSequence++; studentOk = false; borrowerName = ''; $('photo').checked = false; $('sidRes').textContent = 'Check this ID before issuing the form.'; });

  /* ---- books: each line is "Read here" (ID kept) or "Borrow" (borrowing days + due date, no ID kept) ---- */
  function borrowReason(c) {
    if (!$('bt_m').checked) return 'Visitors cannot borrow books.';
    if (c.borrowable === false || c.canBorrow === false) return c.borrowNote || 'This book cannot be borrowed now.';
    if (borrowInfo.blocked) return borrowInfo.blocked;
    return '';
  }
  function renderList() {
    var ids = Object.keys(chosen);
    $('bkEmpty').style.display = ids.length ? 'none' : '';
    $('bkList').innerHTML = ids.map(function (id) { var c = chosen[id], why = borrowReason(c), isB = c.use === 'Borrow';
      return '<div class="py-2 border-bottom"><input type="hidden" name="copy_ids[]" value="' + id + '">' +
        '<div class="d-flex align-items-start gap-2 flex-wrap">' +
        '<div class="flex-grow-1 small" style="min-width:12rem"><span class="fw-semibold">' + esc(c.title) + '</span> <span class="text-muted">' + esc(c.accession) + (c.shelf ? ' · ' + esc(c.shelf) : '') + '</span>' +
        (isB ? '<div class="text-success mt-1 d-flex flex-wrap align-items-center gap-2"><i class="bi bi-calendar-check"></i> ' + kind + ' borrows for ' +
          '<input type="number" class="form-control form-control-sm" style="width:5rem" name="days[' + id + ']" value="' + c.days + '" min="' + MIN_D + '" max="' + MAX_D + '" data-days="' + id + '" aria-label="Days borrowed"> days' +
          ' · from <strong>' + esc(dueOn(0)) + '</strong> · return on or before <strong data-due="' + id + '">' + esc(dueOn(c.days)) + '</strong></div>' : '') + '</div>' +
        '<input type="hidden" name="use[' + id + ']" value="' + (isB ? 'Borrow' : 'Read') + '">' +
        (c.ok === false ? '<div class="small text-danger">' + esc(c.label) + ' — remove this copy or choose another.</div>' : '') +
        (isB ? '' : '<select class="form-select form-select-sm w-auto" name="cond_out[' + id + ']" title="Condition now"><option>Good</option><option>Fair</option><option>Damaged</option></select>') +
        '<button type="button" class="btn btn-sm btn-outline-danger" data-rm="' + id + '" aria-label="Remove"><i class="bi bi-x-lg"></i></button></div>' +
        '' + '</div>'; }).join('');
    ids.forEach(function (id) { var select = document.querySelector('[name="cond_out[' + id + ']"]'); if (select) select.value = chosen[id].condition || 'Good'; });
    refresh();
  }
  /* what the form needs depends on what is on it */
  function refresh() {
    var ids = Object.keys(chosen), reads = ids.filter(function (i) { return chosen[i].use !== 'Borrow'; }).length, borrows = ids.length - reads;
    $('readBlock').classList.toggle('d-none', !reads); $('slotBlock').classList.toggle('d-none', !reads); $('slot').disabled = !reads;
    $('rulesRead').classList.toggle('d-none', !reads); $('rulesBorrow').classList.toggle('d-none', !borrows);
    $('slotLbl').style.display = reads ? '' : 'none';
    $('bkSummary').innerHTML = ids.length ? '<i class="bi bi-book text-primary"></i> ' + reads + ' read here (ID kept) &nbsp;·&nbsp; <i class="bi bi-box-arrow-up-right text-warning"></i> ' + borrows + ' to borrow (no ID kept)' : '';
    var over = borrowInfo.left >= 0 && borrows > borrowInfo.left;
    $('bkWarn').classList.toggle('d-none', !over);
    if (over) $('bkWarn').textContent = 'This borrower can borrow only ' + borrowInfo.left + ' more book(s) (borrowing limit ' + borrowInfo.max + '). Change one to “Read here” or remove it.';
    $('issueBtn').disabled = over;
    $('issueLbl').textContent = !ids.length ? 'Issue form' : (reads && borrows ? 'Issue form, hold ID & record borrowing' : (reads ? 'Issue form & hold ID' : 'Record borrowing'));
    $('footNote').innerHTML = '<i class="bi bi-shield-check text-success"></i> ' + (!ids.length ? 'Add the books first.'
      : (reads && borrows ? 'The ID stays with you until the books read here are back. Borrowed books are recorded as a borrowing transaction with due dates.'
      : (reads ? 'No borrowing transaction and no due date. The ID stays with you until the books are back.' : 'Recorded as a borrowing transaction with due dates. No ID is kept.')));
  }
  $('bkList').addEventListener('click', function (e) { var b = e.target.closest('[data-rm]'); if (b) { delete chosen[b.dataset.rm]; renderList(); } });
  $('bkList').addEventListener('input', function (e) {
    var n = e.target.closest('input[data-days]'); if (!n || !chosen[n.dataset.days]) return;
    var v = parseInt(n.value, 10); if (!(v >= MIN_D && v <= MAX_D)) return;
    chosen[n.dataset.days].days = v; var o = document.querySelector('[data-due="' + n.dataset.days + '"]'); if (o) o.textContent = dueOn(v);
  });
  $('bkList').addEventListener('change', function (e) {
    var cond = e.target.name.match(/^cond_out\[(\d+)\]$/); if (cond && chosen[cond[1]]) chosen[cond[1]].condition = e.target.value;
    var r = e.target.closest('input[name^="use["]'); if (!r) return;
    var c = chosen[r.dataset.id]; if (!c) return;
    $('bkWarn').classList.add('d-none');
    if (r.value === 'Borrow') {
      var dup = Object.keys(chosen).filter(function (k) { return k !== r.dataset.id && chosen[k].use === 'Borrow' && chosen[k].bookId === c.bookId; });
      if (dup.length) { c.use = 'Read'; renderList(); $('bkWarn').textContent = 'Only one copy of “' + c.title + '” can be borrowed. It stays as “Read here”.'; $('bkWarn').classList.remove('d-none'); return; }
    }
    c.use = r.value === 'Borrow' ? 'Borrow' : 'Read'; renderList();
  });
  function add(it) {
    if (chosen[it.copyId]) { $('bkWarn').textContent = 'This copy is already on the form. Its details have been kept.'; $('bkWarn').classList.remove('d-none'); $('bk').value = ''; $('bk').focus(); return; }
    if (!it.ok) return;
    if (mode === 'Borrow' && borrowReason(it)) { $('bkWarn').textContent = '“' + it.title + '” cannot be borrowed: ' + borrowReason(it) + (READ_OK ? ' Switch to “Read here” to read it in the library.' : ''); $('bkWarn').classList.remove('d-none'); return; }
    if (mode === 'Borrow' && Object.keys(chosen).some(function (k) { return chosen[k].bookId === it.bookId; })) { $('bkWarn').textContent = 'Only one copy of “' + it.title + '” can be borrowed.'; $('bkWarn').classList.remove('d-none'); return; }
    $('bkWarn').classList.add('d-none');
    it.use = mode; it.days = it.loanDays; chosen[it.copyId] = it; $('bk').value = ''; $('bkRes').innerHTML = ''; renderList(); $('bk').focus(); }
  var t, copySequence = 0;
  function search(auto) {
    var requestId = ++copySequence;
    var q = $('bk').value.trim(); if (q.length < 2) { $('bkRes').innerHTML = ''; return; }
    fetch('desk_form.php?api=copy&q=' + encodeURIComponent(q), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { if (!r.ok || r.redirected) throw new Error('Lookup failed'); return r.json(); }).then(function (d) {
      if (requestId !== copySequence || $('bk').value.trim() !== q) return;
      var items = d.items || [];
      if (auto) { var ex = items.filter(function (i) { return i.exact; })[0]; if (ex && ex.ok) { add(ex); return; } }
      $('bkRes').innerHTML = items.length ? items.map(function (i, n) {
        return '<button type="button" class="list-group-item list-group-item-action ' + (i.ok ? '' : 'disabled') + '" data-n="' + n + '"><div class="fw-semibold">' + esc(i.title) +
          '</div><div class="small text-muted">' + esc(i.accession) + (i.shelf ? ' · ' + esc(i.shelf) : '') + ' · ' + (i.ok ? '<span class="text-success">Available</span>' : '<span class="text-danger">' + esc(i.label) + '</span>') + '</div></button>'; }).join('')
        : '<div class="list-group-item small text-muted">No matching book.</div>';
      $('bkRes').onclick = function (e) { var b = e.target.closest('[data-n]'); if (b) add(items[+b.dataset.n]); };
    }).catch(function () { if (requestId !== copySequence) return; $('bkRes').innerHTML = '<div class="alert alert-danger mb-0">Could not look up the book. Your selected books are kept. Press Enter to retry.</div>'; });
  }
  $('bk').addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { search(false); }, 250); });
  $('bk').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(t); search(true); } });

  var review = new bootstrap.Modal($('deskReview')), confirmed = false, submitting = false;
  $('confirmDesk').addEventListener('click', function () { confirmed = true; review.hide(); $('deskForm').requestSubmit(); });
  $('deskForm').addEventListener('submit', function (e) {
    if (submitting) { e.preventDefault(); return; }
    var ids = Object.keys(chosen);
    if (!ids.length) { e.preventDefault(); $('bk').focus(); $('bkEmpty').textContent = 'Add at least one book first.'; return; }
    if ($('bt_m').checked && !studentOk) { e.preventDefault(); confirmed = false; lookup(); $('sid').focus(); return; }
    if (!confirmed) {
      e.preventDefault();
      $('reviewWho').textContent = $('bt_m').checked ? borrowerName + ' · ' + $('sid').value : $('wn').value;
      $('reviewBooks').innerHTML = '<ul class="list-group">' + ids.map(function (id) {
        var c = chosen[id];
        return '<li class="list-group-item"><strong>' + esc(c.title) + '</strong> · ' + esc(c.accession) +
          '<div>' + (c.use === 'Borrow' ? 'Take home · due ' + esc(dueOn(c.days)) : 'Read here · condition: ' + esc(c.condition || 'Good')) + '</div></li>';
      }).join('') + '</ul>';
      var reads = ids.filter(function (id) { return chosen[id].use !== 'Borrow'; }).length;
      $('reviewCustody').textContent = reads ? 'Keep the ID in storage slot ' + ($('slot').value || 'the next free slot') + ' until all ' + reads + ' read-here book(s) are returned.' : 'All books are being borrowed. Return the ID to the borrower.';
      review.show(); return;
    }
    submitting = true; $('issueBtn').disabled = true; $('issueLbl').textContent = 'Saving…';
  });
  var draft = <?= json_encode($draft, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var draftItems = <?= json_encode($draftItems, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  Object.keys(draft).forEach(function (key) {
    if (['issue_token','copy_ids','use','days','cond_out'].indexOf(key) !== -1) return;
    var field = $('deskForm').elements.namedItem(key); if (!field) return;
    if (field.type === 'checkbox') field.checked = !!draft[key];
    else if (typeof draft[key] === 'string') field.value = draft[key];
  });
  draftItems.forEach(function (item) { chosen[item.copyId] = item; });
  if (draftItems.length || draft.student_id) {
    mode = (READ_OK && $('md_r').checked) ? 'Read' : 'Borrow'; sync();
    if ($('bt_m').checked) lookup();
  }
  window.addEventListener('pageshow', function () { submitting = false; confirmed = false; refresh(); });

});
</script>

<?php require __DIR__.'/includes/reading_session_launcher.php';require __DIR__ . '/includes/layout_bottom.php'; ?>
