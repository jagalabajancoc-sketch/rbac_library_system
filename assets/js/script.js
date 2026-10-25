/* ==========================================================================
   Bibliophilia — UI behaviour
   Theme toggle, sidebar collapse, scroll preservation, mobile sidebar,
   SweetAlert2 confirmations, SweetAlert2 flash toasts + centered modals.
   ========================================================================== */
(function () {
  "use strict";

  /* ========================================================================
     THEME
     ======================================================================== */
  function applyTheme(mode) {
    document.documentElement.setAttribute("data-theme", mode);
    try {
      localStorage.setItem("bibliophilia-theme", mode);
    } catch (_) {}
  }
  function toggleTheme() {
    var current =
      document.documentElement.getAttribute("data-theme") || "light";
    applyTheme(current === "dark" ? "light" : "dark");
  }

  /* ========================================================================
     SIDEBAR COLLAPSE
     ======================================================================== */
  function applySidebar(mode) {
    document.documentElement.setAttribute("data-sidebar", mode);
    try {
      localStorage.setItem("bibliophilia-sidebar", mode);
    } catch (_) {}
  }
  function toggleSidebar() {
    var current =
      document.documentElement.getAttribute("data-sidebar") || "expanded";
    applySidebar(current === "collapsed" ? "expanded" : "collapsed");
  }

  /* ========================================================================
     SIDEBAR SCROLL PRESERVATION
     ======================================================================== */
  var SCROLL_KEY = "bibliophilia-sidebar-scroll";

  function getSidebarNav() {
    return document.querySelector(".sidebar-nav");
  }

  function saveSidebarScroll() {
    var nav = getSidebarNav();
    if (!nav) return;
    try {
      sessionStorage.setItem(SCROLL_KEY, String(nav.scrollTop));
    } catch (_) {}
  }

  function restoreSidebarScroll() {
    var nav = getSidebarNav();
    if (!nav) return;
    var saved;
    try {
      saved = sessionStorage.getItem(SCROLL_KEY);
    } catch (_) {
      return;
    }
    if (saved === null) return;
    var pos = parseInt(saved, 10) || 0;
    if (pos <= 0) return;
    var max = nav.scrollHeight - nav.clientHeight;
    if (max <= 0) return;
    nav.scrollTop = Math.min(pos, max);
  }

  document.addEventListener(
    "scroll",
    function (e) {
      var nav = getSidebarNav();
      if (
        nav &&
        (e.target === nav ||
          (e.target &&
            e.target.classList &&
            e.target.classList.contains("sidebar-nav")))
      ) {
        saveSidebarScroll();
      }
    },
    true,
  );

  window.addEventListener("beforeunload", saveSidebarScroll);
  window.addEventListener("pagehide", saveSidebarScroll);
  document.addEventListener(
    "click",
    function (e) {
      if (e.target.closest(".sidebar a")) saveSidebarScroll();
    },
    true,
  );

  restoreSidebarScroll();
  requestAnimationFrame(restoreSidebarScroll);
  setTimeout(restoreSidebarScroll, 0);
  setTimeout(restoreSidebarScroll, 80);
  setTimeout(restoreSidebarScroll, 220);
  document.addEventListener("DOMContentLoaded", restoreSidebarScroll);
  window.addEventListener("load", restoreSidebarScroll);

  if ("scrollRestoration" in history) history.scrollRestoration = "manual";

  /* ========================================================================
     CONFIRMATION DIALOGS
     ======================================================================== */
  function proceedWith(el) {
    if (el.tagName === "A" && el.href) {
      window.location.href = el.href;
    } else if (el.tagName === "FORM") {
      el.submit();
    } else {
      var form = el.closest("form");
      if (form) form.submit();
    }
  }

  function inferConfirmStyle(msg) {
    var m = (msg || "").toLowerCase();

    if (m.indexOf("archive") >= 0)
      return {
        title: "Archive this record?",
        confirmText: "Yes, archive",
        confirmColor: "#d97706",
        icon: "warning",
      };
    if (m.indexOf("restore") >= 0)
      return {
        title: "Restore this record?",
        confirmText: "Yes, restore",
        confirmColor: "#059669",
        icon: "question",
      };
    if (m.indexOf("delete") >= 0 || m.indexOf("remove") >= 0)
      return {
        title: "Delete this record?",
        confirmText: "Yes, delete",
        confirmColor: "#dc2626",
        icon: "warning",
      };
    if (
      m.indexOf("deactivate") >= 0 ||
      m.indexOf("disable") >= 0 ||
      m.indexOf("sign-in") >= 0
    )
      return {
        title: "Deactivate this account?",
        confirmText: "Yes, deactivate",
        confirmColor: "#d97706",
        icon: "warning",
      };
    if (m.indexOf("reactivate") >= 0 || m.indexOf("enable") >= 0)
      return {
        title: "Reactivate this account?",
        confirmText: "Yes, reactivate",
        confirmColor: "#059669",
        icon: "question",
      };
    if (m.indexOf("return") >= 0)
      return {
        title: "Confirm return",
        confirmText: "Yes, mark returned",
        confirmColor: "#059669",
        icon: "question",
      };
    if (m.indexOf("cancel") >= 0)
      return {
        title: "Cancel this request?",
        confirmText: "Yes, cancel",
        confirmColor: "#dc2626",
        icon: "warning",
      };

    return {
      title: "Please confirm",
      confirmText: "Yes, continue",
      confirmColor: "#0f6e5a",
      icon: "question",
    };
  }

  document.addEventListener(
    "click",
    function (e) {
      var el = e.target.closest("[data-confirm]");
      if (!el) return;
      e.preventDefault();
      e.stopPropagation();

      var msg = el.getAttribute("data-confirm") || "Are you sure?";
      var style = inferConfirmStyle(msg);

      if (typeof Swal === "undefined" || typeof Swal.fire !== "function") {
        if (window.confirm(style.title + "\n\n" + msg)) proceedWith(el);
        return;
      }

      Swal.fire({
        title: style.title,
        text: msg,
        icon: style.icon,
        showCancelButton: true,
        confirmButtonText: style.confirmText,
        cancelButtonText: "Cancel",
        confirmButtonColor: style.confirmColor,
        cancelButtonColor: "#6b7280",
        reverseButtons: true,
        focusCancel: true,
      }).then(function (result) {
        if (result.isConfirmed) proceedWith(el);
      });
    },
    true,
  );

  /* ========================================================================
     FLASH MESSAGES
     Two modes:
       - modal: true  → centered popup (for important messages)
       - modal: false → corner toast (routine)
     ======================================================================== */
  function processFlashMessages() {
    var dataEl = document.getElementById("flash-data");
    var fallbackEl = document.getElementById("flash-fallback");

    if (!dataEl) {
      if (fallbackEl) fallbackEl.classList.remove("d-none");
      return;
    }

    var flashes = [];
    try {
      flashes = JSON.parse(dataEl.textContent || "[]");
    } catch (_) {
      flashes = [];
    }

    if (typeof Swal === "undefined" || typeof window.swalToast !== "function") {
      if (fallbackEl) fallbackEl.classList.remove("d-none");
      return;
    }

    if (fallbackEl) fallbackEl.remove();

    flashes.forEach(function (f, idx) {
      setTimeout(function () {
        if (f.modal) {
          /* Centered modal for important messages */
          var iconMap = {
            success: "success",
            danger: "error",
            warning: "warning",
            info: "info",
          };
          var titles = {
            success: "Success",
            danger: "Action needed",
            warning: "Attention",
            info: "Notice",
          };
          Swal.fire({
            icon: iconMap[f.type] || "info",
            title: titles[f.type] || "Notice",
            html:
              '<div style="text-align:left;font-size:0.95rem;line-height:1.5;">' +
              String(f.message)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;") +
              "</div>",
            confirmButtonText: "Got it",
            confirmButtonColor: "#0f6e5a",
            allowOutsideClick: false,
            allowEscapeKey: false,
            customClass: { popup: "swal-wide-popup" },
          });
        } else {
          window.swalToast(f.type, f.message);
        }
      }, idx * 250);
    });
  }

  /* ========================================================================
     DOM READY
     ======================================================================== */
  document.addEventListener("DOMContentLoaded", function () {
    /* Theme toggle */
    document
      .querySelectorAll('[data-action="toggle-theme"]')
      .forEach(function (btn) {
        btn.addEventListener("click", function (e) {
          e.preventDefault();
          toggleTheme();
        });
      });

    /* Sidebar collapse toggle */
    document
      .querySelectorAll('[data-action="toggle-sidebar"]')
      .forEach(function (btn) {
        btn.addEventListener("click", function (e) {
          e.preventDefault();
          toggleSidebar();
        });
      });

    /* Mobile sidebar toggle */
    var toggle = document.getElementById("sidebarToggle");
    var sidebar = document.getElementById("sidebar");
    if (toggle && sidebar) {
      toggle.addEventListener("click", function (e) {
        e.stopPropagation();
        sidebar.classList.toggle("show");
      });
      document.addEventListener("click", function (e) {
        if (window.innerWidth < 992 && sidebar.classList.contains("show")) {
          if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
            sidebar.classList.remove("show");
          }
        }
      });
    }

    /* Auto-dismiss any Bootstrap alerts that are visible (fallback path) */
    document.querySelectorAll(".alert-dismissible").forEach(function (el) {
      setTimeout(function () {
        try {
          bootstrap.Alert.getOrCreateInstance(el).close();
        } catch (_) {}
      }, 5500);
    });

    /* Tooltips */
    document
      .querySelectorAll('[data-bs-toggle="tooltip"]')
      .forEach(function (el) {
        new bootstrap.Tooltip(el);
      });

    /* Turn any queued flash messages into toasts or modals */
    processFlashMessages();

    restoreSidebarScroll();
  });
})(window);


/* Loading state: forms marked data-loading disable their submit button and show a
   spinner once submitted, which also stops accidental double submissions. */
document.addEventListener('submit', function (ev) {
  var form = ev.target;
  if (!form.matches || !form.matches('form[data-loading]') || ev.defaultPrevented) return;
  if (form.dataset.submitting === '1') { ev.preventDefault(); return; }
  form.dataset.submitting = '1';
  var btn = form.querySelector('button[type="submit"], button:not([type])');
  if (btn) {
    btn.classList.add('is-loading');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Please wait…';
  }
});
