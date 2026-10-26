<?php

/**
 * Audit trail. Records who did what, for staff AND member actions
 * (e.g. a member submitting or cancelling a Borrow Request).
 * Logging must never break the page, so failures are swallowed.
 */
function log_activity(string $actionType, string $module, string $description, $recordId = null): void
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return;
    // Routine member sign-ins are not audit-worthy; staff sign-ins are.
    if (($_SESSION['user_type'] ?? '') === 'Member' && $actionType === 'Login') return;
    try {
        run(
            "INSERT INTO activity_log (UserID, ActionType, Module, Description, RecordID)
             VALUES (?, ?, ?, ?, ?)",
            [
                (int)$userId,
                mb_substr($actionType, 0, 50),
                mb_substr($module, 0, 50),
                mb_substr($description, 0, 500),
                $recordId !== null ? (int)$recordId : null,
            ]
        );
    } catch (Throwable $e) { /* logging must never break the app */
    }
}
