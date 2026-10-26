<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('record_payments');
$page_title = 'Payments';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'pay') {
        $fineId       = $_POST['fine_id'] ?? '';
        $amountTender = $_POST['amount_tendered'] ?? '';
        $method       = $_POST['payment_method'] ?? '';
        $reference    = mb_substr(trim((string)($_POST['payment_reference'] ?? '')), 0, 100);

        $fine = fetch_one("SELECT * FROM fine WHERE FineID = ?", [$fineId]);
        if (!$fine) {
            flash('danger', 'Fine not found.');
        } elseif ($amountTender === '' || !is_numeric($amountTender) || (float)$amountTender <= 0) {
            flash('danger', 'Enter the cash received (or amount paid).');
        } elseif ($method === '') {
            flash('danger', 'Select a payment method.');
        } elseif (in_array($method, ['GCash', 'Bank Transfer'], true) && $reference === '') {
            flash('danger', 'Enter the online payment reference for ' . $method . '.');
        } else {
            $paidSoFar = (float)fetch_value(
                "SELECT COALESCE(SUM(PaidAmount),0) FROM payment WHERE FineID = ?",
                [$fineId]
            );
            $outstanding = (float)$fine['FineAmount'] - $paidSoFar;

            if ($outstanding <= 0) {
                flash('danger', 'This fine has already been settled in full.');
                redirect('payments.php');
            }

            if ($method === 'Replacement') {
                if (abs((float)$amountTender - $outstanding) > 0.001) {
                    flash('danger', sprintf(
                        'Replacement settlement must equal the full outstanding balance of %s.',
                        money($outstanding)
                    ));
                    redirect('payments.php?fine_id=' . urlencode($fineId));
                }
                $recordAmount = $outstanding;
                $change       = 0.0;
            } elseif ($method === 'Cash') {
                if ((float)$amountTender < $outstanding - 0.001) {
                    flash('danger', sprintf(
                        'Cash received (%s) is less than the balance due (%s).',
                        money($amountTender), money($outstanding)
                    ));
                    redirect('payments.php?fine_id=' . urlencode($fineId));
                }
                $recordAmount = $outstanding;
                $change       = (float)$amountTender - $outstanding;
            } else {
                if ((float)$amountTender > $outstanding + 0.001) {
                    flash('danger', sprintf(
                        'Payment exceeds the outstanding balance of %s.',
                        money($outstanding)
                    ));
                    redirect('payments.php?fine_id=' . urlencode($fineId));
                }
                $recordAmount = (float)$amountTender;
                $change       = 0.0;
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                run("INSERT INTO payment (FineID, PaidAmount, PaymentMethod, PaymentReference) VALUES (?, ?, ?, ?)",
                    [$fineId, $recordAmount, $method, $reference !== '' ? $reference : null]);

                $totalPaid = fetch_value(
                    "SELECT COALESCE(SUM(PaidAmount),0) FROM payment WHERE FineID = ?",
                    [$fineId]
                );
                if ((float)$totalPaid >= (float)$fine['FineAmount'] - 0.001) {
                    run("UPDATE fine SET PaymentStatus = 'Paid' WHERE FineID = ?", [$fineId]);
                }
                $pdo->commit();

                log_activity('Payment', 'Payments',
                    'Recorded payment of ' . money($recordAmount) . " on Fine #$fineId ($method)",
                    $fineId);

                $msg = 'Payment of ' . money($recordAmount) . ' recorded.';
                if ($method === 'Cash' && $change > 0) {
                    $msg .= ' Change given: ' . money($change) . '.';
                }
                flash('success', $msg);
            } catch (Exception $e) {
                $pdo->rollBack();
                flash('danger', 'Could not record payment: ' . $e->getMessage());
            }
        }
        redirect('payments.php');
    }
}

