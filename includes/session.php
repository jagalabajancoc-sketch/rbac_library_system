<?php
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    // Session cookie is not readable by scripts and is not sent on cross-site requests.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/**
 * Re-check the signed-in account against the database on every request, so
 * disabling an account or changing its role takes effect immediately instead
 * of at the next login. Returns false (and ends the session) if it is no
 * longer valid.
 */
function refresh_session_account(): bool
{
    if (empty($_SESSION['user_id'])) return false;
    $row = fetch_one(
        "SELECT ua.IsActive, r.RoleName
           FROM user_account ua LEFT JOIN role r ON r.RoleID = ua.RoleID
          WHERE ua.UserID = ?",
        [$_SESSION['user_id']]
    );
    if (!$row || !(int)$row['IsActive'] || empty($row['RoleName'])) {
        $_SESSION = [];
        session_destroy();
        return false;
    }
    $_SESSION['user_role'] = $row['RoleName'];
    return true;
}

/* Session keys:
     user_id, user_name, user_role, user_role_id, user_type, member_status
   There is NO member_id or staff_id anymore — every FK points at UserID. */

function is_staff_session(): bool
{
    return ($_SESSION['user_type'] ?? '') === 'Staff' && !empty($_SESSION['user_id']);
}

function is_member_session(): bool
{
    return ($_SESSION['user_type'] ?? '') === 'Member' && !empty($_SESSION['user_id']);
}

function current_user_id(): ?int
{
    return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/** Members are just user_account rows with the Member role. */
function current_member_id(): ?int
{
    return is_member_session() ? (int)$_SESSION['user_id'] : null;
}

function home_for_account(): string
{
    if (is_staff_session())  return 'index.php';
    if (is_member_session()) return 'member_portal.php';
    return 'login.php';
}

function find_login_by_username(string $username): ?array
{
    return fetch_one(
        "SELECT ua.*, r.RoleName
           FROM user_account ua
           LEFT JOIN role r ON r.RoleID = ua.RoleID
          WHERE ua.Username = ?",
        [$username]
    );
}

function derive_account_type(array $u): string
{
    if (empty($u['RoleID'])) return '';
    return strcasecmp($u['RoleName'] ?? '', 'Member') === 0 ? 'Member' : 'Staff';
}

function establish_session(array $u, string $type): void
{
    session_regenerate_id(true);

    foreach (['user_id','user_name','user_role','user_role_id','user_type','member_status'] as $k) {
        unset($_SESSION[$k]);
    }

    $_SESSION['user_id']      = (int)$u['UserID'];
    $_SESSION['user_name']    = trim(($u['FirstName'] ?? '') . ' ' . ($u['LastName'] ?? ''));
    $_SESSION['user_role']    = $u['RoleName'] ?? '';
    $_SESSION['user_role_id'] = $u['RoleID'] ?? null;
    $_SESSION['user_type']    = $type;

    if ($type === 'Member') {
        $_SESSION['member_status'] = $u['MembershipStatus'] ?? 'Active';
    }

    if (function_exists('log_activity')) {
        log_activity('Login', 'Auth', 'Signed in as "' . $_SESSION['user_name'] . '"', (int)$u['UserID']);
    }
}