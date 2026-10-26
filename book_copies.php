<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Book Copies';

/* Statuses a staff member may set manually via the Edit Copy form.
   'In Use' is deliberately excluded: it is only valid while a matching
   row exists in `in_library_use` with Status='Active'. Setting it by
   hand would strand the copy (no session to return it). 'Borrowed' is
   also excluded — it must come from issuing books at the Library Desk. */
$editableStatuses = ['Available', 'Lost', 'Damaged', 'Withdrawn'];

$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
if ($view === 'archived') {
    require_permission('view_archived_records');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'archive' || $action === 'restore') {
        require_permission('archive_records');
        $id = $_POST['copy_id'] ?? '';
        if (!$id) { flash('danger', 'Missing copy reference.'); redirect('book_copies.php'); }

        $row = fetch_one(
            "SELECT bc.CopyID, bc.AccessionNumber, bc.Status, b.Title
               FROM book_copy bc JOIN book b ON b.BookID = bc.BookID
              WHERE bc.CopyID = ?",
            [$id]
        );
        if (!$row) { flash('danger', 'Copy not found.'); redirect('book_copies.php'); }
        if ($action === 'archive' && in_array($row['Status'], ['Borrowed', 'In Use'], true)) {
            flash('danger', 'Cannot archive a copy that is currently ' . strtolower($row['Status'])
                . '. Wait until it is returned.');
            redirect('book_copies.php');
        }
        run("UPDATE book_copy SET IsArchived = ? WHERE CopyID = ?", [$action === 'archive' ? 1 : 0, $row['CopyID']]);
        log_activity(
            $action === 'archive' ? 'Archive' : 'Restore',
            'Book Copies',
            ($action === 'archive' ? 'Archived' : 'Restored') . ' copy "' . $row['AccessionNumber'] . '" (' . $row['Title'] . ')',
            $row['CopyID']
        );
        flash('success', $action === 'archive'
            ? 'Copy "' . $row['AccessionNumber'] . '" archived.'
            : 'Copy "' . $row['AccessionNumber'] . '" restored.');
        redirect('book_copies.php?view=' . ($action === 'archive' ? 'archived' : 'active'));
    }

    if ($action === 'add_copies') {
        require_permission('manage_copies');
        $bookId = $_POST['book_id'] ?? '';
        $raw    = trim((string)($_POST['num_copies'] ?? ''));
        if (!$bookId) { flash('danger', 'Please choose a book.'); redirect('book_copies.php'); }
        if ($raw === '' || !ctype_digit($raw)) { flash('danger', 'Number of copies must be a positive whole number.'); redirect('book_copies.php'); }
        $count = (int)$raw;
        if ($count < 1) { flash('danger', 'Number of copies must be at least 1.'); redirect('book_copies.php'); }
        if ($count > COPY_BATCH_LIMIT) { flash('danger', 'Number of copies cannot exceed ' . COPY_BATCH_LIMIT . ' per batch.'); redirect('book_copies.php'); }

        $book = fetch_one("SELECT BookID, Title, IsArchived FROM book WHERE BookID = ?", [$bookId]);
        if (!$book) { flash('danger', 'Book not found.'); redirect('book_copies.php'); }
        if (!empty($book['IsArchived'])) {
            flash('danger', 'Cannot add copies to an archived book. Restore it first.');
            redirect('book_copies.php');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $numbers = create_entered_copies((int)$bookId, $count, (string)($_POST['accession_numbers'] ?? ''));
            promote_reservation_queue((int)$bookId);
            $pdo->commit();
            log_activity('Create', 'Book Copies',
                'Added ' . $count . ' copies to "' . $book['Title'] . '": ' . implode(', ', $numbers),
                (int)$bookId);
            flash('success', sprintf('Added %d copies to "%s": %s', $count, $book['Title'], implode(', ', $numbers)));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('danger', 'Could not add copies: ' . user_message($e));
        }
        redirect('book_copies.php?book_id=' . urlencode($bookId));
    }

    if ($action === 'save') {
        require_permission('manage_copies');
        $id      = $_POST['copy_id'] ?? '';
        $status  = $_POST['status'] ?? 'Available';
        $shelfId = trim((string)($_POST['shelf_id'] ?? ''));

        // Only editable statuses are accepted from the form.
        if (!in_array($status, $editableStatuses, true)) { flash('danger', 'Invalid copy status.'); redirect('book_copies.php'); }
        if (!$id) { flash('danger', 'Missing copy reference.'); redirect('book_copies.php'); }

        $shelfIdVal = null;
        if ($shelfId !== '') {
            $shelf = fetch_one(
                "SELECT sh.ShelfID, sh.SectionID, sh.IsArchived
                   FROM shelf sh WHERE sh.ShelfID = ?",
                [$shelfId]
            );
            if (!$shelf || $shelf['IsArchived']) {
                flash('danger', 'Selected shelf is not available.');
                redirect('book_copies.php');
            }
            $bookSection = fetch_value(
                "SELECT b.SectionID FROM book b
                   JOIN book_copy bc ON bc.BookID = b.BookID
                  WHERE bc.CopyID = ?",
                [$id]
            );
            if ((string)$shelf['SectionID'] !== (string)$bookSection) {
                flash('danger', 'The selected shelf belongs to a different section than the book.');
                redirect('book_copies.php');
            }
            $shelfIdVal = (int)$shelf['ShelfID'];
        }

        $activeLoanItem = (int) fetch_value(
            "SELECT COUNT(*) FROM loan_detail ld
               LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
              WHERE ld.CopyID = ? AND rd.ReturnDetailID IS NULL",
            [$id]
        );
        if ($activeLoanItem > 0) {
            flash('danger', 'This copy has an active borrowing transaction. Process the return on the Returns page instead of editing its status directly.');
            redirect('book_copies.php');
        }
        if ($status === 'Borrowed') {
            flash('danger', 'Cannot set a copy to Borrowed from here. Use the Library Desk to issue books.');
            redirect('book_copies.php');
        }
        $curStatus = (string) fetch_value("SELECT Status FROM book_copy WHERE CopyID = ?", [$id]);
        if ($status === 'Reserved' && $curStatus !== 'Reserved') {
            flash('danger', 'Copies are held only by approving a Borrow Request. Use the Borrow Requests page.');
            redirect('book_copies.php');
        }
        $heldBy = fetch_value("SELECT RequestID FROM borrow_request WHERE AssignedCopyID = ? AND ApprovalStatus = 'Approved' LIMIT 1", [$id]);
        if ($heldBy && $status !== $curStatus) {
            flash('danger', "This copy is held for Borrow Request #$heldBy. Cancel or release that request first.");
            redirect('book_copies.php');
        }
        if ($status === 'In Use') {
            flash('danger', 'Cannot set a copy to In Use from here. Use the In-Library Use page to start a session.');
            redirect('book_copies.php');
        }

        $before = fetch_one(
            "SELECT bc.AccessionNumber, bc.ShelfID, b.Title
               FROM book_copy bc JOIN book b ON b.BookID = bc.BookID
              WHERE bc.CopyID = ?",
            [$id]
        );

        run("UPDATE book_copy SET Status = ?, ShelfID = ? WHERE CopyID = ?",
            [$status, $shelfIdVal, $id]);

        $shelfMsg = ($before && (int)$before['ShelfID'] !== (int)($shelfIdVal ?? 0))
            ? ' — shelf reassigned'
            : '';
        log_activity('Update', 'Book Copies',
            'Updated copy "' . ($before['AccessionNumber'] ?? "#$id") . '" status to ' . $status . $shelfMsg,
            $id);

        if ($status === 'Available') {
            $bk = (int) fetch_value("SELECT BookID FROM book_copy WHERE CopyID = ?", [$id]);
            try { promote_reservation_queue($bk); } catch (Throwable $e) { user_message($e); }
        }
        flash('success', 'Book copy updated.');
        redirect('book_copies.php');
    }
}

