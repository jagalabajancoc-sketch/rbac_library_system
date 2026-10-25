<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Books';

/* Archive view flag */
$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
if ($view === 'archived') {
    require_permission('view_archived_records');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    /* ---------------------------------------------------------------
       ARCHIVE / RESTORE — Admin only
       ------------------------------------------------------------- */
    if ($action === 'archive' || $action === 'restore') {
        require_permission('archive_records');
        $id = $_POST['book_id'] ?? '';
        if (!$id) { flash('danger', 'Missing book reference.'); redirect('books.php'); }
        $book = fetch_one("SELECT BookID, Title, IsArchived FROM book WHERE BookID = ?", [$id]);
        if (!$book) { flash('danger', 'Book not found.'); redirect('books.php'); }
        $newValue = $action === 'archive' ? 1 : 0;
        run("UPDATE book SET IsArchived = ? WHERE BookID = ?", [$newValue, $book['BookID']]);

        log_activity(
            $action === 'archive' ? 'Archive' : 'Restore',
            'Books',
            ($action === 'archive' ? 'Archived' : 'Restored') . ' book "' . $book['Title'] . '"',
            $book['BookID']
        );
        flash('success', $action === 'archive'
            ? 'Book "' . $book['Title'] . '" archived.'
            : 'Book "' . $book['Title'] . '" restored.');
        redirect('books.php?view=' . ($action === 'archive' ? 'archived' : 'active'));
    }

    require_permission('manage_books');

    if ($action === 'save') {
        $id          = $_POST['book_id'] ?? '';
        $isbn        = trim($_POST['isbn'] ?? '');
        $callNumber  = trim($_POST['call_number'] ?? '');
        $edition     = trim($_POST['edition'] ?? '');
        $language    = trim($_POST['language'] ?? '');
        $pagesRaw    = trim((string)($_POST['pages'] ?? ''));
        $description = trim($_POST['description'] ?? '');
        $title       = trim($_POST['title'] ?? '');
        $year        = $_POST['publication_year'] !== '' ? (int)$_POST['publication_year'] : null;
        $publisherId = $_POST['publisher_id'] ?? '';
        $sectionId   = $_POST['section_id'] ?? '';
        $categoryId  = $_POST['category_id'] ?? '';
        $policyId    = $_POST['policy_id'] ?? '';
        $authorIds   = $_POST['author_ids'] ?? [];
        $acqDate     = $_POST['acquisition_date'] !== '' ? $_POST['acquisition_date'] : null;
        $donorOption = $_POST['donor_option'] ?? 'none';
        $donorId     = ($donorOption === 'existing' && !empty($_POST['donor_id'])) ? $_POST['donor_id'] : null;

        $pages = null;
        if ($pagesRaw !== '') {
            if (!ctype_digit($pagesRaw) || (int)$pagesRaw < 1 || (int)$pagesRaw > 100000) {
                flash('danger', 'Pages must be a positive whole number (or leave blank).');
                redirect('books.php');
            }
            $pages = (int)$pagesRaw;
        }

        if ($edition !== '' && !in_array($edition, EDITION_OPTIONS, true)) {
            flash('danger', 'Invalid edition selection.', true);
            redirect('books.php');
        }
        if ($language !== '' && !in_array($language, LANGUAGE_OPTIONS, true)) {
            flash('danger', 'Invalid language selection.', true);
            redirect('books.php');
        }

        if ($donorId) {
            $acquisitionType = 'Donated';
            $acquisitionCost = 0.00;
        } else {
            $acquisitionType = 'Purchased';
            $acquisitionCost = $_POST['acquisition_cost'] !== '' ? (float)$_POST['acquisition_cost'] : 0.00;
        }

        $numCopies = null;
        if (!$id) {
            $raw = trim((string)($_POST['num_copies'] ?? ''));
            if ($raw === '' || !ctype_digit($raw)) {
                flash('danger', 'Number of copies must be a positive whole number.');
                redirect('books.php');
            }
            $numCopies = (int)$raw;
            if ($numCopies < 1) { flash('danger', 'Number of copies must be at least 1.'); redirect('books.php'); }
            if ($numCopies > COPY_BATCH_LIMIT) {
                flash('danger', 'Number of copies cannot exceed ' . COPY_BATCH_LIMIT . ' in a single batch.');
                redirect('books.php');
            }
        }

        if ($title === '' || !$publisherId || !$sectionId || !$categoryId || !$policyId) {
            flash('danger', 'Title, publisher, section, category, and policy are required.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($id) {
                    run("UPDATE book SET ISBN=?, CallNumber=?, Edition=?, Language=?, Pages=?, Description=?, Title=?, PublicationYear=?, PublisherID=?, SectionID=?, CategoryID=?, PolicyID=?, AcquisitionCost=?, AcquisitionType=?, AcquisitionDate=?, DonorID=? WHERE BookID=?",
                        [$isbn, $callNumber ?: null, $edition ?: null, $language ?: null, $pages, $description ?: null,
                         $title, $year, $publisherId, $sectionId, $categoryId, $policyId,
                         $acquisitionCost, $acquisitionType, $acqDate, $donorId, $id]);
                    $bookId = $id;
                    run("DELETE FROM book_author WHERE BookID = ?", [$bookId]);
                    foreach ($authorIds as $aid) {
                        if ($aid !== '') {
                            run("INSERT INTO book_author (BookID, AuthorID) VALUES (?, ?)", [$bookId, $aid]);
                        }
                    }
                    $pdo->commit();

                    log_activity('Update', 'Books', 'Updated book "' . $title . '"', $bookId);
                    flash('success', 'Book updated.');
                } else {
                    run("INSERT INTO book (ISBN, CallNumber, Edition, Language, Pages, Description, Title, PublicationYear, PublisherID, SectionID, CategoryID, PolicyID, AcquisitionCost, AcquisitionType, AcquisitionDate, DonorID) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                        [$isbn, $callNumber ?: null, $edition ?: null, $language ?: null, $pages, $description ?: null,
                         $title, $year, $publisherId, $sectionId, $categoryId, $policyId,
                         $acquisitionCost, $acquisitionType, $acqDate, $donorId]);
                    $bookId = (int)last_id();

                    foreach ($authorIds as $aid) {
                        if ($aid !== '') {
                            run("INSERT INTO book_author (BookID, AuthorID) VALUES (?, ?)", [$bookId, $aid]);
                        }
                    }

                    $numbers = create_book_copies($bookId, $numCopies);
                    $pdo->commit();

                    log_activity('Create', 'Books',
                        'Added book "' . $title . '" with ' . $numCopies . ' cop' . ($numCopies === 1 ? 'y' : 'ies'),
                        $bookId);
                    flash('success', sprintf(
                        'Book added successfully with %d physical copies: %s',
                        $numCopies,
                        implode(', ', $numbers)
                    ));
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                flash('danger', 'Could not save book: ' . $e->getMessage());
            }
        }
        redirect('books.php');
    }

    if ($action === 'add_copies') {
        $bookId = $_POST['book_id'] ?? '';
        $raw    = trim((string)($_POST['num_copies'] ?? ''));

        if ($raw === '' || !ctype_digit($raw)) {
            flash('danger', 'Number of copies must be a positive whole number.');
            redirect('books.php');
        }
        $count = (int)$raw;
        if ($count < 1) { flash('danger', 'Number of copies must be at least 1.'); redirect('books.php'); }
        if ($count > COPY_BATCH_LIMIT) {
            flash('danger', 'Number of copies cannot exceed ' . COPY_BATCH_LIMIT . ' per batch.');
            redirect('books.php');
        }

        $book = fetch_one("SELECT BookID, Title, IsArchived FROM book WHERE BookID = ?", [$bookId]);
        if (!$book) { flash('danger', 'Book not found.'); redirect('books.php'); }
        if (!empty($book['IsArchived'])) {
            flash('danger', 'Cannot add copies to an archived book. Restore it first.');
            redirect('books.php?view=archived');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $numbers = create_book_copies((int)$bookId, $count);
            $pdo->commit();

            log_activity('Create', 'Book Copies',
                'Added ' . $count . ' copies to "' . $book['Title'] . '": ' . implode(', ', $numbers),
                (int)$bookId);
            flash('success', sprintf(
                'Added %d copies to "%s": %s',
                $count,
                $book['Title'],
                implode(', ', $numbers)
            ));
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('danger', 'Could not add copies: ' . $e->getMessage());
        }
        redirect('books.php');
    }
}

