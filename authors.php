<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_authors');
$page_title = 'Authors';

$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
if ($view === 'archived') {
    require_permission('view_archived_records');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'archive' || $action === 'restore') {
        require_permission('archive_records');
        $id = $_POST['author_id'] ?? '';
        if (!$id) { flash('danger', 'Missing author reference.'); redirect('authors.php'); }
        $row = fetch_one("SELECT AuthorID, FirstName, LastName FROM author WHERE AuthorID = ?", [$id]);
        if (!$row) { flash('danger', 'Author not found.'); redirect('authors.php'); }
        run("UPDATE author SET IsArchived = ? WHERE AuthorID = ?", [$action === 'archive' ? 1 : 0, $row['AuthorID']]);
        log_activity(
            $action === 'archive' ? 'Archive' : 'Restore',
            'Authors',
            ($action === 'archive' ? 'Archived' : 'Restored') . ' author "' . $row['FirstName'] . ' ' . $row['LastName'] . '"',
            $row['AuthorID']
        );
        flash('success', $action === 'archive'
            ? 'Author "' . $row['FirstName'] . ' ' . $row['LastName'] . '" archived.'
            : 'Author "' . $row['FirstName'] . ' ' . $row['LastName'] . '" restored.');
        redirect('authors.php?view=' . ($action === 'archive' ? 'archived' : 'active'));
    }

    if ($action === 'save') {
        $id        = $_POST['author_id'] ?? '';
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        if ($firstName === '' || $lastName === '') {
            flash('danger', 'First name and last name are required.');
        } else {
            if ($id) {
                run("UPDATE author SET FirstName = ?, LastName = ? WHERE AuthorID = ?", [$firstName, $lastName, $id]);
                log_activity('Update', 'Authors', 'Updated author "' . $firstName . ' ' . $lastName . '"', $id);
                flash('success', 'Author updated.');
            } else {
                run("INSERT INTO author (FirstName, LastName) VALUES (?, ?)", [$firstName, $lastName]);
                log_activity('Create', 'Authors', 'Added author "' . $firstName . ' ' . $lastName . '"', (int)last_id());
                flash('success', 'Author added.');
            }
        }
        redirect('authors.php');
    }
}

$search = trim($_GET['q'] ?? '');
$where  = ['IsArchived = ' . ($view === 'archived' ? '1' : '0')];
$params = [];
if ($search !== '') {
    $where[]  = '(FirstName LIKE ? OR LastName LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalAuthors = (int) fetch_value("SELECT COUNT(*) FROM author $whereSql", $params);
$pg = pagination_calc($totalAuthors, PER_PAGE_DEFAULT, pagination_page());

$authors = fetch_all(
    "SELECT * FROM author $whereSql ORDER BY LastName, FirstName
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);
$activeCount   = (int) fetch_value("SELECT COUNT(*) FROM author WHERE IsArchived = 0");
$archivedCount = (int) fetch_value("SELECT COUNT(*) FROM author WHERE IsArchived = 1");

require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $view === 'active' ? 'active' : '' ?>" href="authors.php?view=active">
      <i class="bi bi-person-lines-fill"></i> Active
      <span class="badge text-bg-light border ms-1"><?= h($activeCount) ?></span>
    </a>
  </li>
  <?php if (has_permission('view_archived_records')): ?>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'archived' ? 'active' : '' ?>" href="authors.php?view=archived">
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
      <strong>Showing archived authors.</strong> Archived authors don't appear in book forms or dropdowns.
      <?php if (has_permission('archive_records')): ?>
        Use <strong>Restore</strong> to return one to the active list.
      <?php else: ?>
        Only Admin can restore.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="page-toolbar">
  <form class="d-flex" method="get">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <input type="search" name="q" class="form-control" placeholder="Search authors..." value="<?= h($search) ?>">
    <button class="btn btn-outline-secondary ms-2"><i class="bi bi-search"></i></button>
  </form>
  <?php if ($view === 'active'): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#authorModal" onclick="openAuthorModal()">
      <i class="bi bi-plus-lg"></i> Add Author
    </button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>First Name</th>
          <th>Last Name</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$authors): ?>
          <tr><td colspan="3" class="text-center text-muted py-4">No authors found.</td></tr>
        <?php endif; ?>
        <?php foreach ($authors as $a): ?>
          <?php $isArchived = !empty($a['IsArchived']); ?>
          <tr class="<?= $isArchived ? 'text-muted' : '' ?>">
            <td><?= h($a['FirstName']) ?></td>
            <td>
              <?= h($a['LastName']) ?>
              <?php if ($isArchived): ?>
                <span class="badge text-bg-secondary ms-1"><i class="bi bi-archive"></i> Archived</span>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <?php if (!$isArchived && has_permission('manage_authors')): ?>
                <button class="btn btn-sm btn-outline-primary"
                        title="Edit" data-bs-toggle="tooltip"
                        onclick='openAuthorModal(<?= json_encode($a) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
              <?php endif; ?>

              <?php if (has_permission('archive_records')): ?>
                <?php if ($isArchived): ?>
                  <form method="post" class="d-inline" data-confirm="Restore this author?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="author_id" value="<?= h($a['AuthorID']) ?>">
                    <button class="btn btn-sm btn-outline-success" title="Restore" data-bs-toggle="tooltip">
                      <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <form method="post" class="d-inline" data-confirm="Archive this author? It will be hidden from the book form.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="author_id" value="<?= h($a['AuthorID']) ?>">
                    <button class="btn btn-sm btn-outline-warning" title="Archive" data-bs-toggle="tooltip">
                      <i class="bi bi-archive"></i>
                    </button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (!has_permission('manage_authors') && !has_permission('archive_records')): ?>
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

<div class="modal fade" id="authorModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="authorModalTitle">Add Author</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="author_id" id="author_id">
        <div class="mb-3">
          <label class="form-label">First Name</label>
          <input type="text" name="first_name" id="first_name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Last Name</label>
          <input type="text" name="last_name" id="last_name" class="form-control" required>
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
function openAuthorModal(author) {
  document.getElementById('authorModalTitle').textContent = author ? 'Edit Author' : 'Add Author';
  document.getElementById('author_id').value = author ? author.AuthorID : '';
  document.getElementById('first_name').value = author ? author.FirstName : '';
  document.getElementById('last_name').value = author ? author.LastName : '';
  new bootstrap.Modal(document.getElementById('authorModal')).show();
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>