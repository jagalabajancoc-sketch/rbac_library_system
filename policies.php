<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_settings');
$page_title = 'Policies';

$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
if ($view === 'archived') {
    require_permission('view_archived_records');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'archive' || $action === 'restore') {
        require_permission('archive_records');
        $id = $_POST['policy_id'] ?? '';
        if (!$id) { flash('danger', 'Missing policy reference.'); redirect('policies.php'); }
        $row = fetch_one("SELECT PolicyID, PolicyName FROM policy WHERE PolicyID = ?", [$id]);
        if (!$row) { flash('danger', 'Policy not found.'); redirect('policies.php'); }
        run("UPDATE policy SET IsArchived = ? WHERE PolicyID = ?", [$action === 'archive' ? 1 : 0, $row['PolicyID']]);
        log_activity(
            $action === 'archive' ? 'Archive' : 'Restore',
            'Policies',
            ($action === 'archive' ? 'Archived' : 'Restored') . ' policy "' . $row['PolicyName'] . '"',
            $row['PolicyID']
        );
        flash('success', $action === 'archive'
            ? 'Policy "' . $row['PolicyName'] . '" archived.'
            : 'Policy "' . $row['PolicyName'] . '" restored.');
        redirect('policies.php?view=' . ($action === 'archive' ? 'archived' : 'active'));
    }

    if ($action === 'save') {
        $id           = $_POST['policy_id'] ?? '';
        $name         = trim($_POST['policy_name'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $isBorrowable = isset($_POST['is_borrowable']) ? 1 : 0;
        $loanDays     = $_POST['loan_days'] !== '' ? (int)$_POST['loan_days'] : null;
        $maxRenewals  = (int)($_POST['max_renewals'] ?? 0);
        $penaltyRate  = (float)($_POST['penalty_rate'] ?? 0);

        if ($name === '') {
            flash('danger', 'Policy name is required.');
        } else {
            if ($id) {
                run("UPDATE policy SET PolicyName=?, Description=?, IsBorrowable=?, LoanDays=?, MaxRenewals=?, PenaltyRate=? WHERE PolicyID=?",
                    [$name, $description, $isBorrowable, $loanDays, $maxRenewals, $penaltyRate, $id]);
                log_activity('Update', 'Policies', 'Updated policy "' . $name . '"', $id);
                flash('success', 'Policy updated.');
            } else {
                run("INSERT INTO policy (PolicyName, Description, IsBorrowable, LoanDays, MaxRenewals, PenaltyRate) VALUES (?,?,?,?,?,?)",
                    [$name, $description, $isBorrowable, $loanDays, $maxRenewals, $penaltyRate]);
                log_activity('Create', 'Policies', 'Added policy "' . $name . '"', (int)last_id());
                flash('success', 'Policy added.');
            }
        }
        redirect('policies.php');
    }

    if ($action === 'save_limit') {
        require_permission('manage_settings');
        $type = trim($_POST['borrower_type'] ?? '');
        $max  = (int)($_POST['max_books'] ?? 0);

        $validTypes = ['Member'];

        if (!in_array($type, $validTypes, true)) {
            flash('danger', 'Invalid borrower type.');
        } elseif ($max < 1 || $max > 100) {
            flash('danger', 'Maximum books must be between 1 and 100.');
        } else {
            $column = 'MemberMaxBooks';
            run("UPDATE policy SET `$column` = ?", [$max]);
            log_activity('Update', 'Policies',
                "Borrowing limit for {$type} borrower set to {$max} book(s)",
                null);
            flash('success', "Borrowing limit for {$type} borrower updated to {$max} book(s).");
        }
        redirect('policies.php');
    }
}

$whereSql = "WHERE IsArchived = " . ($view === 'archived' ? '1' : '0');
$totalPolicies = (int) fetch_value("SELECT COUNT(*) FROM policy $whereSql");
$pg = pagination_calc($totalPolicies, PER_PAGE_DEFAULT, pagination_page());

$policies = fetch_all(
    "SELECT * FROM policy
      $whereSql
      ORDER BY PolicyName
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
);
$activeCount   = (int) fetch_value("SELECT COUNT(*) FROM policy WHERE IsArchived = 0");
$archivedCount = (int) fetch_value("SELECT COUNT(*) FROM policy WHERE IsArchived = 1");

$limits = get_borrowing_limits();

require __DIR__ . '/includes/layout_top.php';
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $view === 'active' ? 'active' : '' ?>" href="policies.php?view=active">
      <i class="bi bi-file-earmark-text"></i> Active
      <span class="badge text-bg-light border ms-1"><?= h($activeCount) ?></span>
    </a>
  </li>
  <?php if (has_permission('view_archived_records')): ?>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'archived' ? 'active' : '' ?>" href="policies.php?view=archived">
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
      <strong>Showing archived policies.</strong> Archived policies don't appear in the book form, but existing books keep working with their assigned policy.
      <?php if (has_permission('archive_records')): ?>
        Use <strong>Restore</strong> to return one to the active list.
      <?php else: ?>
        Only Admin can restore.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="page-toolbar">
  <div></div>
  <?php if ($view === 'active'): ?>
    <button class="btn btn-primary" onclick="openModal()"><i class="bi bi-plus-lg"></i> Add Policy</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-file-earmark-text"></i> Book Policies</span>
    <span class="small text-muted">Rules that apply to a <strong>book</strong> (borrowable, loan days, penalty rate).</span>
  </div>

  <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Policy Name</th>
          <th>Borrowable</th>
          <th>Loan Days</th>
          <th>Max Renewals</th>
          <th>Penalty/Day</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$policies): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No policies found.</td></tr>
        <?php endif; ?>
        <?php foreach ($policies as $p): ?>
          <?php $isArchived = !empty($p['IsArchived']); ?>
          <tr class="<?= $isArchived ? 'text-muted' : '' ?>">
            <td>
              <?= h($p['PolicyName']) ?>
              <?php if ($isArchived): ?>
                <span class="badge text-bg-secondary ms-1"><i class="bi bi-archive"></i> Archived</span>
              <?php endif; ?>
              <div class="text-muted small"><?= h($p['Description']) ?></div>
            </td>
            <td><?= $p['IsBorrowable'] ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>' ?></td>
            <td><?= $p['LoanDays'] !== null ? h($p['LoanDays']) . ' days' : '—' ?></td>
            <td><?= h($p['MaxRenewals']) ?></td>
            <td><?= money($p['PenaltyRate']) ?></td>
            <td class="text-end text-nowrap">
              <?php if (!$isArchived): ?>
                <button class="btn btn-sm btn-outline-primary" title="Edit" data-bs-toggle="tooltip"
                        onclick='openModal(<?= json_encode($p) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
              <?php endif; ?>

              <?php if (has_permission('archive_records')): ?>
                <?php if ($isArchived): ?>
                  <form method="post" class="d-inline" data-confirm="Restore this policy?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="policy_id" value="<?= h($p['PolicyID']) ?>">
                    <button class="btn btn-sm btn-outline-success" title="Restore" data-bs-toggle="tooltip">
                      <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <form method="post" class="d-inline" data-confirm="Archive this policy?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="policy_id" value="<?= h($p['PolicyID']) ?>">
                    <button class="btn btn-sm btn-outline-warning" title="Archive" data-bs-toggle="tooltip">
                      <i class="bi bi-archive"></i>
                    </button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (!has_permission('manage_settings') && !has_permission('archive_records')): ?>
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

