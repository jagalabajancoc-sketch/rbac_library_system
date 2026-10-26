<?php
/* =====================================================================
 * LIBRARIAN DESK FORM  —  in-library use with ID custody
 *
 * The real procedure: the borrower comes to the desk, hands over an ID, fills in a form, and the librarian
 * KEEPS THE ID until the book is returned. This file is that procedure, digitally:
 *
 *   desk_form_issue()  verifies the person, records the form (purpose, books, rules accepted), starts one
 *                      in_library_use record per physical copy and logs that the librarian is holding the ID
 *                      (and in which slot).
 *   desk_form_return() takes books back one by one (with their condition) and, only when every book is back,
 *                      lets the librarian hand the ID back.
 *
 * Only a librarian (decide_use_requests) can issue; returns / ID hand-back need process_in_library_use.
 * Needs database/migrate_id_custody.sql. Never creates a loan and sets no due date.
 * ===================================================================== */

function desk_schema_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query("SELECT FormID, FormNo, IdSlot, IdStatus FROM in_library_form LIMIT 0");
        db()->query("SELECT ConditionOut, ConditionIn FROM in_library_use LIMIT 0");
        $ok = true;
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True once database/migrate_desk_borrow.sql has been imported (one form for reading AND borrowing). */
function desk_borrow_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db()->query("SELECT LoanID, IssueToken FROM in_library_form LIMIT 0"); $ok = true; } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True once database/migrate_desk_teacher.sql has been imported (borrowers AND teachers use the same form). */
function desk_kind_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db()->query("SELECT MemberKind FROM user_account LIMIT 0"); $ok = true; } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** 'Student' or 'Teacher' (everyone is a Borrower until the migration is applied and they are marked as a teacher). */
function desk_member_kind(int $memberId): string
{
    if (!desk_kind_ready()) return 'Student';
    return fetch_value("SELECT MemberKind FROM user_account WHERE UserID = ?", [$memberId]) === 'Teacher' ? 'Teacher' : 'Student';
}