/* ---------------------------------------------------------------
   LIST QUERY — search now also matches CallNumber
   ------------------------------------------------------------- */
$search = trim($_GET['q'] ?? '');
$where  = [];
$params = [];

$where[] = $view === 'archived' ? 'b.IsArchived = 1' : 'b.IsArchived = 0';

if ($search !== '') {
    $where[] = "(b.Title LIKE ? OR b.ISBN LIKE ? OR b.CallNumber LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalBooks = (int) fetch_value("SELECT COUNT(*) FROM book b $whereSql", $params);
$pg = pagination_calc($totalBooks, PER_PAGE_DEFAULT, pagination_page());

$books = fetch_all(
    "SELECT b.*, p.PublisherName, s.SectionName, c.CategoryName, pol.PolicyName,
            pol.LoanDays AS PolicyLoanDays, dn.DonorName,
            (SELECT COUNT(*) FROM book_copy bc WHERE bc.BookID = b.BookID AND bc.IsArchived = 0) AS CopyCount,
            (SELECT COUNT(*) FROM book_copy bc WHERE bc.BookID = b.BookID AND bc.Status = 'Available' AND bc.IsArchived = 0) AS AvailableCount,
            (SELECT COUNT(*) FROM book_copy bc WHERE bc.BookID = b.BookID AND bc.Status = 'Borrowed' AND bc.IsArchived = 0) AS BorrowedCount,
            (SELECT GROUP_CONCAT(CONCAT(a.FirstName,' ',a.LastName) SEPARATOR ', ')
               FROM book_author ba JOIN author a ON a.AuthorID = ba.AuthorID WHERE ba.BookID = b.BookID) AS Authors
     FROM book b
     LEFT JOIN publisher p ON p.PublisherID = b.PublisherID
     LEFT JOIN section s ON s.SectionID = b.SectionID
     LEFT JOIN category c ON c.CategoryID = b.CategoryID
     LEFT JOIN policy pol ON pol.PolicyID = b.PolicyID
     LEFT JOIN donor dn ON dn.DonorID = b.DonorID
     $whereSql
     ORDER BY b.Title
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$activeCount   = (int) fetch_value("SELECT COUNT(*) FROM book WHERE IsArchived = 0");
$archivedCount = (int) fetch_value("SELECT COUNT(*) FROM book WHERE IsArchived = 1");

$publishers = lookup_options('publisher', 'PublisherID', 'PublisherName');
$sections   = lookup_options('section', 'SectionID', 'SectionName');
$categories = lookup_options('category', 'CategoryID', 'CategoryName');
$policies   = lookup_options('policy', 'PolicyID', 'PolicyName');
$donors     = lookup_options('donor', 'DonorID', 'DonorName');
$authors    = fetch_all("SELECT AuthorID AS id, CONCAT(FirstName, ' ', LastName) AS label FROM author ORDER BY LastName");

$bookAuthors = [];
foreach (fetch_all("SELECT BookID, AuthorID FROM book_author") as $ba) {
    $bookAuthors[$ba['BookID']][] = $ba['AuthorID'];
}
foreach ($books as &$b) {
    $b['AuthorIds'] = $bookAuthors[$b['BookID']] ?? [];
}
unset($b);

/* ---------------------------------------------------------------
   DETAIL MODAL — when ?view_book=X is present
   ------------------------------------------------------------- */
$viewBookId = $_GET['view_book'] ?? null;
$viewBook   = null;
$viewCopies = [];

if ($viewBookId) {
    $viewBook = fetch_one(
        "SELECT b.*, p.PublisherName, s.SectionName, c.CategoryName,
                pol.PolicyName, pol.LoanDays AS PolicyLoanDays, pol.IsBorrowable,
                dn.DonorName, dn.DonorType, dn.ContactInfo,
                (SELECT GROUP_CONCAT(CONCAT(a.FirstName,' ',a.LastName) SEPARATOR ', ')
                   FROM book_author ba JOIN author a ON a.AuthorID = ba.AuthorID
                  WHERE ba.BookID = b.BookID) AS Authors
           FROM book b
           LEFT JOIN publisher p ON p.PublisherID = b.PublisherID
           LEFT JOIN section s ON s.SectionID = b.SectionID
           LEFT JOIN category c ON c.CategoryID = b.CategoryID
           LEFT JOIN policy pol ON pol.PolicyID = b.PolicyID
           LEFT JOIN donor dn ON dn.DonorID = b.DonorID
          WHERE b.BookID = ?",
        [$viewBookId]
    );

    if (!$viewBook) {
        flash('danger', 'Book not found.');
        redirect('books.php');
    }

    $viewCopies = fetch_all(
        "SELECT bc.CopyID, bc.AccessionNumber, bc.Status, bc.IsArchived,
                bc.ShelfID, sh.ShelfCode
           FROM book_copy bc
           LEFT JOIN shelf sh ON sh.ShelfID = bc.ShelfID
          WHERE bc.BookID = ?
          ORDER BY (bc.Status <> 'Available'), bc.AccessionNumber",
        [$viewBookId]
    );

    $closeQuery = $_GET;
    unset($closeQuery['view_book']);
}

require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $view === 'active' ? 'active' : '' ?>" href="books.php?view=active">
      <i class="bi bi-book"></i> Active Books
      <span class="badge text-bg-light border ms-1"><?= h($activeCount) ?></span>
    </a>
  </li>
  <?php if (has_permission('view_archived_records')): ?>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'archived' ? 'active' : '' ?>" href="books.php?view=archived">
        <i class="bi bi-archive"></i> Archived Books
        <span class="badge text-bg-light border ms-1"><?= h($archivedCount) ?></span>
      </a>
    </li>
  <?php endif; ?>
</ul>

<?php if ($view === 'archived'): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="bi bi-archive-fill mt-1"></i>
    <div class="small">
      <strong>You are viewing archived books.</strong>
      Archived titles are hidden from members, from the borrowing catalog, and from the loan/request pickers.
      <?php if (has_permission('archive_records')): ?>
        Use <strong>Restore</strong> to return a book to the active catalog.
      <?php else: ?>
        Only Admin can restore.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="page-toolbar">
  <form class="d-flex" method="get">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <input type="search" name="q" class="form-control" placeholder="Search by title, ISBN, or call number..." value="<?= h($search) ?>">
    <button class="btn btn-outline-secondary ms-2"><i class="bi bi-search"></i></button>
  </form>
  <?php if (has_permission('manage_books') && $view === 'active'): ?>
    <button class="btn btn-primary" onclick="openModal()"><i class="bi bi-plus-lg"></i> Add Book</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted">
    <?= pagination_info($pg) ?>
    <?php if ($search !== ''): ?> · search: <strong><?= h($search) ?></strong><?php endif; ?>
    <span class="ms-2 text-muted">· click a book title to see full details and its copies</span>
  </div>

  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Title</th>
          <th>Author(s)</th>
          <th>Category</th>
          <th class="text-center">Copies</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$books): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">
            <?= $view === 'archived' ? 'No archived books.' : 'No books found.' ?>
          </td></tr>
        <?php endif; ?>
        <?php foreach ($books as $b): ?>
          <?php
            $isArchived = !empty($b['IsArchived']);
            $detailQuery = array_merge($_GET, ['view_book' => $b['BookID']]);
          ?>
          <tr class="<?= $isArchived ? 'text-muted' : '' ?>">
            <td>
              <a href="?<?= h(http_build_query($detailQuery)) ?>" class="text-decoration-none fw-semibold">
                <?= h($b['Title']) ?>
              </a>
              <?php if ($isArchived): ?>
                <span class="badge text-bg-secondary ms-1"><i class="bi bi-archive"></i> Archived</span>
              <?php endif; ?>
              <div class="small text-muted">
                <?= $b['CallNumber'] ? '<span class="text-body">' . h($b['CallNumber']) . '</span> · ' : '' ?>
                <?= h($b['PublisherName']) ?><?= $b['PublicationYear'] ? ' · ' . h($b['PublicationYear']) : '' ?>
                <?= $b['ISBN'] ? ' · ISBN ' . h($b['ISBN']) : '' ?>
                <?= $b['Edition'] ? ' · ' . h($b['Edition']) : '' ?>
              </div>
            </td>
            <td class="small"><?= h($b['Authors'] ?: '—') ?></td>
            <td class="small"><?= h($b['CategoryName']) ?></td>
            <td class="text-center">
              <span class="badge text-bg-success"><?= h($b['AvailableCount']) ?> avail</span>
              <?php if ((int)$b['BorrowedCount'] > 0): ?>
                <span class="badge text-bg-warning"><?= h($b['BorrowedCount']) ?> out</span>
              <?php endif; ?>
              <div class="small text-muted"><?= h($b['CopyCount']) ?> total</div>
            </td>
            <td class="text-end text-nowrap">
              <a href="?<?= h(http_build_query($detailQuery)) ?>"
                 class="btn btn-sm btn-outline-secondary"
                 title="View details" data-bs-toggle="tooltip">
                <i class="bi bi-eye"></i>
              </a>

              <?php if (has_permission('manage_books') && !$isArchived): ?>
                <button class="btn btn-sm btn-outline-success"
                        title="Add Copies" data-bs-toggle="tooltip"
                        onclick='openAddCopiesModal(<?= json_encode(["BookID" => $b["BookID"], "Title" => $b["Title"], "CopyCount" => (int)$b["CopyCount"]]) ?>)'>
                  <i class="bi bi-plus-square"></i>
                </button>
                <button class="btn btn-sm btn-outline-primary"
                        title="Edit Book" data-bs-toggle="tooltip"
                        onclick='openModal(<?= json_encode($b) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
              <?php endif; ?>

              <?php if (has_permission('archive_records')): ?>
                <?php if ($isArchived): ?>
                  <form method="post" class="d-inline" data-confirm="Restore this book to the active catalog?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="book_id" value="<?= h($b['BookID']) ?>">
                    <button class="btn btn-sm btn-outline-success" title="Restore" data-bs-toggle="tooltip">
                      <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <form method="post" class="d-inline" data-confirm="Archive this book? It will be hidden from members and the borrowing catalog.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="book_id" value="<?= h($b['BookID']) ?>">
                    <button class="btn btn-sm btn-outline-warning" title="Archive" data-bs-toggle="tooltip">
                      <i class="bi bi-archive"></i>
                    </button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (!has_permission('manage_books') && !has_permission('archive_records')): ?>
                <span class="text-muted small">View only</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pg['total_pages'] > 1): ?>
    <div class="card-footer"><?= pagination_links($pg, $_GET) ?></div>
  <?php endif; ?>
