<?php
require_once __DIR__ . '/includes/member_auth.php';

$page_title = 'Browse Books';
$page_subtitle = 'Find a book and send a Borrow Request';
$member     = current_member();
$memberId   = current_member_id();

$borrowCheck   = member_borrow_check($memberId);
$limitSnapshot = check_borrowing_limit($memberId, 0);
$bs            = member_borrow_status($memberId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_request') {
    if (!csrf_check()) {
        flash('danger', 'Your session expired. Please try again.');
        redirect('member_catalog.php');
    }
    $bookId = (int)($_POST['book_id'] ?? 0);
    if (!$bookId) { flash('danger', 'No book selected.'); redirect('member_catalog.php'); }

    try {
        // The server decides the type: Borrow if a copy is on the shelf, otherwise Reservation.
        $r = create_borrow_request($memberId, $bookId);
        log_activity('Request', 'Borrow Requests',
            "Member submitted " . ($r['type'] === 'Reservation' ? 'a reservation' : 'a Borrow Request')
            . " for \"{$r['title']}\" (#{$r['id']})", $r['id']);

        if ($r['type'] === 'Reservation') {
            flash('success', "No copy of \"{$r['title']}\" is on the shelf right now, so you are #{$r['position']} in the reservation queue. "
                . 'You will be notified when a copy is held for you.', true);
        } else {
            flash('success', "Your Borrow Request for \"{$r['title']}\" is pending. Staff will review it — "
                . 'track it under My Borrow Requests.', true);
        }
    } catch (Throwable $e) {
        $why = user_message($e, 'Your request could not be submitted. Please try again.');
        if (stripos($why, 'Borrowing limit reached') === 0) {
            $why = 'You have reached your borrowing limit of ' . (int)$bs['max'] . ' book' . ((int)$bs['max'] === 1 ? '' : 's')
                 . '. Please return a borrowed book before requesting another one.';
        }
        flash('danger', 'Request not submitted: ' . $why, true);
    }
    redirect('member_catalog.php');
}

/* Several books in one go. Each book still becomes its OWN request (own status, own decision). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_requests') {
    if (!csrf_check()) {
        flash('danger', 'Your session expired. Please try again.');
        redirect('member_catalog.php');
    }
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($_POST['book_ids'] ?? []))))), 0, 20);
    if (!$ids) { flash('danger', 'Tick at least one book first.'); redirect('member_catalog.php'); }

    $sent = []; $queued = []; $failed = [];
    foreach ($ids as $bookId) {
        try {
            $r = create_borrow_request($memberId, $bookId);
            log_activity('Request', 'Borrow Requests',
                "Member submitted " . ($r['type'] === 'Reservation' ? 'a reservation' : 'a Borrow Request')
                . " for \"{$r['title']}\" (#{$r['id']})", $r['id']);
            if ($r['type'] === 'Reservation') $queued[] = $r['title'] . ' (queue #' . $r['position'] . ')';
            else $sent[] = $r['title'];
        } catch (Throwable $e) {
            $t = (string) fetch_value("SELECT Title FROM book WHERE BookID = ?", [$bookId]);
            $failed[] = ($t !== '' ? "\"$t\": " : '') . user_message($e, 'could not be requested');
        }
    }
    $msg = [];
    if ($sent)   $msg[] = count($sent) . ' Borrow Request(s) sent: ' . implode(', ', $sent) . '.';
    if ($queued) $msg[] = count($queued) . ' reservation(s) queued: ' . implode(', ', $queued) . '.';
    if ($failed) $msg[] = 'Not sent — ' . implode(' ', $failed);
    $msg[] = 'Staff decide each book separately; track them under My Borrow Requests.';
    flash(($sent || $queued) ? ($failed ? 'warning' : 'success') : 'danger', implode(' ', $msg), true);
    redirect('member_catalog.php');
}

/* ---------------------------------------------------------------------
 * Catalog data. Every non-archived book is loaded once and passed to the page as JSON;
 * search, filters, sorting and paging then update instantly in the browser
 * (assets/js/member_catalog.js). Borrow Requests still go through the server handlers above.
 * ------------------------------------------------------------------- */
