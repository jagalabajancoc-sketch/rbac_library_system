/* Member catalog — instant search, combined filters, sorting, paging, grid/list view,
   borrow-request dialog and multi-select. Data comes from <script id="catalog-data">.
   Actual requests are submitted to member_catalog.php (server validates everything again). */
(function () {
  'use strict';

  var dataEl = document.getElementById('catalog-data');
  if (!dataEl) return;

  var cfg = JSON.parse(dataEl.textContent);
  var books = cfg.books || [];
  var PER_PAGE = 12;
  var st = cfg.status || { enabled: false, slots: -1 };   // borrowing status (see member_borrow_status())

  var $ = function (id) { return document.getElementById(id); };
  var els = {
    layout: $('catalogLayout'), grid: $('bookGrid'), empty: $('catEmpty'), pager: $('catPager'),
    count: $('resultCount'), search: $('catSearch'), sort: $('sortBy'),
    authorSearch: $('authorSearch'), backdrop: $('filterBackdrop'),
    filters: $('catFilters')
  };

  var state = {
    q: '', sort: 'relevant', page: 1, view: 'grid', authorQ: '',
    status: {}, section: {}, category: {}, author: {}
  };
  var selected = {};   // book ids ticked for multi-request

  /* ---------- helpers ---------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function lc(s) { return String(s || '').toLowerCase(); }
  function keys(o) { return Object.keys(o).filter(function (k) { return o[k]; }); }
  function byId(id) { for (var i = 0; i < books.length; i++) if (books[i].id === id) return books[i]; return null; }
  function hue(str) { var h = 0; for (var i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) % 360; return h; }
  function coverStyle(b) {
    var h = hue(b.title);
    return 'background:linear-gradient(145deg,hsl(' + h + ' 45% 34%),hsl(' + ((h + 40) % 360) + ' 50% 22%))';
  }
  function coverHtml(b) {
    return '<span class="bk-cover-title">' + esc(b.title.length > 38 ? b.title.slice(0, 36) + '…' : b.title) + '</span>';
  }
  function copiesText(b) { return (b.shelf != null ? b.shelf : b.avail) + ' of ' + b.total + ' ' + (b.total === 1 ? 'Copy' : 'Copies') + ' on shelf'; }

  /* Pre-computed lower-case search fields */
  books.forEach(function (b) {
    b._t = lc(b.title);
    b._a = lc(b.authors.join(', '));
    b._p = lc(b.publisher);
    b._i = lc(b.isbn).replace(/[-\s]/g, '');
    b._sortAuthor = lc(b.authors[0] || '\uffff');
  });

  /* ---------- facets ---------- */
  function tally(field) {
    var map = {};
    books.forEach(function (b) {
      var k = b[field + 'Id'], label = b[field];
      if (!map[k]) map[k] = { value: k, label: label, n: 0 };
      map[k].n++;
    });
    return Object.keys(map).map(function (k) { return map[k]; })
      .sort(function (a, b) { return a.label.localeCompare(b.label, undefined, { numeric: true }); });
  }
  var statusFacets = [
    { value: 'available', label: 'Available', n: books.filter(function (b) { return b.borrowable && b.avail > 0; }).length, dot: true },
    { value: 'onloan',    label: 'On Loan',   n: books.filter(function (b) { return b.onLoan > 0; }).length },
    { value: 'overdue',   label: 'Overdue',   n: books.filter(function (b) { return b.overdue > 0; }).length }
  ];
  var sectionFacets = tally('section');
  var categoryFacets = tally('category');

  var authorMap = {};
  books.forEach(function (b) { b.authors.forEach(function (a) { authorMap[a] = (authorMap[a] || 0) + 1; }); });
  var authorList = Object.keys(authorMap).sort(function (a, b) { return a.localeCompare(b); });

  function facetHtml(group, f) {
    var id = 'f-' + group + '-' + esc(f.value);
    return '<div class="form-check cf-check">' +
      '<input class="form-check-input" type="checkbox" id="' + id + '" data-group="' + group + '" data-value="' + esc(f.value) + '"' +
      (state[group][f.value] ? ' checked' : '') + '>' +
      '<label class="form-check-label" for="' + id + '">' + esc(f.label) +
      (f.dot ? ' <span class="cf-dot" aria-hidden="true"></span>' : '') +
      ' <span class="cf-n">(' + f.n + ')</span></label></div>';
  }
  function renderFacets() {
    $('facet-status').innerHTML = statusFacets.map(function (f) { return facetHtml('status', f); }).join('');
    $('facet-section').innerHTML = sectionFacets.map(function (f) { return facetHtml('section', f); }).join('');
    $('facet-category').innerHTML = categoryFacets.map(function (f) { return facetHtml('category', f); }).join('');
    renderAuthors();
  }
  function renderAuthors() {
    var q = lc(state.authorQ.trim());
    var sel = keys(state.author);
    var matches = authorList.filter(function (a) { return sel.indexOf(a) === -1 && (!q || lc(a).indexOf(q) !== -1); });
    var list = sel.concat(matches.slice(0, q ? 12 : 6));
    var html = list.map(function (a, i) {
      var id = 'f-author-' + i;
      return '<div class="form-check cf-check"><input class="form-check-input" type="checkbox" id="' + id +
        '" data-group="author" data-value="' + esc(a) + '"' + (state.author[a] ? ' checked' : '') + '>' +
        '<label class="form-check-label" for="' + id + '">' + esc(a) + ' <span class="cf-n">(' + authorMap[a] + ')</span></label></div>';
    }).join('');
    if (!list.length) html = '<div class="small text-muted">No authors found.</div>';
    else if (matches.length > list.length - sel.length) html += '<div class="small text-muted mt-1">Type to narrow ' + (matches.length - (list.length - sel.length)) + ' more…</div>';
    $('facet-author').innerHTML = html;
  }

  /* ---------- filtering / sorting ---------- */
  function score(b, tokens) {
    var total = 0;
    for (var i = 0; i < tokens.length; i++) {
      var t = tokens[i], tIsbn = t.replace(/-/g, ''), s = 0;
      if (b._t.indexOf(t) === 0) s = 100;
      else if (b._t.indexOf(t) !== -1) s = 60;
      else if (b._a.indexOf(t) !== -1) s = 40;
      else if (tIsbn && b._i.indexOf(tIsbn) !== -1) s = 30;
      else if (b._p.indexOf(t) !== -1) s = 20;
      else return -1;                    // every word must match something
      total += s;
    }
    return total;
  }
  function statusOk(b, picks) {
    if (!picks.length) return true;
    return picks.some(function (p) {
      if (p === 'available') return b.borrowable && b.avail > 0;
      if (p === 'onloan') return b.onLoan > 0;
      if (p === 'overdue') return b.overdue > 0;
      return false;
    });
  }
  function compute() {
    var tokens = lc(state.q).split(/\s+/).filter(Boolean);
    var st = keys(state.status), se = keys(state.section), ca = keys(state.category), au = keys(state.author);
    var out = [];
    books.forEach(function (b, idx) {
      if (!statusOk(b, st)) return;
      if (se.length && se.indexOf(b.sectionId) === -1) return;
      if (ca.length && ca.indexOf(b.categoryId) === -1) return;
      if (au.length && !b.authors.some(function (a) { return au.indexOf(a) !== -1; })) return;
      var sc = 0;
      if (tokens.length) { sc = score(b, tokens); if (sc < 0) return; }
      out.push({ b: b, sc: sc, idx: idx });
    });
    out.sort(function (x, y) {
      var r = 0;
      if (state.sort === 'title') r = x.b._t.localeCompare(y.b._t);
      else if (state.sort === 'author') r = x.b._sortAuthor.localeCompare(y.b._sortAuthor);
      else if (state.sort === 'publisher') r = y.b._p.localeCompare(x.b._p);
      else r = y.sc - x.sc;              // relevant: best match first, then catalog order
      return r || (x.idx - y.idx);
    });
    return out.map(function (o) { return o.b; });
  }

  /* ---------- rendering ---------- */
  function badgeHtml(b) {
    if (!b.borrowable) return '<span class="badge text-bg-secondary bk-badge">Library Use Only</span>';
    if (b.avail > 0) return '<span class="badge text-bg-success bk-badge">Available</span>';
    if (b.shelf > 0) return '<span class="badge text-bg-warning bk-badge" title="The last copy stays in the library. You can reserve it or read it at the library.">In library only</span>';
    return '<span class="badge text-bg-danger bk-badge">Unavailable</span>';
  }
  function actionHtml(b) {
    if (!b.borrowable) return '<span class="small text-muted"><i class="bi bi-lock"></i> Not for loan</span>';
    if (!cfg.canRequest) {
      return '<button type="button" class="btn btn-sm btn-outline-secondary" disabled title="' + esc(cfg.blockedWhy) + '"><i class="bi bi-lock"></i> Unavailable</button>';
    }
    if (b.mine === 'holding') return '<button type="button" class="btn btn-sm btn-outline-secondary" disabled title="You currently have this book."><i class="bi bi-journal-check"></i> You Have This</button>';
    if (b.mine === 'requested') return '<button type="button" class="btn btn-sm btn-outline-secondary" disabled title="You already have an open request for this book."><i class="bi bi-check2-circle"></i> Already Requested</button>';
    if (st.slots === 0) {
      // Replaces "Request to Borrow". Availability stays visible on the card; clicking explains why.
      return '<button type="button" class="btn btn-sm btn-outline-danger btn-limit" data-limit="1" aria-disabled="true" title="Return a book before requesting another."><i class="bi bi-slash-circle"></i> Limit Reached</button>' +
             '<span class="bk-limit-note">Return a book before requesting another.</span>';
    }
    return b.avail > 0
      ? '<button type="button" class="btn btn-sm btn-primary" data-request="' + b.id + '"><i class="bi bi-bookmark-check"></i> Request to Borrow</button>'
      : '<button type="button" class="btn btn-sm btn-outline-secondary" data-request="' + b.id + '"><i class="bi bi-hourglass-split"></i> Reserve</button>';
  }
  function cardHtml(b) {
    var meta = [b.publisher];
    meta.push(b.year ? b.year : 'No Year');
    meta.push(b.isbn ? 'ISBN ' + b.isbn : 'No ISBN');
    var pick = (cfg.canRequest && b.borrowable && !b.mine && st.slots !== 0)
      ? '<input type="checkbox" class="form-check-input bk-pick" data-pick="' + b.id + '"' + (selected[b.id] ? ' checked' : '') +
        ' aria-label="Select ' + esc(b.title) + '">' : '';
    return '<article class="book-card' + (selected[b.id] ? ' is-picked' : '') + '" role="listitem">' +
      '<div class="bk-cover" style="' + coverStyle(b) + '" aria-hidden="true">' + coverHtml(b) + pick + '</div>' +
      '<div class="bk-main">' +
        '<div class="bk-head"><h3 class="bk-title">' + esc(b.title) + '</h3>' + badgeHtml(b) + '</div>' +
        '<div class="bk-author">' + esc(b.authors.length ? b.authors.join(', ') : 'Unknown author') + '</div>' +
        '<div class="bk-meta">' + meta.map(esc).join(' · ') + '</div>' +
        '<div class="bk-tags"><span class="bk-tag"><i class="bi bi-tag"></i> ' + esc(b.category) + '</span>' +
          '<span class="bk-tag"><i class="bi bi-geo-alt"></i> ' + esc(b.section) + '</span></div>' +
        '<div class="bk-foot">' +
          (b.borrowable ? '<span class="bk-copies">' + esc(copiesText(b)) + '</span>' : '<span></span>') +
          actionHtml(b) +
        '</div>' +
      '</div></article>';
  }

  function render() {
    var list = compute();
    var total = list.length;
    var pages = Math.max(1, Math.ceil(total / PER_PAGE));
    if (state.page > pages) state.page = pages;
    var start = (state.page - 1) * PER_PAGE;
    var slice = list.slice(start, start + PER_PAGE);

    els.count.textContent = total
      ? 'Showing ' + (start + 1) + '-' + (start + slice.length) + ' of ' + total + ' Result' + (total === 1 ? '' : 's')
      : 'Showing 0 of 0 Results';

    els.grid.className = 'book-grid' + (state.view === 'list' ? ' is-list' : '');
    els.grid.innerHTML = slice.map(cardHtml).join('');
    els.empty.hidden = total !== 0;
    els.grid.hidden = total === 0;

    renderPager(pages);
    refreshPickBar();
  }

  function renderPager(pages) {
    if (pages <= 1) { els.pager.hidden = true; els.pager.innerHTML = ''; return; }
    var h = '<ul class="pagination pagination-sm mb-0">';
    h += '<li class="page-item' + (state.page === 1 ? ' disabled' : '') + '"><button class="page-link" data-page="' + (state.page - 1) + '">&laquo;</button></li>';
    for (var p = 1; p <= pages; p++) {
      h += '<li class="page-item' + (p === state.page ? ' active' : '') + '"><button class="page-link" data-page="' + p + '">' + p + '</button></li>';
    }
    h += '<li class="page-item' + (state.page === pages ? ' disabled' : '') + '"><button class="page-link" data-page="' + (state.page + 1) + '">&raquo;</button></li></ul>';
    els.pager.innerHTML = h;
    els.pager.hidden = false;
  }

  /* ---------- events: search, sort, filters ---------- */
  els.search.addEventListener('input', function () { state.q = els.search.value; state.page = 1; render(); });
  els.sort.addEventListener('change', function () { state.sort = els.sort.value; state.page = 1; render(); });
  els.authorSearch.addEventListener('input', function () { state.authorQ = els.authorSearch.value; renderAuthors(); });

  els.filters.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!t.matches || !t.matches('input[data-group]')) return;
    state[t.dataset.group][t.dataset.value] = t.checked;
    state.page = 1;
    if (t.dataset.group === 'author') renderAuthors();
    render();
  });

  Array.prototype.forEach.call(document.querySelectorAll('.cf-toggle'), function (btn) {
    btn.addEventListener('click', function () {
      var open = btn.getAttribute('aria-expanded') !== 'true';
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.parentNode.classList.toggle('is-collapsed', !open);
    });
  });

  function resetAll() {
    state.q = ''; state.sort = 'relevant'; state.page = 1; state.authorQ = '';
    state.status = {}; state.section = {}; state.category = {}; state.author = {};
    els.search.value = ''; els.sort.value = 'relevant'; els.authorSearch.value = '';
    renderFacets(); render();
  }
  $('resetFilters').addEventListener('click', resetAll);
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest ? ev.target.closest('[data-reset]') : null;
    if (t) resetAll();
  });

  /* ---------- view toggle ---------- */
  function setView(v) {
    state.view = v === 'list' ? 'list' : 'grid';
    Array.prototype.forEach.call(document.querySelectorAll('[data-view]'), function (b) {
      var on = b.dataset.view === state.view;
      b.classList.toggle('active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    try { localStorage.setItem('bibliophilia-catalog-view', state.view); } catch (e) {}
    render();
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-view]'), function (b) {
    b.addEventListener('click', function () { setView(b.dataset.view); });
  });

  /* ---------- filter drawer / sidebar toggle ---------- */
  function isMobile() { return window.matchMedia('(max-width: 991.98px)').matches; }
  function closeDrawer() { els.layout.classList.remove('filters-open'); els.backdrop.hidden = true; }
  $('filterToggle').addEventListener('click', function () {
    if (isMobile()) {
      var open = !els.layout.classList.contains('filters-open');
      els.layout.classList.toggle('filters-open', open);
      els.backdrop.hidden = !open;
    } else {
      els.layout.classList.toggle('filters-hidden');
    }
  });
  $('filterClose').addEventListener('click', closeDrawer);
  els.backdrop.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeDrawer(); });
  window.addEventListener('resize', function () { if (!isMobile()) closeDrawer(); });

  /* ---------- paging ---------- */
  els.pager.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-page]');
    if (!b) return;
    state.page = parseInt(b.dataset.page, 10) || 1;
    render();
    els.grid.scrollIntoView({ block: 'start', behavior: 'smooth' });
  });

  /* ---------- borrow request dialog ---------- */
  var modalEl = $('requestModal'), modal = null;
  if (modalEl && window.bootstrap) modal = new bootstrap.Modal(modalEl);
  var confirmHtml = $('reqConfirm') ? $('reqConfirm').innerHTML : '';

  function openRequest(id) {
    var b = byId(id);
    if (!b || !modal) return;
    if (st.slots === 0) { openLimit(); return; }     // never open the confirm dialog at the limit
    var reserve = b.avail < 1;
    var m = cfg.member;
    $('reqBookId').value = b.id;
    $('requestModalTitle').textContent = reserve ? 'Reserve this book' : 'Request to Borrow';
    $('reqTitle').textContent = b.title;
    $('reqAuthor').textContent = b.authors.length ? b.authors.join(', ') : 'Unknown author';
    $('reqMeta').textContent = [b.publisher, b.year || 'No Year', b.isbn ? 'ISBN ' + b.isbn : 'No ISBN'].join(' · ');
    $('reqCover').setAttribute('style', coverStyle(b));
    $('reqCover').innerHTML = coverHtml(b);
    $('reqBadge').innerHTML = badgeHtml(b);
    $('reqCopies').textContent = copiesText(b);
    $('reqMemberName').textContent = m.name;
    $('reqMemberMeta').textContent = m.type + ' · Member #' + m.id +
      (m.max > 0 ? ' · holding ' + m.held + ' of ' + m.max + ' book(s)' : '');
    $('reqNote').textContent = reserve
      ? 'No copy is on the shelf right now. Confirming puts you in the reservation queue; you will be notified when a copy is held for you.'
      : 'Confirming sends a Borrow Request to library staff for review. The book is not marked as borrowed until staff approve it and you collect it' +
        (b.loanDays ? ' (loan period: ' + b.loanDays + ' days).' : '.');
    var btn = $('reqConfirm');
    btn.disabled = false; btn.classList.remove('is-loading'); btn.innerHTML = confirmHtml;
    $('requestForm').dataset.submitting = '';
    modal.show();
  }

  els.grid.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-request]');
    if (btn) { openRequest(parseInt(btn.dataset.request, 10)); return; }
    if (ev.target.closest('[data-limit]')) openLimit();
  });

  /* ---------- borrowing limit alert ---------- */
  var limitEl = $('limitModal'), limitModal = null;
  if (limitEl && window.bootstrap) limitModal = new bootstrap.Modal(limitEl);

  function openLimit() {
    if (!limitModal) return;
    var onlyBorrowed = st.borrowed >= st.max;
    var used = onlyBorrowed ? st.borrowed : st.borrowed + st.pending;
    $('limMsg').textContent = onlyBorrowed
      ? 'You have reached your borrowing limit of ' + st.max + ' book' + (st.max === 1 ? '' : 's') + '. Please return a borrowed book before requesting another one.'
      : 'Your ' + st.borrowed + ' borrowed book(s) and ' + st.pending + ' pending request(s) already fill your limit of ' + st.max +
        '. Wait for staff to review your requests, or return a book, before requesting another.';
    $('limCount').textContent = onlyBorrowed
      ? st.borrowed + ' of ' + st.max + ' books borrowed'
      : st.borrowed + ' of ' + st.max + ' books borrowed + ' + st.pending + ' pending';
    var pct = Math.min(100, Math.round(used / st.max * 100));
    $('limFill').style.width = pct + '%';
    $('limBar').setAttribute('aria-valuemax', st.max);
    $('limBar').setAttribute('aria-valuenow', used);
    limitModal.show();
  }

  /* ---------- "My Borrowing Status" summary + warning (also refreshed by polling) ---------- */
  function pctOf(n) { return st.max > 0 ? Math.min(100, Math.round(n / st.max * 100)) : 0; }
  function renderSummary() {
    var box = $('borrowSummary');
    if (!box || !st.enabled) return;
    box.dataset.state = st.state;
    $('bsBorrowed').textContent = st.borrowed;
    $('bsMax').textContent = st.max;
    $('bsSlots').textContent = st.slots;
    $('bsMessage').textContent = st.message;
    $('bsPending').textContent = st.pending;
    $('bsPendingWrap').hidden = !(st.pending > 0);
    var b = pctOf(st.borrowed);
    $('bsFill').style.width = b + '%';
    $('bsFillPending').style.width = Math.min(100 - b, pctOf(st.pending)) + '%';
    var warn = $('limitWarning');
    if (warn) {
      $('lwTitle').textContent = st.warn_title || '';
      $('lwMsg').textContent = st.warn_message || '';
      if (st.state !== 'warn') warn.hidden = true;
      else if (warn.parentNode) warn.hidden = false;
    }
  }

  var lastStatus = JSON.stringify(st), polling = false;
  function pollStatus() {
    if (polling || document.hidden || !st.enabled) return;
    polling = true;
    fetch('member_borrow_status.php', { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (n) {
        polling = false;
        if (!n || JSON.stringify(n) === lastStatus) return;
        lastStatus = JSON.stringify(n);
        var slotsChanged = n.slots !== st.slots;
        st = n;
        renderSummary();
        if (slotsChanged) render();      // "Limit Reached" <-> "Request to Borrow" flips without a reload
      })
      .catch(function () { polling = false; });
  }
  setInterval(pollStatus, 30000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) pollStatus(); });
  window.addEventListener('focus', pollStatus);

  /* ---------- multi-select ---------- */
  var bar = $('multiForm');
  function refreshPickBar() {
    if (!bar) return;
    var ids = keys(selected), n = ids.length;
    bar.hidden = n === 0;
    $('pickCount').textContent = n;
    $('pickCount2').textContent = n;
    var note = $('pickNote'), slots = cfg.slots;
    note.textContent = slots >= 0
      ? (n > slots ? 'You can only request ' + slots + ' more — extra books will be declined.' : 'You can request ' + slots + ' more book(s).') : '';
    note.className = 'small d-block ' + (slots >= 0 && n > slots ? 'text-danger' : 'text-muted');
  }
  els.grid.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!t.matches || !t.matches('[data-pick]')) return;
    selected[t.dataset.pick] = t.checked;
    var card = t.closest('.book-card');
    if (card) card.classList.toggle('is-picked', t.checked);
    refreshPickBar();
  });
  if (bar) {
    $('pickClear').addEventListener('click', function () { selected = {}; render(); });
    bar.addEventListener('submit', function () {
      Array.prototype.forEach.call(bar.querySelectorAll('input[name="book_ids[]"]'), function (i) { i.remove(); });
      keys(selected).forEach(function (id) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = 'book_ids[]'; i.value = id;
        bar.appendChild(i);
      });
    });
  }

  /* ---------- init ---------- */
  var savedView = 'grid';
  try { savedView = localStorage.getItem('bibliophilia-catalog-view') || 'grid'; } catch (e) {}
  renderFacets();
  setView(savedView);
})();
