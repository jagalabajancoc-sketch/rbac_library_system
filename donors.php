<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Donors';

$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
if ($view === 'archived') {
    require_permission('view_archived_records');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();                       // <-- ADDED
    $action = $_POST['action'] ?? '';

    if ($action === 'archive' || $action === 'restore') {
        require_permission('archive_records');
        $id = $_POST['donor_id'] ?? '';
        if (!$id) { flash('danger', 'Missing donor reference.'); redirect('donors.php'); }
        $row = fetch_one("SELECT DonorID, DonorName FROM donor WHERE DonorID = ?", [$id]);
        if (!$row) { flash('danger', 'Donor not found.'); redirect('donors.php'); }
        run("UPDATE donor SET IsArchived = ? WHERE DonorID = ?", [$action === 'archive' ? 1 : 0, $row['DonorID']]);
        log_activity(
            $action === 'archive' ? 'Archive' : 'Restore',
            'Donors',
            ($action === 'archive' ? 'Archived' : 'Restored') . ' donor "' . $row['DonorName'] . '"',
            $row['DonorID']
        );
        flash('success', $action === 'archive'
            ? 'Donor "' . $row['DonorName'] . '" archived.'
            : 'Donor "' . $row['DonorName'] . '" restored.');
        redirect('donors.php?view=' . ($action === 'archive' ? 'archived' : 'active'));
    }

    if ($action === 'save') {
        require_permission('manage_donors');
        $id      = $_POST['donor_id'] ?? '';
        $name    = trim($_POST['donor_name'] ?? '');
        $type    = trim($_POST['donor_type'] ?? '');
        $contact = trim($_POST['contact_info'] ?? '');

        if ($name === '') {
            flash('danger', 'Donor name is required.');
        } else {
            if ($id) {
                run("UPDATE donor SET DonorName=?, DonorType=?, ContactInfo=? WHERE DonorID=?", [$name, $type, $contact, $id]);
                log_activity('Update', 'Donors', 'Updated donor "' . $name . '"', $id);
                flash('success', 'Donor updated.');
            } else {
                run("INSERT INTO donor (DonorName, DonorType, ContactInfo) VALUES (?,?,?)", [$name, $type, $contact]);
                log_activity('Create', 'Donors', 'Added donor "' . $name . '"', (int)last_id());
                flash('success', 'Donor added.');
            }
        }
        redirect('donors.php');
    }
}

$listSearch=mb_substr(trim((string)($_GET['q']??'')),0,100);$listSearchParams=[];$donorSearch='';
if($listSearch!==''){$donorSearch=' AND (d.DonorName LIKE ? OR d.ContactInfo LIKE ?)';$listSearchParams=array_fill(0,2,'%'.$listSearch.'%');}
$donors = fetch_all(
    "SELECT d.*,
            (SELECT COUNT(*) FROM book b WHERE b.DonorID = d.DonorID) AS CopyCount
       FROM donor d
      WHERE d.IsArchived = " . ($view === 'archived' ? '1' : '0') . "
      $donorSearch ORDER BY d.DonorName",$listSearchParams
);
$activeCount   = (int) fetch_value("SELECT COUNT(*) FROM donor WHERE IsArchived = 0");
$archivedCount = (int) fetch_value("SELECT COUNT(*) FROM donor WHERE IsArchived = 1");

require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $view === 'active' ? 'active' : '' ?>" href="donors.php?view=active">
      <i class="bi bi-gift"></i> Active
      <span class="badge text-bg-light border ms-1"><?= h($activeCount) ?></span>
    </a>
  </li>
  <?php if (has_permission('view_archived_records')): ?>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'archived' ? 'active' : '' ?>" href="donors.php?view=archived">
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
      <strong>Showing archived donors.</strong> Archived donors don't appear in the book form.
      <?php if (has_permission('archive_records')): ?>
        Use <strong>Restore</strong> to return one to the active list.
      <?php else: ?>
        Only Admin can restore.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="page-toolbar"><form method="get" class="d-flex flex-wrap gap-2"><input type="search" name="q" class="form-control" value="<?= h($listSearch) ?>" placeholder="Search this list" aria-label="Search this list"><?php if(isset($view)):?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif;?><button class="btn btn-primary">Search</button></form>
  <div></div>
  <?php if (has_permission('manage_donors') && $view === 'active'): ?>
    <button class="btn btn-primary" onclick="openModal()"><i class="bi bi-plus-lg"></i> Add Donor</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Donor Name</th>
          <th>Type</th>
          <th>Contact</th>
          <th>Donated</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$donors): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No donors found.</td></tr>
        <?php endif; ?>
        <?php foreach ($donors as $d): ?>
          <?php $isArchived = !empty($d['IsArchived']); ?>
          <tr class="<?= $isArchived ? 'text-muted' : '' ?>">
            <td>
              <?= h($d['DonorName']) ?>
              <?php if ($isArchived): ?>
                <span class="badge text-bg-secondary ms-1"><i class="bi bi-archive"></i> Archived</span>
              <?php endif; ?>
            </td>
            <td><?= h($d['DonorType']) ?></td>
            <td><?= h($d['ContactInfo']) ?></td>
            <td><span class="badge text-bg-light border"><?= h($d['CopyCount']) ?></span></td>
            <td class="text-end text-nowrap">
              <?php if (!$isArchived && has_permission('manage_donors')): ?>
                <button class="btn btn-sm btn-outline-primary"
                        title="Edit" data-bs-toggle="tooltip"
                        onclick='openModal(<?= json_encode($d) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
              <?php endif; ?>

              <?php if (has_permission('archive_records')): ?>
                <?php if ($isArchived): ?>
                  <form method="post" class="d-inline" data-confirm="Restore this donor?">
                    <?= csrf_field() /* <-- ADDED */ ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="donor_id" value="<?= h($d['DonorID']) ?>">
                    <button class="btn btn-sm btn-outline-success"
                            title="Restore" data-bs-toggle="tooltip">
                      <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <form method="post" class="d-inline" data-confirm="Archive this donor?">
                    <?= csrf_field() /* <-- ADDED */ ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="donor_id" value="<?= h($d['DonorID']) ?>">
                    <button class="btn btn-sm btn-outline-warning"
                            title="Archive" data-bs-toggle="tooltip">
                      <i class="bi bi-archive"></i>
                    </button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (!has_permission('manage_donors') && !has_permission('archive_records')): ?>
                <span class="text-muted small">View only</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="donorModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() /* <-- ADDED */ ?>
      <div class="modal-header">
        <h5 class="modal-title" id="donorModalTitle">Add Donor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="donor_id" id="donor_id">
        <div class="mb-3">
          <label class="form-label">Donor Name</label>
          <input type="text" name="donor_name" id="donor_name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Donor Type</label>
          <select name="donor_type" id="donor_type" class="form-select">
            <option value="">Select donor type</option>
            <option value="Individual">Individual</option>
            <option value="Organization">Organization</option>
            <option value="Alumni">Alumni</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Contact Information</label>
          <input type="text" name="contact_info" id="contact_info" class="form-control">
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
function openModal(d) {
  document.getElementById('donorModalTitle').textContent = d ? 'Edit Donor' : 'Add Donor';
  document.getElementById('donor_id').value = d ? d.DonorID : '';
  document.getElementById('donor_name').value = d ? d.DonorName : '';
  document.getElementById('donor_type').value = d ? (d.DonorType || '') : '';
  document.getElementById('contact_info').value = d ? (d.ContactInfo || '') : '';
  new bootstrap.Modal(document.getElementById('donorModal')).show();
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>