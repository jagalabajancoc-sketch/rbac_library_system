<?php
/* JSON endpoint for the student kiosk. POST only, CSRF-protected, server-side validation for everything.
   Exposes only the identified student's OWN details; never lists or searches members. */
require_once __DIR__ . '/includes/kiosk_boot.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function kreply(array $d, int $http = 200): void { http_response_code($http); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function kfail(string $code, string $msg, int $http = 200): void { kreply(['ok' => false, 'code' => $code, 'message' => $msg], $http); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') kfail('method', 'Invalid request.', 405);
$sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !is_string($sent) || !hash_equals($_SESSION['csrf_token'], $sent)) {
    kfail('csrf', 'This screen has expired. Please start again.', 403);
}
if ($problem = kiosk_access_problem()) kfail('disabled', $problem, 503);
if (!entry_schema_ready()) kfail('disabled', 'The library system needs a one-time database update (database/migrate_entry_and_permission.sql).', 503);

$raw = file_get_contents('php://input', false, null, 0, 20000);
$in  = json_decode((string)$raw, true);
if (!is_array($in)) kfail('bad_request', 'Invalid request.', 400);
$action = (string)($in['action'] ?? '');

/** The identified student's OWN status: entry today, books in use, pending requests. */
function kiosk_student_state(array $m): array
{
    $sum = kiosk_member_summary($m);
    $entry = member_entry_today((int)$m['UserID']);
    $sum['entry'] = $entry ? date('g:i A', strtotime($entry['EntryTime'])) : null;
    $pend = fetch_all(
        "SELECT q.UseRequestID, q.RequestedAt, COUNT(i.ItemID) AS n,
                GROUP_CONCAT(b.Title ORDER BY i.ItemID SEPARATOR ' · ') AS titles
           FROM use_request q
           JOIN use_request_item i ON i.UseRequestID = q.UseRequestID AND i.Decision = 'Pending'
           JOIN book_copy bc ON bc.CopyID = i.CopyID JOIN book b ON b.BookID = bc.BookID
          WHERE q.MemberID = ? AND q.Status = 'Pending' GROUP BY q.UseRequestID, q.RequestedAt ORDER BY q.RequestedAt",
        [(int)$m['UserID']]);
    $sum['pending'] = array_map(fn($r) => [
        'id' => (int)$r['UseRequestID'], 'ref' => use_request_ref((int)$r['UseRequestID']),
        'n' => (int)$r['n'], 'titles' => mb_substr((string)$r['titles'], 0, 160), 'at' => date('g:i A', strtotime($r['RequestedAt'])),
    ], $pend);
    return $sum;
}

/* Identified student lives only in the server-side kiosk session, with an idle expiry. */
$K = $_SESSION['kiosk'] ?? null;
if ($K && ($K['expires'] ?? 0) < time()) { unset($_SESSION['kiosk']); $K = null; }
$touch = function () { $_SESSION['kiosk']['expires'] = time() + (int)KIOSK_IDLE_SECONDS + 30; };

try {
    if ($action === 'reset') {
        unset($_SESSION['kiosk']);
        kreply(['ok' => true]);
    }

    if ($action === 'identify') {
        if (kiosk_locked_out()) {
            kfail('locked', 'Too many unsuccessful attempts. Please wait a few minutes or ask library staff for help.');
        }
        $card = trim((string)($in['student_id'] ?? ''));
        if ($card === '') kfail('empty', 'Please enter or scan your Student ID.');
        $m = ilu_find_member_by_card($card);
        if (!$m) {
            kiosk_log('id_fail');
            kfail('not_found', 'Student ID not found. Please check your ID and try again.');
        }
        if ($why = kiosk_member_block($m)) {
            kiosk_log('id_blocked', (int)$m['UserID'], $m['MembershipStatus']);
            kfail('blocked', $why);
        }
        if (KIOSK_REQUIRE_ENTRY && !member_entry_today((int)$m['UserID'])) {
            kiosk_log('no_entry', (int)$m['UserID']);
            kfail('no_entry', "We don't have an entry record for you today. Please scan your ID at the library entrance first, then try again.");
        }
        session_regenerate_id(true);
        $_SESSION['kiosk'] = ['member_id' => (int)$m['UserID']];
        $touch();
        kiosk_log('id_ok', (int)$m['UserID']);
        kreply(['ok' => true, 'member' => kiosk_student_state($m)]);
    }

    /* everything below needs an identified student */
    if (!$K || empty($K['member_id'])) kfail('no_member', 'Please identify yourself first.', 401);
    $memberId = (int)$K['member_id'];
    $m = fetch_one(
        "SELECT UserID, FirstName, LastName, MembershipNumber, MembershipStatus, VerificationStatus, IsActive
           FROM user_account WHERE UserID = ?", [$memberId]);
    if (!$m || kiosk_member_block($m)) { unset($_SESSION['kiosk']); kfail('no_member', 'Please identify yourself again.', 401); }
    $touch();

    if ($action === 'search') {
        $res = ilu_search_copies((string)($in['q'] ?? ''), 10);
        kreply(['ok' => true, 'exact' => $res['exact'], 'items' => $res['items']]);
    }

    /* The student only ASKS. Nothing is approved, reserved, started or borrowed until the librarian decides. */
    if ($action === 'submit') {
        $raw = (array)($in['items'] ?? []);
        $items = [];
        foreach ($raw as $r) if (is_array($r)) $items[] = ['copy_id' => (int)($r['copy_id'] ?? 0), 'use' => (string)($r['use'] ?? '')];
        try {
            $reqId = use_request_create($memberId, $items, 'Kiosk', null);
        } catch (LibraryException $e) {
            kiosk_log('request_refused', $memberId, mb_substr($e->getMessage(), 0, 200));
            kfail('refused', $e->getMessage());
        }
        kiosk_log('request_sent', $memberId, use_request_ref($reqId) . ' · ' . count($items) . ' book(s)');
        kreply(['ok' => true, 'ref' => use_request_ref($reqId), 'count' => count($items)]);
    }

    if ($action === 'cancel_request') {
        try { use_request_cancel((int)($in['request_id'] ?? 0), $memberId); }
        catch (LibraryException $e) { kfail('refused', $e->getMessage()); }
        kiosk_log('request_cancel', $memberId);
        kreply(['ok' => true, 'member' => kiosk_student_state($m)]);
    }

    kfail('bad_request', 'Invalid request.', 400);
} catch (Throwable $e) {
    error_log('kiosk_api: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    kfail('server', "We couldn't complete your registration. Please try again.", 500);
}
