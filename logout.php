<?php
require_once __DIR__ . '/includes/session.php';

if (is_staff_session() && function_exists('log_activity')) {
    log_activity('Logout', 'Auth', 'Signed out (' . ($_SESSION['user_name'] ?? 'unknown') . ')');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();
header('Location: login.php');
exit;