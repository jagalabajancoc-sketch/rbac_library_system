<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_sections');
$page_title = 'Sections';

$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
if ($view === 'archived') {
    require_permission('view_archived_records');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'archive' || $action === 'restore') {
        require_permission('archive_records');
        $id = $_POST['section_id'] ?? '';
        if (!$id) { flash('danger', 'Missing section reference.'); redirect('sections.php'); }
        $row = fetch_one("SELECT SectionID, SectionName FROM section WHERE SectionID = ?", [$id]);
        if (!$row) { flash('danger', 'Section not found.'); redirect('sections.php'); }
        $newValue = $action === 'archive' ? 1 : 0;
        run("UPDATE section SET IsArchived = ? WHERE SectionID = ?", [$newValue, $row['SectionID']]);
        log_activity(
            $action === 'archive' ? 'Archive' : 'Restore',
            'Sections',
            ($action === 'archive' ? 'Archived' : 'Restored') . ' section "' . $row['SectionName'] . '"',
            $row['SectionID']
        );
        flash('success', $action === 'archive'
            ? 'Section "' . $row['SectionName'] . '" archived.'
            : 'Section "' . $row['SectionName'] . '" restored.');
        redirect('sections.php?view=' . ($action === 'archive' ? 'archived' : 'active'));
    }

    if ($action === 'save') {
        $id   = $_POST['section_id'] ?? '';
        $name = trim($_POST['section_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '') {
            flash('danger', 'Section name is required.');
        } else {
            if ($id) {
                run("UPDATE section SET SectionName = ?, Description = ? WHERE SectionID = ?", [$name, $desc, $id]);
                log_activity('Update', 'Sections', 'Updated section "' . $name . '"', $id);
                flash('success', 'Section updated.');
            } else {
                run("INSERT INTO section (SectionName, Description) VALUES (?, ?)", [$name, $desc]);
                log_activity('Create', 'Sections', 'Added section "' . $name . '"', (int)last_id());
                flash('success', 'Section added.');
            }
        }
        redirect('sections.php');
    }
}

$whereSql = "WHERE s.IsArchived = " . ($view === 'archived' ? '1' : '0');
$totalSections = (int) fetch_value("SELECT COUNT(*) FROM section s $whereSql");
$pg = pagination_calc($totalSections, PER_PAGE_DEFAULT, pagination_page());

$sections = fetch_all(
    "SELECT s.*,
            (SELECT COUNT(*) FROM book b WHERE b.SectionID = s.SectionID) AS BookCount
       FROM section s
      $whereSql
      ORDER BY s.SectionName
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
);
$activeCount   = (int) fetch_value("SELECT COUNT(*) FROM section WHERE IsArchived = 0");
$archivedCount = (int) fetch_value("SELECT COUNT(*) FROM section WHERE IsArchived = 1");

require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $view === 'active' ? 'active' : '' ?>" href="sections.php?view=active">
      <i class="bi bi-diagram-3"></i> Active
      <span class="badge text-bg-light border ms-1"><?= h($activeCount) ?></span>
    </a>
  </li>
  <?php if (has_permission('view_archived_records')): ?>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'archived' ? 'active' : '' ?>" href="sections.php?view=archived">
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
      <strong>Showing archived sections.</strong> Archived sections don't appear in the book form.
      <?php if (has_permission('archive_records')): ?>
        Use <strong>Restore</strong> to return one to the active list.
      <?php else: ?>
        Only Admin can restore.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="page-toolbar">
  <div></div>
  <?php if ($view === 'active'): ?>
    <button class="btn btn-primary" onclick="openModal()">
      <i class="bi bi-plus-lg"></i> Add Section
    </button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Section Name</th>
          <th>Description</th>
          <th>Books</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$sections): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">No sections found.</td></tr>
        <?php endif; ?>
        <?php foreach ($sections as $s): ?>
          <?php $isArchived = !empty($s['IsArchived']); ?>
          <tr class="<?= $isArchived ? 'text-muted' : '' ?>">
            <td>
              <?= h($s['SectionName']) ?>
              <?php if ($isArchived): ?>
                <span class="badge text-bg-secondary ms-1"><i class="bi bi-archive"></i> Archived</span>
              <?php endif; ?>
            </td>
            <td class="text-muted small"><?= h($s['Description']) ?></td>
            <td><span class="badge text-bg-light border"><?= h($s['BookCount']) ?></span></td>
            <td class="text-end text-nowrap">
              <?php if (!$isArchived && has_permission('manage_sections')): ?>
                <button class="btn btn-sm btn-outline-primary" title="Edit" data-bs-toggle="tooltip"
                        onclick='openModal(<?= json_encode($s) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
              <?php endif; ?>

              <?php if (has_permission('archive_records')): ?>
                <?php if ($isArchived): ?>
                  <form method="post" class="d-inline" data-confirm="Restore this section?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="section_id" value="<?= h($s['SectionID']) ?>">
                    <button class="btn btn-sm btn-outline-success" title="Restore" data-bs-toggle="tooltip">
                      <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <form method="post" class="d-inline" data-confirm="Archive this section? It will be hidden from the book form.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="section_id" value="<?= h($s['SectionID']) ?>">
                    <button class="btn btn-sm btn-outline-warning" title="Archive" data-bs-toggle="tooltip">
                      <i class="bi bi-archive"></i>
                    </button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (!has_permission('manage_sections') && !has_permission('archive_records')): ?>
                <span class="text-muted small">View only</span>
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

<div class="modal fade" id="secModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="secModalTitle">Add Section</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="section_id" id="section_id">
        <div class="mb-3">
          <label class="form-label">Section Name</label>
          <input type="text" name="section_name" id="section_name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Description</label>
          <textarea name="description" id="description" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(s) {
  document.getElementById('secModalTitle').textContent = s ? 'Edit Section' : 'Add Section';
  document.getElementById('section_id').value = s ? s.SectionID : '';
  document.getElementById('section_name').value = s ? s.SectionName : '';
  document.getElementById('description').value = s ? (s.Description || '') : '';
  new bootstrap.Modal(document.getElementById('secModal')).show();
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>