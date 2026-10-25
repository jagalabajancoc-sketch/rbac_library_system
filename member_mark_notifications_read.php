<?php
require_once __DIR__ . '/includes/member_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$notifications = member_notifications(current_member_id());
$keys = [];
foreach ($notifications as $n) {
    if (!empty($n['key'])) $keys[] = $n['key'];
}

$_SESSION['read_notifications'] = array_values(array_unique(array_merge(
    $_SESSION['read_notifications'] ?? [],
    $keys
)));

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'marked' => count($keys)]);