$prefillFineId = $_GET['fine_id'] ?? null;
$prefillFine = $prefillFineId ? fetch_one(
    "SELECT f.*, b.Title, m.FirstName, m.LastName,
        (SELECT COALESCE(SUM(p.PaidAmount),0) FROM payment p WHERE p.FineID = f.FineID) AS PaidSoFar
     FROM fine f
     JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
     JOIN book_copy bc ON bc.CopyID = ld.CopyID
     JOIN book b ON b.BookID = bc.BookID
     JOIN loan l ON l.LoanID = ld.LoanID
     JOIN user_account m ON m.UserID = l.BorrowerUserID
     WHERE f.FineID = ?", [$prefillFineId]
) : null;

$unpaidFines = fetch_all(
    "SELECT f.FineID, f.FineAmount, b.Title, m.FirstName, m.LastName,
            (SELECT COALESCE(SUM(p.PaidAmount),0) FROM payment p WHERE p.FineID = f.FineID) AS PaidSoFar
     FROM fine f
     JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
     JOIN book_copy bc ON bc.CopyID = ld.CopyID
     JOIN book b ON b.BookID = bc.BookID
     JOIN loan l ON l.LoanID = ld.LoanID
     JOIN user_account m ON m.UserID = l.BorrowerUserID
     WHERE f.PaymentStatus = 'Unpaid'
     ORDER BY f.FineID DESC"
);

$search=mb_substr(trim((string)($_GET['q']??'')),0,100);$payWhere='';$payParams=[];
if($search!==''){$payWhere="WHERE CAST(p.PaymentID AS CHAR) LIKE ? OR b.Title LIKE ? OR bc.AccessionNumber LIKE ? OR CONCAT(m.FirstName,' ',m.LastName) LIKE ?";$payParams=array_fill(0,4,'%'.$search.'%');}
$totalPayments=(int)fetch_value("SELECT COUNT(*) FROM payment p JOIN fine f ON f.FineID=p.FineID JOIN loan_detail ld ON ld.LoanDetailID=f.LoanDetailID JOIN book_copy bc ON bc.CopyID=ld.CopyID JOIN book b ON b.BookID=bc.BookID JOIN loan l ON l.LoanID=ld.LoanID JOIN user_account m ON m.UserID=l.BorrowerUserID $payWhere",$payParams);
$pgPay = pagination_calc($totalPayments, 10, pagination_page('pp'));

$payments = fetch_all(
    "SELECT p.*, f.Reason, b.Title, m.FirstName, m.LastName
     FROM payment p
     JOIN fine f ON f.FineID = p.FineID
     JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
     JOIN book_copy bc ON bc.CopyID = ld.CopyID
     JOIN book b ON b.BookID = bc.BookID
     JOIN loan l ON l.LoanID = ld.LoanID
     JOIN user_account m ON m.UserID = l.BorrowerUserID
     $payWhere
     ORDER BY p.PaymentID DESC
     LIMIT {$pgPay['per_page']} OFFSET {$pgPay['offset']}",$payParams
);

require __DIR__ . '/includes/layout_top.php';
?>

