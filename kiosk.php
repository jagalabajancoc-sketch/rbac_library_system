<?php
/* Student self-service kiosk — standalone page, no staff chrome, no staff session. */
require_once __DIR__ . '/includes/kiosk_boot.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
$problem = kiosk_access_problem();
$config = [
    'csrf'       => csrf_token(),
    'idle'       => (int)KIOSK_IDLE_SECONDS,
    'doneSecs'   => (int)KIOSK_DONE_SECONDS,
    'locations'  => array_values(KIOSK_LOCATIONS),
    'borrowOnly' => !IN_LIBRARY_USE_ENABLED,
];
?><!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Book Request · Self-Service</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <link href="assets/css/kiosk.css?v=<?= h(@filemtime(__DIR__ . '/assets/css/kiosk.css') ?: 1) ?>" rel="stylesheet">
</head>
<body class="kiosk-body">
<header class="kiosk-top">
  <div class="kiosk-top-in">
    <div class="kiosk-brand"><i class="bi bi-book-half"></i>
      <div><h1>Book Request</h1>
        <p>Ask the librarian for permission to use or borrow a book. The librarian approves your request at the desk.</p></div>
    </div>
    <span class="kiosk-mode"><span class="dot"></span> Self-service</span>
  </div>
</header>

<?php if ($problem): ?>
<main class="kiosk-wrap"><div class="kcard text-center p-5">
  <i class="bi bi-tools display-4 text-muted"></i>
  <h2 class="h4 mt-3">Self-service is temporarily unavailable</h2>
  <p class="text-muted mb-0">Please ask library staff to register your book. Thank you for your patience.</p>