</div>

<!-- BOOK DETAIL MODAL -->
<?php if ($viewBookId && $viewBook): ?>
<?php
    $policyDays = ((int)($viewBook['PolicyLoanDays'] ?? 0) > 0) ? (int)$viewBook['PolicyLoanDays'] : null;
    $effective  = $policyDays ?? DEFAULT_LOAN_DAYS;
    $closeUrl   = 'books.php' . (empty($closeQuery) ? '' : '?' . http_build_query($closeQuery));

    $copiesAvailable = 0; $copiesBorrowed = 0; $copiesLost = 0;
    $copiesDamaged   = 0; $copiesWithdrawn = 0; $copiesArchived = 0;
    foreach ($viewCopies as $c) {
        if (!empty($c['IsArchived'])) { $copiesArchived++; continue; }
        switch ($c['Status']) {
            case 'Available': $copiesAvailable++; break;
            case 'Borrowed':  $copiesBorrowed++;  break;
            case 'Lost':      $copiesLost++;      break;
            case 'Damaged':   $copiesDamaged++;   break;
            case 'Withdrawn': $copiesWithdrawn++; break;
        }
    }
?>
<div class="modal fade" id="bookDetailModal" tabindex="-1" data-autoshow="1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">
            <?= h($viewBook['Title']) ?>
            <?php if (!empty($viewBook['IsArchived'])): ?>
              <span class="badge text-bg-secondary ms-1"><i class="bi bi-archive"></i> Archived</span>
            <?php endif; ?>
          </h5>
          <div class="small text-muted mt-1">
            <?= h($viewBook['Authors'] ?: 'No author listed') ?>
            <?= $viewBook['ISBN'] ? ' · ISBN ' . h($viewBook['ISBN']) : '' ?>
            <?= $viewBook['PublicationYear'] ? ' · ' . h($viewBook['PublicationYear']) : '' ?>
          </div>
        </div>
        <a href="<?= h($closeUrl) ?>" class="btn-close" aria-label="Close"></a>
      </div>

      <div class="modal-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
          <span class="badge text-bg-success"><?= $copiesAvailable ?> Available</span>
          <?php if ($copiesBorrowed): ?><span class="badge text-bg-warning"><?= $copiesBorrowed ?> Borrowed</span><?php endif; ?>
          <?php if ($copiesDamaged):   ?><span class="badge text-bg-danger"><?= $copiesDamaged ?> Damaged</span><?php endif; ?>
          <?php if ($copiesLost):      ?><span class="badge text-bg-dark"><?= $copiesLost ?> Lost</span><?php endif; ?>
          <?php if ($copiesWithdrawn): ?><span class="badge text-bg-secondary"><?= $copiesWithdrawn ?> Withdrawn</span><?php endif; ?>
          <?php if ($copiesArchived):  ?><span class="badge text-bg-secondary"><?= $copiesArchived ?> Archived</span><?php endif; ?>
          <span class="badge text-bg-light border"><?= count($viewCopies) ?> Total</span>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <div class="text-uppercase text-muted small fw-bold mb-2">Catalog</div>
              <dl class="row mb-0 small">
                <dt class="col-5">Call Number</dt>
                <dd class="col-7"><?= h($viewBook['CallNumber'] ?: '—') ?></dd>

                <dt class="col-5">Publisher</dt>
                <dd class="col-7"><?= h($viewBook['PublisherName'] ?: '—') ?></dd>

                <dt class="col-5">Section</dt>
                <dd class="col-7"><?= h($viewBook['SectionName'] ?: '—') ?></dd>

                <dt class="col-5">Category</dt>
                <dd class="col-7"><?= h($viewBook['CategoryName'] ?: '—') ?></dd>

                <dt class="col-5">Policy</dt>
                <dd class="col-7">
                  <?= h($viewBook['PolicyName'] ?: '—') ?>
                  <?php if (empty($viewBook['IsBorrowable'])): ?>
                    <span class="badge text-bg-secondary ms-1">Reference Only</span>
                  <?php endif; ?>
                </dd>

                <dt class="col-5">Borrowing Period</dt>
                <dd class="col-7">
                  <?= h($effective) ?> days
                  <span class="text-muted small">(<?= $policyDays !== null ? 'from policy' : 'default' ?>)</span>
                </dd>
              </dl>
            </div>
          </div>

          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <div class="text-uppercase text-muted small fw-bold mb-2">Bibliographic</div>
              <dl class="row mb-0 small">
                <dt class="col-5">ISBN</dt>
                <dd class="col-7"><?= h($viewBook['ISBN'] ?: '—') ?></dd>

                <dt class="col-5">Edition</dt>
                <dd class="col-7"><?= h($viewBook['Edition'] ?: '—') ?></dd>

                <dt class="col-5">Language</dt>
                <dd class="col-7"><?= h($viewBook['Language'] ?: '—') ?></dd>

                <dt class="col-5">Pages</dt>
                <dd class="col-7"><?= $viewBook['Pages'] !== null ? h((int)$viewBook['Pages']) . ' pages' : '—' ?></dd>

                <dt class="col-5">Publication Year</dt>
                <dd class="col-7"><?= h($viewBook['PublicationYear'] ?: '—') ?></dd>
              </dl>
            </div>
          </div>
        </div>

        <?php if (!empty($viewBook['Description'])): ?>
          <div class="border rounded p-3 mb-3">
            <div class="text-uppercase text-muted small fw-bold mb-2">Description</div>
            <div class="small" style="white-space: pre-wrap;"><?= h($viewBook['Description']) ?></div>
          </div>
        <?php endif; ?>

        <div class="border rounded mb-3">
          <div class="text-uppercase text-muted small fw-bold px-3 py-2 bg-light border-bottom">Acquisition</div>
          <dl class="row mb-0 small p-3">
            <dt class="col-md-3 col-5">Type</dt>
            <dd class="col-md-3 col-7"><?= h($viewBook['AcquisitionType']) ?></dd>

            <dt class="col-md-3 col-5">Cost</dt>
            <dd class="col-md-3 col-7">
              <?= $viewBook['AcquisitionType'] === 'Donated'
                    ? '<span class="text-muted">—</span>'
                    : money($viewBook['AcquisitionCost']) ?>
            </dd>

            <dt class="col-md-3 col-5">Date Acquired</dt>
            <dd class="col-md-3 col-7"><?= $viewBook['AcquisitionDate'] ? fmt_date($viewBook['AcquisitionDate']) : '—' ?></dd>

            <dt class="col-md-3 col-5">Donor</dt>
            <dd class="col-md-9 col-7">
              <?php if ($viewBook['DonorName']): ?>
                <span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle">
                  <i class="bi bi-gift"></i> <?= h($viewBook['DonorName']) ?>
                </span>
                <?php if ($viewBook['DonorType']): ?>
                  <span class="text-muted small ms-1">
                    (<?= h($viewBook['DonorType']) ?><?= $viewBook['ContactInfo'] ? ' · ' . h($viewBook['ContactInfo']) : '' ?>)
                  </span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted">None</span>
              <?php endif; ?>
            </dd>
          </dl>
        </div>

        <div class="border rounded">
          <div class="d-flex justify-content-between align-items-center px-3 py-2 bg-light border-bottom">
            <span class="text-uppercase text-muted small fw-bold">Physical Copies</span>
            <span class="small text-muted"><?= count($viewCopies) ?> cop<?= count($viewCopies) === 1 ? 'y' : 'ies' ?></span>
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead>
                <tr>
                  <th style="width:60px" class="text-center">#</th>
                  <th>Accession Number</th>
                  <th>Status</th>
                  <th>Shelf</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$viewCopies): ?>
                  <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                      No physical copies on record.
                      <?php if (has_permission('manage_copies') && empty($viewBook['IsArchived'])): ?>
                        Use <strong>Add Copies</strong> to create some.
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($viewCopies as $c): ?>
                  <?php $copyArchived = !empty($c['IsArchived']); ?>
                  <tr class="<?= $copyArchived ? 'text-muted' : '' ?>">
                    <td class="text-center text-muted small"><?= $i++ ?></td>
                    <td class="fw-semibold">
                      <?= h($c['AccessionNumber']) ?>
                      <?php if ($copyArchived): ?>
                        <span class="badge text-bg-secondary ms-1"><i class="bi bi-archive"></i></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?= status_badge($c['Status']) ?>
                      <?php if ($copyArchived): ?>
                        <span class="badge text-bg-light border ms-1">Archived</span>
                      <?php endif; ?>
                    </td>
                    <td class="small">
                      <?php if (!empty($c['ShelfCode'])): ?>
                        <span class="badge text-bg-light border">
                          <i class="bi bi-grid-3x3-gap"></i> <?= h($c['ShelfCode']) ?>
                        </span>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <?php if (!$copyArchived && has_permission('manage_copies')): ?>
                        <button class="btn btn-sm btn-outline-primary"
                                title="Edit status / shelf"
                                data-bs-dismiss="modal"
                                onclick='openEditModal(<?= json_encode($c) ?>)'>
                          <i class="bi bi-pencil"></i>
                        </button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="modal-footer justify-content-between">
        <div class="small text-muted">
          <i class="bi bi-info-circle"></i>
          Click a book's title or the eye icon on the list to open this panel.
        </div>
        <div class="d-flex gap-2">
          <?php if (has_permission('manage_copies') && empty($viewBook['IsArchived'])): ?>
            <button class="btn btn-outline-success"
                    onclick='openAddCopiesModal(<?= json_encode(["BookID" => $viewBook["BookID"], "Title" => $viewBook["Title"], "CopyCount" => count($viewCopies)]) ?>)'>
              <i class="bi bi-plus-square"></i> Add Copies
            </button>
          <?php endif; ?>
          <?php if (has_permission('manage_books') && empty($viewBook['IsArchived'])): ?>
            <button class="btn btn-outline-primary"
                    onclick='openModal(<?= json_encode([
                        "BookID"         => (int)$viewBook["BookID"],
                        "Title"          => $viewBook["Title"],
                        "ISBN"           => $viewBook["ISBN"],
                        "CallNumber"     => $viewBook["CallNumber"],
                        "Edition"        => $viewBook["Edition"],
                        "Language"       => $viewBook["Language"],
                        "Pages"          => $viewBook["Pages"],
                        "Description"    => $viewBook["Description"],
                        "PublicationYear"=> $viewBook["PublicationYear"],
                        "PublisherID"    => (int)$viewBook["PublisherID"],
                        "SectionID"      => (int)$viewBook["SectionID"],
                        "CategoryID"     => (int)$viewBook["CategoryID"],
                        "PolicyID"       => (int)$viewBook["PolicyID"],
                        "AcquisitionCost"=> $viewBook["AcquisitionCost"],
                        "AcquisitionDate"=> $viewBook["AcquisitionDate"],
                        "DonorID"        => $viewBook["DonorID"],
                        "AuthorIds"      => $bookAuthors[$viewBook["BookID"]] ?? [],
                    ]) ?>)'>
              <i class="bi bi-pencil"></i> Edit Book
            </button>
          <?php endif; ?>
          <a href="<?= h($closeUrl) ?>" class="btn btn-secondary">Close</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- EDIT / ADD BOOK MODAL -->
