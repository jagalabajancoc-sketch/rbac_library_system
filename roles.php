<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_roles');
$page_title = 'Roles';

$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_active') {
        require_permission('manage_roles');
        $id = $_POST['role_id'] ?? '';
        $row = fetch_one("SELECT RoleID, RoleName, IsArchived FROM role WHERE RoleID = ?", [$id]);
        if (!$row) {
            flash('danger', 'Role not found.');
            redirect('roles.php');
        }
        $newValue = empty($row['IsArchived']) ? 1 : 0;
        run("UPDATE role SET IsArchived = ? WHERE RoleID = ?", [$newValue, $row['RoleID']]);
               $newValue = empty($row['IsArchived']) ? 1 : 0;
        run("UPDATE role SET IsArchived = ? WHERE RoleID = ?", [$newValue, $row['RoleID']]);
        log_activity(
            $newValue ? 'Archive' : 'Restore',
            'Roles',
            ($newValue ? 'Deactivated' : 'Reactivated') . ' role "' . $row['RoleName'] . '"',
            $row['RoleID']
        );
        flash('success', $newValue
            ? 'Role "' . $row['RoleName'] . '" deactivated.'
            : 'Role "' . $row['RoleName'] . '" reactivated.'); flash('success', $newValue
            ? 'Role "' . $row['RoleName'] . '" deactivated.'
            : 'Role "' . $row['RoleName'] . '" reactivated.');
        redirect('roles.php?view=' . ($newValue ? 'archived' : 'active'));
    }

    if ($action === 'save') {
        $id   = $_POST['role_id'] ?? '';
        $name = trim($_POST['role_name'] ?? '');
        if ($name === '') {
            flash('danger', 'Role name is required.');
        } else {
            try {
                if ($id) {
                    run("UPDATE role SET RoleName = ? WHERE RoleID = ?", [$name, $id]);
                    log_activity('Update', 'Roles', 'Updated role "' . $name . '"', $id);
                    flash('success', 'Role updated.');
                } else {
                    run("INSERT INTO role (RoleName) VALUES (?)", [$name]);
                    log_activity('Create', 'Roles', 'Added role "' . $name . '"', (int)last_id());
                    flash('success', 'Role added.');
                }
            } catch (PDOException $e) {
                flash('danger', 'A role with that name may already exist.');
            }
        }
        redirect('roles.php');
    }
}

/* Staff count is now derived from user_account, not the dropped staff table. */
$whereSql = "WHERE r.IsArchived = " . ($view === 'archived' ? '1' : '0');
$listSearch=mb_substr(trim((string)($_GET['q']??'')),0,100);$listSearchParams=[];
if($listSearch!==''){$whereSql.=" AND r.RoleName LIKE ?";$listSearchParams[]='%'.$listSearch.'%';}

$totalRoles = (int) fetch_value("SELECT COUNT(*) FROM role r $whereSql",$listSearchParams);
$pg = pagination_calc($totalRoles, PER_PAGE_DEFAULT, pagination_page());

$roles = fetch_all(
    "SELECT r.*,
            (SELECT COUNT(*) FROM user_account ua WHERE ua.RoleID = r.RoleID) AS StaffCount
       FROM role r
      $whereSql
      ORDER BY r.RoleName
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",$listSearchParams
);
$activeCount   = (int) fetch_value("SELECT COUNT(*) FROM role WHERE IsArchived = 0");
$archivedCount = (int) fetch_value("SELECT COUNT(*) FROM role WHERE IsArchived = 1");

require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $view === 'active' ? 'active' : '' ?>" href="roles.php?view=active">
      <i class="bi bi-shield-lock"></i> Active
      <span class="badge text-bg-light border ms-1"><?= h($activeCount) ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $view === 'archived' ? 'active' : '' ?>" href="roles.php?view=archived">
      <i class="bi bi-shield-slash"></i> Deactivated
      <span class="badge text-bg-light border ms-1"><?= h($archivedCount) ?></span>
    </a>
  </li>
</ul>

<?php if ($view === 'archived'): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="bi bi-shield-slash-fill mt-1"></i>
    <div class="small">
      <strong>Showing deactivated roles.</strong> They don't appear in the staff form, but staff
      who already have this role keep it. Reactivate to make it selectable again.
    </div>
  </div>
<?php endif; ?>

<div class="page-toolbar"><form method="get" class="d-flex flex-wrap gap-2"><input type="search" name="q" class="form-control" value="<?= h($listSearch) ?>" placeholder="Search this list" aria-label="Search this list"><?php if(isset($view)):?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif;?><button class="btn btn-primary">Search</button></form>
  <div></div>
  <?php if ($view === 'active'): ?>
    <button class="btn btn-primary" onclick="openModal()"><i class="bi bi-plus-lg"></i> Add Role</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Role Name</th><th>Users Assigned</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$roles): ?><tr><td colspan="3" class="text-center text-muted py-4">No roles found.</td></tr><?php endif; ?>
        <?php foreach ($roles as $r): ?>
          <?php $isArchived = !empty($r['IsArchived']); ?>
          <tr class="<?= $isArchived ? 'text-muted' : '' ?>">
            <td>
              <?= h($r['RoleName']) ?>
              <?php if ($isArchived): ?>
                <span class="badge text-bg-secondary ms-1">Deactivated</span>
              <?php endif; ?>
            </td>
            <td><span class="badge text-bg-light border"><?= h($r['StaffCount']) ?></span></td>
            <td class="text-end text-nowrap">
              <?php if (!$isArchived): ?>
                <button class="btn btn-sm btn-outline-primary" title="Edit" data-bs-toggle="tooltip"
                        onclick='openModal(<?= json_encode($r) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
              <?php endif; ?>

              <form method="post" class="d-inline"
                    data-confirm="<?= $isArchived ? 'Reactivate this role?' : 'Deactivate this role? It will no longer appear in the user form, but existing assignments remain.' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="role_id" value="<?= h($r['RoleID']) ?>">
                <button class="btn btn-sm <?= $isArchived ? 'btn-outline-success' : 'btn-outline-warning' ?>"
                        title="<?= $isArchived ? 'Reactivate' : 'Deactivate' ?>" data-bs-toggle="tooltip">
                  <i class="bi <?= $isArchived ? 'bi-shield-check' : 'bi-shield-slash' ?>"></i>
                </button>
              </form>
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

<div class="modal fade" id="roleModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="roleModalTitle">Add Role</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="role_id" id="role_id">
        <div class="mb-3">
          <label class="form-label">Role Name</label>
          <input type="text" name="role_name" id="role_name" class="form-control" required
                 placeholder="e.g. Admin, Librarian, Assistant, Borrower">
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
function openModal(r) {
  document.getElementById('roleModalTitle').textContent = r ? 'Edit Role' : 'Add Role';
  document.getElementById('role_id').value = r ? r.RoleID : '';
  document.getElementById('role_name').value = r ? r.RoleName : '';
  new bootstrap.Modal(document.getElementById('roleModal')).show();
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>