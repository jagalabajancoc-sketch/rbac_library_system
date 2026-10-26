<?php
require_once __DIR__ . '/includes/member_auth.php';

$page_title = 'Notifications';
$member     = current_member();
$memberId   = current_member_id();

$notifications = member_notifications($memberId);
$allKeys = array_values(array_filter(array_map(fn($n) => $n['key'] ?? null, $notifications)));
$_SESSION['read_notifications'] = array_values(array_unique(array_merge(
    $_SESSION['read_notifications'] ?? [],
    $allKeys
)));

require __DIR__ . '/includes/member_layout_top.php';
?>

<div class="page-toolbar">
  <div class="text-muted small">
    Notifications are generated from your borrowing records, requests, and fines.
    They disappear automatically when the underlying condition resolves.
  </div>
  <a href="member_portal.php" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Back to Dashboard
  </a>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-bell-fill text-primary me-1"></i> All Notifications</span>
    <span class="badge text-bg-light border"><?= count($notifications) ?></span>
  </div>

  <div class="list-group list-group-flush">
    <?php if (!$notifications): ?>
      <div class="text-center text-muted py-5">
        <i class="bi bi-check2-circle d-block mb-2" style="font-size:2rem;opacity:.5;"></i>
        Nothing to see here. No overdue items, no unpaid fines, no recent updates.
      </div>
    <?php endif; ?>

    <?php foreach ($notifications as $n): ?>
      <div class="list-group-item d-flex align-items-start gap-3 py-3">
        <span class="badge text-bg-<?= h($n['severity']) ?> rounded-circle flex-shrink-0"
              style="width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;padding:0;font-size:1rem;">
          <i class="bi <?= h($n['icon']) ?>"></i>
        </span>
        <div class="flex-grow-1 min-w-0">
          <div class="fw-semibold small"><?= h($n['title']) ?></div>
          <div class="text-muted small"><?= h($n['message']) ?></div>
          <div class="text-muted" style="font-size:.72rem;"><?= h(fmt_datetime($n['date'])) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/member_layout_bottom.php'; ?>