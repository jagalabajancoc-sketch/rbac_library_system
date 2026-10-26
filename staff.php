<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_staff');
$page_title = 'Staff';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = $_POST['staff_id'] ?? '';
        $first    = trim($_POST['first_name'] ?? '');
        $last     = trim($_POST['last_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $roleId   = $_POST['role_id'] ?? '';
        $password = (string)($_POST['password'] ?? '');

        $errors = [];
        if ($first === '' || $last === '' || $email === '' || $username === '' || !$roleId) {
            $errors[] = 'First name, last name, username, email, and role are required.';
        }
        if ($username !== '' && !preg_match('/^[A-Za-z0-9._-]{4,50}$/', $username)) {
            $errors[] = 'Username must be 4–50 characters and may contain only letters, numbers, dots, underscores and hyphens.';
        }
        if (!$id && $password === '') {
            $errors[] = 'Password is required for a new staff account.';
        }
        if ($password !== '' && strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        /* Uniqueness checks (exclude the record being edited) */
        if (!$errors && $username !== '') {
            $taken = (int) fetch_value(
                "SELECT COUNT(*) FROM user_account WHERE Username = ? AND UserID <> ?",
                [$username, $id ?: 0]
            );
            if ($taken > 0) $errors[] = 'That username is already taken.';
        }
        if (!$errors && $email !== '') {
            $taken = (int) fetch_value(
                "SELECT COUNT(*) FROM user_account WHERE Email = ? AND UserID <> ?",
                [$email, $id ?: 0]
            );
            if ($taken > 0) $errors[] = 'That email address is already used by another account.';
        }

        if ($errors) {
            foreach ($errors as $e) flash('danger', $e);
        } else {
            try {
                if ($id) {
                    if ($password !== '') {
                        run("UPDATE user_account
                                SET FirstName=?, LastName=?, Email=?, Username=?, Phone=?, RoleID=?, PasswordHash=?
                              WHERE UserID=?",
                            [$first, $last, $email, $username, $phone, $roleId,
                             password_hash($password, PASSWORD_DEFAULT), $id]);
                        log_activity('Update', 'Staff', 'Updated staff "' . $first . ' ' . $last . '" and reset their password', $id);
                    } else {
                        run("UPDATE user_account
                                SET FirstName=?, LastName=?, Email=?, Username=?, Phone=?, RoleID=?
                              WHERE UserID=?",
                            [$first, $last, $email, $username, $phone, $roleId, $id]);
                        log_activity('Update', 'Staff', 'Updated staff "' . $first . ' ' . $last . '"', $id);
                    }
                    flash('success', 'Staff member updated.');
                } else {
                    run("INSERT INTO user_account
                            (RoleID, FirstName, LastName, Email, Username, Phone,
                             PasswordHash, RegistrationDate, IsActive)
                         VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), 1)",
                        [$roleId, $first, $last, $email, $username, $phone,
                         password_hash($password, PASSWORD_DEFAULT)]);
                    log_activity('Create', 'Staff', 'Added staff "' . $first . ' ' . $last . '"', (int)last_id());
                    flash('success', 'Staff member added.');
                }
            } catch (PDOException $e) {
                flash('danger', 'Could not save staff member — username or email may already be in use.');
            }
        }
        redirect('staff.php');
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['staff_id'] ?? 0);
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            flash('danger', 'You cannot deactivate your own account while logged in.');
            redirect('staff.php');
        }
        run("UPDATE user_account SET IsActive = 1 - IsActive WHERE UserID = ?", [$id]);
            if ($action === 'toggle_active') {
        $id = (int)($_POST['staff_id'] ?? 0);
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            flash('danger', 'You cannot deactivate your own account while logged in.');
            redirect('staff.php');
        }
        $row = fetch_one("SELECT CONCAT(FirstName,' ',LastName) AS Name, IsActive FROM user_account WHERE UserID = ?", [$id]);
        run("UPDATE user_account SET IsActive = 1 - IsActive WHERE UserID = ?", [$id]);
        $nowActive = $row ? !$row['IsActive'] : null;
        log_activity('Update', 'Staff',
            ($nowActive ? 'Reactivated' : 'Deactivated') . ' sign-in for "' . ($row['Name'] ?? "#$id") . '"', $id);
        flash('success', 'Staff sign-in access updated.');
        redirect('staff.php');
    }flash('success', 'Staff sign-in access updated.');
        redirect('staff.php');
    }
}

/* Staff = every user_account whose role is NOT "Member". */
$listSearch=mb_substr(trim((string)($_GET['q']??'')),0,100);$listSearchParams=[];$staffSearch='';
if($listSearch!==''){$staffSearch=" AND (CONCAT(ua.FirstName,' ',ua.LastName) LIKE ? OR ua.Username LIKE ? OR ua.Email LIKE ? OR r.RoleName LIKE ?)";$listSearchParams=array_fill(0,4,'%'.$listSearch.'%');}
$totalStaff = (int) fetch_value(
    "SELECT COUNT(*) FROM user_account ua
       JOIN role r ON r.RoleID = ua.RoleID
      WHERE r.RoleName <> 'Member' $staffSearch",$listSearchParams
);
$pg = pagination_calc($totalStaff, PER_PAGE_DEFAULT, pagination_page());

