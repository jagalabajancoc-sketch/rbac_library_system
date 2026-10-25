<?php
require_once __DIR__ . '/includes/member_auth.php';

$page_title = 'My Library Card';
$member     = current_member();

$isApproved  = ($member['VerificationStatus'] ?? 'Approved') === 'Approved';
$cardNumber  = $member['MembershipNumber'] ?? null;
$fullName    = trim(($member['FirstName'] ?? '') . ' ' . ($member['LastName'] ?? ''));

require __DIR__ . '/includes/member_layout_top.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-7 col-xl-6">

    <?php if (!$isApproved): ?>
      <div class="card border-warning">
        <div class="card-body text-center p-4">
          <i class="bi bi-hourglass-split text-warning" style="font-size:3rem;"></i>
          <h4 class="mt-3">Student Card Not Yet Issued</h4>
          <p class="text-muted mb-0">
            Your account is <strong><?= h($member['VerificationStatus'] ?? 'Pending') ?></strong>.
            Please visit the library and present a valid ID. Once staff verify your account,
            your digital student card will appear here.
          </p>
        </div>
      </div>
    <?php else: ?>

      <div class="card" style="background:linear-gradient(135deg, #0f2942 0%, #0b5344 100%); color:#fff; border:0; box-shadow:0 12px 32px rgba(10,26,46,.22);">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <div style="letter-spacing:.15em; font-size:.7rem; opacity:.7; text-transform:uppercase;">Bibliophilia Library</div>
              <div style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.4rem; font-weight:800;">Student Card</div>
            </div>
            <i class="bi bi-book-half" style="font-size:2rem; opacity:.35;"></i>
          </div>

          <div class="text-center my-4" style="background:#fff; border-radius:12px; padding:1.2rem;">
            <div id="qrcode" style="display:inline-block; line-height:0;"></div>
            <div class="mt-3" style="color:#0a1a2e;">
              <div style="font-size:.7rem; text-transform:uppercase; letter-spacing:.1em; color:#6b7280;">Student ID</div>
              <div class="fw-bold" style="font-size:1.35rem; font-family:monospace; letter-spacing:.05em;">
                <?= h($cardNumber) ?>
              </div>
            </div>
          </div>

          <div class="small" style="opacity:.85;">
            <i class="bi bi-info-circle"></i>
            If a scanner is unavailable, give this Student ID to the librarian.
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-body">
          <dl class="row mb-0 small">
            <dt class="col-5 text-muted">Name</dt>
            <dd class="col-7 fw-semibold"><?= h($fullName) ?></dd>

            <dt class="col-5 text-muted">Student ID</dt>
            <dd class="col-7"><code><?= h($cardNumber) ?></code></dd>

            <dt class="col-5 text-muted">Verification</dt>
            <dd class="col-7"><?= verification_badge($member['VerificationStatus'] ?? 'Approved') ?></dd>

            <dt class="col-5 text-muted">Account Status</dt>
            <dd class="col-7"><?= membership_badge($member['MembershipStatus']) ?></dd>

            <dt class="col-5 text-muted">Registered</dt>
            <dd class="col-7"><?= fmt_date($member['RegistrationDate']) ?></dd>
          </dl>
        </div>
      </div>

    <?php endif; ?>
  </div>
</div>

<?php if ($isApproved && $cardNumber): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  try {
    var qr = qrcode(0, 'M');
    qr.addData(<?= json_encode($cardNumber, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    qr.make();
    document.getElementById('qrcode').innerHTML = qr.createSvgTag({ cellSize: 5, margin: 3 });
  } catch (e) {
    document.getElementById('qrcode').innerHTML =
      '<div class="text-muted small">QR unavailable</div>';
  }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/member_layout_bottom.php'; ?>