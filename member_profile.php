<?php
/**
 * MY PROFILE
 *
 * A member may update their own contact details and password. They may NOT
 * change MembershipStatus or their IsActive flag — those are staff controlled.
 * Every write is scoped to the session's UserID.
 */
require_once __DIR__ . '/includes/member_auth.php';

$page_title = 'My Profile';
$memberId   = current_member_id();
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    }

    /* ---- Update contact details ---- */
    elseif ($action === 'update_profile') {
        $first   = trim($_POST['first_name'] ?? '');
        $last    = trim($_POST['last_name']  ?? '');
        $email   = trim($_POST['email']      ?? '');
        $phone   = trim($_POST['phone']      ?? '');
        $address = trim($_POST['address']    ?? '');

        if ($first === '' || $last === '') $errors[] = 'First and last name are required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

        if (!$errors) {
            $taken = (int)fetch_value(
                "SELECT COUNT(*) FROM user_account WHERE Email = ? AND UserID <> ?",
                [$email, $memberId]
            );
            if ($taken > 0) {
                $errors[] = 'That email address is already used by another account.';
            } else {
                // NOTE: MembershipStatus, IsActive and RoleID are deliberately
                // absent from this UPDATE — a member cannot self-activate.
                run(
                    "UPDATE user_account
                        SET FirstName = ?, LastName = ?, Email = ?, Phone = ?, Address = ?
                      WHERE UserID = ?",
                    [$first, $last, $email, $phone ?: null, $address ?: null, $memberId]
                );
                $_SESSION['user_name'] = $first . ' ' . $last;
                flash('success', 'Your details have been updated.');
                redirect('member_profile.php');
            }
        }
    }

    /* ---- Change password ---- */
    elseif ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password']     ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $login = fetch_one("SELECT UserID, PasswordHash FROM user_account WHERE UserID = ?", [$memberId]);

        if (!$login || !password_verify($current, $login['PasswordHash'])) {
            $errors[] = 'Your current password is not correct.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'The new password must be at least 8 characters long.';
        } elseif ($new !== $confirm) {
            $errors[] = 'The two new passwords do not match.';
        } else {
            run("UPDATE user_account SET PasswordHash = ? WHERE UserID = ?",
                [password_hash($new, PASSWORD_DEFAULT), $login['UserID']]);
            flash('success', 'Your password has been changed.');
            redirect('member_profile.php');
        }
    }
}

$member = current_member();
$stats  = [
    'loans'    => (int)fetch_value("SELECT COUNT(*) FROM loan         WHERE BorrowerUserID = ?", [$memberId]),
    'requests' => (int)fetch_value("SELECT COUNT(*) FROM borrow_request WHERE UserID         = ?", [$memberId]),
];

require __DIR__ . '/includes/member_layout_top.php';
?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0 ps-3 small"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header">Student</div>
      <div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-6">Student ID</dt>
          <dd class="col-6"><?= h($member['MembershipNumber'] ?: '—') ?></dd>

          <dt class="col-6">Username</dt>
          <dd class="col-6"><?= h($member['Username'] ?: '—') ?></dd>

          <dt class="col-6">Registered</dt>
          <dd class="col-6"><?= fmt_date($member['RegistrationDate']) ?></dd>

          <dt class="col-6">Account Status</dt>
          <dd class="col-6"><?= membership_badge($member['MembershipStatus']) ?></dd>

          <dt class="col-6">Account Status</dt>
          <dd class="col-6">
            <?= $member['IsActive']
                  ? '<span class="badge text-bg-success">Enabled</span>'
                  : '<span class="badge text-bg-secondary">Disabled</span>' ?>
          </dd>

          <dt class="col-6">Last Login</dt>
          <dd class="col-6"><?= fmt_datetime($member['LastLogin']) ?></dd>

          <dt class="col-6">Total Loans</dt>
          <dd class="col-6"><?= h($stats['loans']) ?></dd>

          <dt class="col-6">Total Requests</dt>
          <dd class="col-6"><?= h($stats['requests']) ?></dd>
        </dl>
        <hr>
        <p class="small text-muted mb-0">
          <strong>Account Status</strong> decides whether you may borrow.
          <strong>Account Status</strong> decides whether you may sign in.
          Only library staff can change either one.
        </p>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Change Password</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="mb-3">
            <label class="form-label">Current Password</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">New Password</label>
            <input type="password" name="new_password" class="form-control" required minlength="8">
            <div class="form-text">At least 8 characters.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" required minlength="8">
          </div>
          <button class="btn btn-outline-primary w-100">Update Password</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">My Details</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_profile">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">First Name</label>
              <input type="text" name="first_name" class="form-control" required value="<?= h($member['FirstName']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Last Name</label>
              <input type="text" name="last_name" class="form-control" required value="<?= h($member['LastName']) ?>">
            </div>
            <div class="col-md-7">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" required value="<?= h($member['Email']) ?>">
            </div>
            <div class="col-md-5">
              <label class="form-label">Phone</label>
              <input type="text" name="phone" class="form-control" value="<?= h($member['Phone']) ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <input type="text" name="address" class="form-control" value="<?= h($member['Address']) ?>">
            </div>
          </div>
          <button class="btn btn-primary mt-4">Save Changes</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/member_layout_bottom.php'; ?>