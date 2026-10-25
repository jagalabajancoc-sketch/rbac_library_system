<?php
/* Live borrowing status for the signed-in member (JSON). Polled by the Browse Books page so the
   summary and the "Limit Reached" buttons update after a return or a staff decision. */
require_once __DIR__ . '/includes/member_auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(member_borrow_status(current_member_id()));