<div class="modal fade" id="bookModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="bookModalTitle">Add Book</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="book_id" id="book_id">

        <h6 class="text-uppercase text-muted small fw-bold mb-2">Book Information</h6>
        <div class="row g-3 mb-3">
          <div class="col-md-8">
            <label class="form-label">Title</label>
            <input type="text" name="title" id="title" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">ISBN</label>
            <input type="text" name="isbn" id="isbn" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">Call Number</label>
            <input type="text" name="call_number" id="call_number" class="form-control" maxlength="50">
            <div class="form-text">Optional. Library staff assign these (e.g. <code>QA76.73.P224</code>).</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Edition</label>
            <select name="edition" id="edition" class="form-select">
              <option value="">— None —</option>
              <?php foreach (EDITION_OPTIONS as $e): ?>
                <option value="<?= h($e) ?>"><?= h($e) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Language</label>
            <select name="language" id="language" class="form-select">
              <option value="">— None —</option>
              <?php foreach (LANGUAGE_OPTIONS as $l): ?>
                <option value="<?= h($l) ?>"><?= h($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Pages</label>
            <input type="number" name="pages" id="pages" class="form-control" min="1" max="100000" step="1">
          </div>
          <div class="col-md-4">
            <label class="form-label">Publication Year</label>
            <input type="number" name="publication_year" id="publication_year" class="form-control" min="1000" max="2100">
          </div>
          <div class="col-md-4">
            <label class="form-label">Publisher</label>
            <?= render_select('publisher_id', $publishers, null, 'id="publisher_id"', true) ?>
          </div>
          <div class="col-md-4">
            <label class="form-label">Section</label>
            <?= render_select('section_id', $sections, null, 'id="section_id"', true) ?>
          </div>
          <div class="col-md-4">
            <label class="form-label">Category</label>
            <?= render_select('category_id', $categories, null, 'id="category_id"', true) ?>
          </div>
          <div class="col-md-4">
            <label class="form-label">Policy</label>
            <?= render_select('policy_id', $policies, null, 'id="policy_id"', true) ?>
            <div class="form-text">Controls the borrowing period and reference-only status.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Author(s)</label>
            <select name="author_ids[]" id="author_ids" class="form-select" multiple size="5">
              <?php foreach ($authors as $a): ?>
                <option value="<?= h($a['id']) ?>"><?= h($a['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Hold Ctrl (Cmd on Mac) to select multiple authors.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Description</label>
            <textarea name="description" id="description" class="form-control" rows="3" maxlength="2000"></textarea>
            <div class="form-text">Optional. A short summary or note about this title.</div>
          </div>
        </div>

        <h6 class="text-uppercase text-muted small fw-bold mb-2 mt-3">Acquisition Information</h6>
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label">Acquisition Cost (₱)</label>
            <input type="number" step="0.01" min="0" name="acquisition_cost" id="acquisition_cost" class="form-control" value="0.00">
            <div class="form-text">Ignored (stored as 0) when a donor is selected.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Acquisition Date</label>
            <input type="date" name="acquisition_date" id="acquisition_date" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label d-block">Acquisition Type</label>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="donor_option" id="donor_none" value="none" checked onclick="toggleDonor()">
              <label class="form-check-label" for="donor_none">Purchased (no donor)</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="donor_option" id="donor_existing" value="existing" onclick="toggleDonor()">
              <label class="form-check-label" for="donor_existing">Donated (select donor)</label>
            </div>
          </div>
          <div class="col-12">
            <div id="donorSelectWrap" class="d-none">
              <?= render_select('donor_id', $donors, null, 'id="donor_id"') ?>
              <div class="form-text">Need a new donor? Add one from the <a href="donors.php">Donors</a> page first.</div>
            </div>
          </div>
        </div>

        <div id="copiesSection">
          <h6 class="text-uppercase text-muted small fw-bold mb-2 mt-3">Physical Copies</h6>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Number of Copies</label>
              <input type="number" name="num_copies" id="num_copies" class="form-control"
                     min="1" max="<?= COPY_BATCH_LIMIT ?>" step="1" value="1" required>
              <div class="form-text">
                Accession numbers are generated automatically (e.g. <code>BK-00025-01</code>).
                All new copies start as <strong>Available</strong>.
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Book</button>
      </div>
    </form>
  </div>
</div>

<!-- ADD COPIES MODAL -->
<div class="modal fade" id="addCopiesModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title">Add Copies</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="add_copies">
        <input type="hidden" name="book_id" id="ac_book_id">
        <p class="small text-muted mb-3">
          Add more physical copies of <strong id="ac_book_title"></strong>.
          Currently <strong id="ac_book_count">0</strong> copies on record.
          New accession numbers continue from the last one — they never restart at 01.
        </p>
        <div class="mb-3">
          <label class="form-label">Number of Copies to Add</label>
          <input type="number" name="num_copies" id="ac_num_copies" class="form-control"
                 min="1" max="<?= COPY_BATCH_LIMIT ?>" step="1" value="1" required>
          <div class="form-text">Minimum 1, maximum <?= COPY_BATCH_LIMIT ?> per batch.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success"><i class="bi bi-plus-square"></i> Add Copies</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT COPY MODAL -->
<div class="modal fade" id="editCopyModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" action="book_copies.php" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title">Edit Book Copy</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="copy_id" id="ec_copy_id">
        <div class="mb-3">
          <label class="form-label">Accession Number</label>
          <input type="text" id="ec_accession" class="form-control" readonly>
          <div class="form-text">Accession numbers are permanent and cannot be changed.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Status</label>
          <select name="status" id="ec_status" class="form-select">
            <?php foreach (['Available','Borrowed','Lost','Damaged','Withdrawn'] as $s): ?>
              <option value="<?= h($s) ?>"><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleDonor() {
  var existing = document.getElementById('donor_existing').checked;
  document.getElementById('donorSelectWrap').classList.toggle('d-none', !existing);

  var costField = document.getElementById('acquisition_cost');
  if (existing) {
    costField.value = '0.00';
    costField.disabled = true;
  } else {
    costField.disabled = false;
  }
}

function openModal(b) {
  var isEdit = !!b;
  document.getElementById('bookModalTitle').textContent = isEdit ? 'Edit Book' : 'Add Book';
  document.getElementById('book_id').value = isEdit ? b.BookID : '';
  document.getElementById('title').value = isEdit ? b.Title : '';
  document.getElementById('isbn').value = isEdit ? (b.ISBN || '') : '';
  document.getElementById('call_number').value = isEdit ? (b.CallNumber || '') : '';
  document.getElementById('edition').value = isEdit ? (b.Edition || '') : '';
  document.getElementById('language').value = isEdit ? (b.Language || '') : '';
  document.getElementById('pages').value = isEdit ? (b.Pages != null ? b.Pages : '') : '';
  document.getElementById('description').value = isEdit ? (b.Description || '') : '';
  document.getElementById('publication_year').value = isEdit ? (b.PublicationYear || '') : '';
  document.getElementById('publisher_id').value = isEdit ? b.PublisherID : '';
  document.getElementById('section_id').value = isEdit ? b.SectionID : '';
  document.getElementById('category_id').value = isEdit ? b.CategoryID : '';
  document.getElementById('acquisition_cost').value = isEdit ? (parseFloat(b.AcquisitionCost || 0)).toFixed(2) : '0.00';
  document.getElementById('policy_id').value = isEdit ? b.PolicyID : '';
  document.getElementById('acquisition_date').value = isEdit ? (b.AcquisitionDate || '') : '';

  if (isEdit && b.DonorID) {
    document.getElementById('donor_existing').checked = true;
    document.getElementById('donor_id').value = b.DonorID;
  } else {
    document.getElementById('donor_none').checked = true;
    document.getElementById('donor_id').value = '';
  }
  toggleDonor();

  var authorSelect = document.getElementById('author_ids');
  var selectedIds = (isEdit && b.AuthorIds) ? b.AuthorIds.map(String) : [];
  Array.from(authorSelect.options).forEach(function (opt) {
    opt.selected = selectedIds.includes(opt.value);
  });

  var copiesSection = document.getElementById('copiesSection');
  var numCopies = document.getElementById('num_copies');
  if (isEdit) {
    copiesSection.style.display = 'none';
    numCopies.required = false;
    numCopies.disabled = true;
  } else {
    copiesSection.style.display = '';
    numCopies.required = true;
    numCopies.disabled = false;
    numCopies.value = 1;
  }

  new bootstrap.Modal(document.getElementById('bookModal')).show();
}

function openAddCopiesModal(b) {
  document.getElementById('ac_book_id').value = b.BookID;
  document.getElementById('ac_book_title').textContent = b.Title;
  document.getElementById('ac_book_count').textContent = b.CopyCount;
  document.getElementById('ac_num_copies').value = 1;
  new bootstrap.Modal(document.getElementById('addCopiesModal')).show();
}

function openEditModal(c) {
  document.getElementById('ec_copy_id').value   = c.CopyID;
  document.getElementById('ec_accession').value = c.AccessionNumber;
  document.getElementById('ec_status').value    = c.Status;
  new bootstrap.Modal(document.getElementById('editCopyModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.modal[data-autoshow="1"]').forEach(function (m) {
    new bootstrap.Modal(m).show();
  });
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>