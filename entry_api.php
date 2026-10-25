<?php
/* Library entrance scan (JSON). POST only, CSRF-protected. Verifies the ID belongs to a registered member,
   logs the entry (date + time) and returns only the scanned person's own name. It never approves any book. */
require_once __DIR__ . '/includes/kiosk_boot.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function ereply(array $d, int $http = 200): void { http_response_code($http); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ereply(['ok' => false, 'message' => 'Invalid request.'], 405);
$sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !is_string($sent) || !hash_equals($_SESSION['csrf_token'], $sent)) {
    ereply(['ok' => false, 'message' => 'This screen has expired. Please reload the page.'], 403);
}
if (!ENTRY_GATE_ENABLED || ($problem = kiosk_access_problem()) || !entry_schema_ready()) {
    ereply(['ok' => false, 'code' => 'disabled', 'message' => 'The entrance scanner is not available. Please sign in with the librarian.'], 503);
}
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 5000), true);
$card = trim((string)($in['student_id'] ?? ''));
if ($card === '') ereply(['ok' => false, 'code' => 'empty', 'message' => 'Please scan your school ID.']);

try {
    $r = entry_record_scan($card, 'Scanner', null);
    if ($r['ok']) {
        ereply(['ok' => true, 'already' => $r['already'], 'name' => $r['name'], 'initials' => $r['initials'],
                'card' => $r['card'], 'time' => date('g:i A', strtotime($r['time'])), 'date' => date('l, F j, Y', strtotime($r['time']))]);
    }
    ereply(['ok' => false, 'code' => $r['code'], 'message' => $r['message'], 'name' => $r['name'] ?? null,
            'scanned' => $r['code'] === 'not_found' ? mb_substr($card, 0, 40) : null]);
} catch (Throwable $e) {
    error_log('entry_api: ' . $e->getMessage());
    ereply(['ok' => false, 'code' => 'server', 'message' => "We couldn't record your entry. Please try again or see the librarian."], 500);
}
