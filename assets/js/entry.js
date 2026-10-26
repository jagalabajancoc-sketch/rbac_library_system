/* Entrance scanner screen.
   Two ways in, same server check: (1) the device camera reads the barcode / QR on the school ID,
   (2) a handheld keyboard-style scanner or typing in the box, (3) reading the printed ID number (OCR) when the ID has no barcode. Video is analysed on this device only —
   nothing is recorded or uploaded; only the decoded ID text is sent to entry_api.php. Nothing is stored in the browser. */
(function () {
  'use strict';
  var E = window.ENTRY || {};
  var $ = function (id) { return document.getElementById(id); };
  var input = $('scanId'), form = $('scanForm'), scan = $('scanCard'), res = $('resultCard');
  var busy = false, timer = null, lastCode = '', cooldownUntil = 0;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  /* keep the cursor in the box for handheld scanners — but never steal focus from the camera buttons / dropdown */
  function focusBox() { if (!busy && !scan.hidden && !/^(SELECT|BUTTON)$/.test((document.activeElement || {}).tagName || '')) input.focus(); }
  input.addEventListener('blur', function () { setTimeout(focusBox, 200); });

  function show(html, ok) {
    res.className = 'kcard text-center entry-result ' + (ok ? 'is-ok' : 'is-bad');
    res.innerHTML = html; res.hidden = false; scan.hidden = true;
    clearTimeout(timer);
    timer = setTimeout(reset, (E.reset || 6) * 1000);
  }
  function reset() {
    res.hidden = true; res.innerHTML = ''; scan.hidden = false; input.value = ''; busy = false;
    cooldownUntil = Date.now() + 4000;      // an ID still held up to the camera must not scan twice
    focusBox();
  }
  res.addEventListener('click', reset);

  function submitId(v) {
    v = String(v || '').trim();
    if (!v || busy) return;
    busy = true; input.value = '';
    fetch('entry_api.php', {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': E.csrf }, body: JSON.stringify({ student_id: v })
    }).then(function (r) { return r.json().catch(function () { return { ok: false, message: "We couldn't record your entry. Please try again or see the librarian." }; }); })
      .then(function (r) {
        if (r.ok) {
          show('<div class="entry-big ok"><i class="bi bi-check-circle-fill"></i></div>' +
               '<div class="avatar-lg mx-auto mb-2">' + esc(r.initials) + '</div>' +
               '<h2 class="h2 fw-bold mb-1">' + (r.already ? 'Welcome back, ' : 'Welcome, ') + esc(r.name) + '!</h2>' +
               '<p class="mb-1 text-muted">ID ' + esc(r.card) + '</p>' +
               '<p class="mb-2">' + (r.already ? 'Your attendance is already recorded today — at ' : 'Attendance recorded at ') + '<strong>' + esc(r.time) + '</strong> · ' + esc(r.date) + '</p>' +
               '<p class="small text-muted mb-0">Need a book? Ask the librarian for permission before using or borrowing it.</p>', true);
        } else {
          show('<div class="entry-big bad"><i class="bi bi-x-octagon-fill"></i></div>' +
               '<h2 class="h3 fw-bold mb-2">' + (r.name ? esc(r.name) + ', ' : '') + 'we could not let you in</h2>' +
               '<p class="mb-0">' + esc(r.message || "We couldn't record your entry. Please try again or see the librarian.") + '</p>' +
               (r.scanned ? '<p class="small text-muted mt-2 mb-0">The camera read: <strong>' + esc(r.scanned) + '</strong>' + ' — no borrower has this Borrower ID. Please register first or see the librarian.' + '</p>' : ''), false);
        }
      }).catch(function () {
        show('<div class="entry-big bad"><i class="bi bi-wifi-off"></i></div><h2 class="h3 fw-bold mb-2">Connection problem</h2><p class="mb-0">We couldn\'t record your entry. Please try again or see the librarian.</p>', false);
      });
  }
  form.addEventListener('submit', function (ev) { ev.preventDefault(); submitId(input.value); });

  /* ---------------- camera ---------------- */
  var wrap = $('camWrap'), msg = $('camMsg'), sel = $('camSel'), tog = $('camToggle'), startBtn = $('camStart');
  var qr = null, running = false, starting = false;

  function note(text) { if (text) { msg.textContent = text; msg.hidden = false; } else { msg.hidden = true; msg.textContent = ''; } }
  function onDecode(text) {
    if (busy || !res.hidden) return;
    var t = String(text || '').trim();
    if (!t || t.length > 40) return;                                    // not an ID-sized code
    if (t === lastCode && Date.now() < cooldownUntil) return;
    lastCode = t;
    if (navigator.vibrate) { try { navigator.vibrate(60); } catch (e) {} }
    submitId(t);
  }
  function qrbox(w, h) { return { width: Math.floor(Math.min(w * 0.92, 380)), height: Math.floor(Math.min(h * 0.7, 230)) }; }

  function start(cameraId) {
    if (running || starting) return Promise.resolve();
    starting = true; note('');
    if (!qr) qr = new Html5Qrcode('camView', { verbose: false, useBarcodeDetectorIfSupported: true });
    var cfg = { fps: 10, qrbox: qrbox, aspectRatio: 1.333 };
    var target = cameraId ? { deviceId: { exact: cameraId } } : { facingMode: 'environment' };
    return qr.start(target, cfg, onDecode, function () {}).then(function () {
      running = true; starting = false; wrap.hidden = false; startBtn.hidden = true;
      if (E.ocr && typeof Tesseract !== 'undefined') { ocrBar.hidden = false; ocrSchedule(1200); }
      zxSchedule(800);
      tog.innerHTML = '<i class="bi bi-camera-video-off"></i> Turn camera off';
      return Html5Qrcode.getCameras().then(function (cams) {
        if (cams && cams.length > 1) {
          sel.innerHTML = cams.map(function (c, i) { return '<option value="' + esc(c.id) + '">' + esc(c.label || ('Camera ' + (i + 1))) + '</option>'; }).join('');
          if (cameraId) sel.value = cameraId;
          sel.hidden = false;
        }
      }).catch(function () {});
    }).catch(function (err) {
      starting = false; running = false; wrap.hidden = true; startBtn.hidden = false;
      if (E.ocr && typeof Tesseract !== 'undefined') ocrBar.hidden = false;   // the photo option still works without live video
      var name = err && (err.name || err.message || String(err));
      if (/NotAllowed|Permission/i.test(name)) note('Camera permission was blocked. Allow the camera for this page in the browser, then press “Turn camera on” — or type / use the handheld scanner below.');
      else if (/NotFound|Devices/i.test(name)) note('No camera was found on this device. Please use the handheld scanner or type your ID below.');
      else note('The camera could not be started. Please use the handheld scanner or type your ID below.');
    });
  }
  function stop() {
    ocrStop(); clearTimeout(zxTimer); zxTimer = null;
    if (!qr || !running) return Promise.resolve();
    return qr.stop().then(function () { qr.clear(); }).catch(function () {}).then(function () { running = false; });
  }

  tog.addEventListener('click', function () {
    stop().then(function () { wrap.hidden = true; startBtn.hidden = false; sel.hidden = true; focusBox(); });
  });
  startBtn.addEventListener('click', function () { start(null); });
  sel.addEventListener('change', function () { var id = sel.value; stop().then(function () { return start(id); }); });

  if (E.camera) {
    if (typeof Html5Qrcode === 'undefined') {
      note('The camera scanner could not be loaded (no internet?). Please use the handheld scanner or type your ID below.');
    } else if (!window.isSecureContext) {
      note('The camera only works on a secure (HTTPS) connection. Please use the handheld scanner or type your ID below.');
    } else if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      note('This browser does not support camera scanning. Please use the handheld scanner or type your ID below.');
    } else {
      start(null);
    }
  }


  /* ---------------- stronger QR reading ----------------
     Laptop / phone cameras often give a slightly soft picture that the built-in reader misses. This second reader
     (zxing-wasm) enlarges each frame ~3x first, which reads those soft QR codes. It runs on this device only. */
  var zxBusy = false, zxTimer = null, zxFail = false;
  function zxTick() {
    zxTimer = null;
    if (!running || zxFail || typeof ZXingWASM === 'undefined') return;
    var v = document.querySelector('#camView video');
    if (busy || !res.hidden || zxBusy || !v || !v.videoWidth || v.readyState < 2) return zxSchedule(400);
    zxBusy = true;
    var k = Math.max(1, Math.min(3, 1920 / v.videoWidth)), c = document.createElement('canvas');
    c.width = Math.round(v.videoWidth * k); c.height = Math.round(v.videoHeight * k);
    var ctx = c.getContext('2d', { willReadFrequently: true });
    ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(v, 0, 0, c.width, c.height);
    ZXingWASM.readBarcodes(ctx.getImageData(0, 0, c.width, c.height), { formats: ['QRCode', 'Code128', 'Code39', 'EAN-13'], tryHarder: true, tryInvert: true, maxNumberOfSymbols: 1 })
      .then(function (r) { zxBusy = false; if (r && r[0] && r[0].text) onDecode(r[0].text); })
      .catch(function () { zxBusy = false; zxFail = true; })        // wasm could not load: the built-in reader keeps working
      .then(function () { zxSchedule(350); });
  }
  function zxSchedule(ms) { if (zxTimer || zxFail || !running) return; zxTimer = setTimeout(zxTick, ms); }

  /* ---------------- read the printed ID number (OCR) ----------------
     For IDs with no barcode / QR (e.g. "ID NO. 02-2425-014468"). Frames are read on this device with Tesseract.js;
     a number is only submitted after the SAME number is read twice in a row, so one misread digit can't clock in the wrong person. */
  var ocrBar = $('ocrBar'), ocrStatus = $('ocrStatus'), photoIn = $('idPhoto');
  var worker = null, workerP = null, ocrBusy = false, ocrTimer = null, lastRead = '', agree = 0;
  var ID_RE = /(\d{2})\s*[-\u2010-\u2015_.]\s*(\d{4})\s*[-\u2010-\u2015_.]\s*(\d{5,6})/;

  function ocrNote(t) { if (ocrStatus) ocrStatus.textContent = t || ''; }
  function fixDigits(t) {                     // the usual OCR slips inside a number: O->0, I/l->1, S->5, B->8
    return String(t || '').replace(/[Oo]/g, '0').replace(/[Il|]/g, '1').replace(/S/g, '5').replace(/B/g, '8');
  }
  function findId(text) {
    var m = ID_RE.exec(text) || ID_RE.exec(fixDigits(text));
    return m ? m[1] + '-' + m[2] + '-' + m[3] : '';
  }
  function getWorker() {
    if (worker) return Promise.resolve(worker);
    if (workerP) return workerP;
    ocrNote('Getting the ID reader ready (first time only)…');
    workerP = Tesseract.createWorker('eng').then(function (w) {
      return w.setParameters({ tessedit_pageseg_mode: '11', user_defined_dpi: '300' }).then(function () { worker = w; return w; });
    }).catch(function (e) { workerP = null; throw e; });
    return workerP;
  }
  /* draw the source onto a canvas about 1100px wide, greyscale + stretched contrast: helps on glossy / dim cards */
  function prepCanvas(src, sw, sh) {
    var scale = Math.min(1.5, 1100 / sw), c = document.createElement('canvas');
    c.width = Math.round(sw * scale); c.height = Math.round(sh * scale);
    var ctx = c.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(src, 0, 0, c.width, c.height);
    var d = ctx.getImageData(0, 0, c.width, c.height), p = d.data, lo = 255, hi = 0, i, g;
    for (i = 0; i < p.length; i += 4) { g = (p[i] * 0.299 + p[i + 1] * 0.587 + p[i + 2] * 0.114) | 0; p[i] = p[i + 1] = p[i + 2] = g; if (g < lo) lo = g; if (g > hi) hi = g; }
    var span = Math.max(40, hi - lo);
    for (i = 0; i < p.length; i += 4) { g = Math.max(0, Math.min(255, ((p[i] - lo) * 255 / span) | 0)); p[i] = p[i + 1] = p[i + 2] = g; }
    ctx.putImageData(d, 0, 0);
    return c;
  }
  function readCanvas(c) {
    return getWorker().then(function (w) { return w.recognize(c); }).then(function (r) { return findId((r && r.data && r.data.text) || ''); });
  }
  function ocrTick() {
    ocrTimer = null;
    if (!running || busy || !res.hidden || ocrBusy) return ocrSchedule(900);
    var v = document.querySelector('#camView video');
    if (!v || !v.videoWidth || v.readyState < 2) return ocrSchedule(500);
    ocrBusy = true;
    readCanvas(prepCanvas(v, v.videoWidth, v.videoHeight)).then(function (id) {
      ocrBusy = false;
      if (!id) { agree = 0; lastRead = ''; ocrNote('Looking for the ID number… hold the ID steady and upright.'); return; }
      if (id === lastRead) agree++; else { lastRead = id; agree = 1; }
      if (agree >= 2) {
        if (id === lastCode && Date.now() < cooldownUntil) return;
        lastCode = id; agree = 0; lastRead = '';
        if (navigator.vibrate) { try { navigator.vibrate(60); } catch (e) {} }
        ocrNote(''); submitId(id);
      } else ocrNote('Reading ' + id + '… keep holding still.');
    }).catch(function () {
      ocrBusy = false; ocrNote('The ID reader could not load (no internet?). Use the handheld scanner, type your ID, or try again.');
    }).then(function () { ocrSchedule(700); });
  }
  function ocrSchedule(ms) { if (!E.ocr || ocrTimer || !running) return; ocrTimer = setTimeout(ocrTick, ms); }
  function ocrStop() { clearTimeout(ocrTimer); ocrTimer = null; agree = 0; lastRead = ''; }

  /* photo fallback: the phone's own camera app takes the picture, then we read the number from it */
  if (photoIn) photoIn.addEventListener('change', function () {
    var f = photoIn.files && photoIn.files[0]; photoIn.value = '';
    if (!f || busy) return;
    ocrNote('Reading your photo…');
    var load = window.createImageBitmap ? createImageBitmap(f, { imageOrientation: 'from-image' }) : new Promise(function (ok, no) {
      var im = new Image(); im.onload = function () { ok(im); }; im.onerror = no; im.src = URL.createObjectURL(f);
    });
    load.then(function (img) {
      var w = img.width || img.naturalWidth, h = img.height || img.naturalHeight;
      return readCanvas(prepCanvas(img, w, h));
    }).then(function (id) {
      if (id) { ocrNote(''); submitId(id); }
      else ocrNote("We couldn't find an ID number in that photo. Retake it with the ID upright, flat and well lit.");
    }).catch(function () { ocrNote('The ID reader could not load (no internet?). Use the handheld scanner or type your ID.'); });
  });

  document.addEventListener('visibilitychange', function () {   // free the camera when the tab is hidden
    if (document.hidden) stop().then(function () { wrap.hidden = true; startBtn.hidden = false; });
    else if (E.camera && window.isSecureContext && typeof Html5Qrcode !== 'undefined' && !running) start(null);
  });
  focusBox();
})();
