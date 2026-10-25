<?php

/* The mbstring extension is optional on some hosts; fall back to byte-based versions. */
if (!function_exists('mb_strlen'))  { function mb_strlen($s, $e = null)  { return strlen((string)$s); } }
if (!function_exists('mb_substr'))  { function mb_substr($s, $st, $l = null, $e = null) { return $l === null ? substr((string)$s, $st) : substr((string)$s, $st, $l); } }

require_once __DIR__ . '/../config/db.php';


/* =====================================================================
 * BOOK METADATA ENUMS
 * Must stay in sync with the ENUM definition in the `book` table.
 * ===================================================================== */

const EDITION_OPTIONS = [
    '1st Edition', '2nd Edition', '3rd Edition', '4th Edition',
    '5th Edition', '6th Edition', '7th Edition', '8th Edition',
    '9th Edition', '10th Edition', 'Revised Edition', 'Reprint Edition',
    'Paperback Edition', 'Hardcover Edition', 'Illustrated Edition',
    'Reference Edition', 'Other', 'Unknown',
];

const LANGUAGE_OPTIONS = [
    'English', 'Filipino', 'Cebuano', 'Spanish', 'Chinese',
    'Japanese', 'Korean', 'French', 'German', 'Other', 'Unknown',
];

/* ---------------------------------------------------------------------
 * Generic helpers
 * ------------------------------------------------------------------- */

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message, bool $modal = true): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message, 'modal' => $modal];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function fetch_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_one(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function fetch_value(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row === false ? null : $row[0];
}

function run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function last_id(): string
{
    return db()->lastInsertId();
}

function money($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function fmt_date($date): string
{
    if (!$date) return '—';
    $ts = strtotime($date);
    return $ts ? date('M d, Y', $ts) : h($date);
}

function fmt_datetime($date): string
{
    if (!$date) return '—';
    $ts = strtotime($date);
    return $ts ? date('M d, Y g:i A', $ts) : h($date);
}

function current_page(): string
{
    return basename($_SERVER['SCRIPT_NAME']);
}

function status_badge(string $status): string
{
    $map = [
        'Available'          => 'success',
        'Reserved'           => 'primary',
        'Borrowed'           => 'warning',
        'In Use'             => 'info',
        'Donated'            => 'info',
        'Lost'               => 'dark',
        'Damaged'            => 'danger',
        'Withdrawn'          => 'secondary',
        'Pending'            => 'secondary',
        'Approved'           => 'success',
        'Ready'              => 'success',
        'Rejected'           => 'danger',
        'Fulfilled'          => 'primary',
        'Expired'            => 'secondary',
        'Cancelled'          => 'secondary',
        'Returned'           => 'success',
        'Partial'            => 'warning',
        'Complete'           => 'success',
        'Overdue'            => 'danger',
        'Unpaid'             => 'danger',
        'Paid'               => 'success',
        'Active'             => 'primary',
        'Returned Late'      => 'warning',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $class . '">' . h($status) . '</span>';
}

/* ---------------------------------------------------------------------
 * Lookup helpers
 * ------------------------------------------------------------------- */

function lookup_options(string $table, string $idCol, string $labelCol, string $order = null, bool $excludeArchived = true): array
{
    $order = $order ?? $labelCol;
    $where = '';
    if ($excludeArchived) {
        try {
            $has = (int) fetch_value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = 'IsArchived'",
                [$table]
            );
            if ($has > 0) $where = 'WHERE IsArchived = 0';
        } catch (Exception $e) {}
    }
    return fetch_all(
        "SELECT `$idCol` AS id, `$labelCol` AS label FROM `$table` $where ORDER BY `$order`"
    );
}

function render_select(string $name, array $options, $selected = null, string $extra = '', bool $required = false, string $placeholder = '-- Select --'): string
{
    $out = '<select name="' . h($name) . '" class="form-select" ' . $extra . ($required ? ' required' : '') . '>';
    $out .= '<option value="">' . h($placeholder) . '</option>';
    foreach ($options as $opt) {
        $sel = ((string)$opt['id'] === (string)$selected) ? 'selected' : '';
        $out .= '<option value="' . h($opt['id']) . '" ' . $sel . '>' . h($opt['label']) . '</option>';
    }
    $out .= '</select>';
    return $out;
}

/* ---------------------------------------------------------------------
 * Membership validity
 * ------------------------------------------------------------------- */

const MEMBERSHIP_STATUSES = ['Active', 'Inactive', 'Expired'];

function member_borrow_check($memberId, int $additionalBooks = 0): array
{
    $member = fetch_one(
        "SELECT ua.*, r.RoleName
           FROM user_account ua
           LEFT JOIN role r ON r.RoleID = ua.RoleID
          WHERE ua.UserID = ?",
        [$memberId]
    );

    if (!$member) {
        return ['ok' => false, 'reason' => 'No such member record.', 'member' => null];
    }
    if (strcasecmp((string)$member['RoleName'], 'Member') !== 0) {
        return ['ok' => false, 'reason' => 'That account is not a member.', 'member' => $member];
    }

    /* Verification gate — pending or rejected accounts cannot borrow. */
    $vs = $member['VerificationStatus'] ?? 'Approved';
    if ($vs === 'Pending') {
        return ['ok' => false, 'member' => $member,
                'reason' => 'Your account is pending verification. Please visit the library and present a valid ID to complete registration.'];
    }
    if ($vs === 'Rejected') {
        return ['ok' => false, 'member' => $member,
                'reason' => 'Your registration was rejected. Please visit the library to correct your information.'];
    }
    if ($vs !== 'Approved') {
        return ['ok' => false, 'member' => $member,
                'reason' => 'Your account is not verified for borrowing.'];
    }

    if ($member['MembershipStatus'] !== 'Active') {
        return ['ok' => false,
                'reason' => 'Membership status is ' . $member['MembershipStatus']
                          . '. Only members with status Active may borrow or request books.',
                'member' => $member];
    }

    $unpaid = (int)fetch_value(
        "SELECT COUNT(*) FROM fine f
           JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
           JOIN loan l         ON l.LoanID        = ld.LoanID
          WHERE l.BorrowerUserID = ? AND f.PaymentStatus = 'Unpaid'",
        [$memberId]
    );
    if ($unpaid > 0) {
        return ['ok' => false, 'reason' => "This member has $unpaid unpaid fine(s). Settle them before borrowing again.", 'member' => $member];
    }

    $overdue = (int)fetch_value(
        "SELECT COUNT(*)
           FROM loan_detail ld
           JOIN loan l ON l.LoanID = ld.LoanID
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
          WHERE l.BorrowerUserID = ?
            AND ld.DueDate < CURDATE()
            AND rd.ReturnDetailID IS NULL",
        [$memberId]
    );
    if ($overdue > 0) {
        return ['ok' => false, 'reason' => "This member has $overdue overdue item(s) still out.", 'member' => $member];
    }

    $limitCheck = check_borrowing_limit($memberId, $additionalBooks);
    if (!$limitCheck['ok']) {
        return ['ok' => false, 'reason' => $limitCheck['reason'], 'member' => $member, 'limit' => $limitCheck];
    }

    return ['ok' => true, 'reason' => '', 'member' => $member, 'limit' => $limitCheck];
}

function membership_badge($status): string
{
    $map = ['Active' => 'success', 'Inactive' => 'secondary', 'Expired' => 'danger'];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $class . '">' . h($status) . '</span>';
}

function verification_badge($status): string
{
    $map = ['Approved' => 'success', 'Pending' => 'warning', 'Rejected' => 'danger'];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $class . '">' . h($status) . '</span>';
}

function generate_membership_number(int $userId): string
{
    return sprintf('LIB-%s-%05d', date('Y'), $userId);
}

/* =====================================================================
 * BORROWING LIMITS
 * ===================================================================== */

const BORROWING_LIMIT_DEFAULTS = ['Walk-in' => 100, 'Member' => 3];

function get_borrowing_limits(): array
{
    $out = BORROWING_LIMIT_DEFAULTS;
    try {
        $row = fetch_one(
            "SELECT WalkInMaxBooks, MemberMaxBooks FROM policy
              WHERE IsArchived = 0
              ORDER BY PolicyID ASC
              LIMIT 1"
        );
        if ($row) {
            $out['Walk-in'] = (int)$row['WalkInMaxBooks'];
            $out['Member']  = (int)$row['MemberMaxBooks'];
        }
    } catch (Exception $e) {}
    return $out;
}

function get_borrowing_limit(string $borrowerType): int
{
    $all = get_borrowing_limits();
    return (int)($all[$borrowerType] ?? 0);
}

function resolve_borrower_type($memberId): string
{
    $role = fetch_value(
        "SELECT r.RoleName FROM user_account ua
           LEFT JOIN role r ON r.RoleID = ua.RoleID
          WHERE ua.UserID = ?",
        [$memberId]
    );
    return strcasecmp((string)$role, 'Member') === 0 ? 'Member' : 'Walk-in';
}

function count_active_borrowed_books($memberId): int
{
    return (int)fetch_value(
        "SELECT COUNT(*)
           FROM loan_detail ld
           JOIN loan l ON l.LoanID = ld.LoanID
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
          WHERE l.BorrowerUserID = ?
            AND rd.ReturnDetailID IS NULL",
        [$memberId]
    );
}

function check_borrowing_limit($memberId, int $additionalBooks = 0): array
{
    $type = resolve_borrower_type($memberId);
    $have = count_active_borrowed_books($memberId);

    if ($type === 'Walk-in') {
        return [
            'ok'               => true,
            'borrower_type'    => $type,
            'max_books'        => 0,
            'current_books'    => $have,
            'additional_books' => $additionalBooks,
            'remaining'        => PHP_INT_MAX,
            'reason'           => '',
        ];
    }

    $max = get_borrowing_limit($type);

    if ($max < 1) {
        return [
            'ok'               => false,
            'borrower_type'    => $type,
            'max_books'        => 0,
            'current_books'    => $have,
            'additional_books' => $additionalBooks,
            'remaining'        => 0,
            'reason'           => "No borrowing limit is configured for the '$type' borrower type. "
                                . "Set one under Policies → Borrowing Limits.",
        ];
    }

    $ok        = ($have + $additionalBooks) <= $max;
    $remaining = max(0, $max - $have);

    $reason = '';
    if (!$ok) {
        $extra = $additionalBooks > 0 ? " (this loan would add {$additionalBooks})" : '';
        $reason = "Borrowing limit reached for {$type} borrower: "
                . "{$have} of {$max} book(s) already borrowed{$extra}.";
    }

    return [
        'ok'               => $ok,
        'borrower_type'    => $type,
        'max_books'        => $max,
        'current_books'    => $have,
        'additional_books' => $additionalBooks,
        'remaining'        => $remaining,
        'reason'           => $reason,
    ];
}

/* ---------------------------------------------------------------------
 * Book availability
 * ------------------------------------------------------------------- */

function sql_available_copies(string $bookAlias = 'b'): string
{
    return "(SELECT COUNT(*) FROM book_copy bc_av
              WHERE bc_av.BookID = {$bookAlias}.BookID
                AND bc_av.Status = 'Available'
                AND bc_av.IsArchived = 0)";
}

function sql_total_copies(string $bookAlias = 'b'): string
{
    return "(SELECT COUNT(*) FROM book_copy bc_tt
              WHERE bc_tt.BookID = {$bookAlias}.BookID
                AND bc_tt.IsArchived = 0)";
}

function validate_book_borrowability(int $bookId): array
{
    $book = fetch_one(
        "SELECT b.BookID, b.Title, b.IsArchived,
                pol.PolicyName, pol.IsBorrowable, pol.LoanDays,
                " . sql_available_copies('b') . " AS AvailableCopies
           FROM book b
           JOIN policy pol ON pol.PolicyID = b.PolicyID
          WHERE b.BookID = ?",
        [$bookId]
    );

    if (!$book) return ['ok' => false, 'book' => null, 'reason' => 'That book could not be found.'];
    if (!empty($book['IsArchived'])) {
        return ['ok' => false, 'book' => $book,
                'reason' => '"' . $book['Title'] . '" has been archived and is not available for borrowing.'];
    }
    if (!(int)$book['IsBorrowable']) {
        return ['ok' => false, 'book' => $book,
                'reason' => '"' . $book['Title'] . '" is reference-only and cannot be borrowed or reserved.'];
    }
    if ((int)$book['LoanDays'] < 1) {
        return ['ok' => false, 'book' => $book,
                'reason' => '"' . $book['Title'] . '" is on a borrowable policy without a valid loan period. '
                          . 'Ask an administrator to fix the policy.'];
    }
    return ['ok' => true, 'book' => $book, 'reason' => ''];
}

