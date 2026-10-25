<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_shelves');
$page_title = 'Shelves';

$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
if ($view === 'archived') {
    require_permission('view_archived_records');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'archive' || $action === 'restore') {
        require_permission('archive_records');
        $id = $_POST['shelf_id'] ?? '';
        if (!$id) { flash('danger', 'Missing shelf reference.'); redirect('shelves.php'); }
        $row = fetch_one("SELECT ShelfID, ShelfCode, SectionID FROM shelf WHERE ShelfID = ?", [$id]);
        if (!$row) { flash('danger', 'Shelf not found.'); redirect('shelves.php'); }

        if ($action === 'archive') {
            $inUse = (int) fetch_value(
                "SELECT COUNT(*) FROM book_copy WHERE ShelfID = ? AND IsArchived = 0",
                [$id]
            );
            if ($inUse > 0) {
                flash('danger', "Cannot archive shelf \"{$row['ShelfCode']}\" — $inUse copy(ies) are still assigned. Reassign them first.");
                redirect('shelves.php');
            }
        }

        run("UPDATE shelf SET IsArchived = ? WHERE ShelfID = ?",
            [$action === 'archive' ? 1 : 0, $row['ShelfID']]);

        log_activity(
            $action === 'archive' ? 'Archive' : 'Restore',
            'Shelves',
            ($action === 'archive' ? 'Archived' : 'Restored') . ' shelf "' . $row['ShelfCode'] . '"',
            $row['ShelfID']
        );
        flash('success', 'Shelf "' . $row['ShelfCode'] . '" ' . ($action === 'archive' ? 'archived.' : 'restored.'));
        redirect('shelves.php?view=' . ($action === 'archive' ? 'archived' : 'active'));
    }

    if ($action === 'save') {
        $id        = $_POST['shelf_id'] ?? '';
        $sectionId = $_POST['section_id'] ?? '';
        $code      = trim($_POST['shelf_code'] ?? '');
        $desc      = trim($_POST['shelf_description'] ?? '');

        if ($code === '' || !$sectionId) {
            flash('danger', 'Shelf code and section are required.');
        } else {
            $dup = (int) fetch_value(
                "SELECT COUNT(*) FROM shelf WHERE SectionID = ? AND ShelfCode = ? AND ShelfID <> ?",
                [$sectionId, $code, $id ?: 0]
            );
            if ($dup > 0) {
                flash('danger', 'A shelf with that code already exists in the selected section.');
            } else {
                if ($id) {
                    run("UPDATE shelf SET SectionID=?, ShelfCode=?, ShelfDescription=? WHERE ShelfID=?",
                        [$sectionId, $code, $desc ?: null, $id]);
                    log_activity('Update', 'Shelves', "Updated shelf \"$code\"", $id);
                    flash('success', 'Shelf updated.');
                } else {
                    run("INSERT INTO shelf (SectionID, ShelfCode, ShelfDescription) VALUES (?,?,?)",
                        [$sectionId, $code, $desc ?: null]);
                    $newId = (int) last_id();
                    log_activity('Create', 'Shelves', "Added shelf \"$code\"", $newId);
                    flash('success', 'Shelf added.');
                }
            }
        }
        redirect('shelves.php');
    }
}

$search        = trim($_GET['q'] ?? '');
$sectionFilter = $_GET['section_id'] ?? '';

