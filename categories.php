<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_categories');
$page_title = 'Categories';

$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
if ($view === 'archived') {
    require_permission('view_archived_records');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'archive' || $action === 'restore') {
        require_permission('archive_records');
        $id = $_POST['category_id'] ?? '';
        if (!$id) { flash('danger', 'Missing category reference.'); redirect('categories.php'); }
        $row = fetch_one("SELECT CategoryID, CategoryName FROM category WHERE CategoryID = ?", [$id]);
        if (!$row) { flash('danger', 'Category not found.'); redirect('categories.php'); }
        run("UPDATE category SET IsArchived = ? WHERE CategoryID = ?", [$action === 'archive' ? 1 : 0, $row['CategoryID']]);
        log_activity(
            $action === 'archive' ? 'Archive' : 'Restore',
            'Categories',
            ($action === 'archive' ? 'Archived' : 'Restored') . ' category "' . $row['CategoryName'] . '"',
            $row['CategoryID']
        );
        flash('success', $action === 'archive'
            ? 'Category "' . $row['CategoryName'] . '" archived.'
            : 'Category "' . $row['CategoryName'] . '" restored.');
        redirect('categories.php?view=' . ($action === 'archive' ? 'archived' : 'active'));
    }

    if ($action === 'save') {
        $id   = $_POST['category_id'] ?? '';
        $name = trim($_POST['category_name'] ?? '');
        if ($name === '') {
            flash('danger', 'Category name is required.');
        } else {
            try {
                if ($id) {
                    run("UPDATE category SET CategoryName = ? WHERE CategoryID = ?", [$name, $id]);
                    log_activity('Update', 'Categories', 'Updated category "' . $name . '"', $id);
                    flash('success', 'Category updated.');
                } else {
                    run("INSERT INTO category (CategoryName) VALUES (?)", [$name]);
                    log_activity('Create', 'Categories', 'Added category "' . $name . '"', (int)last_id());
                    flash('success', 'Category added.');
                }
            } catch (PDOException $e) {
                flash('danger', 'A category with that name may already exist.');
            }
        }
        redirect('categories.php');
    }
}

$whereSql = "WHERE c.IsArchived = " . ($view === 'archived' ? '1' : '0');
$listSearch=mb_substr(trim((string)($_GET['q']??'')),0,100);$listSearchParams=[];
if($listSearch!==''){$whereSql.=" AND c.CategoryName LIKE ?";$listSearchParams[]='%'.$listSearch.'%';}

$totalCategories = (int) fetch_value("SELECT COUNT(*) FROM category c $whereSql",$listSearchParams);
$pg = pagination_calc($totalCategories, PER_PAGE_DEFAULT, pagination_page());

$categories = fetch_all(
    "SELECT c.*,
            (SELECT COUNT(*) FROM book b WHERE b.CategoryID = c.CategoryID) AS BookCount
       FROM category c
      $whereSql
      ORDER BY c.CategoryName
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",$listSearchParams
);
$activeCount   = (int) fetch_value("SELECT COUNT(*) FROM category WHERE IsArchived = 0");
$archivedCount = (int) fetch_value("SELECT COUNT(*) FROM category WHERE IsArchived = 1");

require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $view === 'active' ? 'active' : '' ?>" href="categories.php?view=active">
      <i class="bi bi-tags"></i> Active
      <span class="badge text-bg-light border ms-1"><?= h($activeCount) ?></span>
    </a>
  </li>
  <?php if (has_permission('view_archived_records')): ?>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'archived' ? 'active' : '' ?>" href="categories.php?view=archived">
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
      <strong>Showing archived categories.</strong> Archived categories don't appear in the book form.
      <?php if (has_permission('archive_records')): ?>
        Use <strong>Restore</strong> to return one to the active list.
      <?php else: ?>
        Only Admin can restore.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="page-toolbar"><form method="get" class="d-flex flex-wrap gap-2"><input type="search" name="q" class="form-control" value="<?= h($listSearch) ?>" placeholder="Search this list" aria-label="Search this list"><?php if(isset($view)):?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif;?><button class="btn btn-primary">Search</button></form>
  <div></div>
  <?php if ($view === 'active'): ?>
    <button class="btn btn-primary" onclick="openModal()"><i class="bi bi-plus-lg"></i> Add Category</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Category Name</th>
          <th>Books</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$categories): ?>
          <tr><td colspan="3" class="text-center text-muted py-4">No categories found.</td></tr>
        <?php endif; ?>
        <?php foreach ($categories as $c): ?>
          <?php $isArchived = !empty($c['IsArchived']); ?>
          <tr class="<?= $isArchived ? 'text-muted' : '' ?>">
            <td>
              <?= h($c['CategoryName']) ?>
              <?php if ($isArchived): ?>
                <span class="badge text-bg-secondary ms-1"><i class="bi bi-archive"></i> Archived</span>
              <?php endif; ?>
            </td>
            <td><span class="badge text-bg-light border"><?= h($c['BookCount']) ?></span></td>
            <td class="text-end text-nowrap">
              <?php if (!$isArchived && has_permission('manage_categories')): ?>
                <button class="btn btn-sm btn-outline-primary" title="Edit" data-bs-toggle="tooltip"
                        onclick='openModal(<?= json_encode($c) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
              <?php endif; ?>

              <?php if (has_permission('archive_records')): ?>
                <?php if ($isArchived): ?>
                  <form method="post" class="d-inline" data-confirm="Restore this category?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="category_id" value="<?= h($c['CategoryID']) ?>">
                    <button class="btn btn-sm btn-outline-success" title="Restore" data-bs-toggle="tooltip">
                      <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <form method="post" class="d-inline" data-confirm="Archive this category? It will be hidden from the book form.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="category_id" value="<?= h($c['CategoryID']) ?>">
                    <button class="btn btn-sm btn-outline-warning" title="Archive" data-bs-toggle="tooltip">
                      <i class="bi bi-archive"></i>
                    </button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (!has_permission('manage_categories') && !has_permission('archive_records')): ?>
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

<div class="modal fade" id="catModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="catModalTitle">Add Category</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="category_id" id="category_id">
        <div class="mb-3">
          <label class="form-label">Category Name</label>
          <input type="text" name="category_name" id="category_name" class="form-control" required>
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
function openModal(c) {
  document.getElementById('catModalTitle').textContent = c ? 'Edit Category' : 'Add Category';
  document.getElementById('category_id').value = c ? c.CategoryID : '';
  document.getElementById('category_name').value = c ? c.CategoryName : '';
  new bootstrap.Modal(document.getElementById('catModal')).show();
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>