<?php
/**
 * MEMBER PORTAL CHROME — with notification bell.
 */
$page_title    = $page_title    ?? 'My Library';
$page_subtitle = $page_subtitle ?? '';
$member        = $member        ?? current_member();
$active        = current_page();

$css_v = @filemtime(__DIR__ . '/../assets/css/style.css') ?: time();

$initials = strtoupper(substr($member['FirstName'] ?? 'M', 0, 1) . substr($member['LastName'] ?? '', 0, 1));

$logoPath = __DIR__ . '/../assets/img/logo.png';
$logoSrc  = file_exists($logoPath) ? 'assets/img/logo.png' : '';

$notifications = member_notifications(current_member_id());
$readKeys      = $_SESSION['read_notifications'] ?? [];
$unreadCount   = 0;
foreach ($notifications as $n) {
    if (!in_array($n['key'] ?? '', $readKeys, true)) $unreadCount++;
}

$navGroups = [
    'Overview' => [
        'member_portal.php' => ['icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
    ],
    'Discover' => [
        'member_catalog.php'  => ['icon' => 'bi-search', 'label' => 'Browse Books'],
        'member_requests.php' => ['icon' => 'bi-inbox',  'label' => 'My Borrow Requests'],
    ],
    'My Library' => [
        'my_borrowed_books.php'   => ['icon' => 'bi-journal-bookmark',  'label' => 'Borrowed & Returned'],
        'member_fines.php'   => ['icon' => 'bi-cash-coin',         'label' => 'Fines & Payments'],
    ],
    'Account' => [
        'member_card.php'    => ['icon' => 'bi-person-vcard', 'label' => 'My Borrower Card'],
        'member_profile.php' => ['icon' => 'bi-person-circle', 'label' => 'My Profile'],
    ],
];

$borrowCheck = current_member_can_borrow();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-sidebar="expanded">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($page_title) ?> — Bibliophilia</title>

<script>
(function () {
  try {
    var t = localStorage.getItem('bibliophilia-theme');
    if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
    else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      document.documentElement.setAttribute('data-theme', 'dark');
    }
    var s = localStorage.getItem('bibliophilia-sidebar');
    if (s === 'collapsed' || s === 'expanded') document.documentElement.setAttribute('data-sidebar', s);
  } catch (e) {}
})();
</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="assets/css/style.css?v=<?= h($css_v) ?>" rel="stylesheet">