/* ---------------------------------------------------------------------
 * Keep-a-copy-in-the-library rule (BORROW_KEEP_IN_LIBRARY in config/kiosk.php)
 * Availability is never stored in a counter: it is counted live from book_copy.Status, and every loan, return, hold,
 * expiry and reading session already changes that status. So the numbers below are correct after every transaction.
 * ------------------------------------------------------------------- */

function borrow_keep_copies(): int { return max(0, (int)BORROW_KEEP_IN_LIBRARY); }

/** Copies of this title sitting on the shelf right now (Available, not archived). */
function book_shelf_copies(int $bookId): int
{
    return (int) fetch_value("SELECT COUNT(*) FROM book_copy WHERE BookID = ? AND Status = 'Available' AND IsArchived = 0", [$bookId]);
}

/** How many copies of this title may still leave the library right now. */
function book_copies_borrowable_now(int $bookId): int
{
    return max(0, book_shelf_copies($bookId) - borrow_keep_copies());
}

/** Plain-language reason shown to the librarian / student when the rule blocks a borrow. */
function copy_rule_message(string $title, int $shelf, int $taking = 1): string
{
    $keep = borrow_keep_copies();
    $can  = max(0, $shelf - $keep);
    $tail = IN_LIBRARY_USE_ENABLED ? ' It can still be read inside the library.' : '';
    if ($can < 1) {
        return '“' . $title . '” cannot be borrowed now: only ' . $shelf . ($shelf === 1 ? ' copy is' : ' copies are') . ' on the shelf and '
             . ($keep === 1 ? 'one copy' : $keep . ' copies') . ' must always stay in the library.' . $tail;
    }
    return '“' . $title . '”: ' . $shelf . ' copies are on the shelf, so at most ' . $can . ($can === 1 ? ' copy' : ' copies')
         . ' can be borrowed (' . ($keep === 1 ? 'one copy' : $keep . ' copies') . ' must stay in the library). This form asks for ' . $taking . '.';
}

/**
 * Call inside a transaction, after the copies being taken are locked. Throws if taking $taking copies of this title would
 * leave fewer than BORROW_KEEP_IN_LIBRARY copies on the shelf. Locks the title's shelf copies so two librarians cannot
 * both take "the last spare copy" at the same moment.
 */
function assert_keeps_copy_in_library(int $bookId, string $title, int $taking): void
{
    if (borrow_keep_copies() < 1 || $taking < 1) return;
    $shelf = count(fetch_all("SELECT CopyID FROM book_copy WHERE BookID = ? AND Status = 'Available' AND IsArchived = 0 FOR UPDATE", [$bookId]));
    if ($shelf - $taking < borrow_keep_copies()) throw new LibraryException(copy_rule_message($title, $shelf, $taking));
}

/** Same check for a list of ['BookID'=>, 'Title'=>] rows (one row per copy being taken). */
function assert_keeps_copies_for_rows(array $rows): void
{
    $by = [];
    foreach ($rows as $r) { $k = (int)$r['BookID']; $by[$k]['t'] = (string)$r['Title']; $by[$k]['n'] = ($by[$k]['n'] ?? 0) + 1; }
    foreach ($by as $bookId => $x) assert_keeps_copy_in_library($bookId, $x['t'], $x['n']);
}

function availability_badge(int $availableCopies, $isBorrowable = 1): string
{
    if (!$isBorrowable) return '<span class="badge text-bg-secondary">For Library Use Only</span>';
    return $availableCopies > 0
        ? '<span class="badge text-bg-success">Available</span>'
        : '<span class="badge text-bg-danger">Currently Unavailable</span>';
}

function is_overdue($dueDate, $copyStatus = null): bool
{
    return $dueDate && strtotime($dueDate) < strtotime(date('Y-m-d'));
}

/* ---------------------------------------------------------------------
 * CSRF protection
 * ------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_check(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

function csrf_guard(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_check()) {
        flash('danger', 'Your session expired. Please try again.');
        redirect(current_page());
    }
}

/* ---------------------------------------------------------------------
 * PHYSICAL COPY MANAGEMENT
 * ------------------------------------------------------------------- */

const COPY_BATCH_LIMIT = 500;

