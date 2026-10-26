<?php
require __DIR__."/guard.php";
// Run:  php tests/rule_test.php "$PWD"   (needs the throwaway test database from tests/workflow.sh; it wipes loans and requests)
$root = $argv[1]; chdir($root);
require $root . '/includes/functions.php';
$pass = 0; $fail = 0;
function ok($n, $c, $extra = '') { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $n\n"; } else { $fail++; echo "  FAIL  $n $extra\n"; } }
function refused(callable $f, string $needle = ''): string { try { $f(); return ''; } catch (LibraryException $e) { return $e->getMessage(); } }
function st($copy) { return fetch_value("SELECT Status FROM book_copy WHERE CopyID = ?", [$copy]); }
function reset_db() { run("DELETE FROM return_detail"); run("DELETE FROM return_header"); run("DELETE FROM loan_detail"); run("DELETE FROM loan"); run("DELETE FROM borrow_request_detail"); run("DELETE FROM borrow_request"); run("UPDATE book_copy SET Status='Available'"); }
const STAFF = 1, MIA = 2, BEN = 3, CY = 4;   // Clean Code = book 1 (copies 1,2); Refactoring = book 2 (copy 3, single)

echo "Rule: keep " . borrow_keep_copies() . " copy in the library\n";
reset_db();
echo "1. Two copies on the shelf: only one may be borrowed\n";
ok("shelf 2, borrowable now 1", book_shelf_copies(1) === 2 && book_copies_borrowable_now(1) === 1);
$loan = issue_desk_loan(MIA, [1], STAFF);
ok("first copy borrowed", $loan > 0 && st(1) === 'Borrowed');
ok("availability updated automatically (shelf 1, borrowable 0)", book_shelf_copies(1) === 1 && book_copies_borrowable_now(1) === 0);
$msg = refused(fn() => issue_desk_loan(BEN, [2], STAFF));
ok("second copy refused", $msg !== '' && st(2) === 'Available', $msg);
ok("message explains the rule", strpos($msg, 'must always stay in the library') !== false, $msg);

echo "2. Both copies in one request\n";
reset_db();
$msg = refused(fn() => issue_desk_loan(CY, [1, 2], STAFF));
ok("asking for both copies refused, nothing saved", $msg !== '' && st(1) === 'Available' && st(2) === 'Available' && (int)fetch_value("SELECT COUNT(*) FROM loan") === 0, $msg);
ok("message says at most 1 can be borrowed", strpos($msg, 'at most 1 copy') !== false, $msg);

echo "3. Single-copy title\n";
$msg = refused(fn() => issue_desk_loan(MIA, [3], STAFF));
ok("single copy cannot be borrowed", $msg !== '' && st(3) === 'Available', $msg);

echo "4. Different titles are independent\n";
$loan = issue_desk_loan(MIA, [1], STAFF);
ok("one copy of Clean Code is fine again", $loan > 0);

echo "5. Return frees the spare copy again\n";
run("UPDATE book_copy SET Status = 'Available' WHERE CopyID = 1");
ok("after return shelf is 2 again", book_shelf_copies(1) === 2 && book_copies_borrowable_now(1) === 1);
$loan = issue_desk_loan(BEN, [2], STAFF);
ok("another student can now borrow one", $loan > 0 && st(2) === 'Borrowed');

echo "6. Student requests and holds\n";
reset_db();
$r1 = create_borrow_request(MIA, 1);
ok("request with 2 on shelf is a Borrow request", ($r1['type'] ?? '') === 'Borrow' || (fetch_value("SELECT RequestType FROM borrow_request WHERE UserID = ?", [MIA]) === 'Borrow'), json_encode($r1));
$req1 = (int) fetch_value("SELECT RequestID FROM borrow_request WHERE UserID = ?", [MIA]);
approve_borrow_request($req1, 1, STAFF);
ok("approving holds one copy (Reserved)", st(1) === 'Reserved' && book_shelf_copies(1) === 1);
create_borrow_request(BEN, 1);
$req2 = (int) fetch_value("SELECT RequestID FROM borrow_request WHERE UserID = ?", [BEN]);
ok("next request becomes a Reservation (queued) because the last copy stays", fetch_value("SELECT RequestType FROM borrow_request WHERE RequestID = ?", [$req2]) === 'Reservation');
$msg = refused(fn() => approve_borrow_request($req2, 2, STAFF));
ok("staff cannot hold the last spare copy", $msg !== '' && st(2) === 'Available', $msg);
echo "7. Returned copy promotes the queue only when a spare exists\n";
run("UPDATE borrow_request SET ApprovalStatus = 'Cancelled' WHERE RequestID = $req1"); run("UPDATE book_copy SET Status = 'Available' WHERE CopyID = 1");
$n = promote_reservation_queue(1);
ok("queue promoted once 2 copies are back on the shelf", $n === 1 && st(1) === 'Reserved' && st(2) === 'Available', "promoted=$n s1=" . st(1) . " s2=" . st(2));
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