$where  = ["s.IsArchived = " . ($view === 'archived' ? '1' : '0')];
$params = [];
if ($search !== '') {
    $where[] = "(s.ShelfCode LIKE ? OR s.ShelfDescription LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($sectionFilter !== '') {
    $where[] = "s.SectionID = ?";
    $params[] = $sectionFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalShelves = (int) fetch_value("SELECT COUNT(*) FROM shelf s $whereSql", $params);
$pg = pagination_calc($totalShelves, PER_PAGE_DEFAULT, pagination_page());

$shelves = fetch_all(
    "SELECT s.*, sec.SectionName,
            (SELECT COUNT(*) FROM book_copy bc WHERE bc.ShelfID = s.ShelfID AND bc.IsArchived = 0) AS CopyCount
       FROM shelf s
       JOIN section sec ON sec.SectionID = s.SectionID
      $whereSql
      ORDER BY sec.SectionName, s.ShelfCode
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$sections      = lookup_options('section', 'SectionID', 'SectionName');
$activeCount   = (int) fetch_value("SELECT COUNT(*) FROM shelf WHERE IsArchived = 0");
$archivedCount = (int) fetch_value("SELECT COUNT(*) FROM shelf WHERE IsArchived = 1");

require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $view === 'active' ? 'active' : '' ?>" href="shelves.php?view=active">
      <i class="bi bi-grid-3x3-gap"></i> Active
      <span class="badge text-bg-light border ms-1"><?= h($activeCount) ?></span>
    </a>
  </li>
  <?php if (has_permission('view_archived_records')): ?>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'archived' ? 'active' : '' ?>" href="shelves.php?view=archived">
        <i class="bi bi-archive"></i> Archived
        <span class="badge text-bg-light border ms-1"><?= h($archivedCount) ?></span>
      </a>
    </li>
  <?php endif; ?>
</ul>

<?php if ($view === 'archived'): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="bi bi-archive-fill mt-1"></i>
    <div class="small">
      <strong>Showing archived shelves.</strong> They don't appear in the copy-assignment dropdown.
      <?php if (has_permission('archive_records')): ?>Use <strong>Restore</strong> to bring one back.<?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="page-toolbar">
  <form class="d-flex flex-wrap gap-2" method="get">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <input type="search" name="q" class="form-control" style="max-width:220px"
           placeholder="Search shelf code..." value="<?= h($search) ?>">
    <select name="section_id" class="form-select" style="max-width:200px" onchange="this.form.submit()">
      <option value="">All Sections</option>
      <?php foreach ($sections as $s): ?>
        <option value="<?= h($s['id']) ?>" <?= (string)$sectionFilter === (string)$s['id'] ? 'selected' : '' ?>>
          <?= h($s['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  </form>
  <?php if ($view === 'active'): ?>
    <button class="btn btn-primary" onclick="openShelfModal()"><i class="bi bi-plus-lg"></i> Add Shelf</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Shelf Code</th>
          <th>Section</th>
          <th>Description</th>
          <th class="text-center">Copies</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$shelves): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No shelves found.</td></tr>
        <?php endif; ?>
        <?php foreach ($shelves as $s): ?>
          <?php $isArchived = !empty($s['IsArchived']); ?>
          <tr class="<?= $isArchived ? 'text-muted' : '' ?>">
            <td class="fw-semibold">
              <?= h($s['ShelfCode']) ?>
              <?php if ($isArchived): ?>
                <span class="badge text-bg-secondary ms-1"><i class="bi bi-archive"></i> Archived</span>
              <?php endif; ?>
            </td>
            <td><span class="badge text-bg-light border"><?= h($s['SectionName']) ?></span></td>
            <td class="small"><?= h($s['ShelfDescription'] ?: '—') ?></td>
            <td class="text-center">
              <a href="book_copies.php?view=active&shelf_id=<?= h($s['ShelfID']) ?>"
                 class="badge text-bg-light border text-decoration-none">
                <?= h($s['CopyCount']) ?>
              </a>
            </td>
            <td class="text-end text-nowrap">
              <?php if (!$isArchived && has_permission('manage_shelves')): ?>
                <button class="btn btn-sm btn-outline-primary" title="Edit" data-bs-toggle="tooltip"
                        onclick='openShelfModal(<?= json_encode($s) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
              <?php endif; ?>

              <?php if (has_permission('archive_records')): ?>
                <?php if ($isArchived): ?>
                  <form method="post" class="d-inline" data-confirm="Restore this shelf?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="shelf_id" value="<?= h($s['ShelfID']) ?>">
                    <button class="btn btn-sm btn-outline-success" title="Restore" data-bs-toggle="tooltip">
                      <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <form method="post" class="d-inline"
                        data-confirm="Archive this shelf? Copies must be reassigned first.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="shelf_id" value="<?= h($s['ShelfID']) ?>">
                    <button class="btn btn-sm btn-outline-warning" title="Archive" data-bs-toggle="tooltip">
                      <i class="bi bi-archive"></i>
                    </button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
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

<div class="modal fade" id="shelfModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="shelfModalTitle">Add Shelf</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="shelf_id" id="shelf_id">
        <div class="mb-3">
          <label class="form-label">Section</label>
          <?= render_select('section_id', $sections, null, 'id="shelf_section_id"', true) ?>
          <div class="form-text">The shelf will belong to this section.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Shelf Code</label>
          <input type="text" name="shelf_code" id="shelf_code" class="form-control"
                 maxlength="50" required placeholder="e.g. FIC-B1">
          <div class="form-text">Must be unique within the section.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Description</label>
          <input type="text" name="shelf_description" id="shelf_description"
                 class="form-control" maxlength="255" placeholder="e.g. Fiction aisle B, bay 1">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Shelf</button>
      </div>
    </form>
  </div>
</div>

<script>
function openShelfModal(s) {
  document.getElementById('shelfModalTitle').textContent = s ? 'Edit Shelf' : 'Add Shelf';
  document.getElementById('shelf_id').value              = s ? s.ShelfID : '';
  document.getElementById('shelf_section_id').value      = s ? s.SectionID : '';
  document.getElementById('shelf_code').value            = s ? s.ShelfCode : '';
  document.getElementById('shelf_description').value     = s ? (s.ShelfDescription || '') : '';
  new bootstrap.Modal(document.getElementById('shelfModal')).show();
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>