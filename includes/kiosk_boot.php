<?php
/* Shared start-up for the borrower kiosk (kiosk.php, kiosk_api.php).
   - Uses its OWN session cookie, so a staff login in the same browser is never visible to (or reused by) the kiosk.
   - Does not include includes/auth.php: no staff or admin code is reachable from here. */
if (session_status() === PHP_SESSION_NONE) {
    session_name('lms_kiosk');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}
require_once __DIR__ . '/functions.php';

/** null when this device may use the kiosk, otherwise a short reason. */
function kiosk_access_problem(): ?string
{
    if (!KIOSK_ENABLED) return 'The self-service kiosk is switched off.';
    $allowed = KIOSK_ALLOWED_IPS;
    if ($allowed && !in_array(kiosk_client_ip(), $allowed, true)) return 'This device is not set up as a library kiosk.';
    if (!ilu_schema_ready()) return 'The library system needs a one-time database update (database/migrate_self_service_kiosk.sql).';
    return null;
}
