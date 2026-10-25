<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_members');
$page_title = 'Students';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    /* -----------------------------------------------------------------
       APPROVE / REJECT VERIFICATION
       ----------------------------------------------------------------- */
    if ($action === 'approve_verification' || $action === 'reject_verification') {
        $id  = (int)($_POST['member_id'] ?? 0);
        $row = fetch_one(
            "SELECT UserID, FirstName, LastName, VerificationStatus, MembershipNumber
               FROM user_account WHERE UserID = ?",
            [$id]
        );
        if (!$row) { flash('danger', 'Student not found.'); redirect('members.php'); }

        if ($action === 'approve_verification') {
            if (($row['VerificationStatus'] ?? '') === 'Approved' && $row['MembershipNumber']) {
                flash('warning', 'This student is already verified.');
                redirect('members.php' . (isset($_GET['vstatus']) ? '?vstatus=' . urlencode($_GET['vstatus']) : ''));
            }
            $number = $row['MembershipNumber'] ?: generate_membership_number($row['UserID']);   // the Student ID given at registration
            run(
                "UPDATE user_account
                    SET VerificationStatus = 'Approved',
                        MembershipStatus   = 'Active',
                        MembershipNumber   = ?,
                        VerifiedByUserID   = ?,
                        VerifiedAt         = NOW()
                  WHERE UserID = ?",
                [$number, $_SESSION['user_id'], $row['UserID']]
            );
            log_activity('Approve', 'Members',
                'Approved student "' . $row['FirstName'] . ' ' . $row['LastName'] . '" — membership #' . $number,
                $row['UserID']);
            flash('success', 'Student approved. Student ID: ' . $number);
        } else {
            run(
                "UPDATE user_account
                    SET VerificationStatus = 'Rejected',
                        VerifiedByUserID   = ?,
                        VerifiedAt         = NOW()
                  WHERE UserID = ?",
                [$_SESSION['user_id'], $row['UserID']]
            );
            log_activity('Reject', 'Members',
                'Rejected member "' . $row['FirstName'] . ' ' . $row['LastName'] . '"',
                $row['UserID']);
            flash('warning', 'Member verification rejected.');
        }
        redirect('members.php' . (isset($_GET['vstatus']) ? '?vstatus=' . urlencode($_GET['vstatus']) : ''));
    }

    /* -----------------------------------------------------------------
       SAVE (ADD / EDIT)
       ----------------------------------------------------------------- */
    if ($action === 'save') {
        $id      = $_POST['member_id'] ?? '';
        $first   = trim($_POST['first_name'] ?? '');
        $last    = trim($_POST['last_name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $regDate = $_POST['registration_date'] ?: date('Y-m-d');
        $status  = $_POST['membership_status'] ?? 'Active';
        if (!in_array($status, MEMBERSHIP_STATUSES, true)) $status = 'Active';
        $kind    = ($_POST['member_kind'] ?? 'Student') === 'Teacher' ? 'Teacher' : 'Student';
        $studentId = normalize_student_id((string)($_POST['student_id'] ?? ''));
        $studentIdOk = $studentId === '' || valid_student_id($studentId);
        $idTaken = $studentId !== '' && (int) fetch_value("SELECT COUNT(*) FROM user_account WHERE UPPER(MembershipNumber) = ? AND UserID <> ?", [$studentId, (int)$id]) > 0;

        if ($first === '' || $last === '' || $email === '') {
            flash('danger', 'First name, last name, and email are required.');
        } elseif (!$studentIdOk) {
            flash('danger', 'Student ID must be 6-25 letters, numbers or dashes, e.g. 02-2425-014468.');
        } elseif ($idTaken) {
            flash('danger', 'That Student ID is already registered to another student.');
        } else {
            try {
                if ($id) {
                    run(
                        "UPDATE user_account
                            SET FirstName=?, LastName=?, Email=?, Phone=?, Address=?,
                                RegistrationDate=?, MembershipStatus=?
                          WHERE UserID=?",
                        [$first, $last, $email, $phone, $address, $regDate, $status, $id]
                    );
                    if ($studentId !== '') run("UPDATE user_account SET MembershipNumber = ? WHERE UserID = ?", [$studentId, $id]);
                    if (desk_kind_ready()) run("UPDATE user_account SET MemberKind = ? WHERE UserID = ?", [$kind, $id]);
                    log_activity('Update', 'Members', 'Updated student "' . $first . ' ' . $last . '"', $id);
                    flash('success', 'Student updated.');
                } else {
                    $memberRoleId = (int) fetch_value("SELECT RoleID FROM role WHERE RoleName='Member' LIMIT 1");
                    run(
                        "INSERT INTO user_account
                            (RoleID, FirstName, LastName, Email, Phone, Address,
                             Username, PasswordHash, RegistrationDate, MembershipStatus,
                             VerificationStatus, IsActive)
                         VALUES (?,?,?,?,?,?,?,?,?,?, 'Approved', 1)",
                        [$memberRoleId, $first, $last, $email, $phone, $address,
                         strtolower(str_replace(' ', '.', $first . '.' . $last . '.' . time())),
                         '!pending!', $regDate, $status]
                    );
                    $newId = (int) last_id();
                    if ($studentId !== '') run("UPDATE user_account SET MembershipNumber = ? WHERE UserID = ?", [$studentId, $newId]);
                    if (desk_kind_ready()) run("UPDATE user_account SET MemberKind = ? WHERE UserID = ?", [$kind, $newId]);
                    log_activity('Create', 'Members', 'Added student "' . $first . ' ' . $last . '"', $newId);
                    flash('success', 'Student added. Use "Issue Login" to set portal credentials.');
                }
            } catch (PDOException $e) {
                flash('danger', 'Could not save student — that email may already be registered.');
            }
        }
        redirect('members.php');
    }

    /* -----------------------------------------------------------------
       SET MEMBERSHIP STATUS
       ----------------------------------------------------------------- */
    if ($action === 'set_status') {
        $id     = $_POST['member_id'] ?? '';
        $status = $_POST['membership_status'] ?? '';
        if (in_array($status, MEMBERSHIP_STATUSES, true)) {
            $mName = fetch_value("SELECT CONCAT(FirstName,' ',LastName) FROM user_account WHERE UserID = ?", [$id]);
            run("UPDATE user_account SET MembershipStatus = ? WHERE UserID = ?", [$status, $id]);
            log_activity('Update', 'Members',
                'Membership status for "' . ($mName ?: "#$id") . '" set to ' . $status, $id);
            flash('success', "Membership status set to $status.");
        }
        redirect('members.php');
    }

    /* -----------------------------------------------------------------
       TOGGLE SIGN-IN
       ----------------------------------------------------------------- */
    if ($action === 'toggle_login') {
        $id  = (int)($_POST['member_id'] ?? 0);
        $row = fetch_one("SELECT CONCAT(FirstName,' ',LastName) AS Name, IsActive FROM user_account WHERE UserID = ?", [$id]);
        run("UPDATE user_account SET IsActive = 1 - IsActive WHERE UserID = ?", [$id]);
        $nowActive = $row ? !$row['IsActive'] : null;
        log_activity('Update', 'Members',
            ($nowActive ? 'Enabled' : 'Disabled') . ' sign-in for "' . ($row['Name'] ?? "#$id") . '"', $id);
        flash('success', 'Member sign-in access updated.');
        redirect('members.php');
    }

    /* -----------------------------------------------------------------
       ISSUE LOGIN CREDENTIALS
       ----------------------------------------------------------------- */
    if ($action === 'issue_login') {
        $id       = $_POST['member_id'] ?? '';
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9._-]{4,50}$/', $username)) {
            flash('danger', 'Username must be 4–50 characters (letters, numbers, . _ -).');
        } elseif (strlen($password) < 8) {
            flash('danger', 'Password must be at least 8 characters.');
        } elseif (fetch_value("SELECT COUNT(*) FROM user_account WHERE Username = ?", [$username]) > 0) {
            flash('danger', 'That username is already taken.');
        } else {
            run(
                "UPDATE user_account SET Username = ?, PasswordHash = ?, IsActive = 1 WHERE UserID = ?",
                [$username, password_hash($password, PASSWORD_DEFAULT), $id]
            );
            log_activity('Update', 'Members',
                'Issued portal credentials for student #' . $id . ' (username: ' . $username . ')', $id);
            flash('success', 'Portal credentials set for this student.');
        }
        redirect('members.php');
    }
}