$staffList = fetch_all(
    "SELECT ua.UserID AS StaffID, ua.FirstName, ua.LastName, ua.Email, ua.Username,
            ua.Phone, ua.RoleID, r.RoleName, ua.IsActive, ua.LastLogin
       FROM user_account ua
       JOIN role r ON r.RoleID = ua.RoleID
      WHERE r.RoleName <> 'Member' $staffSearch
      ORDER BY ua.LastName, ua.FirstName
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",$listSearchParams
);
$roles = fetch_all("SELECT RoleID AS id, RoleName AS label FROM role WHERE RoleName <> 'Member' AND IsArchived = 0 ORDER BY RoleName");

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-toolbar"><form method="get" class="d-flex flex-wrap gap-2"><input type="search" name="q" class="form-control" value="<?= h($listSearch) ?>" placeholder="Search this list" aria-label="Search this list"><?php if(isset($view)):?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif;?><button class="btn btn-primary">Search</button></form>
  <div></div>
  <button class="btn btn-primary" onclick="openModal()"><i class="bi bi-plus-lg"></i> Add Staff</button>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Name</th>
          <th>Username</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Role</th>
          <th>Sign-in</th>
          <th>Last Login</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$staffList): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No staff found.</td></tr>
        <?php endif; ?>
        <?php foreach ($staffList as $s): ?>
          <?php $isSelf = (int)$s['StaffID'] === (int)($_SESSION['user_id'] ?? 0); ?>
          <tr class="<?= $s['IsActive'] == 0 ? 'table-warning' : '' ?>">
            <td class="fw-semibold">
              <?= h($s['FirstName'] . ' ' . $s['LastName']) ?>
              <?= $isSelf ? ' <span class="badge text-bg-light border">You</span>' : '' ?>
            </td>
            <td>
              <span class="badge text-bg-light border">
                <i class="bi bi-person"></i> <?= h($s['Username']) ?>
              </span>
            </td>
            <td class="small"><?= h($s['Email']) ?></td>
            <td class="small"><?= h($s['Phone'] ?: '—') ?></td>
            <td><span class="badge text-bg-light border"><?= h($s['RoleName']) ?></span></td>
            <td>
              <?= $s['IsActive']
                    ? '<span class="badge text-bg-success">Active</span>'
                    : '<span class="badge text-bg-dark">Deactivated</span>' ?>
            </td>
            <td class="small text-muted"><?= $s['LastLogin'] ? fmt_datetime($s['LastLogin']) : 'Never' ?></td>
            <td class="text-end text-nowrap">
              <button class="btn btn-sm btn-outline-primary" title="Edit" data-bs-toggle="tooltip"
                      onclick='openModal(<?= json_encode($s) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>

              <form method="post" class="d-inline"
                    data-confirm="<?= $s['IsActive'] ? 'Deactivate this staff account?' : 'Reactivate this staff account?' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="staff_id" value="<?= h($s['StaffID']) ?>">
                <button class="btn btn-sm <?= $s['IsActive'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                        title="<?= $s['IsActive'] ? 'Deactivate' : 'Reactivate' ?>"
                        data-bs-toggle="tooltip"
                        <?= $isSelf ? 'disabled' : '' ?>>
                  <i class="bi <?= $s['IsActive'] ? 'bi-person-slash' : 'bi-person-check' ?>"></i>
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

<div class="modal fade" id="staffModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="staffModalTitle">Add Staff</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="staff_id" id="staff_id">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">First Name</label>
            <input type="text" name="first_name" id="first_name" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Last Name</label>
            <input type="text" name="last_name" id="last_name" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Username</label>
            <input type="text" name="username" id="username" class="form-control"
                   required pattern="[A-Za-z0-9._-]{4,50}"
                   placeholder="e.g. jdoe">
            <div class="form-text">4–50 chars · letters, numbers, dot, underscore, hyphen.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="email" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" id="phone" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Role</label>
            <?= render_select('role_id', $roles, null, 'id="role_id"', true) ?>
          </div>

          <div class="col-12">
            <label class="form-label">
              Password <span id="pwHint" class="text-muted small"></span>
            </label>
            <input type="password" name="password" id="password" class="form-control"
                   autocomplete="new-password" minlength="8">
            <div class="form-text">At least 8 characters.</div>
          </div>
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
  document.getElementById('staffModalTitle').textContent = s ? 'Edit Staff' : 'Add Staff';
  document.getElementById('staff_id').value   = s ? s.StaffID   : '';
  document.getElementById('first_name').value = s ? s.FirstName : '';
  document.getElementById('last_name').value  = s ? s.LastName  : '';
  document.getElementById('username').value   = s ? s.Username  : '';
  document.getElementById('email').value      = s ? s.Email     : '';
  document.getElementById('phone').value      = s ? (s.Phone || '') : '';
  document.getElementById('role_id').value    = s ? s.RoleID    : '';
  document.getElementById('password').value   = '';
  document.getElementById('password').required = !s;
  document.getElementById('pwHint').textContent = s ? '(leave blank to keep current password)' : '';
  new bootstrap.Modal(document.getElementById('staffModal')).show();
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>