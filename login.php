<?php
require_once __DIR__ . '/includes/session.php';

if (is_staff_session() || is_member_session()) {
    redirect(home_for_account());
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_check()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter both your username and password.';
    } else {
        $account = find_login_by_username($username);

        if (!$account) {
            $error = 'Invalid username or password.';
        } elseif (!password_verify($password, $account['PasswordHash'])) {
            $error = 'Invalid username or password.';
        } elseif (!$account['IsActive']) {
            $error = 'This account has been disabled. Please contact the library.';
        } else {
            $type = derive_account_type($account);
            if ($type === '') {
                $error = 'This account is not correctly linked to a role. Please contact the library.';
            } else {
                run("UPDATE user_account SET LastLogin = NOW() WHERE UserID = ?", [$account['UserID']]);
                establish_session($account, $type);

                if ($type === 'Member') {
                    $vs = $account['VerificationStatus'] ?? 'Approved';
                    if ($vs === 'Pending') {
                        flash('warning',
                            'Your account is pending verification. Please visit the library and present a valid ID to activate borrowing.',
                            true);   // true = centered modal
                    } elseif ($vs === 'Rejected') {
                        flash('warning',
                            'Your registration was rejected. Please visit the library to correct your information.',
                            true);
                    } elseif ($account['MembershipStatus'] !== 'Active') {
                        flash('warning',
                            'Your student account is currently ' . $account['MembershipStatus']
                            . '. You can view your account but cannot request books until it is reactivated by staff.',
                            true);
                    }
                }
                redirect(home_for_account());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — Bibliophilia</title>
<script>
(function () {
  try {
    var t = localStorage.getItem('bibliophilia-theme');
    if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
    else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      document.documentElement.setAttribute('data-theme', 'dark');
    }
  } catch (e) {}
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="login-wrapper">
  <div class="login-card">
    <div class="brand">
      <?php if (file_exists(__DIR__ . '/assets/img/logo.png')): ?>
        <img src="assets/img/logo.png" alt="Bibliophilia" class="brand-logo-large">
      <?php else: ?>
        <svg class="brand-logo-large" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M2 36 Q14 30 24 34 Q34 30 46 36 L46 40 Q34 34 24 38 Q14 34 2 40 Z" fill="#c9a86e"/>
          <path d="M4 32 Q14 27 23 32 L23 37 Q14 32 4 37 Z" fill="#e3cca0"/>
          <path d="M25 32 Q34 27 44 32 L44 37 Q34 32 25 37 Z" fill="#e3cca0"/>
          <rect x="15" y="8" width="4" height="22" fill="#0f2942"/>
          <rect x="21" y="6" width="4" height="24" fill="#0f2942"/>
          <rect x="27" y="10" width="4" height="20" fill="#0f2942"/>
        </svg>
      <?php endif; ?>
      <h2>Bibliophilia</h2>
      <p class="brand-sub">Books · Learn · Grow</p>
    </div>

    <div class="alert alert-danger py-2 mb-3 d-none" id="login-error-source">
      <span><?= h($error) ?></span>
    </div>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" required autofocus
               autocomplete="username" value="<?= h($_POST['username'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary w-100">
        <i class="bi bi-box-arrow-in-right"></i> Sign in
      </button>
    </form>

    <hr class="my-4">
    <p class="text-center small mb-0 text-muted">
      New to the library?
      <a href="register.php" class="fw-semibold ms-1">Create a student account</a>
    </p>
    <p class="text-center small text-muted mt-2 mb-0" style="font-size:.78rem;">
      New accounts require <strong>in-person verification</strong> at the library before borrowing is enabled.
    </p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/swal-helpers.js"></script>
<script>
<?php if ($error): ?>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof swalError === 'function') {
    swalError('Sign-in failed', <?= json_encode($error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
  } else {
    document.getElementById('login-error-source').classList.remove('d-none');
  }
});
<?php endif; ?>
</script>
</body>
</html>