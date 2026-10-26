<?php
/**
 * MEMBER-SIDE GATE
 * ----------------
 * Every member portal page starts with:
 *     require_once 'includes/member_auth.php';
 *
 * Guarantees the visitor is logged in AS A MEMBER. Staff hitting a member
 * URL are sent back to the staff dashboard.
 *
 * SECURITY CORE: current_member_id() is read from the SESSION ONLY.
 * No member portal query may ever take a MemberID from $_GET or $_POST.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/activity.php';

function require_member(): void
{
    if (is_member_session() && !refresh_session_account()) {
        session_start();
        flash('warning', 'Your session is no longer valid. Please sign in again.', true);
        redirect('login.php');
    }
    if (!is_member_session()) {
        if (is_staff_session()) redirect('index.php');
        redirect('login.php');
    }
}

/**
 * Returns the logged-in member row from user_account.
 * Re-reads on every load so a status change made by staff (e.g. Active →
 * Expired) takes effect on the member's very next page load.
 */
function current_member(): array
{
    $u = fetch_one(
        "SELECT ua.*, r.RoleName
           FROM user_account ua
           LEFT JOIN role r ON r.RoleID = ua.RoleID
          WHERE ua.UserID = ?",
        [current_user_id()]
    );

    // The member record was deleted while the session was still alive.
    if (!$u) {
        $_SESSION = [];
        session_destroy();
        redirect('login.php');
    }
    return $u;
}

/**
 * Convenience wrapper: may the CURRENT member start a new borrowing
 * request? Delegates to the shared rule used by staff loan processing,
 * so member-side and staff-side never disagree.
 */
function current_member_can_borrow(): array
{
    return member_borrow_check(current_member_id());
}

require_member();
if (empty($_SESSION['reservation_cleanup_at']) || $_SESSION['reservation_cleanup_at'] < time() - 60) {
    cleanup_expired_holds();
    $_SESSION['reservation_cleanup_at'] = time();
}