<div class="card mt-4 border-primary-subtle">
  <div class="card-header bg-primary-subtle d-flex justify-content-between align-items-center">
    <span>
      <i class="bi bi-people-fill text-primary"></i>
      <strong class="ms-1">Borrowing Limits</strong>
    </span>
    <span class="small text-muted">
      Rules that apply to a <strong>borrower</strong> — how many books they may hold at once.
    </span>
  </div>

  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th style="width:22%">Borrower Type</th>
          <th style="width:18%">Maximum Books</th>
          <th>Description</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>
            <span class="badge text-bg-primary">
              <i class="bi bi-person-badge"></i> Member
            </span>
          </td>
          <td>
            <span class="fw-semibold fs-5"><?= h($limits['Member']) ?></span>
            <span class="text-muted small ms-1">book(s)</span>
          </td>
          <td class="text-muted small">
            A registered borrower with a portal login account.
          </td>
          <td class="text-end text-nowrap">
            <?php if (has_permission('manage_settings')): ?>
              <button class="btn btn-sm btn-outline-primary"
                      title="Edit limit" data-bs-toggle="tooltip"
                      onclick='openLimitModal(<?= json_encode([
                          "BorrowerType" => "Member",
                          "MaxBooks"     => (int)$limits["Member"],
                          "Description"  => "A registered borrower with a portal login account.",
                      ]) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
            <?php else: ?>
              <span class="text-muted small">View only</span>
            <?php endif; ?>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="card-footer small text-muted">
    <i class="bi bi-info-circle text-primary"></i>
    These limits are enforced when a loan is issued and when a member submits a request.
    They are <strong>separate from Book Policies</strong> — a policy controls a book's rules
    (borrowable, loan days, penalty rate), while a Borrowing Limit controls how many active
    borrowed books a person may have.
  </div>