$rows = fetch_all(
    "SELECT b.BookID, b.Title, b.ISBN, b.PublicationYear,
            b.CategoryID, b.SectionID,
            c.CategoryName, sec.SectionName, p.PublisherName,
            pol.IsBorrowable, pol.LoanDays,
            (SELECT GROUP_CONCAT(CONCAT(a.FirstName, ' ', a.LastName) ORDER BY a.LastName, a.FirstName SEPARATOR '|')
               FROM book_author ba JOIN author a ON a.AuthorID = ba.AuthorID
              WHERE ba.BookID = b.BookID) AS Authors,
            " . sql_available_copies('b') . " AS AvailableCopies,
            " . sql_total_copies('b')     . " AS TotalCopies,
            (SELECT COUNT(*) FROM loan_detail ld
               JOIN book_copy bcl ON bcl.CopyID = ld.CopyID
               LEFT JOIN return_detail rdl ON rdl.LoanDetailID = ld.LoanDetailID
              WHERE bcl.BookID = b.BookID AND rdl.ReturnDetailID IS NULL) AS OnLoanCopies,
            (SELECT COUNT(*) FROM loan_detail ldo
               JOIN book_copy bco ON bco.CopyID = ldo.CopyID
               LEFT JOIN return_detail rdo ON rdo.LoanDetailID = ldo.LoanDetailID
              WHERE bco.BookID = b.BookID AND rdo.ReturnDetailID IS NULL
                AND ldo.DueDate < CURDATE()) AS OverdueCopies
       FROM book b
       JOIN category  c   ON c.CategoryID   = b.CategoryID
       JOIN section   sec ON sec.SectionID  = b.SectionID
       JOIN publisher p   ON p.PublisherID  = b.PublisherID
       JOIN policy    pol ON pol.PolicyID   = b.PolicyID
      WHERE b.IsArchived = FALSE
      ORDER BY b.Title
      LIMIT 2000"
);