<style>
.notif-bell { position: relative; }
.notif-bell > .icon-btn { position: relative; }
.notif-bell .notif-badge {
  position: absolute; top: -2px; right: -2px;
  font-size: .62rem; padding: .2em .42em; line-height: 1;
  border: 2px solid var(--bs-body-bg, #fff);
}
.notif-dropdown {
  width: min(380px, 92vw); padding: 0; overflow: hidden;
  border-radius: .75rem; box-shadow: 0 12px 32px rgba(0,0,0,.15);
}
.notif-header {
  padding: .75rem 1rem; border-bottom: 1px solid var(--bs-border-color, #e5e7eb);
  background: var(--bs-tertiary-bg, #f8fafc);
}
.notif-list { max-height: 380px; overflow-y: auto; }
.notif-item {
  display: flex; gap: .75rem; padding: .75rem 1rem;
  border-bottom: 1px solid var(--bs-border-color, #eef1f5);
  color: inherit; text-decoration: none; transition: background .12s;
}
.notif-item:hover { background: var(--bs-tertiary-bg, #f8fafc); }
.notif-item.notif-unread { background: rgba(13,110,253,.06); }
.notif-item.notif-unread:hover { background: rgba(13,110,253,.1); }
.notif-icon {
  flex-shrink: 0; width: 34px; height: 34px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 50%; padding: 0; font-size: .95rem;
}
.notif-body { display: flex; flex-direction: column; gap: .15rem; min-width: 0; }
.notif-title { font-weight: 600; font-size: .85rem; }
.notif-msg   { font-size: .78rem; color: var(--bs-secondary-color, #6b7280); }
.notif-time  { font-size: .7rem; color: var(--bs-secondary-color, #9ca3af); }
.notif-empty { padding: 1.5rem 1rem; text-align: center; color: var(--bs-secondary-color, #6b7280); font-size: .85rem; }
.notif-footer {
  display: block; text-align: center; padding: .65rem; font-size: .8rem;
  font-weight: 600; background: var(--bs-tertiary-bg, #f8fafc);
  border-top: 1px solid var(--bs-border-color, #e5e7eb);
  color: inherit; text-decoration: none;
}
.notif-footer:hover { background: var(--bs-secondary-bg, #eef1f5); }
</style>
</head>
<body>
<a class="skip-link" href="#mainContent">Skip to main content</a>
<div class="app-wrapper">

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a href="member_portal.php" class="sidebar-brand">
        <?php if ($logoSrc): ?>
          <img src="<?= h($logoSrc) ?>" alt="Bibliophilia" class="brand-logo">
        <?php else: ?>
          <svg class="brand-logo" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M2 36 Q14 30 24 34 Q34 30 46 36 L46 40 Q34 34 24 38 Q14 34 2 40 Z" fill="#c9a86e"/>
            <path d="M4 32 Q14 27 23 32 L23 37 Q14 32 4 37 Z" fill="#e3cca0"/>
            <path d="M25 32 Q34 27 44 32 L44 37 Q34 32 25 37 Z" fill="#e3cca0"/>
            <rect x="15" y="8" width="4" height="22" fill="#e8eef5"/>
            <rect x="21" y="6" width="4" height="24" fill="#e8eef5"/>
            <rect x="27" y="10" width="4" height="20" fill="#e8eef5"/>
          </svg>
        <?php endif; ?>
        <span class="brand-text">
          <span class="brand-title">Bibliophilia</span>
          <span class="brand-sub">Borrower Portal</span>
        </span>
      </a>
    </div>

    <nav class="sidebar-nav" id="sidebarNav">
      <?php foreach ($navGroups as $groupName => $items): ?>
        <div class="nav-section"><?= h($groupName) ?></div>
        <?php foreach ($items as $file => $item): ?>
          <a href="<?= h($file) ?>"
             class="nav-link <?= $active === $file ? 'active' : '' ?>"
             data-label="<?= h($item['label']) ?>">
            <i class="bi <?= h($item['icon']) ?>"></i>
            <span class="nav-label"><?= h($item['label']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <button type="button" class="sidebar-toggle" data-action="toggle-sidebar"
              title="Collapse / expand sidebar" aria-label="Toggle sidebar">
        <i class="bi bi-chevron-double-left"></i>
      </button>
      <span class="sidebar-version">ID <?= h($member['MembershipNumber'] ?: '—') ?></span>
    </div>
  </aside>

  <div class="main-col">
    <header class="topbar">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggle" aria-label="Open menu">
        <i class="bi bi-list"></i>
      </button>
      <h1 class="topbar-title">
        <?= h($page_title) ?>
        <?php if ($page_subtitle): ?><small><?= h($page_subtitle) ?></small><?php endif; ?>
      </h1>

      <?= $topbar_center ?? '' ?>

      <div class="topbar-actions">
        <div class="dropdown notif-bell">
          <button class="icon-btn" type="button" data-bs-toggle="dropdown"
                  data-bs-auto-close="outside"
                  id="notifBellBtn"
                  aria-label="Notifications"
                  aria-haspopup="true" aria-expanded="false">
            <i class="bi bi-bell-fill"></i>
            <?php if ($unreadCount > 0): ?>
              <span class="notif-badge badge rounded-pill text-bg-danger">
                <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
              </span>
            <?php endif; ?>
          </button>
          <div class="dropdown-menu dropdown-menu-end notif-dropdown" aria-labelledby="notifBellBtn">
            <div class="notif-header d-flex justify-content-between align-items-center">
              <strong class="small">Notifications</strong>
              <span class="badge text-bg-light border small"><?= count($notifications) ?></span>
            </div>
            <div class="notif-list">
              <?php if (!$notifications): ?>
                <div class="notif-empty">
                  <i class="bi bi-check2-circle d-block mb-1" style="font-size:1.5rem;"></i>
                  You're all caught up.
                </div>
              <?php else: ?>
                <?php foreach (array_slice($notifications, 0, 6) as $n):
                  $isUnread = !in_array($n['key'] ?? '', $readKeys, true);
                ?>
                  <a href="member_notifications.php" class="notif-item <?= $isUnread ? 'notif-unread' : '' ?>">
                    <span class="notif-icon badge text-bg-<?= h($n['severity']) ?>">
                      <i class="bi <?= h($n['icon']) ?>"></i>
                    </span>
                    <span class="notif-body">
                      <span class="notif-title"><?= h($n['title']) ?></span>
                      <span class="notif-msg"><?= h($n['message']) ?></span>
                      <span class="notif-time"><?= h(fmt_datetime($n['date'])) ?></span>
                    </span>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <a href="member_notifications.php" class="notif-footer">View All Notifications →</a>
          </div>
        </div>

        <button type="button" class="icon-btn theme-toggle" data-action="toggle-theme"
                title="Toggle light / dark mode" aria-label="Toggle theme">
          <i class="bi bi-moon-stars-fill"></i>
          <i class="bi bi-sun-fill"></i>
        </button>

        <div class="topbar-user dropdown">
          <button class="btn d-flex align-items-center gap-2 text-start" data-bs-toggle="dropdown">
            <span class="avatar"><?= h($initials) ?></span>
            <span class="d-none d-sm-flex flex-column lh-sm">
              <span class="fw-semibold small"><?= h($member['FirstName'] . ' ' . $member['LastName']) ?></span>
              <span class="small text-muted" style="font-size:.72rem;">ID <?= h($member['MembershipNumber'] ?: '—') ?></span>
            </span>
            <i class="bi bi-chevron-down small text-muted"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="d-sm-none px-3 py-2">
              <div class="small fw-semibold"><?= h($member['FirstName'] . ' ' . $member['LastName']) ?></div>
              <div class="small text-muted">ID <?= h($member['MembershipNumber'] ?: '—') ?></div>
            </li>
            <li class="d-sm-none"><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="member_profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
          </ul>
        </div>
      </div>
    </header>

    <main class="content" id="mainContent" tabindex="-1">
      <?php $__flashes = get_flashes(); ?>
      <?php if ($__flashes): ?>
        <div id="flash-fallback" class="d-none">
          <?php foreach ($__flashes as $f): ?>
            <div class="alert alert-<?= h($f['type']) ?> alert-dismissible fade show" role="alert">
              <i class="bi bi-<?= $f['type'] === 'success' ? 'check-circle-fill' : ($f['type'] === 'danger' ? 'exclamation-octagon-fill' : 'info-circle-fill') ?> me-1"></i>
              <?= h($f['message']) ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endforeach; ?>
        </div>
        <script type="application/json" id="flash-data"><?= json_encode(
          array_map(fn($f) => ['type' => $f['type'], 'message' => $f['message'], 'modal' => $f['modal'] ?? false], $__flashes),
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?></script>
      <?php endif; ?>

      <?php if (!$borrowCheck['ok']): ?>
        <div class="alert alert-warning d-flex gap-2 align-items-start">
          <i class="bi bi-exclamation-triangle-fill mt-1"></i>
          <div>
            <strong>You cannot request books right now.</strong><br>
            <span class="small"><?= h($borrowCheck['reason']) ?></span>
          </div>
        </div>
      <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var bell = document.getElementById('notifBellBtn');
  if (!bell) return;
  bell.addEventListener('shown.bs.dropdown', function () {
    fetch('member_mark_notifications_read.php', {
      method: 'POST',
      headers: {'X-Requested-With': 'fetch'},
      credentials: 'same-origin'
    }).catch(function () {});
    var badge = bell.querySelector('.notif-badge');
    if (badge) badge.remove();
    document.querySelectorAll('.notif-item.notif-unread')
      .forEach(function (el) { el.classList.remove('notif-unread'); });
  });
});
</script>