</div>

<div class="modal fade" id="policyModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="policyModalTitle">Add Policy</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="policy_id" id="policy_id">
        <div class="mb-3">
          <label class="form-label">Policy Name</label>
          <input type="text" name="policy_name" id="policy_name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Description</label>
          <textarea name="description" id="description" class="form-control" rows="2"></textarea>
        </div>
        <div class="form-check mb-3">
          <input type="checkbox" name="is_borrowable" id="is_borrowable" class="form-check-input" checked>
          <label class="form-check-label" for="is_borrowable">Is Borrowable</label>
        </div>
        <div class="row g-2">
          <div class="col-4">
            <label class="form-label">Loan Days</label>
            <input type="number" min="0" name="loan_days" id="loan_days" class="form-control">
          </div>
          <div class="col-4">
            <label class="form-label">Max Renewals</label>
            <input type="number" min="0" name="max_renewals" id="max_renewals" class="form-control" value="0">
          </div>
          <div class="col-4">
            <label class="form-label">Penalty Rate/Day</label>
            <input type="number" step="0.01" min="0" name="penalty_rate" id="penalty_rate" class="form-control" value="0">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="limitModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-people-fill"></i> Edit Borrowing Limit
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save_limit">
        <input type="hidden" name="borrower_type" id="bl_type">

        <div class="mb-3">
          <label class="form-label">Borrower Type</label>
          <input type="text" id="bl_type_display" class="form-control" readonly>
          <div class="form-text" id="bl_type_hint"></div>
        </div>

        <div class="mb-3">
          <label class="form-label">Maximum Active Borrowed Books</label>
          <input type="number" name="max_books" id="bl_max"
                 class="form-control" min="1" max="100" step="1" required>
          <div class="form-text">
            The borrower cannot start a new loan while they already hold this many active
            borrowed books. <strong>Range: 1–100.</strong>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Limit</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(p) {
  document.getElementById('policyModalTitle').textContent = p ? 'Edit Policy' : 'Add Policy';
  document.getElementById('policy_id').value = p ? p.PolicyID : '';
  document.getElementById('policy_name').value = p ? p.PolicyName : '';
  document.getElementById('description').value = p ? (p.Description || '') : '';
  document.getElementById('is_borrowable').checked = p ? !!Number(p.IsBorrowable) : true;
  document.getElementById('loan_days').value = p ? (p.LoanDays ?? '') : '';
  document.getElementById('max_renewals').value = p ? p.MaxRenewals : 0;
  document.getElementById('penalty_rate').value = p ? p.PenaltyRate : 0;
  new bootstrap.Modal(document.getElementById('policyModal')).show();
}

function openLimitModal(bl) {
  var isWalkIn = bl.BorrowerType === 'Walk-in';
  document.getElementById('bl_type').value = bl.BorrowerType;
  document.getElementById('bl_type_display').value = bl.BorrowerType;
  document.getElementById('bl_max').value = bl.MaxBooks;
  document.getElementById('bl_type_hint').textContent = isWalkIn
    ? 'A borrower without a portal login account.'
    : 'A registered borrower with a portal login account.';
  new bootstrap.Modal(document.getElementById('limitModal')).show();
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>