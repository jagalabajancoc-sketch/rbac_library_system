<?php
require_once __DIR__ . '/includes/member_auth.php';
require_once __DIR__ . '/includes/catalog.php';
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try { echo json_encode(catalog_page($_GET, (int)current_member_id()), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR); }
    catch (Throwable $e) {
        error_log('Catalog lookup failed: '.$e->getMessage());
        http_response_code(503);
        echo json_encode(['error'=>'The catalog could not be loaded. Please try again.']);
    }
    exit;
}


$page_title = 'Browse Books';
$page_subtitle = 'Find a book and send a Borrow Request';
$member     = current_member();
$memberId   = current_member_id();

$borrowCheck   = member_borrow_check($memberId);
$limitSnapshot = check_borrowing_limit($memberId, 0);
$bs            = member_borrow_status($memberId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    try {
        $action=(string)($_POST['action']??'');
        if (!in_array($action,['submit_request','submit_requests'],true)) throw new LibraryException('Unknown request action.');
        $ids=$action==='submit_request'?[(int)($_POST['book_id']??0)]:(array)($_POST['book_ids']??[]);
        $token=(string)($_POST['submission_token']??'');
        if (!$token) throw new LibraryException('Refresh the catalog before submitting a request.');
        $result=create_request_transaction($memberId,$ids,$token);
        $ref=request_transaction_ref($result['transaction_id']);
        log_activity('Request','Borrow Requests',"Submitted $ref with ".count($result['items']).' book(s)',$result['transaction_id']);
        flash('success',"$ref saved with ".count($result['items']).' book(s). Track decisions and reservation positions under My Borrow Requests.',true);
        redirect('member_requests.php?q='.urlencode($ref));
    } catch (Throwable $e) { flash('danger',user_message($e,'The request could not be saved. No books were submitted.'),true); }
    redirect('member_catalog.php');
}
$requestToken=bin2hex(random_bytes(32));

$catalogConfig = [
    'endpoint'   => 'member_catalog.php?api=catalog',
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

    <div id="catError" class="alert alert-danger" role="alert" hidden>
      <span id="catErrorMessage">The catalog could not be loaded.</span>
      <button type="button" class="btn btn-sm btn-outline-danger ms-2" id="catRetry">Try again</button>
    </div>
    <noscript><div class="alert alert-info">Enable JavaScript to search and request books, or ask the librarian for help.</div></noscript>
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
      they are submitted together as one transaction, with a status for each book.
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
          <a href="my_borrowed_books.php" class="btn btn-primary"><i class="bi bi-journal-bookmark"></i> View My Borrowed Books</a>
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
      <?= csrf_field() ?><input type="hidden" name="submission_token" value="<?= h($requestToken) ?>">
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
  <?= csrf_field() ?><input type="hidden" name="submission_token" value="<?= h($requestToken) ?>">
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
