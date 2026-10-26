/* Circulation controls enhance existing server-validated forms. */
(function () {
  'use strict';
  // Remember only presentation state; never keep book selections or return conditions.
  var script = document.querySelector('script[data-circulation-user]');
  var query = new URLSearchParams(location.search);
  query.delete('receipt'); query.delete('view'); query.sort();
  var stateKey = 'circulation:' + (script ? script.dataset.circulationUser : '') + ':' + location.pathname + '?' + query.toString();
  var details = Array.from(document.querySelectorAll('details[data-member-id], details[data-submission-id]'));
  function identity(detail) { return detail.dataset.memberId ? 'member:' + detail.dataset.memberId : 'submission:' + detail.dataset.submissionId; }
  try {
    var state = JSON.parse(sessionStorage.getItem(stateKey) || 'null');
    if (state && Date.now() - state.at < 1800000 && details.length) {
      details.forEach(function (detail) { if (Object.prototype.hasOwnProperty.call(state.open, identity(detail))) detail.open = state.open[identity(detail)]; });
      if (!location.hash && !new URLSearchParams(location.search).has('receipt') && !new URLSearchParams(location.search).has('view')) requestAnimationFrame(function () { window.scrollTo(0, state.y || 0); });
    }
  } catch (_) { /* Storage may be unavailable; native controls still work. */ }
  function saveView() {
    if (!details.length) return;
    var open = {}; details.forEach(function (detail) { open[identity(detail)] = detail.open; });
    try { sessionStorage.setItem(stateKey, JSON.stringify({at: Date.now(), open: open, y: window.scrollY})); } catch (_) {}
  }
  window.addEventListener('pagehide', saveView);
  document.addEventListener('submit', saveView);
  document.querySelectorAll('[data-group-toggle]').forEach(function (button) {
    button.hidden = false;
    button.addEventListener('click', function () {
      var groups = document.querySelectorAll('.borrower-group');
      var expand = Array.from(groups).some(function (group) { return !group.open; });
      groups.forEach(function (group) { group.open = expand; });
      updateGroupControl();
    });
  });
  function updateGroupControl() {
    var groups = Array.from(document.querySelectorAll('.borrower-group'));
    document.querySelectorAll('[data-group-toggle]').forEach(function (button) {
      button.textContent = groups.length && groups.every(function (group) { return group.open; }) ? 'Collapse members' : 'Expand members';
      button.disabled = !groups.length;
    });
  }
  document.querySelectorAll('.borrower-group').forEach(function (group) { group.addEventListener('toggle', updateGroupControl); });
  updateGroupControl();
  // Keep real table semantics on desktop and label each stacked cell on phones.
  document.querySelectorAll('#mainContent table, #viewLoanModal table, .request-modal table').forEach(function (table) {
    var headings = Array.from(table.querySelectorAll('thead th')).map(function (heading) { return heading.textContent.trim(); });
    table.querySelectorAll('tbody tr').forEach(function (row) {
      Array.from(row.cells).forEach(function (cell, index) { if (cell.tagName === 'TD') cell.dataset.label = headings[index] || ''; });
    });
    table.classList.add('mobile-records');
  });
  function selected(form, selector) { return form.querySelectorAll(selector + ':checked').length; }
  document.querySelectorAll('[data-return-form]').forEach(function (form) {
    var boxes = form.querySelectorAll('.return-pick');
    if (!boxes.length) return;
    function update() {
      boxes.forEach(function (box) { document.getElementById(box.dataset.condition).disabled = !box.checked; });
      var count = selected(form, '.return-pick');
      form.querySelector('[data-selected-count]').textContent = count + (count === 1 ? ' book selected' : ' books selected');
      form.querySelector('[data-review-returns]').disabled = count === 0;
      form.querySelector('[data-clear-selection]').disabled = count === 0;
    }
    boxes.forEach(function (box) { box.addEventListener('change', update); });
    // Per-borrower shortcuts: act only on the books inside that borrower's group.
    form.querySelectorAll('[data-select-all], [data-select-none]').forEach(function (button) {
      button.addEventListener('click', function () {
        var checked = button.hasAttribute('data-select-all');
        var group = button.closest('details');
        (group ? group.querySelectorAll('.return-pick') : []).forEach(function (box) { box.checked = checked; });
        update();
      });
    });
    form.querySelector('[data-clear-selection]').addEventListener('click', function () { boxes.forEach(function (box) { box.checked = false; }); update(); });
    form.addEventListener('submit', function (event) { if (!selected(form, '.return-pick')) event.preventDefault(); });
    update();
    window.addEventListener('pageshow', update);
  });
  document.querySelectorAll('.request-action-form').forEach(function (form) {
    var output = form.querySelector('[data-selected-count]');
    if (!output) return;
    function update() {
      var count = selected(form, 'input[name="request_ids[]"]');
      output.textContent = count + (count === 1 ? ' book selected' : ' books selected');
      var choices = Array.from(form.querySelectorAll('input[name="request_ids[]"]:checked'));
      var pending = count > 0 && choices.every(function (box) { return box.dataset.requestStatus === 'Pending'; });
      var approved = count > 0 && choices.every(function (box) { return box.dataset.requestStatus === 'Approved'; });
      form.querySelectorAll('button[name="action"]').forEach(function (button) {
        var available = button.value === 'cancel' || (['approve', 'reject'].includes(button.value) && pending) || (button.value === 'release' && approved);
        button.hidden = !available;
        button.disabled = !count || !available;
      });
      var reason = form.querySelector('[data-request-reason]');
      if (reason) {
        reason.hidden = !count;
        var label = reason.querySelector('[data-reason-label]');
        if (label) label.textContent = pending && form.querySelector('button[value="reject"]') ? 'Reason (required for rejection)' : 'Reason (optional)';
      }
      form.querySelector('[data-action-hint]').textContent = !form.querySelector('button[value="approve"],button[value="release"]') ? (count ? 'Selected requests can be cancelled.' : 'Select books to cancel.') : !count ? 'Select books to see available actions.' : pending ? 'Pending books can be approved, rejected or cancelled.' : approved ? 'Approved books are ready to release or cancel.' : 'Mixed statuses: select only pending or approved books to process them together, or cancel the selection.';
    }
    form.querySelectorAll('[data-select-status]').forEach(function(button){
      button.addEventListener('click',function(){
        form.querySelectorAll('input[name="request_ids[]"]').forEach(function(box){box.checked=box.dataset.requestStatus===button.dataset.selectStatus;});
        update();
      });
    });
    form.querySelectorAll('input[name="request_ids[]"]').forEach(function (box) { box.addEventListener('change', update); });
    update();
    window.addEventListener('pageshow', update);
  });
})();
