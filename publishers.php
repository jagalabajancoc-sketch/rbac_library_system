<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_publishers');
$page_title = 'Publishers';

$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
if ($view === 'archived') {
    require_permission('view_archived_records');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'archive' || $action === 'restore') {
        require_permission('archive_records');
        $id = $_POST['publisher_id'] ?? '';
        if (!$id) { flash('danger', 'Missing publisher reference.'); redirect('publishers.php'); }
        $row = fetch_one("SELECT PublisherID, PublisherName FROM publisher WHERE PublisherID = ?", [$id]);
        if (!$row) { flash('danger', 'Publisher not found.'); redirect('publishers.php'); }
        run("UPDATE publisher SET IsArchived = ? WHERE PublisherID = ?", [$action === 'archive' ? 1 : 0, $row['PublisherID']]);
        log_activity(
            $action === 'archive' ? 'Archive' : 'Restore',
            'Publishers',
            ($action === 'archive' ? 'Archived' : 'Restored') . ' publisher "' . $row['PublisherName'] . '"',
            $row['PublisherID']
        );
        flash('success', $action === 'archive'
            ? 'Publisher "' . $row['PublisherName'] . '" archived.'
            : 'Publisher "' . $row['PublisherName'] . '" restored.');
        redirect('publishers.php?view=' . ($action === 'archive' ? 'archived' : 'active'));
    }

    if ($action === 'save') {
        $id   = $_POST['publisher_id'] ?? '';
        $name = trim($_POST['publisher_name'] ?? '');
        if ($name === '') {
            flash('danger', 'Publisher name is required.');
        } else {
            if ($id) {
                run("UPDATE publisher SET PublisherName = ? WHERE PublisherID = ?", [$name, $id]);
                log_activity('Update', 'Publishers', 'Updated publisher "' . $name . '"', $id);
                flash('success', 'Publisher updated.');
            } else {
                run("INSERT INTO publisher (PublisherName) VALUES (?)", [$name]);
                log_activity('Create', 'Publishers', 'Added publisher "' . $name . '"', (int)last_id());
                flash('success', 'Publisher added.');
            }
        }
        redirect('publishers.php');
    }
}

$whereSql = "WHERE p.IsArchived = " . ($view === 'archived' ? '1' : '0');
$totalPublishers = (int) fetch_value("SELECT COUNT(*) FROM publisher p $whereSql");
$pg = pagination_calc($totalPublishers, PER_PAGE_DEFAULT, pagination_page());

$publishers = fetch_all(
    "SELECT p.*,
            (SELECT COUNT(*) FROM book b WHERE b.PublisherID = p.PublisherID) AS BookCount
       FROM publisher p
      $whereSql
      ORDER BY p.PublisherName
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
);
$activeCount   = (int) fetch_value("SELECT COUNT(*) FROM publisher WHERE IsArchived = 0");
$archivedCount = (int) fetch_value("SELECT COUNT(*) FROM publisher WHERE IsArchived = 1");

require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $view === 'active' ? 'active' : '' ?>" href="publishers.php?view=active">
      <i class="bi bi-building"></i> Active
      <span class="badge text-bg-light border ms-1"><?= h($activeCount) ?></span>
    </a>
  </li>
  <?php if (has_permission('view_archived_records')): ?>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'archived' ? 'active' : '' ?>" href="publishers.php?view=archived">
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
      <strong>Showing archived publishers.</strong> Archived publishers don't appear in the book form.
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
    <button class="btn btn-primary" onclick="openModal()"><i class="bi bi-plus-lg"></i> Add Publisher</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Publisher Name</th>
          <th>Books</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$publishers): ?>
          <tr><td colspan="3" class="text-center text-muted py-4">No publishers found.</td></tr>
        <?php endif; ?>
        <?php foreach ($publishers as $p): ?>
          <?php $isArchived = !empty($p['IsArchived']); ?>
          <tr class="<?= $isArchived ? 'text-muted' : '' ?>">
            <td>
              <?= h($p['PublisherName']) ?>
              <?php if ($isArchived): ?>
                <span class="badge text-bg-secondary ms-1"><i class="bi bi-archive"></i> Archived</span>
              <?php endif; ?>
            </td>
            <td><span class="badge text-bg-light border"><?= h($p['BookCount']) ?></span></td>
            <td class="text-end text-nowrap">
              <?php if (!$isArchived && has_permission('manage_publishers')): ?>
                <button class="btn btn-sm btn-outline-primary" title="Edit" data-bs-toggle="tooltip"
                        onclick='openModal(<?= json_encode($p) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
              <?php endif; ?>

              <?php if (has_permission('archive_records')): ?>
                <?php if ($isArchived): ?>
                  <form method="post" class="d-inline" data-confirm="Restore this publisher?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="publisher_id" value="<?= h($p['PublisherID']) ?>">
                    <button class="btn btn-sm btn-outline-success" title="Restore" data-bs-toggle="tooltip">
                      <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <form method="post" class="d-inline" data-confirm="Archive this publisher?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="publisher_id" value="<?= h($p['PublisherID']) ?>">
                    <button class="btn btn-sm btn-outline-warning" title="Archive" data-bs-toggle="tooltip">
                      <i class="bi bi-archive"></i>
                    </button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (!has_permission('manage_publishers') && !has_permission('archive_records')): ?>
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

<div class="modal fade" id="pubModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="pubModalTitle">Add Publisher</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="publisher_id" id="publisher_id">
        <div class="mb-3">
          <label class="form-label">Publisher Name</label>
          <input type="text" name="publisher_name" id="publisher_name" class="form-control" required>
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
function openModal(p) {
  document.getElementById('pubModalTitle').textContent = p ? 'Edit Publisher' : 'Add Publisher';
  document.getElementById('publisher_id').value = p ? p.PublisherID : '';
  document.getElementById('publisher_name').value = p ? p.PublisherName : '';
  new bootstrap.Modal(document.getElementById('pubModal')).show();
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>