<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/activity.php';

function require_login(): void
{
    if (is_staff_session() && !refresh_session_account()) {
        session_start();
        flash('warning', 'Your session is no longer valid. Please sign in again.', true);
        redirect('login.php');
    }
    if (!is_staff_session()) {
        if (is_member_session()) {
            flash('warning', 'That page is part of the staff area. Here is your member portal.');
            redirect('member_portal.php');
        }
        redirect('login.php');
    }
}

function current_staff(): ?array
{
    if (!is_staff_session()) return null;
    return [
        'UserID' => $_SESSION['user_id'],
        'Name'   => $_SESSION['user_name'] ?? '',
        'Role'   => $_SESSION['user_role'] ?? '',
        'RoleID' => $_SESSION['user_role_id'] ?? null,
    ];
}

require_login();

/* Run expiry cleanup at most once per minute per session. */
if (empty($_SESSION['reservation_cleanup_at']) || $_SESSION['reservation_cleanup_at'] < time() - 60) {
    cleanup_expired_holds();
    $_SESSION['reservation_cleanup_at'] = time();
}