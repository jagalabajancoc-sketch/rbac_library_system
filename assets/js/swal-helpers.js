/* ==========================================================================
   Bibliophilia — SweetAlert2 helpers
   Safe to call even if SweetAlert2 fails to load (falls back to native).
   ========================================================================== */
(function (window) {
  "use strict";

  var ICON_MAP = {
    success: "success",
    danger: "error",
    error: "error",
    warning: "warning",
    warn: "warning",
    info: "info",
  };

  function hasSwal() {
    return (
      typeof window.Swal !== "undefined" &&
      typeof window.Swal.fire === "function"
    );
  }

  function nativeFallback(icon, title, message) {
    var text = (title ? title : "") + (message ? "\n\n" + message : "");
    if (!text.trim()) text = "(no message)";
    try {
      window.alert(text);
    } catch (e) {
      console.log(text);
    }
  }

  /* ---------- Flash-message toast (top-right) ---------- */
  window.swalToast = function (type, message) {
    if (!hasSwal()) {
      nativeFallback(type, "", message);
      return Promise.resolve();
    }
    var icon = ICON_MAP[type] || "info";
    var isLong = (message || "").length > 90;

    return window.Swal.fire({
      toast: !isLong,
      position: "top-end",
      icon: icon,
      title: message,
      showConfirmButton: isLong ? true : false,
      confirmButtonText: isLong ? "OK" : undefined,
      timer: isLong ? undefined : 5000,
      timerProgressBar: !isLong,
      width: isLong ? "520px" : undefined,
      didOpen: function (popup) {
        if (isLong) return;
        popup.addEventListener("mouseenter", window.Swal.stopTimer);
        popup.addEventListener("mouseleave", window.Swal.resumeTimer);
      },
    });
  };

  /* ---------- Quick popups ---------- */
  window.swalNotice = function (message, title) {
    if (!hasSwal()) {
      nativeFallback("info", title || "", message);
      return Promise.resolve();
    }
    return window.Swal.fire({
      icon: "info",
      title: title || "Notice",
      text: message || "",
      confirmButtonText: "OK",
    });
  };

  window.swalSuccess = function (title, message) {
    if (!hasSwal()) {
      nativeFallback("success", title, message);
      return Promise.resolve();
    }
    return window.Swal.fire({
      icon: "success",
      title: title || "Done",
      text: message || "",
      confirmButtonText: "OK",
      confirmButtonColor: "#059669",
    });
  };

  window.swalError = function (title, message) {
    if (!hasSwal()) {
      nativeFallback("error", title, message);
      return Promise.resolve();
    }
    return window.Swal.fire({
      icon: "error",
      title: title || "Something went wrong",
      text: message || "",
      confirmButtonText: "OK",
      confirmButtonColor: "#dc2626",
    });
  };

  window.swalWarn = function (title, message) {
    if (!hasSwal()) {
      nativeFallback("warning", title, message);
      return Promise.resolve();
    }
    return window.Swal.fire({
      icon: "warning",
      title: title || "Attention",
      text: message || "",
      confirmButtonText: "OK",
      confirmButtonColor: "#d97706",
    });
  };

  window.swalInfo = function (title, message) {
    if (!hasSwal()) {
      nativeFallback("info", title, message);
      return Promise.resolve();
    }
    return window.Swal.fire({
      icon: "info",
      title: title || "Information",
      text: message || "",
      confirmButtonText: "OK",
      confirmButtonColor: "#0284c7",
    });
  };

  /* ---------- Promise-based confirmation ----------
     Usage:
       swalConfirm({title:'Delete?', text:'Cannot be undone.'})
         .then(r => { if (r.isConfirmed) { ... } });
  */
  window.swalConfirm = function (opts) {
    opts = opts || {};
    var title = opts.title || "Please confirm";
    var text = opts.text || "";
    var icon = opts.icon || "question";
    var confirmText = opts.confirmText || "Yes, continue";
    var cancelText = opts.cancelText || "Cancel";
    var confirmColor = opts.confirmColor || "#0f6e5a";
    var cancelColor = opts.cancelColor || "#6b7280";

    if (!hasSwal()) {
      var ok = window.confirm(title + (text ? "\n\n" + text : ""));
      return Promise.resolve({ isConfirmed: ok, isDismissed: !ok });
    }

    return window.Swal.fire({
      title: title,
      text: text,
      icon: icon,
      showCancelButton: true,
      confirmButtonText: confirmText,
      cancelButtonText: cancelText,
      confirmButtonColor: confirmColor,
      cancelButtonColor: cancelColor,
      reverseButtons: true,
      focusCancel: true,
    });
  };
})(window);