$bookFilter   = $_GET['book_id'] ?? '';
$shelfFilter  = $_GET['shelf_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$where  = [];
$params = [];
$where[] = 'bc.IsArchived = ' . ($view === 'archived' ? '1' : '0');
if ($bookFilter !== '')   { $where[] = 'bc.BookID = ?'; $params[] = $bookFilter; }
if ($shelfFilter !== '')  { $where[] = 'bc.ShelfID = ?'; $params[] = $shelfFilter; }
if ($statusFilter !== '') { $where[] = 'bc.Status = ?'; $params[] = $statusFilter; }
if ($search !== '')       { $where[] = 'bc.AccessionNumber LIKE ?'; $params[] = "%$search%"; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalCopies = (int) fetch_value("SELECT COUNT(*) FROM book_copy bc $whereSql", $params);
$pg = pagination_calc($totalCopies, PER_PAGE_DEFAULT, pagination_page());

$copies = fetch_all(
    "SELECT bc.*, b.Title, b.AcquisitionDate, b.SectionID, d.DonorName,
            sh.ShelfCode, sh.ShelfDescription, sec.SectionName
       FROM book_copy bc
       JOIN book b ON b.BookID = bc.BookID
       LEFT JOIN donor d   ON d.DonorID = b.DonorID
       LEFT JOIN shelf sh  ON sh.ShelfID = bc.ShelfID
       LEFT JOIN section sec ON sec.SectionID = sh.SectionID
       $whereSql
       ORDER BY bc.CopyID DESC
       LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$books = lookup_options('book', 'BookID', 'Title');
$currentBookTitle = $bookFilter ? fetch_value("SELECT Title FROM book WHERE BookID = ?", [$bookFilter]) : null;

$shelves = fetch_all(
    "SELECT sh.ShelfID AS id, sh.SectionID,
            CONCAT(sec.SectionName, ' · ', sh.ShelfCode) AS label
       FROM shelf sh JOIN section sec ON sec.SectionID = sh.SectionID
      WHERE sh.IsArchived = 0
      ORDER BY sec.SectionName, sh.ShelfCode"
);

$activeCount   = (int) fetch_value("SELECT COUNT(*) FROM book_copy WHERE IsArchived = 0");
$archivedCount = (int) fetch_value("SELECT COUNT(*) FROM book_copy WHERE IsArchived = 1");

require __DIR__ . '/includes/layout_top.php';
?>

<?php if ($currentBookTitle): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Showing copies for: <strong><?= h($currentBookTitle) ?></strong></span>
    <a href="book_copies.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
  </div>
<?php endif; ?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $view === 'active' ? 'active' : '' ?>"
       href="book_copies.php?view=active<?= $bookFilter ? '&book_id=' . urlencode($bookFilter) : '' ?>">
      <i class="bi bi-journal-bookmark"></i> Active
      <span class="badge text-bg-light border ms-1"><?= h($activeCount) ?></span>
    </a>
  </li>
  <?php if (has_permission('view_archived_records')): ?>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'archived' ? 'active' : '' ?>"
         href="book_copies.php?view=archived<?= $bookFilter ? '&book_id=' . urlencode($bookFilter) : '' ?>">
        <i class="bi bi-archive"></i> Archived
        <span class="badge text-bg-light border ms-1"><?= h($archivedCount) ?></span>
      </a>
    </li>
  <?php endif; ?>
</ul>

<?php if ($view === 'archived'): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="bi bi-archive-fill mt-1"></i>
    <div class="small">
      <strong>Showing archived copies.</strong> These are not offered for borrowing or reservation.
      <?php if (has_permission('archive_records')): ?>
        Use <strong>Restore</strong> to return one to the active pool.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="page-toolbar">
  <form class="d-flex flex-wrap gap-2" method="get">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <?php if ($bookFilter): ?><input type="hidden" name="book_id" value="<?= h($bookFilter) ?>"><?php endif; ?>
    <input type="search" name="q" class="form-control" style="max-width:200px" placeholder="Search accession #..." value="<?= h($search) ?>">
    <select name="status" class="form-select" style="max-width:160px" onchange="this.form.submit()">
      <option value="">All Statuses</option>
      <?php foreach (['Available', 'Reserved', 'Borrowed', 'In Use', 'Lost', 'Damaged', 'Withdrawn'] as $s): ?>
        <option value="<?= h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="shelf_id" class="form-select" style="max-width:200px" onchange="this.form.submit()">
      <option value="">All Shelves</option>
      <?php foreach ($shelves as $sh): ?>
        <option value="<?= h($sh['id']) ?>" <?= (string)$shelfFilter === (string)$sh['id'] ? 'selected' : '' ?>>
          <?= h($sh['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  </form>
  <?php if (has_permission('manage_copies') && $view === 'active'): ?>
    <button class="btn btn-primary" onclick="openAddCopiesModal()">
      <i class="bi bi-plus-lg"></i> Add Copies
    </button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="px-3 py-2 border-bottom bg-light small text-muted">
    <?= pagination_info($pg) ?>
    <?php if ($search !== ''): ?> · search: <strong><?= h($search) ?></strong><?php endif; ?>
    <?php if ($statusFilter !== ''): ?> · status: <strong><?= h($statusFilter) ?></strong><?php endif; ?>
  </div>

  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Accession #</th>
          <th>Book Title</th>
          <th>Shelf</th>
          <th>Acquired</th>
          <th>Status</th>
          <th>Donor</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$copies): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No copies found.</td></tr>
        <?php endif; ?>
        <?php foreach ($copies as $c): ?>
          <?php $isArchived = !empty($c['IsArchived']); ?>
          <tr class="<?= $isArchived ? 'text-muted' : '' ?>">
            <td class="fw-semibold">
              <?= h($c['AccessionNumber']) ?>
              <?php if ($isArchived): ?>
                <span class="badge text-bg-secondary ms-1"><i class="bi bi-archive"></i></span>
              <?php endif; ?>
            </td>
            <td><?= h($c['Title']) ?></td>
            <td>
              <?php if ($c['ShelfCode']): ?>
                <span class="badge text-bg-light border"
                      title="<?= h($c['ShelfDescription'] ?: '') ?>">
                  <i class="bi bi-grid-3x3-gap"></i>
                  <?= h($c['ShelfCode']) ?>
                </span>
                <div class="small text-muted"><?= h($c['SectionName']) ?></div>
              <?php else: ?>
                <span class="text-muted small">Unassigned</span>
              <?php endif; ?>
            </td>
            <td><?= fmt_date($c['AcquisitionDate']) ?></td>
            <td><?= status_badge($c['Status']) ?></td>
            <td>
              <?php if ($c['DonorName']): ?>
                <span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle">
                  <i class="bi bi-gift"></i> <?= h($c['DonorName']) ?>
                </span>
              <?php else: ?>
                <span class="text-muted">None</span>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <?php if (!$isArchived && has_permission('manage_copies')): ?>
                <button class="btn btn-sm btn-outline-primary"
                        title="Edit status / shelf" data-bs-toggle="tooltip"
                        onclick='openEditModal(<?= json_encode($c) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
              <?php endif; ?>

              <?php if (has_permission('archive_records')): ?>
                <?php if ($isArchived): ?>
                  <form method="post" class="d-inline" data-confirm="Restore this copy?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="copy_id" value="<?= h($c['CopyID']) ?>">
                    <button class="btn btn-sm btn-outline-success" title="Restore" data-bs-toggle="tooltip">
                      <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <form method="post" class="d-inline" data-confirm="Archive this copy?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="copy_id" value="<?= h($c['CopyID']) ?>">
                    <button class="btn btn-sm btn-outline-warning" title="Archive" data-bs-toggle="tooltip">
                      <i class="bi bi-archive"></i>
                    </button>
                  </form>
                <?php endif; ?>
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

<div class="modal fade" id="addCopiesModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title">Add Book Copies</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="add_copies">
        <div class="mb-3">
          <label class="form-label">Book Title</label>
          <?= render_select('book_id', $books, $bookFilter, 'id="ac_book_id"', true) ?>
          <div class="form-text">Enter the accession numbers printed on the physical copies.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Number of Copies</label>
          <input type="number" name="num_copies" id="ac_num_copies" class="form-control"
                 min="1" max="<?= COPY_BATCH_LIMIT ?>" step="1" value="1" required>
<label class="form-label mt-2" for="additional_accessions">Accession numbers (one per line)</label><textarea name="accession_numbers" id="additional_accessions" class="form-control" rows="3" required></textarea><div class="form-text">One unique accession number per copy, up to 40 characters each.</div>
          <div class="form-text">
            Minimum 1, maximum <?= COPY_BATCH_LIMIT ?> per batch.
            New copies start as <strong>Available</strong>.
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Copies</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="editCopyModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
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
        </div>
        <div class="mb-3">
          <label class="form-label">Book Title</label>
          <input type="text" id="ec_title" class="form-control" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label">Status</label>
          <select name="status" id="ec_status" class="form-select">
            <?php foreach ($editableStatuses as $s): ?>
              <option value="<?= h($s) ?>"><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">
            <strong>Borrowed</strong> and <strong>In Use</strong> can only be set by
            issuing books or starting an in-library session — not from this form.
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Shelf</label>
          <select name="shelf_id" id="ec_shelf_id" class="form-select">
            <option value="">— Unassigned —</option>
            <?php foreach ($shelves as $sh): ?>
              <option value="<?= h($sh['id']) ?>"><?= h($sh['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Optional. Must belong to the same section as the book.</div>
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
function openAddCopiesModal() {
  document.getElementById('ac_num_copies').value = 1;
  new bootstrap.Modal(document.getElementById('addCopiesModal')).show();
}
function openEditModal(c) {
  document.getElementById('ec_copy_id').value   = c.CopyID;
  document.getElementById('ec_accession').value = c.AccessionNumber;
  document.getElementById('ec_title').value     = c.Title;
  document.getElementById('ec_status').value    = c.Status;
  document.getElementById('ec_shelf_id').value  = c.ShelfID || '';
  new bootstrap.Modal(document.getElementById('editCopyModal')).show();
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>