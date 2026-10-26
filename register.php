<?php
require_once __DIR__ . '/includes/session.php';

if (is_staff_session() || is_member_session()) {
    redirect(home_for_account());
}

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name'  => trim($_POST['last_name']  ?? ''),
        'email'      => trim($_POST['email']      ?? ''),
        'phone'      => trim($_POST['phone']      ?? ''),
        'address'    => trim($_POST['address']    ?? ''),
        'username'   => trim($_POST['username']   ?? ''),
        'student_id' => normalize_borrower_id((string)($_POST['borrower_id'] ?? $_POST['student_id'] ?? '')),
        'member_kind' => is_string($_POST['member_kind']??null)?$_POST['member_kind']:'Student',
    ];
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if (!csrf_check()) $errors[] = 'Your session expired. Please submit the form again.';
    if ($old['first_name'] === '') $errors[] = 'First name is required.';
    if ($old['last_name']  === '') $errors[] = 'Last name is required.';
    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($old['username'] === '' || !preg_match('/^[A-Za-z0-9._-]{4,50}$/', $old['username']))
        $errors[] = 'Username must be 4–50 characters (letters, numbers, . _ -).';
    if (!in_array($old['member_kind'],['Student','Teacher'],true)) $errors[] = 'Choose a valid borrower type.';
    if (!desk_kind_ready()) $errors[] = 'Borrower registration is temporarily unavailable. Please contact library staff.';
    if ($old['student_id'] === '') $errors[] = 'Borrower ID number is required.';
    elseif (!valid_borrower_id($old['student_id'])) $errors[] = 'Use 3–30 letters, numbers or dashes from your school or employee ID.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    elseif ($password !== $confirm) $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        if (fetch_value("SELECT COUNT(*) FROM user_account WHERE Username = ?", [$old['username']]) > 0)
            $errors[] = 'That username is already taken.';
        if (fetch_value("SELECT COUNT(*) FROM user_account WHERE Email = ?", [$old['email']]) > 0)
            $errors[] = 'That email is already registered.';
        if (borrower_id_in_use($old['student_id']))
            $errors[] = 'That Borrower ID is already registered. Please see the librarian if this is yours.';
    }

    if (!$errors) {
        try {
            $memberRoleId = (int) fetch_value("SELECT RoleID FROM role WHERE RoleName = 'Member' LIMIT 1");
            run(
                "INSERT INTO user_account
                    (RoleID, FirstName, LastName, Email, Phone, Address,
                     Username, PasswordHash, RegistrationDate, MembershipStatus,
                     VerificationStatus, IsActive, MembershipNumber, MemberKind)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'Active', 'Pending', 1, ?, ?)",
                [$memberRoleId, $old['first_name'], $old['last_name'], $old['email'],
                 $old['phone'] ?: null, $old['address'] ?: null,
                 $old['username'], password_hash($password, PASSWORD_DEFAULT), $old['student_id'], $old['member_kind']]
            );
            flash('success', 'Your account has been created. Please visit the library and present your school or employee ID so staff can verify your account and borrower type.', true);
            redirect('login.php');
        } catch (PDOException $e) {
            $errors[] = 'Registration could not be completed — that username, email or Borrower ID may have just been taken.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account — Bibliophilia</title>
<script>
(function () {
  try {
    var t = localStorage.getItem('bibliophilia-theme');
    if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
  } catch (e) {}
})();
</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="login-wrapper"><div class="login-card">
  <div class="brand">
    <h2>Bibliophilia</h2>
    <p class="brand-sub">Create Your Borrower Account</p>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger py-2 mb-3 d-none" id="reg-error-source">
      <ul class="mb-0 ps-3 small"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="row g-2">
      <div class="col-6"><label class="form-label small">First Name</label>
        <input type="text" name="first_name" class="form-control" required value="<?= h($old['first_name'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label small">Last Name</label>
        <input type="text" name="last_name" class="form-control" required value="<?= h($old['last_name'] ?? '') ?>"></div>
    </div>
    <div class="mb-3 mt-3"><label class="form-label" for="register-kind">Borrower type</label>
      <select name="member_kind" id="register-kind" class="form-select" required>
        <option value="Student" <?= ($old['member_kind']??'Student')==='Student'?'selected':'' ?>>Student</option>
        <option value="Teacher" <?= ($old['member_kind']??'')==='Teacher'?'selected':'' ?>>Teacher</option>
      </select><div class="form-text">Library staff will verify your type when checking your ID.</div></div>
    <div class="mb-3"><label class="form-label" for="register-id">Borrower ID Number</label>
      <input type="text" name="borrower_id" id="register-id" class="form-control" required maxlength="30" placeholder="School or employee ID number" autocapitalize="characters" value="<?= h($old['student_id'] ?? '') ?>">
      <div class="form-text">Use the number printed on your school or employee ID. This becomes your library borrower ID.</div></div>
    <div class="mb-3"><label class="form-label">Email</label>
      <input type="email" name="email" class="form-control" required value="<?= h($old['email'] ?? '') ?>"></div>
    <div class="row g-2">
      <div class="col-6"><label class="form-label small">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= h($old['phone'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label small">Address</label>
        <input type="text" name="address" class="form-control" value="<?= h($old['address'] ?? '') ?>"></div>
    </div>
    <hr class="my-3">
    <div class="mb-3"><label class="form-label">Username</label>
      <input type="text" name="username" class="form-control" required value="<?= h($old['username'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label">Password</label>
      <input type="password" name="password" class="form-control" required minlength="8">
      <div class="form-text">At least 8 characters.</div></div>
    <div class="mb-3"><label class="form-label">Confirm Password</label>
      <input type="password" name="confirm_password" class="form-control" required minlength="8"></div>
    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-person-plus"></i> Create Account</button>
  </form>
  <hr class="my-4">
  <p class="text-center small mb-0 text-muted">Already registered?
    <a href="login.php" class="fw-semibold ms-1">Sign in</a></p>
  <p class="text-center small text-muted mt-2 mb-0" style="font-size:.78rem;">
    New accounts require <strong>in-person verification</strong> at the library before borrowing is enabled.
  </p>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/swal-helpers.js"></script>
<script>
<?php if ($errors): ?>
document.addEventListener('DOMContentLoaded', function () {
  var items = <?= json_encode(array_values($errors), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  if (typeof Swal !== 'undefined') {
    var html = items.length === 1
      ? '<p class="mb-0">' + items[0].replace(/</g, '&lt;') + '</p>'
      : '<ul class="text-start small mb-0" style="padding-left:1.1rem;">' +
          items.map(function (m) { return '<li>' + m.replace(/</g, '&lt;') + '</li>'; }).join('') + '</ul>';
    Swal.fire({ icon: 'error', title: 'Registration could not be completed', html: html, confirmButtonColor: '#dc2626' });
  } else {
    document.getElementById('reg-error-source').classList.remove('d-none');
  }
});
<?php endif; ?>
</script>
</body>
</html>