/* Borrower self-service kiosk. All state lives in memory (and the server-side kiosk session);
   nothing is written to localStorage / sessionStorage / cookies by this script. */
(function () {
  'use strict';
  var C = window.KIOSK || {};
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  var member = null, cart = [], busy = false, searchSeq = 0, searchTimer = null, lastResults = [];
  var idleTimer = null, idleTick = null, doneTimer = null;

  /* ---------- API ---------- */
  function api(action, data) {
    var body = JSON.stringify(Object.assign({ action: action }, data || {}));
    return fetch('kiosk_api.php', {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': C.csrf }, body: body
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, code: 'server', message: "We couldn't complete your registration. Please try again." }; })
        .then(function (j) { j.httpStatus = r.status; return j; });
    });
  }
  var NET_MSG = "We couldn't complete your registration. Please try again.";

  /* ---------- UI helpers ---------- */
  var dialogEl = $('kDialog'), dialog = new bootstrap.Modal(dialogEl);
  function showDialog(title, msg, kind) {
    var ic = { warn: 'bi-exclamation-triangle-fill', err: 'bi-x-octagon-fill', ok: 'bi-check-circle-fill', info: 'bi-info-circle-fill' }[kind || 'warn'];
    $('kDialogIcon').className = 'kd-icon kd-' + (kind || 'warn');
    $('kDialogIcon').innerHTML = '<i class="bi ' + ic + '"></i>';
    $('kDialogTitle').textContent = title;
    $('kDialogMsg').textContent = msg;
    dialog.show();
  }
  dialogEl.addEventListener('shown.bs.modal', function () { $('kDialogOk').focus(); });

  function toast(msg, kind) {
    var el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + (kind || 'success') + ' border-0';
    el.setAttribute('role', 'status');
    el.innerHTML = '<div class="d-flex"><div class="toast-body">' + esc(msg) + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
    $('toasts').appendChild(el);
    var t = new bootstrap.Toast(el, { delay: 3500 }); t.show();
    el.addEventListener('hidden.bs.toast', function () { el.remove(); });
  }
  function inline(id, msg) {
    var e = $(id);
    if (msg) { e.textContent = msg; e.hidden = false; } else { e.hidden = true; e.textContent = ''; }
  }
  function setBusy(btn, on, label) {
    busy = on; btn.disabled = on;
    if (on) { btn.dataset.html = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + label; }
    else if (btn.dataset.html) { btn.innerHTML = btn.dataset.html; }
  }
  function step(n) {
    Array.prototype.forEach.call(document.querySelectorAll('#ksteps li'), function (li) {
      var s = parseInt(li.dataset.step, 10);
      li.classList.toggle('active', s === n);
      li.classList.toggle('done', s < n);
    });
  }

  /* ---------- reset (clears everything the previous borrower entered) ---------- */
  function resetAll(msg) {
    clearTimeout(idleTimer); clearInterval(idleTick); clearInterval(doneTimer);
    $('idleBanner').hidden = true;
    api('reset').catch(function () {});
    member = null; cart = []; lastResults = [];
    $('studentId').value = ''; $('bookQ').value = ''; $('results').innerHTML = ''; $('cart').innerHTML = '';
    inline('idError', ''); inline('bookError', '');
    ['whoCard', 'panel2', 'panel3', 'panelDone'].forEach(function (id) { $(id).hidden = true; });
    $('panel1').hidden = false;
    step(1);
    if (msg) toast(msg, 'secondary');
    setTimeout(function () { $('studentId').focus(); }, 50);
  }

  /* ---------- idle protection ---------- */
  function armIdle() {
    clearTimeout(idleTimer); clearInterval(idleTick);
    $('idleBanner').hidden = true;
    if (!member) return;
    var warnAt = Math.max(5, C.idle - 15) * 1000;
    idleTimer = setTimeout(function () {
      var left = Math.min(15, C.idle);
      $('idleLeft').textContent = left; $('idleBanner').hidden = false;
      idleTick = setInterval(function () {
        left--; $('idleLeft').textContent = Math.max(0, left);
        if (left <= 0) { clearInterval(idleTick); resetAll('Screen cleared after a period of inactivity.'); }
      }, 1000);
    }, warnAt);
  }
  ['pointerdown', 'keydown', 'input'].forEach(function (ev) {
    document.addEventListener(ev, function () { if (member && $('idleBanner').hidden) armIdle(); }, true);
  });
  $('idleStay').addEventListener('click', armIdle);

  /* ---------- Step 1: identify ---------- */
  $('idForm').addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (busy) return;
    var v = $('studentId').value.trim();
    if (!v) { inline('idError', 'Please enter or scan your Borrower ID.'); $('studentId').focus(); return; }
    inline('idError', '');
    var btn = $('idBtn'); setBusy(btn, true, 'Checking…');
    api('identify', { student_id: v }).then(function (r) {
      setBusy(btn, false);
      if (!r.ok) {
        if (r.code === 'blocked' || r.code === 'locked' || r.code === 'disabled' || r.code === 'no_entry') showDialog(r.code === 'locked' ? 'Please wait' : r.code === 'no_entry' ? 'Please scan in first' : 'Cannot continue', r.message, 'warn');
        else inline('idError', r.message);
        $('studentId').select(); return;
      }
      member = r.member;
      $('studentId').value = '';          // do not leave the ID sitting in the field
      showWho(); $('panel1').hidden = true; $('panel2').hidden = false;
      step(2); armIdle();
      setTimeout(function () { $('bookQ').focus(); }, 50);
    }).catch(function () { setBusy(btn, false); inline('idError', NET_MSG); });
  });

  function showWho() {
    $('whoAvatar').textContent = member.initials;
    $('whoName').textContent = member.name;
    $('whoCard2').textContent = 'ID ' + member.card;
    $('whoStatus').textContent = member.status;
    $('whoInUse').textContent = member.in_use;
    $('whoEntry').textContent = member.entry || '—';
    var list = $('mineList'); list.innerHTML = '';
    member.sessions.forEach(function (s) {
      var li = document.createElement('li'); li.className = 'mine-item';
      li.innerHTML = '<i class="bi bi-book"></i> <span><strong>' + esc(s.title) + '</strong> <span class="text-muted">· copy ' + esc(s.copy) + ' · since ' + esc(s.since) + '</span></span>';
      list.appendChild(li);
    });
    $('mineWrap').hidden = member.sessions.length === 0;
    var pl = $('pendList'); pl.innerHTML = '';
    (member.pending || []).forEach(function (p) {
      var li = document.createElement('li'); li.className = 'mine-item';
      li.innerHTML = '<i class="bi bi-hourglass-split"></i> <span class="flex-grow-1"><strong>' + esc(p.ref) + '</strong> · ' + p.n + ' book(s) · sent ' + esc(p.at) +
        '<br><span class="text-muted">' + esc(p.titles) + '</span></span>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" data-cancel="' + p.id + '">Cancel</button>';
      pl.appendChild(li);
    });
    $('pendWrap').hidden = !(member.pending && member.pending.length);
    $('whoCard').hidden = false;
  }
  $('notYou').addEventListener('click', function () { resetAll(); });
  $('pendList').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-cancel]'); if (!b || busy) return;
    busy = true;
    api('cancel_request', { request_id: parseInt(b.dataset.cancel, 10) }).then(function (r) {
      busy = false;
      if (!r.ok) { showDialog('Could not cancel', r.message || NET_MSG, 'warn'); return; }
      member = r.member; showWho(); toast('Request cancelled.', 'secondary');
    }).catch(function () { busy = false; showDialog('Could not cancel', NET_MSG, 'err'); });
  });

  /* ---------- Step 2: search / scan ---------- */
  function inCart(id) { return cart.some(function (c) { return c.copyId === id; }); }
  function renderResults(items) {
    lastResults = items;
    var box = $('results');
    if (!items.length) { box.innerHTML = ''; return; }
    box.innerHTML = items.map(function (it, i) {
      var already = inCart(it.copyId);
      var BO = !!(window.KIOSK && window.KIOSK.borrowOnly);
      var can = it.ok && !already && (!BO || it.borrowable);
      var badge = already ? '<span class="badge text-bg-primary">Added</span>'
        : it.ok ? '<span class="badge text-bg-success">Available</span>'
        : '<span class="badge text-bg-secondary">' + esc(it.label) + '</span>';
      var borrowBtn = '<button type="button" class="btn btn-primary btn-sm" data-add="' + i + '" data-use="Borrow"' + (can ? '' : ' disabled') + '><i class="bi bi-box-arrow-up-right"></i> Borrow</button>';
      var btns = BO ? (it.borrowable ? borrowBtn : '<span class="badge text-bg-light border" title="This title cannot be taken out of the library">Library use only</span>') : '<button type="button" class="btn btn-primary btn-sm" data-add="' + i + '" data-use="In-Library"' + (can ? '' : ' disabled') + '><i class="bi bi-book"></i> Read here</button>' +
        (it.borrowable
          ? '<button type="button" class="btn btn-outline-primary btn-sm" data-add="' + i + '" data-use="Borrow"' + (can ? '' : ' disabled') + '><i class="bi bi-box-arrow-up-right"></i> Borrow</button>'
          : '<span class="badge text-bg-light border" title="This title cannot be taken out of the library">Library use only</span>');
      return '<div class="result' + (can ? '' : ' is-off') + '" role="listitem">' +
        '<div class="r-main"><div class="r-title">' + esc(it.title) + '</div>' +
        '<div class="r-sub">' + (it.authors ? esc(it.authors) + ' · ' : '') + 'Copy ID <strong>' + esc(it.accession) + '</strong>' +
        (it.shelf ? ' · ' + esc(it.shelf) : '') + '</div></div>' +
        '<div class="r-side">' + badge + btns + '</div></div>';
    }).join('');
  }

  function doSearch(q, fromEnter) {
    var seq = ++searchSeq;
    inline('bookError', '');
    if (q.trim().length < 2) { $('results').innerHTML = ''; return Promise.resolve(); }
    return api('search', { q: q }).then(function (r) {
      if (seq !== searchSeq) return;
      if (!r.ok) {
        if (r.httpStatus === 401) { resetAll('Please identify yourself again.'); return; }
        inline('bookError', r.message || NET_MSG); return;
      }
      if (!r.items.length) {
        $('results').innerHTML = '';
        inline('bookError', 'No matching book copy was found. Please check the barcode or search again.'); return;
      }
      renderResults(r.items);
      if (fromEnter) {                       // scanner flow: Enter right after an exact barcode adds it
        var ex = r.items.filter(function (x) { return x.exact; })[0];
        if (ex) {
          if (inCart(ex.copyId)) { toast('That copy is already in your list.', 'secondary'); }
          else if (ex.ok && window.KIOSK.borrowOnly && !ex.borrowable) showDialog('Cannot borrow', 'This book is for library use only and cannot be borrowed.', 'warn');
          else if (ex.ok) { addCopy(ex, window.KIOSK.borrowOnly ? 'Borrow' : 'In-Library'); $('bookQ').value = ''; $('results').innerHTML = ''; }
          else showDialog('Book not available', ex.reason, 'warn');
        }
      }
    }).catch(function () { if (seq === searchSeq) inline('bookError', NET_MSG); });
  }
  $('bookQ').addEventListener('input', function () {
    clearTimeout(searchTimer);
    var q = this.value;
    searchTimer = setTimeout(function () { doSearch(q, false); }, 280);
  });
  $('bookForm').addEventListener('submit', function (ev) {
    ev.preventDefault(); clearTimeout(searchTimer);
    doSearch($('bookQ').value, true);
  });
  $('results').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-add]'); if (!b) return;
    var it = lastResults[parseInt(b.dataset.add, 10)];
    if (it && it.ok && !inCart(it.copyId)) { addCopy(it, b.dataset.use); renderResults(lastResults); }
  });

  function addCopy(it, use) {
    if (inCart(it.copyId)) return;
    if (window.KIOSK && window.KIOSK.borrowOnly) { if (!it.borrowable) return; use = 'Borrow'; }
    else if (use === 'Borrow' && !it.borrowable) use = 'In-Library';
    cart.push({ copyId: it.copyId, accession: it.accession, title: it.title, authors: it.authors, use: use === 'Borrow' ? 'Borrow' : 'In-Library' });
    toast('“' + it.title + '” added.', 'success');
    renderCart();
  }
  function renderCart() {
    $('cartHead').hidden = cart.length === 0;
    $('cartCount').textContent = cart.length;
    $('cart').innerHTML = cart.map(function (c, i) {
      return '<div class="cart-item" role="listitem"><i class="bi bi-book-fill"></i>' +
        '<div class="r-main"><div class="r-title">' + esc(c.title) + ' <span class="badge use-badge ' + (c.use === 'Borrow' ? 'text-bg-warning' : 'text-bg-info') + '">' + (c.use === 'Borrow' ? 'To borrow' : 'Read in library') + '</span></div><div class="r-sub">Copy ID ' + esc(c.accession) + (c.authors ? ' · ' + esc(c.authors) : '') + '</div></div>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" data-rm="' + i + '" aria-label="Remove ' + esc(c.title) + '"><i class="bi bi-x-lg"></i></button></div>';
    }).join('');
    $('panel3').hidden = cart.length === 0;
    if (cart.length) { fillConfirm(); step(3); } else step(2);
  }
  $('cart').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-rm]'); if (!b) return;
    cart.splice(parseInt(b.dataset.rm, 10), 1); renderCart(); renderResults(lastResults);
  });

  /* ---------- Step 3: confirm ---------- */
  function fillConfirm() {
    $('cfName').textContent = member.name + ' (ID ' + member.card + ')';
    $('cfCount').textContent = cart.length + (cart.length === 1 ? ' book' : ' books') + ' — waiting for librarian approval';
    $('cfList').innerHTML = cart.map(function (c) {
      return '<li>• <strong>' + esc(c.title) + '</strong> <span class="text-muted">— copy ' + esc(c.accession) + ' · ' + (c.use === 'Borrow' ? 'to borrow' : 'read in library') + '</span></li>';
    }).join('');
  }
  $('startBtn').addEventListener('click', function () {
    if (busy || !cart.length) return;
    var btn = $('startBtn'); setBusy(btn, true, 'Sending…');
    api('submit', { items: cart.map(function (c) { return { copy_id: c.copyId, use: c.use }; }) }).then(function (r) {
      setBusy(btn, false);
      if (!r.ok) {
        if (r.httpStatus === 401) { resetAll('Please identify yourself again.'); return; }
        showDialog('We could not send your request', r.message || NET_MSG, r.code === 'server' ? 'err' : 'warn');
        return;
      }
      showSuccess(r, cart.slice());       // only ever shown after the server confirmed
    }).catch(function () { setBusy(btn, false); showDialog('We could not send your request', NET_MSG, 'err'); });
  });

  function showSuccess(r, items) {
    clearTimeout(idleTimer); clearInterval(idleTick); $('idleBanner').hidden = true;
    ['whoCard', 'panel2', 'panel3'].forEach(function (id) { $(id).hidden = true; });
    $('doneRef').textContent = r.ref;
    $('doneList').innerHTML = items.map(function (i) { return '<li><i class="bi bi-hourglass-split text-warning"></i> ' + esc(i.title) + ' <span class="text-muted">· copy ' + esc(i.accession) + ' · ' + (i.use === 'Borrow' ? 'to borrow' : 'read in library') + '</span></li>'; }).join('');
    $('panelDone').hidden = false;
    $('ksteps').querySelectorAll('li').forEach(function (li) { li.classList.remove('active'); li.classList.add('done'); });
    var left = C.doneSecs + 8; $('doneLeft').textContent = left;      // a little longer so the reference can be read
    clearInterval(doneTimer);
    doneTimer = setInterval(function () { left--; $('doneLeft').textContent = Math.max(0, left); if (left <= 0) resetAll(); }, 1000);
    setTimeout(function () { $('doneBtn').focus(); }, 50);
  }
  $('doneBtn').addEventListener('click', function () { resetAll(); });

  resetAll();
})();