/* Books this member already holds or has an open request for (item: block duplicate requests). */
$mineRequested = array_column(fetch_all(
    "SELECT DISTINCT d.BookID FROM borrow_request r JOIN borrow_request_detail d ON d.RequestID = r.RequestID
      WHERE r.UserID = ? AND r.ApprovalStatus IN ('Pending','Approved')", [$memberId]), 'BookID');
$mineHolding = array_column(fetch_all(
    "SELECT DISTINCT bc.BookID FROM loan_detail ld
       JOIN loan l ON l.LoanID = ld.LoanID JOIN book_copy bc ON bc.CopyID = ld.CopyID
       LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
      WHERE l.BorrowerUserID = ? AND rd.ReturnDetailID IS NULL", [$memberId]), 'BookID');

$catalog = [];
foreach ($rows as $r) {
    $catalog[] = [
        'id'         => (int)$r['BookID'],
        'title'      => (string)$r['Title'],
        'authors'    => $r['Authors'] !== null && $r['Authors'] !== '' ? explode('|', $r['Authors']) : [],
        'publisher'  => (string)$r['PublisherName'],
        'year'       => $r['PublicationYear'] ? (string)$r['PublicationYear'] : '',
        'isbn'       => (string)($r['ISBN'] ?? ''),
        'categoryId' => (string)$r['CategoryID'],
        'category'   => (string)$r['CategoryName'],
        'sectionId'  => (string)$r['SectionID'],
        'section'    => (string)$r['SectionName'],
        'borrowable' => (int)$r['IsBorrowable'] === 1,
        'loanDays'   => (int)$r['LoanDays'],
        'avail'      => max(0, (int)$r['AvailableCopies'] - borrow_keep_copies()),   // copies that may still leave the library
        'shelf'      => (int)$r['AvailableCopies'],
        'total'      => (int)$r['TotalCopies'],
        'onLoan'     => (int)$r['OnLoanCopies'],
        'overdue'    => (int)$r['OverdueCopies'],
        'mine'       => in_array($r['BookID'], $mineHolding) ? 'holding' : (in_array($r['BookID'], $mineRequested) ? 'requested' : ''),
    ];
}

$catalogConfig = [
    'books'      => $catalog,
    'canRequest' => (bool)$borrowCheck['ok'],
    'blockedWhy' => (string)($borrowCheck['reason'] ?? ''),
    'slots'      => $bs['slots'],
    'status'     => $bs,
    'member'     => [
        'name'  => trim(($member['FirstName'] ?? '') . ' ' . ($member['LastName'] ?? '')),
        'id'    => (string)($member['UserID'] ?? ''),
        'type'  => (string)($limitSnapshot['borrower_type'] ?? 'Member'),
        'held'  => (int)$limitSnapshot['current_books'],
        'max'   => (int)$limitSnapshot['max_books'],
    ],
];

/* Search box + filter button live in the shared top bar (see includes/member_layout_top.php). */
ob_start(); ?>
<div class="topbar-search">
  <div class="topbar-search-field">
    <i class="bi bi-search" aria-hidden="true"></i>
    <input type="search" id="catSearch" class="form-control" autocomplete="off"
           placeholder="Search by Title, Author, ISBN, or Publisher..."
           aria-label="Search books by title, author, ISBN or publisher">
  </div>
  <button type="button" class="icon-btn" id="filterToggle" aria-label="Show or hide filters"
          aria-controls="catFilters" title="Filters">
    <i class="bi bi-sliders"></i>
  </button>
</div>
<?php
$topbar_center = ob_get_clean();

require __DIR__ . '/includes/member_layout_top.php';
?>

<div class="catalog-layout" id="catalogLayout">

  <!-- ===== Filter sidebar (drawer on mobile) ===== -->
  <aside class="cat-filters" id="catFilters" aria-label="Filter your results">
    <div class="cat-filters-head">
      <h2>Filter Your Results</h2>
      <button type="button" class="btn-close d-lg-none" id="filterClose" aria-label="Close filters"></button>
    </div>

    <div class="cat-filters-body">
      <section class="cf-group" data-group="status">
        <button type="button" class="cf-toggle" aria-expanded="true"><span>By Status</span><i class="bi bi-chevron-down"></i></button>
        <div class="cf-options" id="facet-status"></div>
      </section>
      <section class="cf-group" data-group="section">
        <button type="button" class="cf-toggle" aria-expanded="true"><span>By Section</span><i class="bi bi-chevron-down"></i></button>
        <div class="cf-options" id="facet-section"></div>
      </section>
      <section class="cf-group" data-group="category">
        <button type="button" class="cf-toggle" aria-expanded="true"><span>By Category</span><i class="bi bi-chevron-down"></i></button>
        <div class="cf-options" id="facet-category"></div>
      </section>
      <section class="cf-group" data-group="author">
        <button type="button" class="cf-toggle" aria-expanded="true"><span>By Author (Search)</span><i class="bi bi-chevron-down"></i></button>
        <div class="cf-options">
          <input type="search" class="form-control form-control-sm mb-2" id="authorSearch" placeholder="Search" aria-label="Search authors" autocomplete="off">
          <div id="facet-author"></div>
        </div>
      </section>
    </div>

    <div class="cat-filters-foot">
      <button type="button" class="btn btn-outline-secondary btn-sm" id="resetFilters"><i class="bi bi-arrow-counterclockwise"></i> Reset Filters</button>
      <div class="btn-group btn-group-sm" role="group" aria-label="Catalog view">
        <button type="button" class="btn btn-outline-secondary" data-view="grid" title="Grid view" aria-label="Grid view" aria-pressed="true"><i class="bi bi-grid-fill"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-view="list" title="List view" aria-label="List view" aria-pressed="false"><i class="bi bi-list-ul"></i></button>
      </div>
    </div>
  </aside>
  <div class="cat-backdrop" id="filterBackdrop" hidden></div>

  <!-- ===== Catalog ===== -->
  <section class="cat-main" aria-label="Book catalog">
<?php if ($bs['enabled']): ?>
    <div class="borrow-summary" id="borrowSummary" data-state="<?= h($bs['state']) ?>" aria-label="My Borrowing Status">
      <div class="bsum-title"><i class="bi bi-person-check-fill"></i> My Borrowing Status</div>
      <div class="bsum-stat"><span class="bsum-label">Borrowed Books</span><strong><span id="bsBorrowed"><?= h($bs['borrowed']) ?></span> / <span id="bsMax"><?= h($bs['max']) ?></span></strong></div>
      <div class="bsum-stat"><span class="bsum-label">Remaining Borrowing Slots</span><strong id="bsSlots"><?= h($bs['slots']) ?></strong></div>
      <div class="bsum-stat bsum-pending" id="bsPendingWrap" <?= $bs['pending'] > 0 ? '' : 'hidden' ?>><span class="bsum-label">Pending Requests</span><strong id="bsPending"><?= h($bs['pending']) ?></strong></div>
      <div class="bsum-bar-wrap">
        <div class="bsum-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= h($bs['max']) ?>" aria-valuenow="<?= h($bs['borrowed']) ?>" aria-label="Books borrowed">
          <span class="bsum-fill" id="bsFill" style="width:<?= h(min(100, round($bs['borrowed'] / $bs['max'] * 100))) ?>%"></span><span class="bsum-fill-pending" id="bsFillPending" style="width:<?= h(min(100 - min(100, round($bs['borrowed'] / $bs['max'] * 100)), round($bs['pending'] / $bs['max'] * 100))) ?>%"></span>
        </div>
        <div class="bsum-msg"><span class="bsum-status-label">Status:</span> <span id="bsMessage"><?= h($bs['message']) ?></span></div>
      </div>
    </div>

    <div class="alert alert-warning alert-dismissible d-flex gap-2 align-items-start" id="limitWarning" role="status" <?= $bs['state'] === 'warn' ? '' : 'hidden' ?>>
      <i class="bi bi-exclamation-triangle-fill mt-1"></i>
      <div><strong id="lwTitle"><?= h($bs['warn_title']) ?></strong><br><span class="small" id="lwMsg"><?= h($bs['warn_message']) ?></span></div>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
    </div>
<?php endif; ?>
    <div class="cat-toolbar">
      <div class="cat-count" id="resultCount" aria-live="polite">Loading…</div>
      <div class="cat-sort">
        <label for="sortBy" class="small text-muted">Sort by:</label>
        <select id="sortBy" class="form-select form-select-sm">
          <option value="relevant">Relevant</option>
          <option value="title">Title A-Z</option>
          <option value="author">Author A-Z</option>
          <option value="publisher">Publisher Z-A</option>
        </select>
      </div>
    </div>

    <div id="bookGrid" class="book-grid" role="list"></div>

    <div id="catEmpty" class="cat-empty" hidden>
      <i class="bi bi-journal-x"></i>
      <h3>No books match your search</h3>
      <p class="text-muted mb-3">Try different keywords or clear some filters.</p>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-reset>Reset Filters</button>
    </div>

    <nav id="catPager" class="cat-pager" aria-label="Catalog pages" hidden></nav>

    <p class="small text-muted mt-3 mb-0">
      <strong>Request to Borrow</strong> sends a Borrow Request for staff to review; if approved, a copy is held for you to collect.
      <strong>Reserve</strong> puts you in the queue for a book with no copy on the shelf. Tick several books to request them together —
      each book is still reviewed on its own.
    </p>
  </section>
</div>

<script type="application/json" id="catalog-data"><?= json_encode($catalogConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>

<?php if ($borrowCheck['ok']): ?>
<!-- ===== Borrowing limit reached ===== -->
<div class="modal fade" id="limitModal" tabindex="-1" aria-labelledby="limitModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body text-center p-4">
        <div class="limit-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <h2 class="fs-4 fw-bold mb-2" id="limitModalTitle">Borrowing Limit Reached!</h2>
        <p class="mb-3" id="limMsg"></p>
        <div class="fw-semibold small mb-1" id="limCount"></div>
        <div class="bsum-bar mx-auto mb-4" style="max-width:320px" role="progressbar" id="limBar" aria-valuemin="0" aria-label="Borrowing limit usage">
          <span class="bsum-fill" id="limFill" style="width:100%"></span>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-center">
          <a href="member_loans.php" class="btn btn-primary"><i class="bi bi-journal-bookmark"></i> View My Borrowed Books</a>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===== Borrow request confirmation dialog ===== -->
<div class="modal fade" id="requestModal" tabindex="-1" aria-labelledby="requestModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content" id="requestForm" data-loading>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_request">
      <input type="hidden" name="book_id" id="reqBookId" value="">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="requestModalTitle">Request to Borrow</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="req-book">
          <div class="bk-cover" id="reqCover" aria-hidden="true"></div>
          <div class="min-w-0">
            <div class="fw-bold" id="reqTitle"></div>
            <div class="small" id="reqAuthor"></div>
            <div class="small text-muted" id="reqMeta"></div>
            <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
              <span id="reqBadge"></span>
              <span class="badge text-bg-light border" id="reqCopies"></span>
            </div>
          </div>
        </div>
        <hr>
        <div class="small">
          <div class="fw-semibold mb-1">Requesting as</div>
          <div class="req-member">
            <span class="avatar"><?= h($initials ?? '') ?></span>
            <div>
              <div class="fw-semibold" id="reqMemberName"></div>
              <div class="text-muted" id="reqMemberMeta"></div>
            </div>
          </div>
        </div>
        <p class="small text-muted mt-3 mb-0" id="reqNote"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="reqConfirm"><i class="bi bi-bookmark-check"></i> Confirm Request</button>
      </div>
    </form>
  </div>
</div>

<form method="post" id="multiForm" class="pick-bar" data-loading hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="submit_requests">
  <div class="pick-bar-text">
    <strong><span id="pickCount">0</span> book(s) selected</strong>
    <span class="small text-muted d-block" id="pickNote"></span>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-secondary" id="pickClear">Clear</button>
    <button class="btn btn-primary" id="pickSend"><i class="bi bi-bookmark-check"></i> Request <span id="pickCount2">0</span> book(s)</button>
  </div>
</form>
<?php endif; ?>

<?php /* polled for live updates */ ?>
<script src="assets/js/member_catalog.js?v=<?= h(@filemtime(__DIR__ . '/assets/js/member_catalog.js') ?: time()) ?>" defer></script>

<?php require __DIR__ . '/includes/member_layout_bottom.php'; ?>
