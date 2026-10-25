<?php
/**
 * STAFF CHROME
 * ------------
 * Grouped sidebar with Bibliophilia logo. Light/dark aware.
 */
$page_title    = $page_title    ?? 'Dashboard';
$page_subtitle = $page_subtitle ?? '';
$staff  = current_staff();
$active = current_page();

$css_v = @filemtime(__DIR__ . '/../assets/css/style.css') ?: time();
$js_v  = @filemtime(__DIR__ . '/../assets/js/script.js')  ?: time();

$initials = '';
foreach (preg_split('/\s+/', trim($staff['Name'] ?? '')) as $part) {
    if ($part !== '') $initials .= strtoupper($part[0]);
}
$initials = substr($initials, 0, 2) ?: 'S';

$logoPath = __DIR__ . '/../assets/img/logo.png';
$logoSrc  = file_exists($logoPath) ? 'assets/img/logo.png' : '';

/* Grouped navigation */
$navGroups = [
    'Overview' => [
        'index.php' => ['icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
    ],
    'Catalog' => [
        'books.php'       => ['icon' => 'bi-book',              'label' => 'Books'],
        'book_copies.php' => ['icon' => 'bi-journal-bookmark',  'label' => is_admin() ? 'Book Copies' : 'Availability'],
        'authors.php'     => ['icon' => 'bi-person-lines-fill', 'label' => 'Authors',    'cap' => 'manage_authors'],
        'categories.php'  => ['icon' => 'bi-tags',              'label' => 'Categories', 'cap' => 'manage_categories'],
        'publishers.php'  => ['icon' => 'bi-building',          'label' => 'Publishers', 'cap' => 'manage_publishers'],
        'sections.php'    => ['icon' => 'bi-diagram-3',         'label' => 'Sections',   'cap' => 'manage_sections'],
        'shelves.php'     => ['icon' => 'bi-grid-3x3-gap',      'label' => 'Shelves',    'cap' => 'manage_shelves'],
        'policies.php'    => ['icon' => 'bi-sliders',           'label' => 'Policies',   'cap' => 'manage_settings'],
    ],
    'Circulation' => [
        'library_visits.php' => ['icon' => 'bi-door-open',          'label' => 'Library Entry', 'cap' => 'view_library_entries'],
        'use_requests.php'   => ['icon' => 'bi-patch-check',        'label' => 'Borrow Desk', 'cap' => 'decide_use_requests'],
        'borrow_requests.php' => ['icon' => 'bi-inbox',             'label' => 'Borrow Requests'],
        'loans.php'          => ['icon' => 'bi-arrow-right-square', 'label' => 'Loans',          'cap' => 'process_loans'],
        'returns.php'        => ['icon' => 'bi-arrow-left-square',  'label' => 'Returns',        'cap' => 'process_returns'],
    ],
    'Members' => [
        'members.php' => ['icon' => 'bi-people', 'label' => 'Students', 'cap' => 'manage_members'],
        'donors.php'  => ['icon' => 'bi-gift',   'label' => 'Donors',  'cap' => 'manage_donors'],
    ],
    'Finance' => [
        'fines.php'    => ['icon' => 'bi-exclamation-circle', 'label' => 'Fines',    'cap' => 'manage_finance'],
        'payments.php' => ['icon' => 'bi-credit-card',        'label' => 'Payments', 'cap' => 'record_payments'],
    ],
    'Administration' => [
        'staff.php'        => ['icon' => 'bi-person-badge',  'label' => 'Staff',        'cap' => 'manage_staff'],
        'roles.php'        => ['icon' => 'bi-shield-lock',   'label' => 'Roles',        'cap' => 'manage_roles'],
        'activity_log.php' => ['icon' => 'bi-clock-history', 'label' => 'Activity Log', 'cap' => 'view_activity_log'],
    ],
    'Insights' => [
        'reports.php' => ['icon' => 'bi-bar-chart-line', 'label' => is_admin() ? 'Reports' : 'Relevant Reports'],
    ],
];

/* The Library Desk is the one form for borrowing AND reading here, so it is always in the menu. */
$navGroups['Circulation']['desk_form.php'] = ['icon' => 'bi-person-vcard', 'label' => 'Library Desk', 'cap' => 'process_in_library_use'];
if (IN_LIBRARY_USE_ENABLED) {
    $navGroups['Circulation']['in_library_use.php'] = ['icon' => 'bi-book-half', 'label' => 'Read-Here Books', 'cap' => 'process_in_library_use'];
}
foreach ($navGroups as $groupName => $items) {
    foreach ($items as $file => $item) {
        if (isset($item['cap']) && !has_permission($item['cap'])) {
            unset($navGroups[$groupName][$file]);
        }
    }
    if (empty($navGroups[$groupName])) unset($navGroups[$groupName]);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($page_title) ?> — Bibliophilia</title>

<!-- Prevent theme flash: apply saved preference BEFORE paint -->
<script>
(function () {
  try {
    var t = localStorage.getItem('bibliophilia-theme');
    if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
    else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      document.documentElement.setAttribute('data-theme', 'dark');
    }
  } catch (e) {}
})();
</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="assets/css/style.css?v=<?= h($css_v) ?>" rel="stylesheet">
</head>
<body>
<div class="app-wrapper">

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a href="index.php" class="sidebar-brand">
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
          <span class="brand-sub">Library System</span>
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

      <div class="topbar-actions">
        <button type="button" class="icon-btn theme-toggle" data-action="toggle-theme"
                title="Toggle light / dark mode" aria-label="Toggle theme">
          <i class="bi bi-moon-stars-fill"></i>
          <i class="bi bi-sun-fill"></i>
        </button>

        <div class="topbar-user dropdown">
          <button class="btn d-flex align-items-center gap-2 text-start" data-bs-toggle="dropdown">
            <span class="avatar"><?= h($initials) ?></span>
            <span class="d-none d-sm-flex flex-column lh-sm">
              <span class="fw-semibold small"><?= h($staff['Name']) ?></span>
              <span class="small text-muted" style="font-size:.72rem;"><?= h($staff['Role'] ?: 'Unassigned') ?></span>
            </span>
            <i class="bi bi-chevron-down small text-muted"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="d-sm-none px-3 py-2">
              <div class="small fw-semibold"><?= h($staff['Name']) ?></div>
              <div class="small text-muted"><?= h($staff['Role'] ?: 'Unassigned') ?></div>
            </li>
            <li class="d-sm-none"><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
          </ul>
        </div>
      </div>
    </header>

    <main class="content">
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
          array_map(fn($f) => ['type' => $f['type'], 'message' => $f['message'], 'modal' => $f['modal'] ?? true], $__flashes),
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?></script>
      <?php endif; ?>