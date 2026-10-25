<?php
/**
 * Bibliophilia — one-time, idempotent migration for the Borrow Request update.
 *
 * Run once from the project folder:      php database/migrate_borrow_request.php
 * (Safe to run again: every step checks the current state first.)
 *
 * What it does
 *   1. Renames loan_request        -> borrow_request
 *             loan_request_detail  -> borrow_request_detail   (foreign keys follow the rename)
 *   2. Renames the request type value 'Loan' -> 'Borrow' (types are now Borrow | Reservation)
 *   3. Adds borrow_request.DecisionReason (why a request was rejected / cancelled)
 *   4. Adds indexes used by the request, loan, return and availability queries
 *   5. Adds a UNIQUE index so one loan item can only ever be returned once
 *   6. Reports (does not change) any availability inconsistencies it finds
 *
 * Nothing is deleted. Take a database backup first anyway.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this script from the command line.\n"); }

require_once __DIR__ . '/../config/db.php';
$pdo = db();

function say(string $m): void { echo $m . PHP_EOL; }
function table_exists(PDO $p, string $t): bool {
    $s = $p->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$t]); return (int)$s->fetchColumn() > 0;
}
function column_info(PDO $p, string $t, string $c): ?array {
    $s = $p->prepare("SELECT COLUMN_TYPE, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$t, $c]); $r = $s->fetch(); return $r ?: null;
}
function index_exists(PDO $p, string $t, string $i): bool {
    $s = $p->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $s->execute([$t, $i]); return (int)$s->fetchColumn() > 0;
}
function add_index(PDO $p, string $table, string $name, string $cols, bool $unique = false): void {
    if (!table_exists($p, $table)) { say("  - skip $name (table $table missing)"); return; }
    if (index_exists($p, $table, $name)) { say("  = $name already exists"); return; }
    $p->exec("ALTER TABLE `$table` ADD " . ($unique ? 'UNIQUE ' : '') . "INDEX `$name` ($cols)");
    say("  + added $name on $table($cols)");
}

say("== 1. Rename tables");
foreach (['loan_request' => 'borrow_request', 'loan_request_detail' => 'borrow_request_detail'] as $old => $new) {
    if (table_exists($pdo, $old) && !table_exists($pdo, $new)) { $pdo->exec("RENAME TABLE `$old` TO `$new`"); say("  + renamed $old -> $new"); }
    elseif (table_exists($pdo, $new))                          { say("  = $new already in place"); }
    else                                                       { say("  ! neither $old nor $new exists — is this the right database?"); exit(1); }
}

say("== 2. Request type: Loan -> Borrow");
$col = column_info($pdo, 'borrow_request', 'RequestType');
if (!$col) { say("  ! borrow_request.RequestType not found"); exit(1); }
if ($col['DATA_TYPE'] === 'enum') {
    if (stripos($col['COLUMN_TYPE'], "'Loan'") !== false) {
        $pdo->exec("ALTER TABLE borrow_request MODIFY RequestType ENUM('Loan','Borrow','Reservation') NOT NULL DEFAULT 'Borrow'");
        $n = $pdo->exec("UPDATE borrow_request SET RequestType = 'Borrow' WHERE RequestType = 'Loan'");
        $pdo->exec("ALTER TABLE borrow_request MODIFY RequestType ENUM('Borrow','Reservation') NOT NULL DEFAULT 'Borrow'");
        say("  + converted $n row(s) and updated the ENUM");
    } else { say("  = already Borrow | Reservation"); }
} else {
    $n = $pdo->exec("UPDATE borrow_request SET RequestType = 'Borrow' WHERE RequestType = 'Loan'");
    say("  + converted $n row(s) (column is " . $col['DATA_TYPE'] . ")");
}

say("== 3. Decision reason column");
if (!column_info($pdo, 'borrow_request', 'DecisionReason')) {
    $pdo->exec("ALTER TABLE borrow_request ADD COLUMN DecisionReason VARCHAR(255) NULL AFTER ApprovalDate");
    say("  + added borrow_request.DecisionReason");
} else { say("  = already present"); }

say("== 4. Indexes");
add_index($pdo, 'borrow_request',        'idx_br_user_status',   'UserID, ApprovalStatus');
add_index($pdo, 'borrow_request',        'idx_br_status_type',   'ApprovalStatus, RequestType, RequestDate');
add_index($pdo, 'borrow_request',        'idx_br_copy',          'AssignedCopyID');
add_index($pdo, 'borrow_request_detail', 'idx_brd_book',         'BookID');
add_index($pdo, 'loan',                  'idx_loan_borrower',    'BorrowerUserID');
add_index($pdo, 'loan',                  'idx_loan_request',     'RequestID');
add_index($pdo, 'loan_detail',           'idx_ld_copy',          'CopyID');
add_index($pdo, 'loan_detail',           'idx_ld_due',           'DueDate');
add_index($pdo, 'book_copy',             'idx_bc_book_status',   'BookID, Status, IsArchived');
add_index($pdo, 'in_library_use',        'idx_ilu_copy_status',  'CopyID, Status');

say("== 5. One return per loan item");
$dups = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT LoanDetailID FROM return_detail GROUP BY LoanDetailID HAVING COUNT(*) > 1) d")->fetchColumn();
if ($dups > 0) { say("  ! $dups loan item(s) have duplicate return rows — clean these up, then re-run to add the UNIQUE index."); }
else           { add_index($pdo, 'return_detail', 'uq_return_loan_detail', 'LoanDetailID', true); }

say("== 6. Consistency report (read-only)");
$checks = [
  'Copies marked Borrowed with no open loan item' =>
    "SELECT COUNT(*) FROM book_copy bc WHERE bc.Status='Borrowed' AND NOT EXISTS (SELECT 1 FROM loan_detail ld LEFT JOIN return_detail rd ON rd.LoanDetailID=ld.LoanDetailID WHERE ld.CopyID=bc.CopyID AND rd.ReturnDetailID IS NULL)",
  'Open loan items whose copy is not marked Borrowed' =>
    "SELECT COUNT(*) FROM loan_detail ld LEFT JOIN return_detail rd ON rd.LoanDetailID=ld.LoanDetailID JOIN book_copy bc ON bc.CopyID=ld.CopyID WHERE rd.ReturnDetailID IS NULL AND bc.Status NOT IN ('Borrowed','Lost','Damaged','Withdrawn')",
  'Copies marked Reserved with no approved hold' =>
    "SELECT COUNT(*) FROM book_copy bc WHERE bc.Status='Reserved' AND NOT EXISTS (SELECT 1 FROM borrow_request r WHERE r.AssignedCopyID=bc.CopyID AND r.ApprovalStatus='Approved')",
  'Approved holds whose copy is not Reserved' =>
    "SELECT COUNT(*) FROM borrow_request r JOIN book_copy bc ON bc.CopyID=r.AssignedCopyID WHERE r.ApprovalStatus='Approved' AND bc.Status<>'Reserved'",
];
foreach ($checks as $label => $sql) { say(sprintf("  %-55s %d", $label, (int)$pdo->query($sql)->fetchColumn())); }
say("Done.");
