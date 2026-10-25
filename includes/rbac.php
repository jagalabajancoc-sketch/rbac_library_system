<?php
/**
 * Role-Based Access Control
 */

const CAPABILITY_ROLES = [
    // ---- Administrative ----
    'manage_staff'        => ['Admin'],
    'manage_roles'        => ['Admin'],
    'view_activity_log'   => ['Admin'],

    // ---- Master data ----
    'manage_authors'      => ['Admin'],
    'manage_publishers'   => ['Admin'],
    'manage_categories'   => ['Admin'],
    'manage_sections'     => ['Admin'],
    'manage_shelves'      => ['Admin', 'Librarian'],
    'manage_settings'     => ['Admin'],

    // ---- Catalog ----
    'manage_books'        => ['Admin', 'Librarian'],
    'manage_copies'       => ['Admin', 'Librarian'],

    // ---- Archive ----
    'archive_records'         => ['Admin'],
    'view_archived_records'   => ['Admin', 'Librarian'],

    // ---- Circulation ----
    'manage_members'         => ['Admin', 'Librarian'],
    'approve_requests'       => ['Admin', 'Librarian'],
    'process_loans'          => ['Admin', 'Librarian', 'Assistant'],
    'process_returns'        => ['Admin', 'Librarian', 'Assistant'],
    'process_in_library_use'      => ['Admin', 'Librarian', 'Assistant'],
    'view_in_library_use_history' => ['Admin', 'Librarian', 'Assistant'],
    'view_library_entries'   => ['Admin', 'Librarian', 'Assistant'],   // entry log (who is inside)
    'decide_use_requests'    => ['Admin', 'Librarian'],                // only the librarian grants permission to use/borrow
    'record_payments'        => ['Admin', 'Librarian'],
    'manage_donors'          => ['Admin'],
    'manage_finance'         => ['Admin'],

    // ---- Reports ----
    'view_financial_reports'      => ['Admin'],
];

/**
 * Reads the role name from the session.
 * Prefers the new 'user_role' key (set by session.php) but falls back
 * to the legacy 'staff_role' key so both work during migration.
 */
function current_role_name(): string
{
    if (!empty($_SESSION['user_role'])) return (string)$_SESSION['user_role'];
    if (!empty($_SESSION['staff_role'])) return (string)$_SESSION['staff_role'];
    return '';
}

function is_admin(): bool
{
    return strcasecmp(current_role_name(), 'Admin') === 0;
}

function is_librarian(): bool
{
    return strcasecmp(current_role_name(), 'Librarian') === 0;
}

function is_assistant(): bool
{
    return strcasecmp(current_role_name(), 'Assistant') === 0;
}

function has_permission(string $capability): bool
{
    if (!function_exists('is_staff_session') || !is_staff_session()) {
        return false;
    }
    if (!array_key_exists($capability, CAPABILITY_ROLES)) {
        error_log("RBAC: unknown capability '{$capability}' requested — denying.");
        return false;
    }
    foreach (CAPABILITY_ROLES[$capability] as $role) {
        if (strcasecmp(current_role_name(), $role) === 0) {
            return true;
        }
    }
    return false;
}

function require_permission(string $capability): void
{
    if (!has_permission($capability)) {
        forbidden();
    }
}

function forbidden(): void
{
    http_response_code(403);
    $staff = function_exists('current_staff') ? current_staff() : null;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Access Denied — Bibliophilia</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="login-wrapper">
  <div class="login-card text-center" style="max-width:440px;">
    <div class="brand">
      <i class="bi bi-shield-lock" style="color:#ef4444;background:#fef2f2;"></i>
      <h2>Access Denied</h2>
      <p class="text-muted small mb-0">
        <?php if ($staff): ?>
          You're signed in as <strong><?= h($staff['Name']) ?></strong>
          (<?= h($staff['Role'] ?: 'Unknown role') ?>),
          which doesn't have permission to view this page.
        <?php else: ?>
          You must be signed in to view this page.
        <?php endif; ?>
      </p>
    </div>
    <a href="index.php" class="btn btn-primary w-100 mt-2"><i class="bi bi-speedometer2"></i> Back to Dashboard</a>
    <a href="logout.php" class="btn btn-outline-secondary w-100 mt-2">Sign out</a>
  </div>
</div>
</body>
</html>
<?php
    exit;
}