</div></main>
<?php else: ?>
<main class="kiosk-wrap" id="kiosk" aria-live="polite">

  <ol class="ksteps" id="ksteps" aria-label="Progress">
    <li data-step="1" class="active"><span class="n">1</span><span class="t">Identify Student</span></li>
    <li data-step="2"><span class="n">2</span><span class="t">Select Books</span></li>
    <li data-step="3"><span class="n">3</span><span class="t">Send Request</span></li>
  </ol>

  <div class="idle-banner" id="idleBanner" hidden role="alert">
    <i class="bi bi-hourglass-split"></i> Still there? This screen will clear in <strong id="idleLeft">15</strong>s for your privacy.
    <button type="button" class="btn btn-sm btn-light ms-2" id="idleStay">I'm still here</button>
  </div>

  <!-- STEP 1 -->
  <section class="kcard" id="panel1" aria-labelledby="h1">
    <h2 id="h1" class="kh"><i class="bi bi-person-vcard"></i> Step 1 · Identify yourself</h2>
    <form id="idForm" autocomplete="off" novalidate>
      <label for="studentId" class="form-label fw-semibold">Student ID</label>
      <div class="kfield">
        <i class="bi bi-person-badge"></i>
        <input id="studentId" type="text" class="form-control form-control-lg" maxlength="40"
               placeholder="Enter or scan your Student ID" autocomplete="off" autocapitalize="characters"
               spellcheck="false" aria-describedby="idHelp idError" autofocus>
      </div>
      <div id="idError" class="kerror" role="alert" hidden></div>
      <div id="idHelp" class="form-text">Use your Student ID (for example 02-2425-014468). Barcode and QR scanners work too.</div>
      <button class="btn btn-primary btn-lg mt-3 kbtn" id="idBtn" type="submit"><i class="bi bi-arrow-right-circle"></i> Continue</button>
    </form>
  </section>

  <!-- identified summary (replaces step 1 once it succeeds) -->
  <section class="kcard" id="whoCard" hidden aria-label="Identified student">
    <div class="who">
      <span class="avatar-lg" id="whoAvatar" aria-hidden="true"></span>
      <div class="who-info">
        <div class="who-name" id="whoName"></div>
        <div class="who-sub"><span id="whoCard2"></span> · <span class="badge text-bg-success" id="whoStatus"></span></div>
        <div class="who-sub"><i class="bi bi-door-open text-success"></i> Entered today at <strong id="whoEntry"></strong>
          <?php if (IN_LIBRARY_USE_ENABLED): ?>· <i class="bi bi-book"></i> Books in use now: <strong id="whoInUse">0</strong><?php else: ?><span id="whoInUse" hidden>0</span><?php endif; ?></div>
      </div>
      <button type="button" class="btn btn-outline-secondary" id="notYou"><i class="bi bi-arrow-counterclockwise"></i> Not you?</button>
    </div>
    <div id="mineWrap" class="mine" hidden>
      <div class="fw-semibold small mb-2">Your books in use</div>
      <ul id="mineList" class="list-unstyled mb-0"></ul>
    </div>
    <div id="pendWrap" class="mine" hidden>
      <div class="fw-semibold small mb-2"><i class="bi bi-hourglass-split text-warning"></i> Waiting for the librarian</div>
      <ul id="pendList" class="list-unstyled mb-0"></ul>
    </div>
  </section>

  <!-- STEP 2 -->
  <section class="kcard" id="panel2" hidden aria-labelledby="h2">
    <h2 id="h2" class="kh"><i class="bi bi-search"></i> Step 2 · Choose the books you want</h2>
    <form id="bookForm" autocomplete="off" novalidate>
      <label for="bookQ" class="form-label fw-semibold">Find a book</label>
      <div class="kfield">
        <i class="bi bi-upc-scan"></i>
        <input id="bookQ" type="text" class="form-control form-control-lg" maxlength="80"
               placeholder="Search by book title, accession number, or barcode" autocomplete="off" spellcheck="false"
               aria-describedby="bookHelp bookError">
      </div>
      <div id="bookError" class="kerror" role="alert" hidden></div>
      <div id="bookHelp" class="form-text">Scan the barcode on the book, or type part of the title. Pick the exact copy, then choose whether you will read it here or borrow it.</div>
    </form>
    <div id="results" class="results" role="list" aria-label="Search results"></div>

    <div class="cart-head" id="cartHead" hidden><span class="fw-semibold">Your selected books</span> <span class="badge text-bg-primary" id="cartCount">0</span></div>
    <div id="cart" class="cart" role="list"></div>
  </section>

  <!-- STEP 3 -->
  <section class="kcard" id="panel3" hidden aria-labelledby="h3">
    <h2 id="h3" class="kh"><i class="bi bi-clipboard-check"></i> Step 3 · Review and send</h2>
    <dl class="kconfirm">
      <div><dt>Student</dt><dd id="cfName"></dd></div>
      <div><dt>Books</dt><dd><span id="cfCount"></span><ul id="cfList" class="list-unstyled mb-0 mt-1"></ul></dd></div>
    </dl>
    <div class="notice-box"><i class="bi bi-person-check"></i>
      <div><strong>The librarian decides.</strong> Sending this request does not approve it. Wait at the desk and show your ID —
      do not take a book until the librarian approves it.</div></div>
    <button type="button" class="btn btn-primary btn-lg kbtn mt-3" id="startBtn"><i class="bi bi-send"></i> Send Request to Librarian</button>
  </section>

  <!-- SUCCESS -->
  <section class="kcard text-center" id="panelDone" hidden aria-labelledby="hd">
    <div class="done-icon"><i class="bi bi-send-check-fill"></i></div>
    <h2 id="hd" class="h3 fw-bold mb-2">Request sent to the librarian</h2>
    <div class="ref-badge" id="doneRef"></div>
    <p class="mb-3">Please go to the librarian's desk and show your ID. <strong>Your book is not approved yet</strong> — wait for the librarian to confirm before using or taking it.</p>
    <ul id="doneList" class="list-unstyled small mb-3"></ul>
    <button type="button" class="btn btn-primary btn-lg kbtn" id="doneBtn"><i class="bi bi-check2"></i> Done</button>
    <div class="small text-muted mt-3">This screen resets in <span id="doneLeft"></span>s for the next student.</div>
  </section>
</main>

<div class="toast-container position-fixed bottom-0 start-50 translate-middle-x p-3" id="toasts"></div>

<div class="modal fade" id="kDialog" tabindex="-1" aria-labelledby="kDialogTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content text-center p-3">
    <div class="modal-body">
      <div class="kd-icon" id="kDialogIcon"><i class="bi bi-exclamation-triangle-fill"></i></div>
      <h2 class="h4 fw-bold" id="kDialogTitle">Notice</h2>
      <p class="mb-3" id="kDialogMsg"></p>
      <button type="button" class="btn btn-primary btn-lg px-5" data-bs-dismiss="modal" id="kDialogOk">OK</button>
    </div>
  </div></div>
</div>

<script>window.KIOSK = <?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/kiosk.js?v=<?= h(@filemtime(__DIR__ . '/assets/js/kiosk.js') ?: 1) ?>"></script>
<?php endif; ?>
</body>
</html>