function next_accession_numbers(int $bookId, int $count): array
{
    if ($count < 1) throw new InvalidArgumentException('Number of copies must be at least 1.');
    if ($count > COPY_BATCH_LIMIT) throw new InvalidArgumentException('Number of copies cannot exceed ' . COPY_BATCH_LIMIT . ' per batch.');

    $prefix = sprintf('BK-%05d-', $bookId);
    $rows = fetch_all(
        "SELECT AccessionNumber FROM book_copy WHERE BookID = ? AND AccessionNumber LIKE ?",
        [$bookId, $prefix . '%']
    );

    $max = 0;
    foreach ($rows as $row) {
        $suffix = substr($row['AccessionNumber'], strlen($prefix));
        if ($suffix !== '' && ctype_digit($suffix)) $max = max($max, (int)$suffix);
    }

    $out = [];
    for ($i = 1; $i <= $count; $i++) {
        $out[] = $prefix . str_pad((string)($max + $i), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

function create_book_copies(int $bookId, int $count): array
{
    $numbers = next_accession_numbers($bookId, $count);
    foreach ($numbers as $acc) {
        run("INSERT INTO book_copy (BookID, AccessionNumber, Status) VALUES (?, ?, 'Available')",
            [$bookId, $acc]);
    }
    return $numbers;
}

/* ---------------------------------------------------------------------
 * BORROWING PERIOD
 * ------------------------------------------------------------------- */
const DEFAULT_LOAN_DAYS = 14;

function resolve_loan_days(int $bookId): int
{
    $row = fetch_one(
        "SELECT pol.LoanDays AS PolicyLoanDays FROM book b
           JOIN policy pol ON pol.PolicyID = b.PolicyID WHERE b.BookID = ?",
        [$bookId]
    );
    if (!$row) return DEFAULT_LOAN_DAYS;
    if ((int)$row['PolicyLoanDays'] > 0) return (int)$row['PolicyLoanDays'];
    return DEFAULT_LOAN_DAYS;
}

/* ---------------------------------------------------------------------
 * PAGINATION
 * ------------------------------------------------------------------- */

const PER_PAGE_DEFAULT = 15;

function pagination_page(string $param = 'page'): int
{
    $p = (int)($_GET[$param] ?? 1);
    return $p < 1 ? 1 : $p;
}

function pagination_calc(int $total, int $perPage, int $page): array
{
    $perPage    = max(1, $perPage);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $perPage;
    return [
        'total' => $total, 'per_page' => $perPage, 'page' => $page,
        'total_pages' => $totalPages, 'offset' => $offset,
        'from' => $total === 0 ? 0 : $offset + 1,
        'to'   => min($offset + $perPage, $total),
    ];
}

function pagination_info(array $pg): string
{
    if ($pg['total'] === 0) return 'No records';
    return 'Showing <strong>' . $pg['from'] . '–' . $pg['to']
         . '</strong> of <strong>' . $pg['total'] . '</strong>';
}

function pagination_links(array $pg, array $preserveQuery = [], string $param = 'page'): string
{
    return pagination_style_03($pg, $preserveQuery, $param);
}

function pagination_style_03(array $pg, array $preserveQuery = [], string $param = 'page'): string
{
    if ($pg['total_pages'] <= 1) return '';
    $query = $preserveQuery; unset($query[$param]);
    $qs = http_build_query($query);
    $make = function (int $n) use ($qs, $param) {
        $base = $qs === '' ? '?' : '?' . $qs . '&';
        return $base . $param . '=' . $n;
    };
    $page = $pg['page']; $last = $pg['total_pages'];
    $pages = [];
    if ($last <= 7) { for ($i = 1; $i <= $last; $i++) $pages[] = $i; }
    else {
        $pages[] = 1;
        $start = max(2, $page - 1); $end = min($last - 1, $page + 1);
        if ($start > 2) $pages[] = '…';
        for ($i = $start; $i <= $end; $i++) $pages[] = $i;
        if ($end < $last - 1) $pages[] = '…';
        $pages[] = $last;
    }
    $out  = '<nav class="pagination-style-03" aria-label="Pagination">';
    $out .= $page <= 1
        ? '<span class="pg-btn pg-back disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i> Back</span>'
        : '<a class="pg-btn pg-back" href="' . h($make($page - 1)) . '" rel="prev"><i class="bi bi-chevron-left"></i> Back</a>';
    $out .= '<span class="pg-sep">|</span><span class="pg-numbers">';
    foreach ($pages as $p) {
        if ($p === '…') $out .= '<span class="pg-ellipsis">…</span>';
        elseif ((int)$p === $page) $out .= '<span class="pg-num active" aria-current="page">' . $p . '</span>';
        else $out .= '<a class="pg-num" href="' . h($make((int)$p)) . '">' . $p . '</a>';
    }
    $out .= '</span><span class="pg-sep">|</span>';
    $out .= $page >= $last
        ? '<span class="pg-btn pg-next disabled" aria-disabled="true">Next <i class="bi bi-chevron-right"></i></span>'
        : '<a class="pg-btn pg-next" href="' . h($make($page + 1)) . '" rel="next">Next <i class="bi bi-chevron-right"></i></a>';
    $out .= '<span class="pg-sep">|</span>';
    $out .= '<form class="pg-goto" method="get">';
    foreach ($preserveQuery as $k => $v) {
        if ($k === $param) continue;
        if (is_array($v)) { foreach ($v as $vv) $out .= '<input type="hidden" name="' . h($k) . '[]" value="' . h($vv) . '">'; }
        else { $out .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">'; }
    }
    $out .= '<label class="pg-goto-label" for="pg-goto">Go to</label>';
    $out .= '<input id="pg-goto" type="number" name="' . h($param) . '" class="pg-goto-input" min="1" max="' . $last . '" value="' . $page . '" required>';
    $out .= '<button class="pg-goto-btn" type="submit" aria-label="Go to page">Go</button></form></nav>';
    return $out;
}

/* =====================================================================
 * IN-LIBRARY USE  (on-site reading — never creates a loan)
 * ===================================================================== */

const IN_LIBRARY_USE_LONG_HOURS = 4;

function validate_in_library_use_copy(int $copyId): array
{
    $copy = fetch_one(
        "SELECT bc.CopyID, bc.AccessionNumber, bc.Status, bc.IsArchived,
                bc.BookID, b.Title, b.IsArchived AS BookArchived,
                pol.PolicyName, pol.IsBorrowable,
                sh.ShelfCode, sec.SectionName
           FROM book_copy bc
           JOIN book b       ON b.BookID    = bc.BookID
           JOIN policy pol   ON pol.PolicyID = b.PolicyID
           LEFT JOIN shelf sh   ON sh.ShelfID   = bc.ShelfID
           LEFT JOIN section sec ON sec.SectionID = sh.SectionID
          WHERE bc.CopyID = ?",
        [$copyId]
    );

    if (!$copy) return ['ok' => false, 'copy' => null, 'reason' => 'Copy not found.'];
    if (!empty($copy['IsArchived'])) return ['ok' => false, 'copy' => $copy,
        'reason' => 'Copy ' . $copy['AccessionNumber'] . ' is archived.'];
    if (!empty($copy['BookArchived'])) return ['ok' => false, 'copy' => $copy,
        'reason' => 'The book "' . $copy['Title'] . '" is archived.'];
    if ($copy['Status'] !== 'Available') return ['ok' => false, 'copy' => $copy,
        'reason' => 'Copy ' . $copy['AccessionNumber'] . ' is currently "' . $copy['Status']
                  . '" — only Available copies can be used in the library.'];

    $active = (int) fetch_value(
        "SELECT COUNT(*) FROM in_library_use WHERE CopyID = ? AND Status = 'Active'",
        [$copyId]
    );
    if ($active > 0) return ['ok' => false, 'copy' => $copy,
        'reason' => 'Copy ' . $copy['AccessionNumber'] . ' already has an active in-library-use session.'];

    return ['ok' => true, 'copy' => $copy, 'reason' => ''];
}

function start_in_library_use(
    int $copyId,
    string $borrowerType,
    $memberId,
    ?string $walkInName,
    ?string $walkInContact,
    int $userId,
    ?string $remarks
): int {
    if (!in_array($borrowerType, ['Member', 'Walk-in'], true)) {
        throw new LibraryException('Invalid borrower type.');
    }
    if ($borrowerType === 'Member' && !$memberId) {
        throw new LibraryException('Please select a registered member.');
    }
    if ($borrowerType === 'Walk-in' && (!$walkInName || trim($walkInName) === '')) {
        throw new LibraryException('Please enter the walk-in visitor\'s name.');
    }

    if ($borrowerType === 'Member') {
        $m = fetch_one(
            "SELECT ua.UserID, ua.MembershipStatus, r.RoleName
               FROM user_account ua
               LEFT JOIN role r ON r.RoleID = ua.RoleID
              WHERE ua.UserID = ?",
            [$memberId]
        );
        if (!$m) throw new LibraryException('Member not found.');
        if (strcasecmp((string)$m['RoleName'], 'Member') !== 0) {
            throw new LibraryException('Selected account is not a registered member.');
        }
        if ($m['MembershipStatus'] !== 'Active') {
            throw new LibraryException('That member is not Active.');
        }
        $walkInName = null;
        $walkInContact = null;
    } else {
        $memberId = null;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $copy = fetch_one(
            "SELECT CopyID, Status, IsArchived FROM book_copy WHERE CopyID = ? FOR UPDATE",
            [$copyId]
        );
        if (!$copy) throw new LibraryException('Copy not found.');
        if (!empty($copy['IsArchived'])) throw new LibraryException('Copy is archived.');
        if ($copy['Status'] !== 'Available') {
            throw new LibraryException('Copy is currently "' . $copy['Status'] . '" and cannot be used.');
        }

        $active = (int) fetch_value(
            "SELECT COUNT(*) FROM in_library_use WHERE CopyID = ? AND Status = 'Active'",
            [$copyId]
        );
        if ($active > 0) throw new LibraryException('This copy already has an active in-library-use session.');

        $valid = validate_in_library_use_copy($copyId);
        if (!$valid['ok']) throw new LibraryException($valid['reason']);

        run(
            "INSERT INTO in_library_use
                (CopyID, MemberID, WalkInName, WalkInContact, BorrowerType,
                 UserID, StartTime, Status, Remarks)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), 'Active', ?)",
            [$copyId, $memberId, $walkInName, $walkInContact,
             $borrowerType, $userId, $remarks ?: null]
        );
        $id = (int)last_id();

        run("UPDATE book_copy SET Status = 'In Use' WHERE CopyID = ?", [$copyId]);

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function end_in_library_use(int $sessionId, ?int $userId, ?string $remarks = null): void
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $session = fetch_one(
            "SELECT InLibraryUseID, CopyID, Status FROM in_library_use
              WHERE InLibraryUseID = ? FOR UPDATE",
            [$sessionId]
        );
        if (!$session) throw new LibraryException('Session not found.');
        if ($session['Status'] !== 'Active') {
            throw new LibraryException('This session has already been marked returned.');
        }

        $copy = fetch_one(
            "SELECT CopyID, Status, IsArchived FROM book_copy WHERE CopyID = ? FOR UPDATE",
            [$session['CopyID']]
        );

        run(
            "UPDATE in_library_use
                SET Status = 'Returned',
                    ReturnTime = NOW(),
                    ReturnedByUserID = ?,
                    Remarks = COALESCE(?, Remarks)
              WHERE InLibraryUseID = ?",
            [$userId, $remarks ?: null, $sessionId]
        );

        if ($copy && empty($copy['IsArchived'])) {
            $terminal = ['Lost', 'Damaged', 'Withdrawn', 'Borrowed', 'Reserved'];
            if (!in_array($copy['Status'], $terminal, true)) {
                run("UPDATE book_copy SET Status = 'Available' WHERE CopyID = ?", [$copy['CopyID']]);
                promote_reservation_queue((int) fetch_value("SELECT BookID FROM book_copy WHERE CopyID = ?", [$copy['CopyID']]));
            }
        }

        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction) $pdo->rollBack();
        throw $e;
    }
}

function format_duration($from, $to = null): string
{
    if (!$from) return '—';
    $to = $to ?: date('Y-m-d H:i:s');
    $s  = max(0, strtotime($to) - strtotime($from));
    $h  = (int)floor($s / 3600);
    $m  = (int)floor(($s % 3600) / 60);
    if ($h >= 1) return $h . 'h ' . $m . 'm';
    return $m . 'm';
}

/* =====================================================================
 * MEMBER NOTIFICATIONS
 * ===================================================================== */

function member_notifications($memberId): array
{
    $notifications = [];

    $overdue = fetch_all(
        "SELECT ld.LoanDetailID, ld.LoanID, ld.DueDate, b.Title,
                bc.AccessionNumber,
                DATEDIFF(CURDATE(), ld.DueDate) AS DaysLate
           FROM loan l
           JOIN loan_detail ld ON ld.LoanID = l.LoanID
           JOIN book_copy bc   ON bc.CopyID = ld.CopyID
           JOIN book b         ON b.BookID  = bc.BookID
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
          WHERE l.BorrowerUserID = ?
            AND rd.ReturnDetailID IS NULL
            AND ld.DueDate < CURDATE()
          ORDER BY ld.DueDate ASC",
        [$memberId]
    );

    foreach ($overdue as $o) {
        $days = (int)$o['DaysLate'];
        $notifications[] = [
            'type' => 'overdue', 'key' => 'overdue_' . $o['LoanDetailID'],
            'severity' => 'danger', 'icon' => 'bi-exclamation-octagon-fill',
            'title' => 'Overdue: ' . $o['Title'],
            'message' => 'Due ' . fmt_date($o['DueDate'])
                       . ' — ' . $days . ' day' . ($days === 1 ? '' : 's') . ' late.'
                       . ' Copy ' . $o['AccessionNumber'] . '.',
            'date' => $o['DueDate'], 'sort' => strtotime($o['DueDate']),
        ];
    }

    $dueSoon = fetch_all(
        "SELECT ld.LoanDetailID, ld.LoanID, ld.DueDate, b.Title,
                bc.AccessionNumber,
                DATEDIFF(ld.DueDate, CURDATE()) AS DaysLeft
           FROM loan l
           JOIN loan_detail ld ON ld.LoanID = l.LoanID
           JOIN book_copy bc   ON bc.CopyID = ld.CopyID
           JOIN book b         ON b.BookID  = bc.BookID
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
          WHERE l.BorrowerUserID = ?
            AND rd.ReturnDetailID IS NULL
            AND ld.DueDate >= CURDATE()
            AND ld.DueDate <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
          ORDER BY ld.DueDate ASC",
        [$memberId]
    );

    foreach ($dueSoon as $u) {
        $left = (int)$u['DaysLeft'];
        $when = $left === 0 ? 'today' : ($left === 1 ? 'tomorrow' : "in {$left} days");
        $notifications[] = [
            'type' => 'due_soon', 'key' => 'due_' . $u['LoanDetailID'],
            'severity' => 'warning', 'icon' => 'bi-clock-fill',
            'title' => 'Due soon: ' . $u['Title'],
            'message' => 'Due ' . $when . ' (' . fmt_date($u['DueDate']) . ').'
                       . ' Copy ' . $u['AccessionNumber'] . '.',
            'date' => $u['DueDate'], 'sort' => strtotime($u['DueDate']),
        ];
    }

    /* Approved requests waiting for pickup (copy held for the member) */
    $ready = fetch_all(
        "SELECT rq.RequestID, rq.RequestType, rq.ExpiresAt, rq.NotifiedAt, bc.AccessionNumber,
                (SELECT GROUP_CONCAT(b.Title SEPARATOR ', ')
                   FROM borrow_request_detail rd JOIN book b ON b.BookID = rd.BookID
                  WHERE rd.RequestID = rq.RequestID) AS Books
           FROM borrow_request rq
           JOIN book_copy bc ON bc.CopyID = rq.AssignedCopyID
          WHERE rq.UserID = ? AND rq.ApprovalStatus = 'Approved'
          ORDER BY rq.NotifiedAt DESC",
        [$memberId]
    );
    foreach ($ready as $r) {
        $isRes = $r['RequestType'] === 'Reservation';
        $when  = $r['NotifiedAt'] ?: $r['ExpiresAt'];
        $notifications[] = [
            'type' => 'ready', 'key' => 'ready_' . $r['RequestID'],
            'severity' => 'success', 'icon' => 'bi-bag-check-fill',
            'title' => ($isRes ? 'Your reserved book is available: ' : 'Borrow request approved: ') . ($r['Books'] ?: '—'),
            'message' => 'A copy is being held for you' . ($r['ExpiresAt'] ? ' until ' . fmt_datetime($r['ExpiresAt']) : '')
                       . '. Please collect it at the circulation desk (copy ' . $r['AccessionNumber'] . ').',
            'date' => $when, 'sort' => strtotime((string)$when),
        ];
    }

    $decisions = fetch_all(
        "SELECT rq.RequestID, rq.RequestType, rq.ApprovalStatus, rq.ApprovalDate, rq.DecisionReason,
                (SELECT GROUP_CONCAT(b.Title SEPARATOR ', ')
                   FROM borrow_request_detail rd
                   JOIN book b ON b.BookID = rd.BookID
                  WHERE rd.RequestID = rq.RequestID) AS Books
           FROM borrow_request rq
          WHERE rq.UserID = ?
            AND rq.ApprovalStatus IN ('Rejected', 'Cancelled', 'Fulfilled', 'Expired')
            AND rq.ApprovalDate IS NOT NULL
            AND rq.ApprovalDate >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          ORDER BY rq.ApprovalDate DESC LIMIT 10",
        [$memberId]
    );

    foreach ($decisions as $d) {
        $label  = $d['RequestType'] === 'Reservation' ? 'Reservation' : 'Borrow request';
        $reason = $d['DecisionReason'] ? ' Reason: ' . $d['DecisionReason'] . '.' : '';
        switch ($d['ApprovalStatus']) {
            case 'Fulfilled':
                $sev = 'primary'; $icon = 'bi-bag-check-fill';
                $title = $label . ' #' . $d['RequestID'] . ' — book released';
                $msg = 'Released to you on ' . fmt_date($d['ApprovalDate']) . '.';
                break;
            case 'Rejected':
                $sev = 'danger'; $icon = 'bi-x-circle-fill';
                $title = $label . ' #' . $d['RequestID'] . ' rejected';
                $msg = 'Rejected on ' . fmt_date($d['ApprovalDate']) . '.' . $reason;
                break;
            case 'Expired':
                $sev = 'warning'; $icon = 'bi-hourglass-bottom';
                $title = $label . ' #' . $d['RequestID'] . ' expired';
                $msg = 'The copy was not collected in time and was released.';
                break;
            default:
                $sev = 'secondary'; $icon = 'bi-dash-circle-fill';
                $title = $label . ' #' . $d['RequestID'] . ' cancelled';
                $msg = 'Cancelled on ' . fmt_date($d['ApprovalDate']) . '.' . $reason;
                break;
        }
        $notifications[] = [
            'type' => 'decision',
            'key'  => 'dec_' . $d['RequestID'] . '_' . $d['ApprovalStatus'],
            'severity' => $sev, 'icon' => $icon, 'title' => $title,
            'message' => $msg . ' Book(s): ' . ($d['Books'] ?: '—'),
            'date' => $d['ApprovalDate'], 'sort' => strtotime($d['ApprovalDate']),
        ];
    }

    $fines = fetch_all(
        "SELECT f.FineID, f.FineAmount, f.Reason, f.FineDate, b.Title,
                (SELECT COALESCE(SUM(p.PaidAmount),0)
                   FROM payment p WHERE p.FineID = f.FineID) AS PaidSoFar
           FROM fine f
           JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
           JOIN loan l         ON l.LoanID        = ld.LoanID
           JOIN book_copy bc   ON bc.CopyID       = ld.CopyID
           JOIN book b         ON b.BookID        = bc.BookID
          WHERE l.BorrowerUserID = ?
            AND f.PaymentStatus = 'Unpaid'
          ORDER BY f.FineDate DESC",
        [$memberId]
    );

    foreach ($fines as $f) {
        $balance = max(0.0, (float)$f['FineAmount'] - (float)$f['PaidSoFar']);
        if ($balance <= 0) continue;
        $notifications[] = [
            'type' => 'fine', 'key' => 'fine_' . $f['FineID'],
            'severity' => 'danger', 'icon' => 'bi-cash-coin',
            'title' => 'Unpaid fine: ' . money($balance),
            'message' => $f['Title'] . ' — ' . $f['Reason']
                       . ' (charged ' . fmt_date($f['FineDate']) . ').',
            'date' => $f['FineDate'], 'sort' => strtotime($f['FineDate']),
        ];
    }

    $priority = ['overdue' => 0, 'fine' => 1, 'ready' => 2, 'due_soon' => 3, 'decision' => 4];
    usort($notifications, function ($a, $b) use ($priority) {
        $pa = $priority[$a['type']] ?? 99;
        $pb = $priority[$b['type']] ?? 99;
        if ($pa !== $pb) return $pa - $pb;
        return ($b['sort'] ?? 0) - ($a['sort'] ?? 0);
    });

    return $notifications;
}

/* =====================================================================
 * BORROW REQUESTS, RESERVATIONS AND HOLDS
 * ---------------------------------------------------------------------
 * One table (borrow_request + borrow_request_detail) holds every member
 * request. Its RequestType never changes after it is created:
 *
 *   Borrow       member asked for a book that had a copy on the shelf
 *   Reservation  member queued for a book with no copy on the shelf
 *
 * ApprovalStatus is the only thing that moves:
 *
 *   Pending    Borrow: waits for staff review.  Reservation: waits in the queue.
 *   Approved   a physical copy is HELD (book_copy.Status = 'Reserved') for pickup.
 *              The book is NOT borrowed yet — no loan row, no due date.
 *   Fulfilled  staff released the copy (loans.php) -> loan + loan_detail created,
 *              copy becomes 'Borrowed', due date starts counting.
 *   Rejected   staff declined (DecisionReason says why).
 *   Cancelled  member or staff withdrew it (a held copy is released).
 *   Expired    the hold was not collected in time (the copy is released).
 *
 * Whenever a copy is released, promote_reservation_queue() offers it to the
 * oldest eligible queued Reservation, otherwise it goes back to 'Available'.
 * book_copy.Status is therefore the single source of truth for availability.
 * ===================================================================== */

const BORROW_REQUEST_HOLD_HOURS = 24;   // pickup window after staff approve a Borrow request
const RESERVATION_HOLD_HOURS    = 48;   // pickup window after a queued Reservation gets its copy

/** A business-rule problem whose message is safe to show to the user. */
class LibraryException extends RuntimeException {}

/**
 * Text that is safe to show for a caught error. Business-rule messages pass
 * through; anything else (SQL errors, etc.) is logged and replaced.
 */
function user_message(Throwable $e, string $fallback = 'Something went wrong. Please try again.'): string
{
    if ($e instanceof LibraryException) return $e->getMessage();
    error_log('LMS error: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    return $fallback;
}

/** Run $fn inside a DB transaction (joins the caller's transaction if one is open). */
function in_transaction(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function request_type_badge(string $type): string
{
    return $type === 'Reservation'
        ? '<span class="badge text-bg-info"><i class="bi bi-hourglass-split"></i> Reservation</span>'
        : '<span class="badge text-bg-primary"><i class="bi bi-bookmark-plus"></i> Borrow</span>';
}

/** Table row used when a list has nothing to show. */
function empty_row(int $cols, string $icon, string $title, string $text = ''): string
{
    return '<tr><td colspan="' . $cols . '"><div class="empty-state"><i class="bi ' . h($icon) . '"></i>'
         . '<div class="fw-semibold">' . h($title) . '</div>'
         . ($text !== '' ? '<div class="small">' . $text . '</div>' : '')
         . '</div></td></tr>';
}

/** Books on this member's open (Pending or held) requests. */
function count_open_request_books($memberId, int $excludeRequestId = 0): int
{
    return (int) fetch_value(
        "SELECT COUNT(*)
           FROM borrow_request_detail d
           JOIN borrow_request r ON r.RequestID = d.RequestID
          WHERE r.UserID = ?
            AND r.ApprovalStatus IN ('Pending','Approved')
            AND r.RequestID <> ?",
        [$memberId, $excludeRequestId]
    );
}

/** Does this member already have an open request or reservation for this book? */
function has_open_request_for_book(int $memberId, int $bookId): bool
{
    return (int) fetch_value(
        "SELECT COUNT(*)
           FROM borrow_request r
           JOIN borrow_request_detail d ON d.RequestID = r.RequestID
          WHERE r.UserID = ? AND d.BookID = ?
            AND r.ApprovalStatus IN ('Pending','Approved')",
        [$memberId, $bookId]
    ) > 0;
}

/** Does this member currently have an unreturned copy of this book? */
function member_holds_title(int $memberId, int $bookId): bool
{
    return (int) fetch_value(
        "SELECT COUNT(*)
           FROM loan_detail ld
           JOIN loan l        ON l.LoanID  = ld.LoanID
           JOIN book_copy bc  ON bc.CopyID = ld.CopyID
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
          WHERE l.BorrowerUserID = ? AND bc.BookID = ? AND rd.ReturnDetailID IS NULL",
        [$memberId, $bookId]
    ) > 0;
}

/**
 * Everything that must be true before a member may submit a request for a
 * book (steps 3 of the workflow). Also decides the request type.
 */
function check_borrow_request_eligibility(int $memberId, int $bookId): array
{
    // Open requests count toward the borrowing limit, so a member cannot
    // queue up more books than they are allowed to hold.
    $member = member_borrow_check($memberId, count_open_request_books($memberId) + 1);
    if (!$member['ok']) return ['ok' => false, 'reason' => $member['reason']];

    $book = validate_book_borrowability($bookId);
    if (!$book['ok']) return ['ok' => false, 'reason' => $book['reason']];
    $title = $book['book']['Title'];

    if (has_open_request_for_book($memberId, $bookId)) {
        return ['ok' => false, 'reason' => 'You already have an open request or reservation for "' . $title . '".'];
    }
    if (member_holds_title($memberId, $bookId)) {
        return ['ok' => false, 'reason' => 'You currently have a copy of "' . $title . '" on loan.'];
    }

    return [
        'ok'   => true, 'reason' => '', 'book' => $book['book'],
        'type' => (max(0, (int)$book['book']['AvailableCopies'] - borrow_keep_copies()) > 0) ? 'Borrow' : 'Reservation',
    ];
}

/** 1-based place in the reservation queue for the same book (0 if not queued). */
function reservation_queue_position(int $requestId): int
{
    $row = fetch_one(
        "SELECT r.RequestID, r.RequestDate, r.RequestType, r.ApprovalStatus, d.BookID
           FROM borrow_request r
           JOIN borrow_request_detail d ON d.RequestID = r.RequestID
          WHERE r.RequestID = ? LIMIT 1",
        [$requestId]
    );
    if (!$row || $row['RequestType'] !== 'Reservation' || $row['ApprovalStatus'] !== 'Pending') return 0;
    return 1 + (int) fetch_value(
        "SELECT COUNT(*)
           FROM borrow_request r2
           JOIN borrow_request_detail d2 ON d2.RequestID = r2.RequestID
          WHERE r2.RequestType = 'Reservation' AND r2.ApprovalStatus = 'Pending'
            AND d2.BookID = ?
            AND (r2.RequestDate < ? OR (r2.RequestDate = ? AND r2.RequestID < ?))",
        [$row['BookID'], $row['RequestDate'], $row['RequestDate'], $row['RequestID']]
    );
}

/** Member submits a request. Returns ['id','type','title','position']. */
function create_borrow_request(int $memberId, int $bookId): array
{
    return in_transaction(function () use ($memberId, $bookId) {
        // Serialise submissions by the same member so a double-click or two
        // browser tabs cannot create duplicates or exceed the limit.
        fetch_one("SELECT UserID FROM user_account WHERE UserID = ? FOR UPDATE", [$memberId]);

        $check = check_borrow_request_eligibility($memberId, $bookId);
        if (!$check['ok']) throw new LibraryException($check['reason']);

        run("INSERT INTO borrow_request (UserID, RequestType, ApprovalStatus) VALUES (?, ?, 'Pending')",
            [$memberId, $check['type']]);
        $id = (int) last_id();
        run("INSERT INTO borrow_request_detail (RequestID, BookID) VALUES (?, ?)", [$id, $bookId]);

        return [
            'id'       => $id,
            'type'     => $check['type'],
            'title'    => $check['book']['Title'],
            'position' => $check['type'] === 'Reservation' ? reservation_queue_position($id) : 0,
        ];
    });
}

/** Lock a request row and return it with its (first) book. */
function lock_borrow_request(int $requestId): array
{
    $req = fetch_one("SELECT * FROM borrow_request WHERE RequestID = ? FOR UPDATE", [$requestId]);
    if (!$req) throw new LibraryException('That request could not be found.');
    $book = fetch_one(
        "SELECT d.BookID, b.Title
           FROM borrow_request_detail d JOIN book b ON b.BookID = d.BookID
          WHERE d.RequestID = ? ORDER BY d.BookID LIMIT 1",
        [$requestId]
    );
    $req['BookID'] = $book['BookID'] ?? null;
    $req['Title']  = $book['Title']  ?? '—';
    return $req;
}

/** Staff approve a Pending request by holding a specific available copy for pickup. */
function approve_borrow_request(int $requestId, int $copyId, int $staffUserId): array
{
    return in_transaction(function () use ($requestId, $copyId, $staffUserId) {
        $req = lock_borrow_request($requestId);
        if ($req['ApprovalStatus'] !== 'Pending') {
            throw new LibraryException('That request is already ' . strtolower($req['ApprovalStatus']) . '.');
        }

        $member = member_borrow_check((int)$req['UserID'], count_open_request_books((int)$req['UserID'], $requestId) + 1);
        if (!$member['ok']) throw new LibraryException($member['reason']);

        $book = validate_book_borrowability((int)$req['BookID']);
        if (!$book['ok']) throw new LibraryException($book['reason']);

        $copy = fetch_one(
            "SELECT CopyID, BookID, AccessionNumber, Status, IsArchived FROM book_copy WHERE CopyID = ? FOR UPDATE",
            [$copyId]
        );
        if (!$copy)                                         throw new LibraryException('That copy could not be found.');
        if ((int)$copy['BookID'] !== (int)$req['BookID'])   throw new LibraryException('That copy belongs to a different book.');
        if (!empty($copy['IsArchived']))                    throw new LibraryException('Copy ' . $copy['AccessionNumber'] . ' is archived.');
        if ($copy['Status'] !== 'Available') {
            throw new LibraryException('Copy ' . $copy['AccessionNumber'] . ' is currently "' . $copy['Status'] . '" — choose another copy.');
        }

        assert_keeps_copy_in_library((int)$req['BookID'], (string)$req['Title'], 1);   // holding a copy takes it off the shelf too
        run("UPDATE book_copy SET Status = 'Reserved' WHERE CopyID = ?", [$copyId]);
        run("UPDATE borrow_request
                SET ApprovalStatus   = 'Approved',
                    AssignedCopyID   = ?,
                    ExpiresAt        = DATE_ADD(NOW(), INTERVAL ? HOUR),
                    NotifiedAt       = NOW(),
                    ApprovedByUserID = ?,
                    ApprovalDate     = NOW(),
                    DecisionReason   = NULL
              WHERE RequestID = ?",
            [$copyId, BORROW_REQUEST_HOLD_HOURS, $staffUserId, $requestId]);

        return ['title' => $req['Title'], 'accession' => $copy['AccessionNumber']];
    });
}

/** Staff reject a Pending request. A reason is mandatory. Returns the book title. */
function reject_borrow_request(int $requestId, string $reason, int $staffUserId): string
{
    $reason = trim(preg_replace('/\s+/', ' ', $reason));
    if (mb_strlen($reason) < 3)   throw new LibraryException('Please give a reason for rejecting this request.');
    if (mb_strlen($reason) > 255) throw new LibraryException('The reason is too long (255 characters maximum).');

    return in_transaction(function () use ($requestId, $reason, $staffUserId) {
        $req = lock_borrow_request($requestId);
        if ($req['ApprovalStatus'] !== 'Pending') {
            throw new LibraryException('That request is already ' . strtolower($req['ApprovalStatus']) . '.');
        }
        run("UPDATE borrow_request
                SET ApprovalStatus = 'Rejected', ApprovedByUserID = ?, ApprovalDate = NOW(), DecisionReason = ?
              WHERE RequestID = ?",
            [$staffUserId, $reason, $requestId]);
        return $req['Title'];
    });
}

/**
 * Cancel a Pending or held request. Members may cancel only their own;
 * staff may cancel any. A held copy is released and offered to the queue.
 * Returns the book title.
 */
function cancel_borrow_request(int $requestId, int $actorUserId, bool $actorIsStaff, ?string $reason = null): string
{
    return in_transaction(function () use ($requestId, $actorUserId, $actorIsStaff, $reason) {
        $req = lock_borrow_request($requestId);
        if (!$actorIsStaff && (int)$req['UserID'] !== $actorUserId) {
            throw new LibraryException('That request could not be found.');   // do not reveal other members' requests
        }
        if (!in_array($req['ApprovalStatus'], ['Pending', 'Approved'], true)) {
            throw new LibraryException('That request is already ' . strtolower($req['ApprovalStatus']) . ' and cannot be cancelled.');
        }
        if ((int) fetch_value("SELECT COUNT(*) FROM loan WHERE RequestID = ?", [$requestId]) > 0) {
            throw new LibraryException('That request has already been issued as a loan.');
        }

        $reason = trim((string)$reason) !== '' ? mb_substr(trim($reason), 0, 255)
                : ($actorIsStaff ? 'Cancelled by library staff' : 'Cancelled by the member');
        $heldCopyId = $req['AssignedCopyID'] ? (int)$req['AssignedCopyID'] : null;

        run("UPDATE borrow_request
                SET ApprovalStatus = 'Cancelled', AssignedCopyID = NULL, ApprovalDate = NOW(),
                    DecisionReason = ?, ApprovedByUserID = IF(?, ?, ApprovedByUserID)
              WHERE RequestID = ?",
            [$reason, $actorIsStaff ? 1 : 0, $actorUserId, $requestId]);

        if ($heldCopyId) release_held_copy($heldCopyId, (int)$req['BookID']);
        return $req['Title'];
    });
}

/** A held copy is no longer needed: offer it to the queue, else put it back on the shelf. */
function release_held_copy(int $copyId, int $bookId): void
{
    in_transaction(function () use ($copyId, $bookId) {
        run("UPDATE book_copy SET Status = 'Available' WHERE CopyID = ? AND Status = 'Reserved'", [$copyId]);
        promote_reservation_queue($bookId);
    });
}

/**
 * Give every Available copy of a book to the oldest eligible Pending
 * Reservation (first come, first served). Members who are currently not
 * eligible keep their place in the queue. Returns how many were promoted.
 * Call it whenever a copy of the book becomes Available.
 */
function promote_reservation_queue(int $bookId): int
{
    return in_transaction(function () use ($bookId) {
        if (!validate_book_borrowability($bookId)['ok']) return 0;

        $waiters = fetch_all(
            "SELECT r.RequestID, r.UserID
               FROM borrow_request r
               JOIN borrow_request_detail d ON d.RequestID = r.RequestID
              WHERE r.RequestType = 'Reservation' AND r.ApprovalStatus = 'Pending'
                AND r.AssignedCopyID IS NULL AND d.BookID = ?
              ORDER BY r.RequestDate ASC, r.RequestID ASC
              FOR UPDATE",
            [$bookId]
        );

        $promoted = 0;
        foreach ($waiters as $w) {
            $copy = fetch_one(
                "SELECT CopyID FROM book_copy
                  WHERE BookID = ? AND Status = 'Available' AND IsArchived = 0
                  ORDER BY CopyID LIMIT 1 FOR UPDATE",
                [$bookId]
            );
            if (!$copy) break;
            if (count(fetch_all("SELECT CopyID FROM book_copy WHERE BookID = ? AND Status = 'Available' AND IsArchived = 0", [$bookId])) - 1 < borrow_keep_copies()) break;   // last spare copy stays on the shelf

            $uid = (int)$w['UserID'];
            $eligible = member_borrow_check($uid, count_open_request_books($uid, (int)$w['RequestID']) + 1);
            if (!$eligible['ok']) continue;

            run("UPDATE book_copy SET Status = 'Reserved' WHERE CopyID = ?", [$copy['CopyID']]);
            run("UPDATE borrow_request
                    SET ApprovalStatus = 'Approved', AssignedCopyID = ?,
                        ExpiresAt = DATE_ADD(NOW(), INTERVAL ? HOUR), NotifiedAt = NOW(),
                        ApprovalDate = NOW(), ApprovedByUserID = NULL, DecisionReason = NULL
                  WHERE RequestID = ?",
                [$copy['CopyID'], RESERVATION_HOLD_HOURS, $w['RequestID']]);
            $promoted++;
        }
        return $promoted;
    });
}

/** Expire one held request whose pickup window has passed. Safe to call repeatedly. */
function expire_hold(int $requestId): void
{
    in_transaction(function () use ($requestId) {
        $req = fetch_one(
            "SELECT RequestID, ApprovalStatus, AssignedCopyID,
                    (ExpiresAt IS NOT NULL AND ExpiresAt < NOW()) AS IsDue
               FROM borrow_request WHERE RequestID = ? FOR UPDATE",
            [$requestId]
        );
        if (!$req || $req['ApprovalStatus'] !== 'Approved' || !(int)$req['IsDue']) return;
        if ((int) fetch_value("SELECT COUNT(*) FROM loan WHERE RequestID = ?", [$requestId]) > 0) return;

        $bookId = (int) fetch_value("SELECT BookID FROM borrow_request_detail WHERE RequestID = ? LIMIT 1", [$requestId]);
        run("UPDATE borrow_request
                SET ApprovalStatus = 'Expired', AssignedCopyID = NULL, ApprovalDate = NOW(),
                    DecisionReason = 'Not collected within the pickup window'
              WHERE RequestID = ?", [$requestId]);
        if ($req['AssignedCopyID']) release_held_copy((int)$req['AssignedCopyID'], $bookId);
    });
}

/**
 * Housekeeping, run at most once a minute per session:
 *   1. expire holds that were not collected in time
 *   2. offer Available copies to any queued reservation that is waiting
 *      (self-heals if copies were added or restored by staff)
 */
function cleanup_expired_holds(): void
{
    try {
        $due = fetch_all(
            "SELECT RequestID FROM borrow_request
              WHERE ApprovalStatus = 'Approved' AND AssignedCopyID IS NOT NULL
                AND ExpiresAt IS NOT NULL AND ExpiresAt < NOW()"
        );
        foreach ($due as $row) {
            try { expire_hold((int)$row['RequestID']); } catch (Throwable $e) { user_message($e); }
        }

        $stalled = fetch_all(
            "SELECT DISTINCT d.BookID
               FROM borrow_request r
               JOIN borrow_request_detail d ON d.RequestID = r.RequestID
              WHERE r.RequestType = 'Reservation' AND r.ApprovalStatus = 'Pending'
                AND EXISTS (SELECT 1 FROM book_copy bc
                             WHERE bc.BookID = d.BookID AND bc.Status = 'Available' AND bc.IsArchived = 0)"
        );
        foreach ($stalled as $row) {
            try { promote_reservation_queue((int)$row['BookID']); } catch (Throwable $e) { user_message($e); }
        }
    } catch (Throwable $t) {
        user_message($t);   // never break a page over housekeeping
    }
}


/**
 * Staff release the held copy to the member: this is the moment the book is
 * actually borrowed. Creates the loan, stamps the due date from the book's
 * policy, marks the copy Borrowed and the request Fulfilled — all or nothing.
 *
 * $chosenCopies is only used for the rare request that has no held copy
 * (legacy data): [BookID => CopyID].  Returns ['loan_id','items'].
 */
function release_borrow_request(int $requestId, int $staffUserId, array $chosenCopies = []): array
{
    cleanup_expired_holds();   // an expired hold must not be releasable

    return in_transaction(function () use ($requestId, $staffUserId, $chosenCopies) {
        $req = lock_borrow_request($requestId);
        if ($req['ApprovalStatus'] !== 'Approved') {
            throw new LibraryException('Request #' . $requestId . ' is ' . strtolower($req['ApprovalStatus'])
                . ' — only approved requests with a held copy can be released.');
        }
        if ((int) fetch_value("SELECT COUNT(*) FROM loan WHERE RequestID = ?", [$requestId]) > 0) {
            throw new LibraryException('Request #' . $requestId . ' has already been issued as a loan.');
        }

        $memberId = (int)$req['UserID'];
        $bookIds  = array_map('intval', array_column(
            fetch_all("SELECT BookID FROM borrow_request_detail WHERE RequestID = ? ORDER BY BookID", [$requestId]),
            'BookID'
        ));
        if (!$bookIds) throw new LibraryException("Request #$requestId has no books on record.");

        // Membership, fines, overdue items and the borrowing limit are re-checked at release time.
        $member = member_borrow_check($memberId, count($bookIds));
        if (!$member['ok']) throw new LibraryException($member['reason']);

        $heldCopyId   = $req['AssignedCopyID'] ? (int)$req['AssignedCopyID'] : 0;
        $heldCopyBook = $heldCopyId ? (int) fetch_value("SELECT BookID FROM book_copy WHERE CopyID = ?", [$heldCopyId]) : 0;

        $items = []; $used = [];
        foreach ($bookIds as $bookId) {
            $book = validate_book_borrowability($bookId);
            if (!$book['ok']) throw new LibraryException($book['reason']);
            $title = $book['book']['Title'];

            $isHeld = $heldCopyId && $heldCopyBook === $bookId;
            $copyId = $isHeld ? $heldCopyId : (int)($chosenCopies[$bookId] ?? 0);
            if ($copyId < 1)        throw new LibraryException('Select which copy of "' . $title . '" to issue.');
            if (isset($used[$copyId])) throw new LibraryException('The same copy was selected twice.');
            $used[$copyId] = true;

            $copy = fetch_one(
                "SELECT CopyID, BookID, AccessionNumber, Status, IsArchived FROM book_copy WHERE CopyID = ? FOR UPDATE",
                [$copyId]
            );
            if (!$copy)                                  throw new LibraryException('The selected copy could not be found.');
            if ((int)$copy['BookID'] !== $bookId)        throw new LibraryException('Copy ' . $copy['AccessionNumber'] . ' does not belong to "' . $title . '".');
            if (!empty($copy['IsArchived']))             throw new LibraryException('Copy ' . $copy['AccessionNumber'] . ' is archived.');
            $needed = $isHeld ? 'Reserved' : 'Available';
            if ($copy['Status'] !== $needed) {
                throw new LibraryException('Copy ' . $copy['AccessionNumber'] . ' is "' . $copy['Status'] . '", expected "' . $needed . '".');
            }
            $items[] = ['CopyID' => $copyId, 'BookID' => $bookId];
        }

        run("INSERT INTO loan (BorrowerUserID, ProcessedByUserID, RequestID) VALUES (?, ?, ?)",
            [$memberId, $staffUserId, $requestId]);
        $loanId = (int) last_id();

        foreach ($items as $it) {
            $due = date('Y-m-d', strtotime('+' . resolve_loan_days($it['BookID']) . ' days'));
            run("INSERT INTO loan_detail (LoanID, CopyID, DueDate, BookCondition) VALUES (?, ?, ?, 'Good')",
                [$loanId, $it['CopyID'], $due]);
            run("UPDATE book_copy SET Status = 'Borrowed' WHERE CopyID = ?", [$it['CopyID']]);
        }
        run("UPDATE borrow_request SET ApprovalStatus = 'Fulfilled' WHERE RequestID = ?", [$requestId]);

        return ['loan_id' => $loanId, 'items' => count($items)];
    });
}



/**
 * "Approve all" for ONE borrower: approves each listed Pending Borrow request as its own
 * record (own copy, own status, own transaction). A book with no copy on the shelf, or that
 * fails a rule, is skipped and reported — it never blocks or changes the others.
 * Only requests that belong to $userId are touched.
 * Returns ['approved' => int, 'skipped' => [string, ...]].
 */
function approve_borrower_requests(int $userId, array $requestIds, int $staffUserId): array
{
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
    if (!$requestIds) return ['approved' => 0, 'skipped' => []];

    $marks = implode(',', array_fill(0, count($requestIds), '?'));
    $rows = fetch_all(
        "SELECT r.RequestID,
                (SELECT d.BookID FROM borrow_request_detail d WHERE d.RequestID = r.RequestID ORDER BY d.BookID LIMIT 1) AS BookID,
                (SELECT b.Title FROM borrow_request_detail d JOIN book b ON b.BookID = d.BookID WHERE d.RequestID = r.RequestID ORDER BY d.BookID LIMIT 1) AS Title
           FROM borrow_request r
          WHERE r.UserID = ? AND r.RequestID IN ($marks)
            AND r.ApprovalStatus = 'Pending' AND r.RequestType = 'Borrow'
          ORDER BY r.RequestDate ASC, r.RequestID ASC",
        array_merge([$userId], $requestIds)
    );

    $approved = 0; $skipped = [];
    foreach ($rows as $r) {
        try {
            in_transaction(function () use ($r, $staffUserId) {
                $copyId = (int) fetch_value(
                    "SELECT CopyID FROM book_copy
                      WHERE BookID = ? AND Status = 'Available' AND IsArchived = 0
                      ORDER BY CopyID LIMIT 1 FOR UPDATE",
                    [$r['BookID']]
                );
                if ($copyId < 1) throw new LibraryException('no copy on the shelf');
                approve_borrow_request((int)$r['RequestID'], $copyId, $staffUserId);
            });
            $approved++;
        } catch (Throwable $e) {
            $skipped[] = '"' . $r['Title'] . '": ' . user_message($e, 'could not be approved');
        }
    }
    return ['approved' => $approved, 'skipped' => $skipped];
}


/* =====================================================================
 * BORROWING STATUS (summary bar, "almost at limit" warning, limit alert)
 * One place decides the numbers, wording and colour state, so the Browse
 * Books page, the dashboard and the live-status endpoint always agree.
 *
 *   borrowed = books currently out and not yet returned
 *   pending  = books on the member's open (Pending / Approved) requests.
 *              The existing request rules count these toward the limit, so
 *              they use up slots too — but they are shown separately and are
 *              never described as "borrowed".
 *   slots    = max - borrowed - pending   (-1 = no limit applies)
 *   state    = ok (green) | warn (amber, exactly 1 slot left after use) | full (red)
 * ===================================================================== */
function member_borrow_status($memberId): array
{
    $snap     = check_borrowing_limit($memberId, 0);
    $max      = (int)$snap['max_books'];
    $borrowed = (int)$snap['current_books'];
    $pending  = count_open_request_books($memberId);

    $st = [
        'enabled' => $max > 0, 'type' => $snap['borrower_type'],
        'max' => $max, 'borrowed' => $borrowed, 'pending' => $pending,
        'slots' => -1, 'state' => 'ok', 'message' => '',
        'warn_title' => '', 'warn_message' => '',
    ];
    if ($max < 1) return $st;

    $slots = max(0, $max - $borrowed - $pending);
    $st['slots'] = $slots;
    $used = $borrowed + $pending;

    if ($slots === 0) {
        $st['state']   = 'full';
        $st['message'] = $borrowed >= $max
            ? 'Borrowing limit reached.'
            : 'Borrowing limit reached — your pending requests use the remaining slots.';
    } else {
        $st['state']   = ($slots === 1 && $used > 0) ? 'warn' : 'ok';
        $st['message'] = "You can borrow $slots more book" . ($slots === 1 ? '' : 's') . '.';
    }

    if ($st['state'] === 'warn') {
        $st['warn_title']   = 'Almost at Your Borrowing Limit';
        $st['warn_message'] = $pending === 0
            ? "You have borrowed $borrowed of $max books. You can borrow only 1 more book."
            : "You have borrowed $borrowed of $max books and have $pending pending request" . ($pending === 1 ? '' : 's')
              . '. You can request only 1 more book.';
    }
    return $st;
}

/** Amber "almost at limit" notice (and, when asked, the red "limit reached" notice). */
function render_borrow_alert(array $st, bool $includeFull = false): string
{
    if (empty($st['enabled'])) return '';
    if ($st['state'] === 'warn') {
        return '<div class="alert alert-warning d-flex gap-2 align-items-start" role="status">'
             . '<i class="bi bi-exclamation-triangle-fill mt-1"></i><div><strong>' . h($st['warn_title']) . '</strong><br>'
             . '<span class="small">' . h($st['warn_message']) . '</span></div></div>';
    }
    if ($includeFull && $st['state'] === 'full') {
        $msg = $st['borrowed'] >= $st['max']
            ? 'You have reached your borrowing limit of ' . $st['max'] . ' book' . ($st['max'] === 1 ? '' : 's')
              . '. Please return a borrowed book before requesting another one.'
            : 'Your borrowed books and pending requests fill your limit of ' . $st['max']
              . '. Wait for staff to review your requests, or return a book, before requesting another.';
        return '<div class="alert alert-danger d-flex gap-2 align-items-start" role="status">'
             . '<i class="bi bi-exclamation-octagon-fill mt-1"></i><div><strong>Borrowing Limit Reached!</strong><br>'
             . '<span class="small">' . h($msg) . '</span> '
             . '<a class="small fw-semibold" href="member_loans.php">View My Borrowed Books →</a></div></div>';
    }
    return '';
}



/* =====================================================================
 * SELF-SERVICE IN-LIBRARY USE (kiosk.php / kiosk_api.php) + staff-assisted start
 *
 * In-library use never creates a loan or a due date: it only records which physical
 * copy a reader has on the reading tables and marks that copy "In Use" until the
 * session ends. The student identifier is the member's library card number
 * (user_account.MembershipNumber) — the only identifier the system has.
 * ===================================================================== */
require_once __DIR__ . '/../config/kiosk.php';

/** True once database/migrate_self_service_kiosk.sql has been imported. */
function ilu_schema_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query("SELECT Source, SessionRef, Location FROM in_library_use LIMIT 0");
        db()->query("SELECT EventID FROM kiosk_event LIMIT 0");
        $ok = true;
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

function kiosk_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);   // never trust forwarded headers here
}

/** Audit + rate-limit trail. Stores no ID text or personal details. Never breaks the caller. */
function kiosk_log(string $type, ?int $memberId = null, string $detail = ''): void
{
    try {
        run("INSERT INTO kiosk_event (EventType, MemberID, IPAddress, Detail) VALUES (?, ?, ?, ?)",
            [mb_substr($type, 0, 30), $memberId, kiosk_client_ip(), $detail !== '' ? mb_substr($detail, 0, 255) : null]);
        if (random_int(1, 100) === 1) run("DELETE FROM kiosk_event WHERE EventTime < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    } catch (Throwable $e) { error_log('kiosk_log: ' . $e->getMessage()); }
}

function kiosk_locked_out(): bool
{
    try {
        $n = (int) fetch_value(
            "SELECT COUNT(*) FROM kiosk_event
              WHERE EventType = 'id_fail' AND IPAddress = ?
                AND EventTime > DATE_SUB(NOW(), INTERVAL " . (int)KIOSK_LOCKOUT_MINUTES . " MINUTE)",
            [kiosk_client_ip()]);
        return $n >= (int)KIOSK_MAX_ID_ATTEMPTS;
    } catch (Throwable $e) { return false; }
}

/** Tidy a student ID typed by a person or read by the camera: "02 2425 014468" / "02–2425–014468" -> "02-2425-014468". */
function normalize_student_id(string $raw): string
{
    return strtoupper(trim(preg_replace('/[\x{2010}-\x{2015}\s]+/u', '-', trim($raw)), '-'));
}

/** Is this a plausible student ID (letters, digits and dashes, 6-25 characters)? */
function valid_student_id(string $id): bool
{
    return (bool) preg_match('/^[A-Z0-9][A-Z0-9-]{5,24}$/', $id);
}

/** Look a student up by Student ID (stored as user_account.MembershipNumber). Case-insensitive; dashes and spaces are ignored. */
function ilu_find_member_by_card(string $raw): ?array
{
    $card = trim($raw);
    if ($card === '' || mb_strlen($card) > 40) return null;
    $flat = preg_replace('/[^A-Z0-9]/', '', strtoupper($card));
    if ($flat === '') return null;
    return fetch_one(
        "SELECT ua.UserID, ua.FirstName, ua.LastName, ua.MembershipNumber, ua.MembershipStatus,
                ua.VerificationStatus, ua.IsActive
           FROM user_account ua JOIN role r ON r.RoleID = ua.RoleID
          WHERE r.RoleName = 'Member' AND ua.MembershipNumber IS NOT NULL
            AND REPLACE(REPLACE(UPPER(ua.MembershipNumber), '-', ''), ' ', '') = ? LIMIT 1",
        [$flat]
    ) ?: null;
}

/** Why this member may not self-register, or null if they may. */
function kiosk_member_block(array $m): ?string
{
    if (empty($m['IsActive']) || ($m['MembershipStatus'] ?? '') !== 'Active') {
        return 'Your library account is currently inactive. Please contact library staff for assistance.';
    }
    if (($m['VerificationStatus'] ?? 'Approved') !== 'Approved') {
        return 'Your registration is still waiting for verification. Please visit the library desk with a valid ID.';
    }
    return null;
}

/** The identified student's OWN information only (nothing about other members). */
function kiosk_member_summary(array $m): array
{
    $mine = fetch_all(
        "SELECT ilu.InLibraryUseID AS id, ilu.StartTime, b.Title, bc.AccessionNumber
           FROM in_library_use ilu
           JOIN book_copy bc ON bc.CopyID = ilu.CopyID JOIN book b ON b.BookID = bc.BookID
          WHERE ilu.MemberID = ? AND ilu.Status = 'Active' ORDER BY ilu.StartTime",
        [(int)$m['UserID']]
    );
    $initials = strtoupper(mb_substr((string)$m['FirstName'], 0, 1) . mb_substr((string)$m['LastName'], 0, 1));
    return [
        'name'      => trim($m['FirstName'] . ' ' . $m['LastName']),
        'card'      => $m['MembershipNumber'],
        'status'    => $m['MembershipStatus'],
        'initials'  => $initials,
        'in_use'    => count($mine),
        'sessions'  => array_map(fn($r) => [
            'id' => (int)$r['id'], 'title' => $r['Title'], 'copy' => $r['AccessionNumber'],
            'since' => date('g:i A', strtotime($r['StartTime'])),
        ], $mine),
    ];
}

/** Copy rows in the shape the kiosk needs, with whether each is already in an active session. */
function ilu_copy_rows(string $where, array $params, int $limit = 12): array
{
    return fetch_all(
        "SELECT bc.CopyID, bc.AccessionNumber, bc.Status, b.BookID, b.Title,
                (SELECT GROUP_CONCAT(CONCAT(a.FirstName, ' ', a.LastName) ORDER BY a.LastName SEPARATOR ', ')
                   FROM book_author ba JOIN author a ON a.AuthorID = ba.AuthorID WHERE ba.BookID = b.BookID) AS Authors,
                sh.ShelfCode, sec.SectionName, pol.IsBorrowable,
                EXISTS(SELECT 1 FROM in_library_use x WHERE x.CopyID = bc.CopyID AND x.Status = 'Active') AS InUse
           FROM book_copy bc
           JOIN book b ON b.BookID = bc.BookID
           JOIN policy pol ON pol.PolicyID = b.PolicyID
           LEFT JOIN shelf sh ON sh.ShelfID = bc.ShelfID
           LEFT JOIN section sec ON sec.SectionID = sh.SectionID
          WHERE bc.IsArchived = 0 AND b.IsArchived = 0 AND ($where)
          ORDER BY b.Title, bc.AccessionNumber
          LIMIT " . (int)$limit,
        $params
    );
}

/** Can this copy be registered for in-library use right now? */
function ilu_copy_state(array $r): array
{
    if ((int)$r['InUse'] || $r['Status'] === 'In Use') {
        return ['ok' => false, 'label' => 'In use by another reader',
                'reason' => 'This physical copy is currently registered to another active session.'];
    }
    switch ($r['Status']) {
        case 'Available': return ['ok' => true, 'label' => 'Available', 'reason' => ''];
        case 'Borrowed':  return ['ok' => false, 'label' => 'Checked out for home use',
                                  'reason' => 'This copy is checked out for home use and cannot be registered for reading in the library.'];
        case 'Reserved':  return ['ok' => false, 'label' => 'Held for a borrow request',
                                  'reason' => 'This copy is being held for another member.'];
        default:          return ['ok' => false, 'label' => 'Not available',
                                  'reason' => 'This copy is not available right now. Please ask library staff.'];
    }
}

/** Search by title / accession number (= the barcode on the copy). Exact accession match is flagged. */
function ilu_search_copies(string $q, int $limit = 12): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) return ['exact' => false, 'items' => []];
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $rows = ilu_copy_rows(
        "UPPER(bc.AccessionNumber) = UPPER(?) OR b.Title LIKE ? OR bc.AccessionNumber LIKE ?",
        [$q, $like, $like], $limit);
    $exact = false; $items = [];
    foreach ($rows as $r) {
        $st = ilu_copy_state($r);
        $isExact = strcasecmp($r['AccessionNumber'], $q) === 0;
        $exact = $exact || $isExact;
        $items[] = [
            'copyId' => (int)$r['CopyID'], 'accession' => $r['AccessionNumber'], 'title' => $r['Title'],
            'authors' => $r['Authors'] ?: '', 'shelf' => $r['ShelfCode'] ?: '', 'section' => $r['SectionName'] ?: '',
            'ok' => $st['ok'], 'label' => $st['label'], 'reason' => $st['reason'], 'exact' => $isExact,
            'borrowable' => (int)$r['IsBorrowable'] === 1,
            'bookId'     => (int)$r['BookID'],
            'loanDays'   => resolve_loan_days((int)$r['BookID']),
            'due'        => date('M d, Y', strtotime('+' . resolve_loan_days((int)$r['BookID']) . ' days')),
            'canBorrow'  => (int)$r['IsBorrowable'] === 1 && book_copies_borrowable_now((int)$r['BookID']) > 0,
            'borrowNote' => (int)$r['IsBorrowable'] !== 1 ? 'Library use only — it cannot be borrowed.'
                          : (book_copies_borrowable_now((int)$r['BookID']) > 0 ? '' : copy_rule_message((string)$r['Title'], book_shelf_copies((int)$r['BookID']))),
        ];
    }
    usort($items, fn($a, $b) => ($b['exact'] <=> $a['exact']) ?: ($b['ok'] <=> $a['ok']));
    return ['exact' => $exact, 'items' => $items];
}

/**
 * Start one in-library session covering one or more PHYSICAL copies (one in_library_use row per copy,
 * sharing a SessionRef). All-or-nothing, copies locked in id order so two kiosks cannot take the same
 * copy. Never touches loan / loan_detail and sets no due date.
 *
 * $source 'Kiosk': recorded by the student themselves (UserID = their own id) and the member must be
 * verified. $source 'Staff': $actorUserId is the staff member.
 * Returns ['ref' => string, 'items' => [['id','title','accession'], ...]].
 */
function start_in_library_session(
    array $copyIds, string $borrowerType, ?int $memberId, ?string $walkInName, ?string $walkInContact,
    ?int $actorUserId, ?string $location, ?string $remarks, string $source = 'Staff', ?string $sessionRef = null
): array {
    $copyIds = array_values(array_unique(array_filter(array_map('intval', $copyIds))));
    sort($copyIds);
    if (!$copyIds) throw new LibraryException('Please add at least one book.');
    if (count($copyIds) > (int)KIOSK_MAX_COPIES_PER_SESSION) {
        throw new LibraryException('Too many books in one session (maximum ' . (int)KIOSK_MAX_COPIES_PER_SESSION . ').');
    }
    if (!in_array($borrowerType, ['Member', 'Walk-in'], true)) throw new LibraryException('Invalid borrower type.');
    if (!in_array($source, ['Staff', 'Kiosk'], true)) throw new LibraryException('Invalid source.');
    $location = $location !== null ? trim($location) : null;
    if ($location !== null && mb_strlen($location) > 100) $location = mb_substr($location, 0, 100);
    if ($location === '') $location = null;

    if ($borrowerType === 'Member') {
        if (!$memberId) throw new LibraryException('Please identify the member.');
        $m = fetch_one(
            "SELECT ua.UserID, ua.MembershipStatus, ua.VerificationStatus, ua.IsActive, r.RoleName
               FROM user_account ua LEFT JOIN role r ON r.RoleID = ua.RoleID WHERE ua.UserID = ?", [$memberId]);
        if (!$m || strcasecmp((string)$m['RoleName'], 'Member') !== 0) throw new LibraryException('Member not found.');
        if ($m['MembershipStatus'] !== 'Active' || empty($m['IsActive'])) {
            throw new LibraryException('Your library account is currently inactive. Please contact library staff for assistance.');
        }
        if ($source === 'Kiosk' && ($m['VerificationStatus'] ?? 'Approved') !== 'Approved') {
            throw new LibraryException('Your registration is still waiting for verification. Please visit the library desk.');
        }
        $walkInName = null; $walkInContact = null;
    } else {
        $memberId = null;
        if (!$walkInName || trim($walkInName) === '') throw new LibraryException("Please enter the walk-in visitor's name.");
    }

    $recordedBy = $source === 'Kiosk' ? $memberId : $actorUserId;
    if (!$recordedBy) throw new LibraryException('No staff member recorded for this session.');
    $ref = $sessionRef ?: ('S' . date('ymd') . strtoupper(bin2hex(random_bytes(3))));

    return in_transaction(function () use ($copyIds, $borrowerType, $memberId, $walkInName, $walkInContact,
                                          $recordedBy, $location, $remarks, $source, $ref) {
        $items = [];
        foreach ($copyIds as $copyId) {
            // lock the physical copy: a second kiosk waits here, then sees it is In Use
            $lock = fetch_one("SELECT CopyID FROM book_copy WHERE CopyID = ? FOR UPDATE", [$copyId]);
            if (!$lock) throw new LibraryException('No matching book copy was found. Please check the barcode or search again.');
            $rows = ilu_copy_rows('bc.CopyID = ?', [$copyId], 1);
            if (!$rows) throw new LibraryException('A selected copy is archived or no longer in the catalog.');
            $r  = $rows[0];
            $st = ilu_copy_state($r);
            if (!$st['ok']) throw new LibraryException('“' . $r['Title'] . '” (copy ' . $r['AccessionNumber'] . '): ' . $st['reason']);

            run("INSERT INTO in_library_use
                    (CopyID, MemberID, WalkInName, WalkInContact, BorrowerType, UserID, StartTime, Status, Remarks, Source, SessionRef, Location)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), 'Active', ?, ?, ?, ?)",
                [$copyId, $memberId, $walkInName, $walkInContact, $borrowerType, $recordedBy,
                 $remarks ?: null, $source, $ref, $location]);
            $newId = (int) last_id();      // read it straight after the INSERT (the UPDATE below would reset it to 0)
            run("UPDATE book_copy SET Status = 'In Use' WHERE CopyID = ?", [$copyId]);
            $items[] = ['id' => $newId, 'copy' => $copyId, 'title' => $r['Title'], 'accession' => $r['AccessionNumber']];
        }
        return ['ref' => $ref, 'items' => $items];
    });
}



/* =====================================================================
 * CAPITOL UNIVERSITY PROCEDURE
 *   1. Student scans the school ID at the library entrance  -> library_entry
 *   2. Student asks permission for a book (kiosk or at the desk) -> use_request (Pending)
 *   3. The LIBRARIAN verifies student + book and approves / rejects each book
 *        approved for in-library use -> in_library_use record (no loan, no due date)
 *        approved for borrowing      -> loan + loan_detail with the policy's due date
 * Scanning an ID never approves anything: only use_request_decide() (librarian) creates a use or a loan.
 * ===================================================================== */

function entry_schema_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query("SELECT EntryID FROM library_entry LIMIT 0");
        db()->query("SELECT UseRequestID FROM use_request LIMIT 0");
        db()->query("SELECT ItemID FROM use_request_item LIMIT 0");
        $ok = true;
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

function use_request_ref(int $id): string { return 'UR-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT); }

/** Today's entry for this member that has not been checked out, or null. */
function member_entry_today(int $memberId): ?array
{
    return fetch_one(
        "SELECT EntryID, EntryTime FROM library_entry
          WHERE MemberID = ? AND Result = 'Allowed' AND DATE(EntryTime) = CURDATE()
          ORDER BY EntryTime DESC LIMIT 1", [$memberId]) ?: null;
}

/**
 * Process one ID scan at the entrance (or a manual entry by staff).
 * Verifies the ID belongs to a registered, active, verified member, then logs the entry with date/time.
 * A second scan while already inside does not create a duplicate row.
 */
function entry_record_scan(string $card, string $method = 'Scanner', ?int $staffId = null): array
{
    $ip = kiosk_client_ip();
    if ($method === 'Scanner' && kiosk_locked_out()) {
        return ['ok' => false, 'code' => 'locked', 'message' => 'Too many unrecognised scans. Please see the librarian at the desk.'];
    }
    $m = ilu_find_member_by_card($card);
    if (!$m) {
        kiosk_log('id_fail');
        run("INSERT INTO library_entry (MemberID, Result, DenyReason, Method, RecordedByUserID, IPAddress) VALUES (NULL, 'Denied', 'ID not registered', ?, ?, ?)",
            [$method, $staffId, $ip]);
        return ['ok' => false, 'code' => 'not_found',
                'message' => 'This ID is not registered in the library system. Please see the librarian at the desk.'];
    }
    $block = null;
    if (empty($m['IsActive']) || $m['MembershipStatus'] !== 'Active') $block = 'Membership ' . strtolower((string)$m['MembershipStatus']);
    elseif (($m['VerificationStatus'] ?? 'Approved') !== 'Approved') $block = 'Registration awaiting verification';
    if ($block) {
        run("INSERT INTO library_entry (MemberID, Result, DenyReason, Method, RecordedByUserID, IPAddress) VALUES (?, 'Denied', ?, ?, ?, ?)",
            [(int)$m['UserID'], $block, $method, $staffId, $ip]);
        return ['ok' => false, 'code' => 'blocked', 'name' => trim($m['FirstName'] . ' ' . $m['LastName']),
                'message' => 'Your library account cannot be used for entry right now (' . strtolower($block) . '). Please see the librarian at the desk.'];
    }
    $summary = ['name' => trim($m['FirstName'] . ' ' . $m['LastName']), 'card' => $m['MembershipNumber'],
                'initials' => strtoupper(mb_substr($m['FirstName'], 0, 1) . mb_substr($m['LastName'], 0, 1)),
                'member_id' => (int)$m['UserID']];
    return in_transaction(function () use ($m, $method, $staffId, $ip, $summary) {
        fetch_one("SELECT UserID FROM user_account WHERE UserID = ? FOR UPDATE", [(int)$m['UserID']]);  // serialise double-scans
        if ($open = member_entry_today((int)$m['UserID'])) {
            return $summary + ['ok' => true, 'already' => true, 'entry_id' => (int)$open['EntryID'], 'time' => $open['EntryTime']];
        }
        run("INSERT INTO library_entry (MemberID, Result, Method, RecordedByUserID, IPAddress) VALUES (?, 'Allowed', ?, ?, ?)",
            [(int)$m['UserID'], $method, $staffId, $ip]);
        $id = (int) last_id();
        return $summary + ['ok' => true, 'already' => false, 'entry_id' => $id, 'time' => date('Y-m-d H:i:s')];
    });
}

/**
 * A student asks permission to use ($items 'In-Library') or borrow ('Borrow') specific physical copies.
 * Creates a PENDING request only: no copy is reserved, no session or loan exists until a librarian approves.
 * $source 'Kiosk' requires an entry scan for today (KIOSK_REQUIRE_ENTRY); 'Desk' = entered by the librarian.
 */
function use_request_create(int $memberId, array $items, string $source = 'Kiosk', ?int $staffId = null): int
{
    $clean = [];
    foreach ($items as $it) {
        $cid = (int)($it['copy_id'] ?? 0);
        $use = (!IN_LIBRARY_USE_ENABLED || ($it['use'] ?? '') === 'Borrow') ? 'Borrow' : 'In-Library';
        if ($cid > 0) $clean[$cid] = $use;
        if ($cid > 0 && !IN_LIBRARY_USE_ENABLED && (int) fetch_value("SELECT pol.IsBorrowable FROM book_copy bc JOIN book b ON b.BookID = bc.BookID LEFT JOIN policy pol ON pol.PolicyID = b.PolicyID WHERE bc.CopyID = ?", [$cid]) !== 1)
            throw new LibraryException('One of these books is for library use only and cannot be borrowed. Please ask the librarian.');
    }
    if (!$clean) throw new LibraryException('Please add at least one book.');
    if (count($clean) > (int)KIOSK_MAX_COPIES_PER_SESSION) {
        throw new LibraryException('Too many books in one request (maximum ' . (int)KIOSK_MAX_COPIES_PER_SESSION . ').');
    }
    $m = fetch_one(
        "SELECT ua.UserID, ua.MembershipStatus, ua.VerificationStatus, ua.IsActive, r.RoleName
           FROM user_account ua LEFT JOIN role r ON r.RoleID = ua.RoleID WHERE ua.UserID = ?", [$memberId]);
    if (!$m || strcasecmp((string)$m['RoleName'], 'Member') !== 0) throw new LibraryException('Student not found.');
    if ($why = kiosk_member_block($m)) throw new LibraryException($why);

    $entry = member_entry_today($memberId);
    if ($source === 'Kiosk' && KIOSK_REQUIRE_ENTRY && !$entry) {
        throw new LibraryException("We don't have an entry record for you today. Please scan your ID at the library entrance first.");
    }

    return in_transaction(function () use ($memberId, $clean, $source, $staffId, $entry) {
        foreach ($clean as $cid => $use) {
            $rows = ilu_copy_rows('bc.CopyID = ?', [$cid], 1);
            if (!$rows) throw new LibraryException('No matching book copy was found. Please check the barcode or search again.');
            $r = $rows[0]; $st = ilu_copy_state($r);
            if (!$st['ok']) throw new LibraryException('“' . $r['Title'] . '” (copy ' . $r['AccessionNumber'] . '): ' . $st['reason']);
            if ($use === 'Borrow') {
                $bk = validate_book_borrowability((int)$r['BookID']);
                if (!$bk['ok']) throw new LibraryException('“' . $r['Title'] . '” can only be used inside the library.');
            }
            $dup = fetch_value(
                "SELECT COUNT(*) FROM use_request_item i JOIN use_request q ON q.UseRequestID = i.UseRequestID
                  WHERE q.MemberID = ? AND q.Status = 'Pending' AND i.Decision = 'Pending' AND i.CopyID = ?", [$memberId, $cid]);
            if ((int)$dup > 0) throw new LibraryException('You already have a pending request for “' . $r['Title'] . '” (copy ' . $r['AccessionNumber'] . ').');
        }
        run("INSERT INTO use_request (MemberID, EntryID, Source, Status, CreatedByUserID) VALUES (?, ?, ?, 'Pending', ?)",
            [$memberId, $entry['EntryID'] ?? null, $source, $source === 'Desk' ? $staffId : null]);
        $reqId = (int) last_id();
        foreach ($clean as $cid => $use) {
            run("INSERT INTO use_request_item (UseRequestID, CopyID, RequestedUse) VALUES (?, ?, ?)", [$reqId, $cid, $use]);
        }
        return $reqId;
    });
}

/** Issue a loan for physical copies at the desk (same rules as loans.php walk-in loan). Returns LoanID. */
/** $daysByCopy: optional [copyId => days] chosen on the desk form; a copy without an entry uses the book policy's loan days. */
function issue_desk_loan(int $memberId, array $copyIds, int $staffUserId, array $daysByCopy = []): int
{
    return in_transaction(function () use ($memberId, $copyIds, $staffUserId, $daysByCopy) {
        $check = member_borrow_check($memberId, count($copyIds));
        if (!$check['ok']) throw new LibraryException('Borrowing not allowed — ' . $check['reason']);
        $assigned = [];
        foreach ($copyIds as $copyId) {
            $copy = fetch_one(
                "SELECT bc.CopyID, bc.BookID, bc.AccessionNumber, bc.Status, bc.IsArchived, b.Title
                   FROM book_copy bc JOIN book b ON b.BookID = bc.BookID WHERE bc.CopyID = ? FOR UPDATE", [$copyId]);
            if (!$copy || !empty($copy['IsArchived'])) throw new LibraryException('A selected copy is no longer in the catalog.');
            if ($copy['Status'] !== 'Available') throw new LibraryException('Copy ' . $copy['AccessionNumber'] . ' is currently "' . $copy['Status'] . '".');
            $bk = validate_book_borrowability((int)$copy['BookID']);
            if (!$bk['ok']) throw new LibraryException($bk['reason']);
            $assigned[] = ['CopyID' => (int)$copy['CopyID'], 'Days' => (int)($daysByCopy[(int)$copy['CopyID']] ?? 0), 'BookID' => (int)$copy['BookID'], 'Title' => $copy['Title']];
        }
        assert_keeps_copies_for_rows($assigned);          // one copy of every title must stay in the library
        run("INSERT INTO loan (BorrowerUserID, ProcessedByUserID, RequestID) VALUES (?, ?, NULL)", [$memberId, $staffUserId]);
        $loanId = (int) last_id();
        foreach ($assigned as $a) {
            $due = date('Y-m-d', strtotime('+' . ($a['Days'] > 0 ? $a['Days'] : resolve_loan_days($a['BookID'])) . ' days'));
            run("INSERT INTO loan_detail (LoanID, CopyID, DueDate, BookCondition) VALUES (?, ?, ?, 'Good')", [$loanId, $a['CopyID'], $due]);
            run("UPDATE book_copy SET Status = 'Borrowed' WHERE CopyID = ?", [$a['CopyID']]);
        }
        return $loanId;
    });
}

/**
 * THE LIBRARIAN'S DECISION. $decisions: ItemID => ['do' => 'approve'|'reject', 'as' => 'In-Library'|'Borrow', 'reason' => text].
 * Items without a decision stay Pending. Everything in one transaction: if any approved book fails a rule
 * (unavailable, borrowing limit, unpaid fines…) NOTHING is changed and the librarian is told why.
 */
function use_request_decide(int $requestId, array $decisions, int $staffId, ?string $location = null): array
{
    return in_transaction(function () use ($requestId, $decisions, $staffId, $location) {
        $req = fetch_one("SELECT * FROM use_request WHERE UseRequestID = ? FOR UPDATE", [$requestId]);
        if (!$req || $req['Status'] !== 'Pending') throw new LibraryException('This request was already decided or cancelled.');
        $memberId = (int)$req['MemberID'];
        $items = fetch_all(
            "SELECT i.*, b.Title, bc.AccessionNumber FROM use_request_item i
               JOIN book_copy bc ON bc.CopyID = i.CopyID JOIN book b ON b.BookID = bc.BookID
              WHERE i.UseRequestID = ? AND i.Decision = 'Pending' ORDER BY i.ItemID FOR UPDATE", [$requestId]);

        $inl = []; $bor = []; $rej = [];
        foreach ($items as $it) {
            $d = $decisions[(int)$it['ItemID']] ?? null;
            if (!$d) continue;
            if (($d['do'] ?? '') === 'reject') {
                $why = trim((string)($d['reason'] ?? ''));
                if ($why === '') throw new LibraryException('Please give a reason for rejecting “' . $it['Title'] . '”.');
                $rej[] = [$it, mb_substr($why, 0, 255)];
            } elseif (($d['do'] ?? '') === 'approve') {
                $as = !IN_LIBRARY_USE_ENABLED ? 'Borrow' : (in_array($d['as'] ?? '', ['In-Library', 'Borrow'], true) ? $d['as'] : $it['RequestedUse']);
                if ($as === 'Borrow') $bor[] = $it; else $inl[] = $it;
            }
        }
        if (!$inl && !$bor && !$rej) throw new LibraryException('Choose Approve or Reject for at least one book.');

        if ($inl || $bor) {
            $m = fetch_one("SELECT UserID, MembershipStatus, VerificationStatus, IsActive FROM user_account WHERE UserID = ?", [$memberId]);
            if (!$m || ($why = kiosk_member_block($m))) throw new LibraryException($why ?? 'Student not found.');
        }
        $loanId = null; $useMap = [];
        if ($inl) {
            $res = start_in_library_session(array_column($inl, 'CopyID'), 'Member', $memberId, null, null, $staffId,
                        $location, 'Approved · ' . use_request_ref($requestId), 'Staff');
            foreach ($res['items'] as $r) $useMap[(int)$r['copy']] = (int)$r['id'];
        }
        if ($bor) $loanId = issue_desk_loan($memberId, array_map('intval', array_column($bor, 'CopyID')), $staffId);

        foreach ($inl as $it) run("UPDATE use_request_item SET Decision='Approved', ApprovedAs='In-Library', InLibraryUseID=? WHERE ItemID=?", [$useMap[(int)$it['CopyID']] ?? null, $it['ItemID']]);
        foreach ($bor as $it) run("UPDATE use_request_item SET Decision='Approved', ApprovedAs='Borrow', LoanID=? WHERE ItemID=?", [$loanId, $it['ItemID']]);
        foreach ($rej as [$it, $why]) run("UPDATE use_request_item SET Decision='Rejected', RejectReason=? WHERE ItemID=?", [$why, $it['ItemID']]);

        $left = (int) fetch_value("SELECT COUNT(*) FROM use_request_item WHERE UseRequestID = ? AND Decision = 'Pending'", [$requestId]);
        if ($left === 0) run("UPDATE use_request SET Status='Decided', DecidedByUserID=?, DecidedAt=NOW() WHERE UseRequestID=?", [$staffId, $requestId]);
        return ['in_library' => count($inl), 'borrowed' => count($bor), 'rejected' => count($rej), 'loan_id' => $loanId, 'left' => $left];
    });
}

/** The student withdraws their own PENDING request (kiosk). */
function use_request_cancel(int $requestId, int $memberId): void
{
    $n = run("UPDATE use_request SET Status = 'Cancelled' WHERE UseRequestID = ? AND MemberID = ? AND Status = 'Pending'", [$requestId, $memberId])->rowCount();
    if (!$n) throw new LibraryException('That request can no longer be cancelled.');
}


/**
 * Librarian desk form: the student is standing at the desk with the book(s) and has handed over their ID.
 * The librarian has already verified the student and each book on screen and chose Approve or Reject per book.
 * Records the whole thing in ONE transaction — the request (Source 'Desk'), each decision, and for approved
 * books the loan with its due date — so nothing is ever half-saved.
 *
 * $rows: [['copy_id' => int, 'do' => 'approve'|'reject', 'reason' => string], ...]
 * Returns the use_request_decide() summary plus 'request_id'.
 */
function use_request_desk_record(int $memberId, array $rows, int $staffId): array
{
    if (!$rows) throw new LibraryException('Add at least one book first.');
    if (count($rows) > (int)KIOSK_MAX_COPIES_PER_SESSION) {
        throw new LibraryException('Too many books in one form (maximum ' . (int)KIOSK_MAX_COPIES_PER_SESSION . ').');
    }
    return in_transaction(function () use ($memberId, $rows, $staffId) {
        $m = fetch_one(
            "SELECT ua.UserID, r.RoleName FROM user_account ua
               LEFT JOIN role r ON r.RoleID = ua.RoleID WHERE ua.UserID = ? FOR UPDATE", [$memberId]);
        if (!$m || strcasecmp((string)$m['RoleName'], 'Member') !== 0) throw new LibraryException('Student not found.');

        $entry = member_entry_today($memberId);
        run("INSERT INTO use_request (MemberID, EntryID, Source, Status, CreatedByUserID) VALUES (?, ?, 'Desk', 'Pending', ?)",
            [$memberId, $entry['EntryID'] ?? null, $staffId]);
        $reqId = (int) last_id();

        $decisions = []; $seen = [];
        foreach ($rows as $r) {
            $cid = (int)($r['copy_id'] ?? 0);
            if ($cid < 1 || isset($seen[$cid])) continue;
            $seen[$cid] = true;
            if (!(int) fetch_value("SELECT COUNT(*) FROM book_copy WHERE CopyID = ?", [$cid])) {
                throw new LibraryException('A book copy on the form could not be found. Please start the form again.');
            }
            run("INSERT INTO use_request_item (UseRequestID, CopyID, RequestedUse) VALUES (?, ?, 'Borrow')", [$reqId, $cid]);
            $itemId = (int) last_id();
            $decisions[$itemId] = ($r['do'] ?? '') === 'approve'
                ? ['do' => 'approve', 'as' => 'Borrow']
                : ['do' => 'reject', 'reason' => (string)($r['reason'] ?? '')];
        }
        if (!$decisions) throw new LibraryException('Add at least one book first.');

        $res = use_request_decide($reqId, $decisions, $staffId, null);
        $res['request_id'] = $reqId;
        return $res;
    });
}


/* Librarian desk form with ID custody */
require_once __DIR__ . '/desk_form.php';
