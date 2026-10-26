<?php
/* Library entrance screen: borrowers scan their school ID (keyboard-style scanner) when they walk in. */
require_once __DIR__ . '/includes/kiosk_boot.php';
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$problem = !ENTRY_GATE_ENABLED ? 'The entrance scanner is switched off.' : (kiosk_access_problem() ?: (!entry_schema_ready() ? 'The library system needs a one-time database update (database/migrate_entry_and_permission.sql).' : null));
?><!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
  <title>Library Entrance</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css?v=<?= h(@filemtime(__DIR__ . '/assets/css/style.css') ?: 1) ?>" rel="stylesheet">
  <link href="assets/css/kiosk.css?v=<?= h(@filemtime(__DIR__ . '/assets/css/kiosk.css') ?: 1) ?>" rel="stylesheet">
</head>
<body class="kiosk-body entry-body">
<header class="kiosk-top"><div class="kiosk-top-in">
  <div class="kiosk-brand"><i class="bi bi-door-open"></i><div><h1>Library Entrance</h1><p>Scan your borrower card to enter.</p></div></div>
  <span class="kiosk-mode"><span class="dot"></span> <span id="clock"></span></span>
</div></header>

<main class="kiosk-wrap">
<?php if ($problem): ?>
  <div class="kcard text-center p-5"><i class="bi bi-tools display-4 text-muted"></i>
    <h2 class="h4 mt-3">Entrance scanner unavailable</h2><p class="text-muted mb-0">Please see the librarian at the desk. <?= h($problem) ?></p></div>
<?php else: ?>
  <section class="kcard text-center entry-card" id="scanCard">
    <h2 class="h3 fw-bold mb-1">Scan your borrower card</h2>
    <p class="text-muted mb-3" id="scanHint"><?= ENTRY_OCR_ENABLED ? 'Hold your ID steady, upright and well lit inside the frame — the barcode, QR code or printed ID number is read automatically.' : 'Hold the QR code on your Borrower Card (shown in your borrower portal) inside the frame.' ?> Your entry is recorded with the date and time.</p>

    <div id="camWrap" class="cam-wrap mx-auto" hidden>
      <div id="camView" aria-label="Camera preview"></div>
      <div class="cam-bar">
        <select id="camSel" class="form-select form-select-sm" aria-label="Choose camera" hidden></select>
        <button type="button" class="btn btn-sm btn-light" id="camToggle"><i class="bi bi-camera-video-off"></i> Turn camera off</button>
      </div>
    </div>
    <div id="camMsg" class="cam-msg" role="status" hidden></div>
<?php if (ENTRY_OCR_ENABLED): ?>
    <div id="ocrBar" class="mt-2" hidden>
      <div id="ocrStatus" class="small text-muted mb-2" role="status"></div>
      <label class="btn btn-outline-secondary btn-sm mb-0"><i class="bi bi-image"></i> Take a photo of the ID instead
        <input type="file" id="idPhoto" accept="image/*" capture="environment" hidden></label>
    </div>
<?php endif; ?>
    <button type="button" class="btn btn-primary btn-lg kbtn mt-2" id="camStart" hidden><i class="bi bi-camera-video"></i> Turn camera on</button>

    <div class="entry-or"><span>or use a handheld scanner / type your ID</span></div>
    <form id="scanForm" autocomplete="off" class="mx-auto" style="max-width:460px">
      <div class="kfield"><i class="bi bi-person-badge"></i>
        <input id="scanId" type="text" class="form-control form-control-lg" maxlength="40" autocomplete="off" autocapitalize="characters"
               spellcheck="false" placeholder="Waiting for ID scan…" aria-label="Borrower ID"></div>
    </form>
    <div class="small text-muted mt-3"><i class="bi bi-info-circle"></i> Entering the library does not give permission to use or borrow a book — ask the librarian.</div>
  </section>

  <section class="kcard text-center entry-result" id="resultCard" hidden aria-live="assertive"></section>
<?php endif; ?>
</main>
<?php if (!$problem): ?>
<script>window.ENTRY = <?= json_encode(['csrf' => csrf_token(), 'reset' => (int)ENTRY_RESET_SECONDS, 'camera' => (bool)ENTRY_CAMERA_ENABLED, 'ocr' => (bool)(ENTRY_CAMERA_ENABLED && ENTRY_OCR_ENABLED)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php if (ENTRY_CAMERA_ENABLED): ?><script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script><?php endif; ?>
<?php if (ENTRY_CAMERA_ENABLED): ?><script src="https://cdn.jsdelivr.net/npm/zxing-wasm@2.1.0/dist/iife/reader/index.js"></script><?php endif; ?>
<?php if (ENTRY_CAMERA_ENABLED && ENTRY_OCR_ENABLED): ?><script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js"></script><?php endif; ?>
<script src="assets/js/entry.js?v=<?= h(@filemtime(__DIR__ . '/assets/js/entry.js') ?: 1) ?>"></script>
<?php endif; ?>
<script>(function(){var c=document.getElementById('clock');function t(){c.textContent=new Date().toLocaleString([], {weekday:'short',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});}t();setInterval(t,15000);})();</script>
</body>
</html>