/** The books on a loan with their due dates (what the borrower takes home). */
function desk_loan_items(int $loanId): array
{
    return fetch_all(
        "SELECT ld.LoanDetailID, ld.DueDate, b.Title, bc.AccessionNumber,
                DATEDIFF(ld.DueDate, DATE(l.LoanDate)) AS LoanDays
           FROM loan_detail ld JOIN loan l ON l.LoanID = ld.LoanID
           JOIN book_copy bc ON bc.CopyID = ld.CopyID JOIN book b ON b.BookID = bc.BookID
          WHERE ld.LoanID = ? ORDER BY b.Title, bc.AccessionNumber", [$loanId]);
}

/** Closing time (timestamp) of the day that $date falls on. */
function desk_closing_ts(?string $date = null): int
{
    $day = date('Y-m-d', $date ? strtotime($date) : time());
    return (int) strtotime($day . ' ' . DESK_CLOSING_TIME);
}

/** Next free ID storage slot (1..DESK_ID_SLOTS) that is not holding an ID right now. */
function desk_next_slot(): ?string
{
    $taken = array_column(fetch_all("SELECT IdSlot FROM in_library_form WHERE IdStatus = 'Held' AND IdSlot IS NOT NULL"), 'IdSlot');
    $taken = array_flip(array_map('strval', $taken));
    for ($i = 1; $i <= (int)DESK_ID_SLOTS; $i++) {
        if (!isset($taken[(string)$i])) return (string)$i;
    }
    return null;
}

function desk_free_slots(): int
{
    $used = (int) fetch_value("SELECT COUNT(*) FROM in_library_form WHERE IdStatus = 'Held'");
    return max(0, (int)DESK_ID_SLOTS - $used);
}

function desk_make_form_no(): string
{
    $prefix = 'ILF-' . date('ymd') . '-';
    $max = (int) fetch_value("SELECT COALESCE(MAX(CAST(SUBSTRING(FormNo, ?) AS UNSIGNED)), 0) FROM in_library_form WHERE FormNo LIKE ?",
                             [strlen($prefix) + 1, $prefix . '%']);
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/** Open form whose ID the librarian is still holding for this borrower, or null. */
function desk_open_form_for_member(int $memberId): ?array
{
    return fetch_one("SELECT FormID, FormNo, IdSlot, IssuedAt FROM in_library_form
                       WHERE MemberID = ? AND Status = 'Open' ORDER BY IssuedAt DESC LIMIT 1", [$memberId]) ?: null;
}

/**
 * Desk lookup of a borrower by the ID they handed over: who it is, whether they may use books, and what the
 * librarian should know first. Read-only. Returns ['found' => false] when the ID is not registered.
 */
function desk_member_lookup(string $raw): array
{
    $m = ilu_find_member_by_card($raw);
    if (!$m) return ['found' => false];
    $uid = (int)$m['UserID'];

    $block = null; $warn = [];
    if (empty($m['IsActive']) || ($m['MembershipStatus'] ?? '') !== 'Active') {
        $block = 'Membership is ' . strtolower((string)$m['MembershipStatus']) . ' — not eligible to use books.';
    } elseif (($m['VerificationStatus'] ?? 'Approved') === 'Rejected') {
        $block = 'This registration was rejected. Please refer the borrower to the librarian in charge.';
    } elseif (($m['VerificationStatus'] ?? 'Approved') === 'Pending') {
        $warn[] = 'Registration is still pending approval. Your check of the physical ID counts as verification.';
    }

    $open = desk_open_form_for_member($uid);
    if ($open && !$block) {
        $block = 'This borrower already has an open form (' . $open['FormNo'] . ') — their ID is in storage slot ' . ($open['IdSlot'] ?: '—')
               . '. Return those books first.';
    }

    $entry = entry_schema_ready() ? member_entry_today($uid) : null;
    if (!$entry && entry_schema_ready()) $warn[] = 'No entrance scan recorded today.';

    $fines = (float) fetch_value(
        "SELECT COALESCE(SUM(f.FineAmount - COALESCE((SELECT SUM(p.PaidAmount) FROM payment p WHERE p.FineID = f.FineID), 0)), 0)
           FROM fine f JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID JOIN loan l ON l.LoanID = ld.LoanID
          WHERE l.BorrowerUserID = ? AND f.PaymentStatus = 'Unpaid'", [$uid]);
    if ($fines > 0.004) $warn[] = 'Unpaid fines: ₱' . number_format($fines, 2) . '.';

    $inUse = (int) fetch_value("SELECT COUNT(*) FROM in_library_use WHERE MemberID = ? AND Status = 'Active'", [$uid]);
    if ($inUse > 0) $warn[] = "Already has $inUse book(s) registered as in use.";

    $bc  = member_borrow_check($uid, 1);
    $lim = check_borrowing_limit($uid, 0);

    return [
        'borrowBlocked' => $bc['ok'] ? null : $bc['reason'],
        'borrowed' => (int)$lim['current_books'], 'maxBooks' => (int)$lim['max_books'],
        'borrowLeft' => (int)$lim['max_books'] > 0 ? max(0, (int)$lim['max_books'] - (int)$lim['current_books']) : 0,
        'found' => true, 'userId' => $uid, 'kind' => desk_member_kind($uid),
        'name' => trim($m['FirstName'] . ' ' . $m['LastName']), 'card' => $m['MembershipNumber'],
        'status' => $m['MembershipStatus'], 'verification' => $m['VerificationStatus'],
        'entryToday' => $entry ? date('g:i A', strtotime($entry['EntryTime'])) : null,
        'blocked' => $block, 'warnings' => $warn,
    ];
}

/**
 * Issue ONE desk form for a borrower (or visitor) that can mix both kinds of lines:
 *   'Read'   — read here: an in-library session; the librarian KEEPS THE ID (slot) until the book is back.
 *   'Borrow' — take home: a normal loan with the policy's loan days and due date; NO ID is kept.
 * All-or-nothing: if any line fails a rule, nothing is recorded.
 * $in keys: borrower_type, student_id, days[copyId] (days borrowed, Borrow lines only), walk_in_name, walk_in_contact, id_type, id_number, verify_method, photo_checked,
 *           copy_ids[], use[copyId => 'Read'|'Borrow'], cond_out[copyId => cond], purpose, purpose_note, location, ack, slot
 * @return array ['form_id','form_no','slot'(null if no ID kept),'items'(read),'borrowed'(loan lines),'loan_id']
 */
function desk_form_issue(array $in, int $staffId): array
{
    $token = $in['issue_token'] ?? null;
    if ($token !== null && (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token))) throw new LibraryException('This form has expired. Reload the desk and try again.');
    // Serialize desk form numbers and ID-slot allocation, including different staff sessions.
    $lock = 'desk-issue-'.substr(hash('sha256', DB_NAME), 0, 32);
    if ((int)fetch_value('SELECT GET_LOCK(?, 5)', [$lock]) !== 1) throw new LibraryException('The desk is saving another form. Your entries are kept; please try again.');
    try {
        if ($token) {
            $saved = fetch_one('SELECT FormID, FormNo, IdSlot, LoanID, IssuedByUserID FROM in_library_form WHERE IssueToken=?', [$token]);
            if ($saved) {
                if ((int)$saved['IssuedByUserID'] !== $staffId) throw new LibraryException('This form belongs to another staff session.');
                return ['form_id'=>(int)$saved['FormID'], 'form_no'=>$saved['FormNo'], 'slot'=>$saved['IdSlot'],
                    'items'=>desk_form_items($saved['FormNo']), 'borrowed'=>$saved['LoanID']?desk_loan_items((int)$saved['LoanID']):[], 'loan_id'=>$saved['LoanID']];
            }
        }
        return circulation_transaction(fn()=>desk_form_issue_transaction($in, $staffId));
    } finally { fetch_value('SELECT RELEASE_LOCK(?)', [$lock]); }
}

function desk_form_issue_transaction(array $in, int $staffId): array
{
    if (!desk_schema_ready() || !desk_borrow_ready()) throw new LibraryException('The desk database update has not been applied yet (migrate_id_custody.sql, then migrate_desk_borrow.sql).');
    $type = ($in['borrower_type'] ?? 'Member') === 'Walk-in' ? 'Walk-in' : 'Member';
    $copyIds = array_values(array_unique(array_filter(array_map('intval', (array)($in['copy_ids'] ?? [])))));
    if (!$copyIds) throw new LibraryException('Add at least one book to the form.');
    if (count($copyIds) > 60) throw new LibraryException('Issue at most 60 copies on one form.');
    sort($copyIds);
    if (empty($in['photo_checked'])) throw new LibraryException('Confirm that you compared the photo and name on the ID with the person at the desk.');
    if (empty($in['ack'])) throw new LibraryException('The borrower must accept the rules before the form is issued.');

    $useMap = (array)($in['use'] ?? []);
    $readIds = []; $borrowIds = [];
    foreach ($copyIds as $cid) { if (($useMap[$cid] ?? 'Read') === 'Borrow') $borrowIds[] = $cid; else $readIds[] = $cid; }
    if ($readIds && !IN_LIBRARY_USE_ENABLED) throw new LibraryException('Reading in the library is switched off — every book on the form must be borrowed.');
    if ($type === 'Walk-in' && $borrowIds) {
        throw new LibraryException('Visitors cannot borrow books. Choose “Read here” for every book, or register the visitor as a borrower first.');
    }

    $purpose = in_array($in['purpose'] ?? '', DESK_PURPOSES, true) ? $in['purpose'] : 'Reading';
    $method  = in_array($in['verify_method'] ?? '', ['Scanned', 'Typed', 'Visual'], true) ? $in['verify_method'] : 'Typed';
    $note    = mb_substr(trim((string)($in['purpose_note'] ?? '')), 0, 255) ?: null;
    $loc     = $readIds ? (mb_substr(trim((string)($in['location'] ?? '')), 0, 100) ?: null) : null;

    return in_transaction(function () use ($in, $type, $readIds, $borrowIds, $purpose, $method, $note, $loc, $staffId) {
        $memberId = null; $walkName = null; $walkContact = null; $idType = 'School ID'; $idNumber = null;

        if ($type === 'Member') {
            $m = ilu_find_member_by_card((string)($in['student_id'] ?? ''));
            if (!$m) throw new LibraryException('Borrower ID not found. Check the number on the ID card.');
            fetch_one('SELECT UserID FROM user_account WHERE UserID=? FOR UPDATE', [(int)$m['UserID']]);
            $look = desk_member_lookup((string)$m['MembershipNumber']);
            if (!empty($look['blocked'])) throw new LibraryException($look['blocked']);
            if (DESK_REQUIRE_ENTRY && !$look['entryToday']) {
                throw new LibraryException('This borrower has not scanned in at the entrance today. Record the entry first.');
            }
            $memberId = (int)$m['UserID']; $idNumber = $m['MembershipNumber'];
            $idType = desk_member_kind($memberId)==='Teacher'?'Employee ID':'School ID';
        } else {
            $walkName    = mb_substr(trim((string)($in['walk_in_name'] ?? '')), 0, 150);
            $walkContact = mb_substr(trim((string)($in['walk_in_contact'] ?? '')), 0, 100) ?: null;
            $idType      = in_array($in['id_type'] ?? '', DESK_WALKIN_ID_TYPES, true) ? $in['id_type'] : '';
            $idNumber    = mb_substr(trim((string)($in['id_number'] ?? '')), 0, 60);
            if ($walkName === '') throw new LibraryException("Enter the visitor's full name.");
            if ($idType === '')   throw new LibraryException('Choose the type of ID the visitor is leaving.');
            if ($idNumber === '') throw new LibraryException('Enter the number on the ID being held.');
        }

        /* ---- borrowed lines: ONE copy of any title, and not a title the borrower already has out ---- */
        $daysByCopy = [];
        if ($borrowIds) {
            $rows = ilu_copy_rows('bc.CopyID IN (' . implode(',', array_map('intval', $borrowIds)) . ')', [], 60);
            if (count($rows) !== count($borrowIds)) throw new LibraryException('A book you chose to borrow could not be found.');
            $seen = []; $daysByCopy = [];
            $daysIn = (array)($in['days'] ?? []);
            foreach ($rows as $r) {
                $bk = (int)$r['BookID'];
                /* days borrowed: chosen on the form (same for a borrower and a teacher); empty = the policy's loan days */
                $raw = trim((string)($daysIn[(int)$r['CopyID']] ?? ''));
                $d = $raw === '' ? resolve_loan_days($bk) : (ctype_digit($raw) ? (int)$raw : 0);
                if ($d < DESK_MIN_LOAN_DAYS || $d > DESK_MAX_LOAN_DAYS) {
                    throw new LibraryException('Days borrowed for “' . $r['Title'] . '” must be from ' . DESK_MIN_LOAN_DAYS . ' to ' . DESK_MAX_LOAN_DAYS . '.');
                }
                $daysByCopy[(int)$r['CopyID']] = $d;
                if (isset($seen[$bk])) throw new LibraryException('Only one copy of “' . $r['Title'] . '” can be borrowed. Remove the extra copy.');
                $seen[$bk] = true;
                $has = (int) fetch_value(
                    "SELECT COUNT(*) FROM loan_detail ld JOIN loan l ON l.LoanID = ld.LoanID JOIN book_copy bc ON bc.CopyID = ld.CopyID
                       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
                      WHERE l.BorrowerUserID = ? AND bc.BookID = ? AND rd.ReturnDetailID IS NULL", [$memberId, $bk]);
                if ($has > 0) throw new LibraryException('This borrower already has “' . $r['Title'] . '” borrowed. One copy of a title at a time.');
            }
        }

        $formNo = desk_make_form_no();

        /* ---- read-here lines: ID custody ---- */
        $slot = null; $readItems = [];
        if ($readIds) {
            $slot = trim((string)($in['slot'] ?? ''));
            if ($slot === '') $slot = (string) desk_next_slot();
            if ($slot === '') throw new LibraryException('Every ID storage slot is in use. Return an ID first or raise DESK_ID_SLOTS.');
            if (!preg_match('/^[A-Za-z0-9-]{1,20}$/', $slot)) throw new LibraryException('The ID storage slot may only contain letters, numbers and dashes.');
            if ((int) fetch_value("SELECT COUNT(*) FROM in_library_form WHERE IdSlot = ? AND IdStatus = 'Held' FOR UPDATE", [$slot]) > 0) {
                throw new LibraryException("ID storage slot $slot already holds another ID. Use the suggested storage slot.");
            }
            $res = start_in_library_session($readIds, $type, $memberId, $walkName, $walkContact, $staffId, $loc, $note, 'Staff', $formNo);
            $readItems = $res['items'];
            foreach ($readItems as $it) {
                $c = $in['cond_out'][$it['copy']] ?? 'Good';
                if (in_array($c, ['Fair', 'Damaged'], true)) {
                    run("UPDATE in_library_use SET ConditionOut = ? WHERE SessionRef = ? AND CopyID = ?", [$c, $formNo, $it['copy']]);
                }
            }
        }

        /* ---- borrowed lines: a normal loan with due dates (no ID kept) ---- */
        $loanId = null; $borrowItems = [];
        if ($borrowIds) {
            $loanId = issue_desk_loan($memberId, array_map('intval', $borrowIds), $staffId, $daysByCopy);
            $borrowItems = desk_loan_items($loanId);
        }

        $closing = $readIds ? desk_closing_ts() : 0;
        run("INSERT INTO in_library_form
               (FormNo, BorrowerType, MemberID, WalkInName, WalkInContact, IdType, IdNumber, IdVerifyMethod, IdPhotoChecked,
                IdSlot, Purpose, PurposeNote, Location, RulesAcknowledged, AcknowledgedAt, IssuedByUserID, ExpectedReturnAt,
                IdStatus, Status, LoanID, IssueToken)
             VALUES (?,?,?,?,?,?,?,?,1,?,?,?,?,1,NOW(),?,?,?,?,?,?)",
            [$formNo, $type, $memberId, $walkName, $walkContact, $idType, $idNumber, $method,
             $slot, $readIds ? $purpose : 'Borrowing', $note, $loc, $staffId,
             ($closing && $closing > time()) ? date('Y-m-d H:i:s', $closing) : null,
             $readIds ? 'Held' : 'NotRequired', $readIds ? 'Open' : 'Closed', $loanId, $in['issue_token'] ?? null]);
        $formId = (int) last_id();

        log_activity('Create', 'Library Desk',
            "Form $formNo issued — " . count($readItems) . ' read here' . ($slot ? " (ID held in storage slot $slot)" : '')
            . ', ' . count($borrowItems) . ' borrowed' . ($loanId ? " (Transaction #$loanId)" : '') . ', ' . $type, $formId);
        return ['form_id' => $formId, 'form_no' => $formNo, 'slot' => $slot, 'items' => $readItems,
                'borrowed' => $borrowItems, 'loan_id' => $loanId];
    });
}

/** The form with the borrower's details (or null). Look up by id or by form number. */
function desk_form_get($idOrNo): ?array
{
    $col = is_int($idOrNo) ? 'f.FormID' : 'f.FormNo';
    $f = fetch_one(
        "SELECT f.*, ua.FirstName, ua.LastName, ua.MembershipNumber,
                iss.FirstName AS IssFirst, iss.LastName AS IssLast, rb.FirstName AS RetFirst, rb.LastName AS RetLast
           FROM in_library_form f
           LEFT JOIN user_account ua  ON ua.UserID  = f.MemberID
           LEFT JOIN user_account iss ON iss.UserID = f.IssuedByUserID
           LEFT JOIN user_account rb  ON rb.UserID  = f.IdReturnedByUserID
          WHERE $col = ?", [$idOrNo]);
    if (!$f) return null;
    $f['Borrower'] = $f['BorrowerType'] === 'Member' ? trim($f['FirstName'] . ' ' . $f['LastName']) : $f['WalkInName'];
    $f['Items'] = desk_form_items($f['FormNo']);
    $f['Borrowed'] = !empty($f['LoanID']) ? desk_loan_items((int)$f['LoanID']) : [];
    return $f;
}

function desk_form_items(string $formNo): array
{
    return fetch_all(
        "SELECT ilu.InLibraryUseID AS SessionID, ilu.CopyID, ilu.Status, ilu.StartTime, ilu.ReturnTime, ilu.ConditionOut, ilu.ConditionIn,
                b.Title, bc.AccessionNumber, sh.ShelfCode
           FROM in_library_use ilu
           JOIN book_copy bc ON bc.CopyID = ilu.CopyID JOIN book b ON b.BookID = bc.BookID
           LEFT JOIN shelf sh ON sh.ShelfID = bc.ShelfID
          WHERE ilu.SessionRef = ? ORDER BY b.Title, bc.AccessionNumber", [$formNo]);
}

/**
 * Take books back and/or hand the ID back.
 * $returns: [sessionId => 'Good'|'Fair'|'Damaged'|'Lost']. The ID can be handed back only when no book of the form is still out.
 * A Damaged / Lost book needs a note before the ID is released (so the librarian records what happened).
 */
function desk_form_return(int $formId, array $returns, bool $releaseId, ?string $note, int $staffId): array
{
    if (!desk_schema_ready()) throw new LibraryException('The ID-custody database update has not been applied yet.');
    $note = $note !== null ? (mb_substr(trim($note), 0, 255) ?: null) : null;

    return in_transaction(function () use ($formId, $returns, $releaseId, $note, $staffId) {
        $f = fetch_one("SELECT FormID, FormNo, Status, IdStatus, IdSlot FROM in_library_form WHERE FormID = ? FOR UPDATE", [$formId]);
        if (!$f) throw new LibraryException('Form not found.');
        if ($f['Status'] !== 'Open') throw new LibraryException('This form is already closed.');

        $count = 0;
        foreach ($returns as $sid => $cond) {
            $sid = (int)$sid;
            $cond = in_array($cond, ['Good', 'Fair', 'Damaged', 'Lost'], true) ? $cond : 'Good';
            $s = fetch_one("SELECT InLibraryUseID, CopyID, Status FROM in_library_use WHERE InLibraryUseID = ? AND SessionRef = ? FOR UPDATE",
                           [$sid, $f['FormNo']]);
            if (!$s) throw new LibraryException('A selected book does not belong to this form.');
            if ($s['Status'] !== 'Active') continue;                       // already back — ignore
            // mark the copy first so end_in_library_use() does not put a damaged / lost copy back on the shelf
            if (in_array($cond, ['Damaged', 'Lost'], true)) {
                run("UPDATE book_copy SET Status = ? WHERE CopyID = ? AND Status IN ('In Use','Available')", [$cond, $s['CopyID']]);
            }
            end_in_library_use($sid, $staffId, null);
            run("UPDATE in_library_use SET ConditionIn = ? WHERE InLibraryUseID = ?", [$cond, $sid]);
            $count++;
        }

        $remaining = (int) fetch_value("SELECT COUNT(*) FROM in_library_use WHERE SessionRef = ? AND Status = 'Active'", [$f['FormNo']]);
        $released = false;
        if ($releaseId) {
            if ($remaining > 0) throw new LibraryException("$remaining book(s) are still out. Take them all back before returning the ID.");
            $problem = (int) fetch_value("SELECT COUNT(*) FROM in_library_use WHERE SessionRef = ? AND ConditionIn IN ('Damaged','Lost')", [$f['FormNo']]);
            if ($problem > 0 && !$note) throw new LibraryException('A book came back damaged or lost. Add a note about it before handing the ID back.');
            run("UPDATE in_library_form SET IdStatus = 'Returned', IdReturnedAt = NOW(), IdReturnedByUserID = ?, IdReturnNote = ?, Status = 'Closed'
                  WHERE FormID = ?", [$staffId, $note, $formId]);
            $released = true;
        }

        if ($count || $released) {
            log_activity($released ? 'Return' : 'Update', 'In-Library Desk',
                "Form {$f['FormNo']}: $count book(s) returned" . ($released ? ", ID handed back from storage slot {$f['IdSlot']}" : ", $remaining still out"), $formId);
        }
        return ['returned' => $count, 'remaining' => $remaining, 'released' => $released, 'slot' => $f['IdSlot'], 'form_no' => $f['FormNo']];
    });
}

/** Everything the "IDs held" list needs: each open form with its books. */
function desk_forms_held(): array
{
    $forms = fetch_all(
        "SELECT f.FormID, f.FormNo, f.BorrowerType, f.IdType, f.IdNumber, f.IdSlot, f.IssuedAt, f.Purpose, f.Location, f.WalkInName, f.LoanID,
                ua.FirstName, ua.LastName, ua.MembershipNumber
           FROM in_library_form f LEFT JOIN user_account ua ON ua.UserID = f.MemberID
          WHERE f.Status = 'Open' ORDER BY f.IssuedAt ASC");
    foreach ($forms as &$f) {
        $f['Borrower'] = $f['BorrowerType'] === 'Member' ? trim($f['FirstName'] . ' ' . $f['LastName']) : $f['WalkInName'];
        $f['Items']    = desk_form_items($f['FormNo']);
        $f['Borrowed'] = !empty($f['LoanID']) ? desk_loan_items((int)$f['LoanID']) : [];
        $f['Active']   = count(array_filter($f['Items'], fn($i) => $i['Status'] === 'Active'));
        $f['Minutes']  = (int) floor((time() - strtotime($f['IssuedAt'])) / 60);
        $f['PastClosing'] = time() > desk_closing_ts($f['IssuedAt']);
        $f['Long']     = $f['Minutes'] >= IN_LIBRARY_USE_LONG_HOURS * 60;
    }
    return $forms;
}