/* ---------------------------------------------------------------------
   FILTERS
   ------------------------------------------------------------------- */
$search        = trim($_GET['q'] ?? '');
$statusFilter  = $_GET['status'] ?? '';
$vstatusFilter = $_GET['vstatus'] ?? '';

$where  = ["r.RoleName = 'Member'"];
$params = [];
if ($search !== '') {
    $where[] = "(ua.FirstName LIKE ? OR ua.LastName LIKE ? OR ua.Email LIKE ? OR ua.MembershipNumber LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if (in_array($statusFilter, MEMBERSHIP_STATUSES, true)) {
    $where[] = 'ua.MembershipStatus = ?';
    $params[] = $statusFilter;
}
if (in_array($vstatusFilter, ['Pending', 'Approved', 'Rejected'], true)) {
    $where[] = 'ua.VerificationStatus = ?';
    $params[] = $vstatusFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalMembers = (int) fetch_value(
    "SELECT COUNT(*) FROM user_account ua JOIN role r ON r.RoleID = ua.RoleID $whereSql",
    $params
);
$pg = pagination_calc($totalMembers, PER_PAGE_DEFAULT, pagination_page());

$members = fetch_all(
    "SELECT ua.UserID AS MemberID, ua.*, r.RoleName,
        (SELECT COUNT(*) FROM loan l WHERE l.BorrowerUserID = ua.UserID) AS LoanCount,
        (SELECT COUNT(*) FROM loan_detail ld JOIN loan l3 ON l3.LoanID = ld.LoanID
            LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
           WHERE l3.BorrowerUserID = ua.UserID AND rd.ReturnDetailID IS NULL) AS CurrentlyOut,
        (SELECT COUNT(*) FROM fine f JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
            JOIN loan l2 ON l2.LoanID = ld.LoanID
           WHERE l2.BorrowerUserID = ua.UserID AND f.PaymentStatus = 'Unpaid') AS UnpaidFines
     FROM user_account ua
     JOIN role r ON r.RoleID = ua.RoleID
     $whereSql
     ORDER BY ua.MembershipStatus <> 'Active', ua.VerificationStatus <> 'Pending', ua.LastName, ua.FirstName
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$pendingCount = (int) fetch_value(
    "SELECT COUNT(*) FROM user_account ua JOIN role r ON r.RoleID = ua.RoleID
      WHERE r.RoleName = 'Member' AND ua.VerificationStatus = 'Pending'"
);

require __DIR__ . '/includes/layout_top.php';
?>

<?php if ($pendingCount > 0 && $vstatusFilter !== 'Pending'): ?>
  <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-hourglass-split"></i>
      <span>
        <strong><?= $pendingCount ?></strong> student registration<?= $pendingCount === 1 ? '' : 's' ?>
        awaiting in-person verification.
      </span>
    </div>
    <a href="members.php?vstatus=Pending" class="btn btn-sm btn-warning">
      <i class="bi bi-eye"></i> Review Pending
    </a>
  </div>
<?php endif; ?>

<div class="page-toolbar">
  <form class="d-flex gap-2 flex-wrap" method="get">
    <input type="search" name="q" class="form-control"
           placeholder="Search name, email, membership #..."
           value="<?= h($search) ?>" style="min-width:220px;">
    <select name="status" class="form-select" onchange="this.form.submit()" style="min-width:150px;">
      <option value="">All Memberships</option>
      <?php foreach (MEMBERSHIP_STATUSES as $st): ?>
        <option value="<?= h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= h($st) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="vstatus" class="form-select" onchange="this.form.submit()" style="min-width:180px;">
      <option value="">All Verifications</option>
      <option value="Pending"  <?= $vstatusFilter === 'Pending'  ? 'selected' : '' ?>>
        Pending<?= $pendingCount > 0 ? ' (' . $pendingCount . ')' : '' ?>
      </option>
      <option value="Approved" <?= $vstatusFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
      <option value="Rejected" <?= $vstatusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
    </select>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  </form>
  <button class="btn btn-primary" onclick="openModal()"><i class="bi bi-plus-lg"></i> Add Student</button>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr>
        <th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Registered</th>
        <th>Verification</th><th>Membership</th><th>Sign-in</th>
        <th>Loans</th><th>Out</th><th>Fines</th><th class="text-end">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (!$members): ?>
          <tr><td colspan="12" class="text-center text-muted py-4">No students found.</td></tr>
        <?php endif; ?>
        <?php foreach ($members as $m): ?>
          <?php
            $vstatus   = $m['VerificationStatus'] ?? 'Approved';
            $isPending = $vstatus === 'Pending';
          ?>
          <tr class="<?= $m['MembershipStatus'] !== 'Active' ? 'table-warning' : '' ?> <?= $isPending ? 'table-info' : '' ?>">
            <td class="text-muted small">#<?= h($m['MemberID']) ?></td>
            <td>
              <a href="member_view.php?id=<?= h($m['MemberID']) ?>" class="fw-semibold text-decoration-none">
                <?= h($m['FirstName'] . ' ' . $m['LastName']) ?>
              </a>
            </td>
            <td class="small"><?= h($m['Email']) ?></td>
            <td class="small"><?= h($m['Phone'] ?: '—') ?></td>
            <td class="small"><?= fmt_date($m['RegistrationDate']) ?></td>
            <td>
              <?= verification_badge($vstatus) ?>
              <?php if (!empty($m['MembershipNumber'])): ?>
                <div class="small text-muted mt-1"><?= h($m['MembershipNumber']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" class="d-flex gap-1">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="member_id" value="<?= h($m['MemberID']) ?>">
                <select name="membership_status" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                  <?php foreach (MEMBERSHIP_STATUSES as $st): ?>
                    <option value="<?= h($st) ?>" <?= $m['MembershipStatus'] === $st ? 'selected' : '' ?>><?= h($st) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="small">
              <?php if ($m['IsActive']): ?>
                <span class="badge text-bg-success">Active</span>
              <?php else: ?>
                <span class="badge text-bg-dark">Disabled</span>
              <?php endif; ?>
            </td>
            <td><span class="badge text-bg-light border"><?= h($m['LoanCount']) ?></span></td>
            <td>
              <?= $m['CurrentlyOut'] > 0
                    ? '<span class="badge text-bg-warning">' . h($m['CurrentlyOut']) . '</span>'
                    : '<span class="badge text-bg-light border">0</span>' ?>
            </td>
            <td>
              <?= $m['UnpaidFines'] > 0
                    ? '<span class="badge text-bg-danger">' . h($m['UnpaidFines']) . '</span>'
                    : '<span class="badge text-bg-light border">0</span>' ?>
            </td>
            <td class="text-end text-nowrap">
              <?php if ($isPending): ?>
                <form method="post" class="d-inline"
                      data-confirm="Approve this student's verification? They will receive a membership number and be able to borrow.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="approve_verification">
                  <input type="hidden" name="member_id" value="<?= h($m['MemberID']) ?>">
                  <button class="btn btn-sm btn-success">
                    <i class="bi bi-check2-circle"></i> Approve
                  </button>
                </form>
                <form method="post" class="d-inline"
                      data-confirm="Reject this student's registration? They will not be able to borrow.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reject_verification">
                  <input type="hidden" name="member_id" value="<?= h($m['MemberID']) ?>">
                  <button class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x-circle"></i> Reject
                  </button>
                </form>
              <?php endif; ?>

              <a href="member_view.php?id=<?= h($m['MemberID']) ?>"
                 class="btn btn-sm btn-outline-secondary" title="View">
                <i class="bi bi-eye"></i>
              </a>
              <button class="btn btn-sm btn-outline-primary"
                      onclick='openModal(<?= json_encode($m) ?>)' title="Edit">
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline"
                    data-confirm="<?= $m['IsActive'] ? 'Deactivate sign-in?' : 'Reactivate sign-in?' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_login">
                <input type="hidden" name="member_id" value="<?= h($m['MemberID']) ?>">
                <button class="btn btn-sm <?= $m['IsActive'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                        title="<?= $m['IsActive'] ? 'Deactivate' : 'Reactivate' ?>">
                  <i class="bi <?= $m['IsActive'] ? 'bi-person-slash' : 'bi-person-check' ?>"></i>
                </button>
              </form>
              <button class="btn btn-sm btn-outline-success"
                      onclick='openLoginModal(<?= json_encode(["MemberID" => $m["MemberID"], "Name" => $m["FirstName"] . " " . $m["LastName"], "Username" => $m["Username"]]) ?>)'
                      title="Set portal credentials">
                <i class="bi bi-key"></i>
              </button>
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

<!-- ADD / EDIT MEMBER MODAL -->
<div class="modal fade" id="memberModal" tabindex="-1"><div class="modal-dialog">
  <form method="post" class="modal-content">
    <?= csrf_field() ?>
    <div class="modal-header"><h5 class="modal-title" id="memberModalTitle">Add Student</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="member_id" id="member_id">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">First Name</label>
          <input type="text" name="first_name" id="first_name" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Last Name</label>
          <input type="text" name="last_name" id="last_name" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Email</label>
          <input type="email" name="email" id="email" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Phone</label>
          <input type="text" name="phone" id="phone" class="form-control"></div>
        <div class="col-12"><label class="form-label">Address</label>
          <input type="text" name="address" id="address" class="form-control"></div>
        <?php if (desk_kind_ready()): ?>
        <div class="col-md-6"><label class="form-label">Type</label>
          <select name="member_kind" id="member_kind" class="form-select"><option value="Student">Student</option><option value="Teacher">Teacher</option></select>
          <div class="form-text">Teachers borrow at the Library Desk with the same form as students.</div></div>
        <?php endif; ?>
        <div class="col-md-6"><label class="form-label">Student ID No.</label>
          <input type="text" name="student_id" id="student_id" class="form-control" maxlength="25" placeholder="02-2425-014468">
          <div class="form-text">The number on the school ID. It is the student's library ID and QR code.</div></div>
        <div class="col-md-6"><label class="form-label">Registration Date</label>
          <input type="date" name="registration_date" id="registration_date" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Membership Status</label>
          <select name="membership_status" id="membership_status" class="form-select">
            <?php foreach (MEMBERSHIP_STATUSES as $st): ?>
              <option value="<?= h($st) ?>"><?= h($st) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="form-text mt-3">
        Students added here are automatically marked <strong>Approved</strong>.
        Self-registered students arrive as <strong>Pending</strong>.
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-primary">Save</button>
    </div>
  </form>
</div></div>

<!-- PORTAL CREDENTIALS MODAL -->
<div class="modal fade" id="loginModal" tabindex="-1"><div class="modal-dialog">
  <form method="post" class="modal-content">
    <?= csrf_field() ?>
    <div class="modal-header"><h5 class="modal-title">Set Portal Credentials</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="action" value="issue_login">
      <input type="hidden" name="member_id" id="login_member_id">
      <p class="small text-muted">Credentials for <strong id="login_member_name"></strong>.</p>
      <div class="mb-3"><label class="form-label">Username</label>
        <input type="text" name="username" id="login_username" class="form-control"
               required pattern="[A-Za-z0-9._-]{4,50}"></div>
      <div class="mb-3"><label class="form-label">New Password</label>
        <input type="text" name="password" class="form-control" required minlength="8">
        <div class="form-text">At least 8 characters. The student can change this later in their portal.</div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-primary">Save Credentials</button>
    </div>
  </form>
</div></div>

<script>
function openModal(m) {
  document.getElementById('memberModalTitle').textContent = m ? 'Edit Student' : 'Add Student';
  document.getElementById('member_id').value         = m ? m.MemberID : '';
  document.getElementById('first_name').value        = m ? m.FirstName : '';
  document.getElementById('last_name').value         = m ? m.LastName : '';
  document.getElementById('email').value             = m ? m.Email : '';
  document.getElementById('phone').value             = m ? (m.Phone || '') : '';
  document.getElementById('address').value           = m ? (m.Address || '') : '';
  document.getElementById('student_id').value        = m ? (m.MembershipNumber || '') : '';
  if (document.getElementById('member_kind')) document.getElementById('member_kind').value = m ? (m.MemberKind || 'Student') : 'Student';
  document.getElementById('registration_date').value = m ? m.RegistrationDate : new Date().toISOString().slice(0, 10);
  document.getElementById('membership_status').value = m ? (m.MembershipStatus || 'Active') : 'Active';
  new bootstrap.Modal(document.getElementById('memberModal')).show();
}
function openLoginModal(m) {
  document.getElementById('login_member_id').value         = m.MemberID;
  document.getElementById('login_member_name').textContent = m.Name;
  document.getElementById('login_username').value          = m.Username || '';
  new bootstrap.Modal(document.getElementById('loginModal')).show();
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>