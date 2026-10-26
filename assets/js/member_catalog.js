/* Member catalog — server search, combined filters, sorting, paging, grid/list view,
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
    return '<span class="bk-cover-title" aria-hidden="true">' + esc(b.title.length > 38 ? b.title.slice(0, 36) + '…' : b.title) + '</span>';
  }
  function copiesText(b) { return (b.shelf != null ? b.shelf : b.avail) + ' of ' + b.total + ' ' + (b.total === 1 ? 'Copy' : 'Copies') + ' on shelf'; }

  var statusFacets = [
    { value: 'available', label: 'Available to borrow' },
    { value: 'onloan', label: 'Borrowed' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'reference', label: 'Library use only' }
  ];
  var sectionFacets = [], categoryFacets = [], authorMap = {}, authorList = [];
  var initial = new URLSearchParams(location.search);
  state.q = initial.get('q') || ''; state.sort = initial.get('sort') || 'relevant';
  state.page = Math.max(1, parseInt(initial.get('page'), 10) || 1);
  ['status','section','category','author'].forEach(function (group) {
    initial.getAll(group + '[]').forEach(function (v) { state[group][v] = true; });
  });
  els.search.value = state.q; els.sort.value = state.sort;

  function facetHtml(group, f) {
    var id = 'f-' + group + '-' + esc(f.value);
    return '<div class="form-check cf-check">' +
      '<input class="form-check-input" type="checkbox" id="' + id + '" data-group="' + group + '" data-value="' + esc(f.value) + '"' +
      (state[group][f.value] ? ' checked' : '') + '>' +
      '<label class="form-check-label" for="' + id + '">' + esc(f.label) +
      (f.dot ? ' <span class="cf-dot" aria-hidden="true"></span>' : '') +
      (f.n == null ? '' : ' <span class="cf-n">(' + f.n + ')</span>') + '</label></div>';
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
        '<label class="form-check-label" for="' + id + '">' + esc(a) + (authorMap[a] == null ? '' : ' <span class="cf-n">(' + authorMap[a] + ')</span>') + '</label></div>';
    }).join('');
    if (!list.length) html = '<div class="small text-muted">No authors found.</div>';
    else if (matches.length > list.length - sel.length) html += '<div class="small text-muted mt-1">Type to narrow ' + (matches.length - (list.length - sel.length)) + ' more…</div>';
    $('facet-author').innerHTML = html;
  }

  /* ---------- rendering ---------- */
  function badgeHtml(b) {
    if (!b.borrowable) return '<span class="badge text-bg-secondary bk-badge">Library Use Only</span>';
    if (b.avail > 0) return '<span class="badge text-bg-success bk-badge">Available</span>';
    if (b.shelf > 0) return '<span class="badge text-bg-warning bk-badge" title="The last copy stays in the library. You can reserve it or read it at the library.">In library only</span>';
    return '<span class="badge text-bg-danger bk-badge">Unavailable</span>';
  }
  function actionHtml(b) {
    if (!b.borrowable) return '<span class="small text-muted"><i class="bi bi-lock"></i> Not for borrowing</span>';
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
    if (b.edition) meta.push(b.edition);
    meta.push(b.year ? b.year : 'No Year');
    meta.push(b.isbn ? 'ISBN ' + b.isbn : 'No ISBN');
    var pick = (cfg.canRequest && b.borrowable && !b.mine && st.slots !== 0)
      ? '<input type="checkbox" class="form-check-input bk-pick" data-pick="' + b.id + '"' + (selected[b.id] ? ' checked' : '') +
        ' aria-label="Select ' + esc(b.title) + '">' : '';
    return '<article class="book-card' + (selected[b.id] ? ' is-picked' : '') + '" role="listitem">' +
      '<div class="bk-cover" style="' + coverStyle(b) + '">' + coverHtml(b) + pick + '</div>' +
      '<div class="bk-main">' +
        '<div class="bk-head"><h3 class="bk-title">' + esc(b.title) + '</h3>' + badgeHtml(b) + '</div>' +
        '<div class="bk-author">' + esc(b.authors.length ? b.authors.join(', ') : 'Unknown author') + '</div>' +
        '<div class="bk-meta">' + meta.map(esc).join(' · ') + '</div>' +
        '<div class="bk-tags"><span class="bk-tag"><i class="bi bi-tag"></i> ' + esc(b.category) + '</span>' +
          '<span class="bk-tag"><i class="bi bi-geo-alt"></i> ' + esc(b.section) + '</span></div>' +
        '<div class="small text-muted mt-2"><i class="bi bi-bookshelf" aria-hidden="true"></i> ' + esc(b.locations) + '</div>' +
        '<div class="bk-foot">' +
          (b.borrowable ? '<span class="bk-copies">' + esc(copiesText(b)) + '</span>' : '<span></span>') +
          actionHtml(b) +
        '</div>' +
      '</div></article>';
  }

  var pendingRequest = null, requestSequence = 0, lastPage = null;
  function requestParams() {
    var params = new URLSearchParams();
    if (state.q) params.set('q', state.q);
    params.set('sort', state.sort); params.set('page', state.page);
    ['status','section','category','author'].forEach(function (group) {
      keys(state[group]).forEach(function (v) { params.append(group + '[]', v); });
    });
    return params;
  }
  function render() {
    if (pendingRequest) pendingRequest.abort();
    pendingRequest = new AbortController();
    var sequence = ++requestSequence, params = requestParams();
    params.set('author_q', state.authorQ);
    els.grid.setAttribute('aria-busy', 'true');
    els.grid.hidden = true; els.empty.hidden = true; els.pager.hidden = true;
    $('catError').hidden = true; els.count.textContent = 'Searching the catalog…';
    fetch(cfg.endpoint + '&' + params.toString(), { credentials: 'same-origin', cache: 'no-store', signal: pendingRequest.signal })
      .then(function (r) {
        if (r.redirected) throw new Error('Your session has ended. Please sign in again.');
        if (!r.ok) throw new Error('The catalog could not be loaded. Please try again.');
        return r.json();
      }).then(function (result) {
        if (sequence !== requestSequence) return;
        if (!Array.isArray(result.books)) throw new Error('Unexpected catalog response. Please sign in again.');
        books = result.books; state.page = result.page; lastPage = result;
        sectionFacets = result.facets.section; categoryFacets = result.facets.category;
        authorList = result.facets.authors.map(function (a) { authorMap[a.label] = a.n; return a.label; });
        renderFacets(); draw(result);
        var url = new URL(location.href); url.search = requestParams().toString();
        history.replaceState(null, '', url);
      }).catch(function (err) {
        if (err.name === 'AbortError' || sequence !== requestSequence) return;
        els.count.textContent = 'Catalog unavailable';
        $('catErrorMessage').textContent = err.message || 'Could not load books. Please try again.';
        $('catError').hidden = false;
      }).finally(function () {
        if (sequence === requestSequence) els.grid.setAttribute('aria-busy', 'false');
      });
  }
  function draw(result) {
    var total = result.total, start = (result.page - 1) * result.perPage;
    els.count.textContent = total ? 'Showing ' + (start + 1) + '-' + (start + books.length) + ' of ' + total + ' results' : 'No matching books';
    els.grid.className = 'book-grid' + (state.view === 'list' ? ' is-list' : '');
    els.grid.innerHTML = books.map(cardHtml).join('');
    els.empty.hidden = total !== 0; els.grid.hidden = total === 0;
    renderPager(result.pages); refreshPickBar();
  }
  function renderPager(pages) {
    els.pager.hidden = pages <= 1;
    if (pages <= 1) { els.pager.innerHTML = ''; return; }
    function button(page, label, disabled, current) {
      return '<li class="page-item' + (current ? ' active' : '') + '"><button class="page-link" data-page="' + page + '"' +
        (disabled ? ' disabled' : '') + (current ? ' aria-current="page"' : '') + '>' + label + '</button></li>';
    }
    var html = '<ul class="pagination pagination-sm flex-wrap mb-0">' + button(state.page - 1, 'Previous', state.page === 1, false);
    var last = 0;
    for (var p = 1; p <= pages; p++) {
      if (p !== 1 && p !== pages && Math.abs(p - state.page) > 2) continue;
      if (last && p - last > 1) html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
      html += button(p, p, false, p === state.page); last = p;
    }
    els.pager.innerHTML = html + button(state.page + 1, 'Next', state.page === pages, false) + '</ul>';
  }
  $('catRetry').addEventListener('click', render);
  var searchDelay;
  function searchSoon() { clearTimeout(searchDelay); searchDelay = setTimeout(render, 250); }

  /* ---------- events: search, sort, filters ---------- */
  els.search.addEventListener('input', function () { state.q = els.search.value; state.page = 1; searchSoon(); });
  els.sort.addEventListener('change', function () { state.sort = els.sort.value; state.page = 1; render(); });
  els.authorSearch.addEventListener('input', function () { state.authorQ = els.authorSearch.value; searchSoon(); });

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
    if (lastPage) draw(lastPage); else render();
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-view]'), function (b) {
    b.addEventListener('click', function () { setView(b.dataset.view); });
  });

  /* ---------- filter drawer / sidebar toggle ---------- */
  function isMobile() { return window.matchMedia('(max-width: 991.98px)').matches; }
  function closeDrawer() { els.layout.classList.remove('filters-open'); els.backdrop.hidden = true; $('filterToggle').setAttribute('aria-expanded','false'); }
  $('filterToggle').addEventListener('click', function () {
    if (isMobile()) {
      var open = !els.layout.classList.contains('filters-open');
      els.layout.classList.toggle('filters-open', open);
      els.backdrop.hidden = !open; $('filterToggle').setAttribute('aria-expanded',String(open));
      if (open) $('filterClose').focus();
    } else {
      els.layout.classList.toggle('filters-hidden');
    }
  });
  $('filterClose').addEventListener('click', function () { closeDrawer(); $('filterToggle').focus(); });
  els.backdrop.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeDrawer(); });
  window.addEventListener('resize', function () { if (!isMobile()) closeDrawer(); });

  /* ---------- paging ---------- */
  els.pager.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-page]');
    if (!b) return;
    state.page = parseInt(b.dataset.page, 10) || 1;
    render();
    els.grid.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
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
      ? 'No copy is available to borrow right now. Confirming puts you in the reservation queue; you will be notified when a copy is held for you.'
      : 'Confirming sends a Borrow Request to library staff for review. The book is not marked as borrowed until staff approve it and you collect it' +
        (b.loanDays ? ' (borrowing period: ' + b.loanDays + ' days).' : '.');
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
    var note = $('pickNote'), slots = st.slots;
    note.textContent = slots >= 0
      ? (n > slots ? 'You can only request ' + slots + ' more — extra books will be declined.' : 'You can request ' + slots + ' more book(s).') : '';
    note.className = 'small d-block ' + (slots >= 0 && n > slots ? 'text-danger' : 'text-muted');
  }
  els.grid.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!t.matches || !t.matches('[data-pick]')) return;
    if (t.checked && keys(selected).length >= 20) { t.checked = false; $('pickNote').textContent = 'Request up to 20 books at a time.'; return; }
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