<form method="get" class="page-toolbar d-flex gap-2"><input type="search" class="form-control" name="q" value="<?= h($search) ?>" placeholder="Payment, Borrower, title or accession" aria-label="Search payments"><button class="btn btn-primary">Search</button><a href="payments.php" class="btn btn-outline-secondary">Clear</a></form><div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Record a Payment</div>
      <div class="card-body">
        <form method="post" id="payForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="pay">

          <!-- FINE -->
          <div class="mb-3">
            <label class="form-label">Fine</label>
            <select name="fine_id" id="fineSelect" class="form-select" required
                    onchange="if(this.value) window.location='payments.php?fine_id='+this.value;">
              <option value="">-- Select Unpaid Fine --</option>
              <?php foreach ($unpaidFines as $f): ?>
                <?php $bal = max(0, (float)$f['FineAmount'] - (float)$f['PaidSoFar']); ?>
                <option value="<?= h($f['FineID']) ?>"
                        data-balance="<?= h($bal) ?>"
                        <?= (string)$prefillFineId === (string)$f['FineID'] ? 'selected' : '' ?>>
                  #<?= h($f['FineID']) ?> — <?= h($f['FirstName'] . ' ' . $f['LastName']) ?> —
                  <?= h($f['Title']) ?> (<?= money($bal) ?> outstanding)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- FINE DETAIL -->
          <?php if ($prefillFine): ?>
            <?php $balance = max(0, (float)$prefillFine['FineAmount'] - (float)$prefillFine['PaidSoFar']); ?>
            <div class="alert alert-light border small mb-3">
              <div><strong>Member:</strong> <?= h($prefillFine['FirstName'] . ' ' . $prefillFine['LastName']) ?></div>
              <div><strong>Book:</strong> <?= h($prefillFine['Title']) ?></div>
              <div><strong>Reason:</strong> <?= h($prefillFine['Reason']) ?></div>
              <div><strong>Fine Amount:</strong> <?= money($prefillFine['FineAmount']) ?></div>
              <div><strong>Already Paid:</strong> <?= money($prefillFine['PaidSoFar']) ?></div>
              <hr class="my-2">
              <div class="fs-5">
                <strong>Balance Due:</strong>
                <span class="text-danger"><?= money($balance) ?></span>
              </div>
            </div>
          <?php endif; ?>

          <!-- PAYMENT METHOD -->
          <div class="mb-3">
            <label class="form-label">Payment Method</label>
            <select name="payment_method" id="payMethod" class="form-select" required>
              <option value="">-- Select --</option>
              <option value="Cash">Cash</option>
              <option value="GCash">GCash</option>
              <option value="Bank Transfer">Bank Transfer</option>
              <option value="Replacement">Book replaced</option>
              <option value="Other">Other</option>
            </select>
          </div>

          <div class="mb-3 d-none" id="referenceWrap">
            <label class="form-label" for="paymentReference">Online Payment Reference</label>
            <input type="text" name="payment_reference" id="paymentReference" class="form-control"
                   maxlength="100" autocomplete="off" aria-describedby="referenceHelp">
            <div class="form-text" id="referenceHelp">Required for GCash and bank transfers. Enter the transaction/reference number shown by the payment provider.</div>
          </div>

          <!-- AMOUNT -->
          <div class="mb-3">
            <label class="form-label" id="amountLabel">Amount (₱)</label>
            <input type="number" step="0.01" min="0.01"
                   name="amount_tendered" id="amountInput"
                   class="form-control" required
                   placeholder="0.00">
            <div class="form-text" id="amountHint">
              Select a payment method to see what to enter. For a replacement, enter the full balance after the new copy has been received and verified.
            </div>
          </div>

          <!-- CHANGE BOX (only for Cash) -->
          <div id="changeBox" class="alert alert-success d-none mb-3">
            <div class="d-flex justify-content-between">
              <span>Cash Received:</span>
              <strong id="cashReceived">₱0.00</strong>
            </div>
            <div class="d-flex justify-content-between">
              <span>Balance Due:</span>
              <strong id="cashBalance">₱0.00</strong>
            </div>
            <hr class="my-2">
            <div class="d-flex justify-content-between fs-5">
              <strong>Change:</strong>
              <strong class="text-success" id="cashChange">₱0.00</strong>
            </div>
          </div>

          <!-- SHORTFALL WARNING -->
          <div id="shortfallBox" class="alert alert-danger d-none mb-3">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <strong>Cash received is less than the balance due.</strong>
            Customer still owes <strong id="shortfallAmount">₱0.00</strong>.
          </div>

          <button type="submit" class="btn btn-primary w-100" id="payBtn">Record Payment</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Payment History</div>
      <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pgPay) ?></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr><th>#</th><th>Borrower</th><th>Book / Reason</th><th>Amount</th><th>Method / Reference</th><th>Date</th></tr>
          </thead>
          <tbody>
            <?php if (!$payments): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No payments recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($payments as $p): ?>
              <tr>
                <td>#<?= h($p['PaymentID']) ?></td>
                <td><?= h($p['FirstName'] . ' ' . $p['LastName']) ?></td>
                <td class="small"><?= h($p['Title']) ?>
                  <div class="text-muted"><?= h($p['Reason']) ?></div>
                </td>
                <td><?= money($p['PaidAmount']) ?></td>
                <td><?= h($p['PaymentMethod']) ?><?php if (!empty($p['PaymentReference'])): ?><div class="small text-muted">Ref: <?= h($p['PaymentReference']) ?></div><?php endif; ?></td>
                <td><?= fmt_datetime($p['PaymentDate']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pgPay['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($pgPay, $_GET, 'pp') ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  var fineSelect   = document.getElementById('fineSelect');
  var amountInput  = document.getElementById('amountInput');
  var methodSel    = document.getElementById('payMethod');
  var changeBox    = document.getElementById('changeBox');
  var shortfallBox = document.getElementById('shortfallBox');
  var labelText    = document.getElementById('amountLabel');
  var hintText     = document.getElementById('amountHint');
  var payBtn       = document.getElementById('payBtn');
  var form         = document.getElementById('payForm');
  var referenceWrap = document.getElementById('referenceWrap');
  var referenceInput = document.getElementById('paymentReference');

  function balance() {
    if (!fineSelect) return 0;
    var opt = fineSelect.options[fineSelect.selectedIndex];
    if (!opt || !opt.dataset.balance) return 0;
    return parseFloat(opt.dataset.balance) || 0;
  }

  function fmtMoney(n) {
    return '₱' + (Number(n) || 0).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function recalc() {
    var method = methodSel ? methodSel.value : '';
    var bal    = balance();
    var tender = parseFloat(amountInput.value) || 0;
    var online = method === 'GCash' || method === 'Bank Transfer';
    referenceWrap.classList.toggle('d-none', !online);
    referenceInput.required = online;

    if (method === 'Cash') {
      labelText.textContent = 'Cash Received (₱)';
      hintText.innerHTML =
        'Enter the amount the customer physically handed over. ' +
        'The system computes change automatically and records the full outstanding balance.';

      var change = Math.max(0, tender - bal);
      document.getElementById('cashReceived').textContent = fmtMoney(tender);
      document.getElementById('cashBalance').textContent  = fmtMoney(bal);
      document.getElementById('cashChange').textContent   = fmtMoney(change);
      changeBox.classList.remove('d-none');

      if (tender > 0 && tender + 0.001 < bal) {
        document.getElementById('shortfallAmount').textContent = fmtMoney(bal - tender);
        shortfallBox.classList.remove('d-none');
        payBtn.disabled = true;
      } else {
        shortfallBox.classList.add('d-none');
        payBtn.disabled = false;
      }
    } else if (method !== '') {
      labelText.textContent = 'Amount Paid (₱)';
      hintText.innerHTML    = 'Enter the exact amount being paid. Cannot exceed the outstanding balance.';
      changeBox.classList.add('d-none');
      shortfallBox.classList.add('d-none');
      payBtn.disabled = false;
    } else {
      labelText.textContent = 'Amount (₱)';
      hintText.innerHTML    = 'Select a payment method to see what to enter.';
      changeBox.classList.add('d-none');
      shortfallBox.classList.add('d-none');
      payBtn.disabled = false;
    }
  }

  if (amountInput) amountInput.addEventListener('input', recalc);
  if (methodSel) methodSel.addEventListener('change', function () {
    amountInput.value = '';
    recalc();
  });

  recalc();

  if (form) {
    form.addEventListener('submit', function () {
      payBtn.disabled = true;
      payBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';
    });
  }
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>