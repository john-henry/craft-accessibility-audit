/**
 * Accessibility Audit: decoupled overlay loader.
 *
 * Served by OverlayController::actionScript with a boot config prepended as
 * window.__A11Y_OVERLAY_BOOT__ ({ resolveUrl, cssUrls, jsUrls }). Included on
 * decoupled front ends with a plain script tag; inert for every visitor until
 * an overlay token is present, so it is safe to ship in production bundles.
 *
 * Activation: an admin opens the front end via the activation link from the
 * plugin's Tools settings, carrying the token in the URL fragment
 * (#a11y-connect=TOKEN; fragments never reach servers or logs). The loader
 * strips it from the address bar before any network call, validates it via
 * the resolve endpoint, and keeps it in localStorage. Ordinary visitors get
 * total silence; a fragment is explicit intent, so activation always answers
 * with a small notice, saved or refused. */
(function () {
  'use strict';

  if (window.__accessibilityAuditLoaderRan) return;
  window.__accessibilityAuditLoaderRan = true;

  // The CP Inspect preview loads pages with this param and runs its own axe
  // pass; the overlay would double up (frontend-axe.js has the same guard).
  if (new URLSearchParams(window.location.search).get('accessibility-audit-preview') === '1') return;

  var BOOT = window.__A11Y_OVERLAY_BOOT__ || {};
  if (!BOOT.resolveUrl) return;

  var KEY = 'a11yOverlayToken';

  // On a page the injected overlay already owns (hybrid install), a second
  // boot would double the panel; a fragment token still gets processed below.
  var injectedOwnsPage = !!window.__accessibilityAudit;

  // ─── Token discovery: URL fragment first, then localStorage ──────────────
  var fromFragment = false;
  var token = '';
  var match = /(?:^|[#&])a11y-connect=([^&]+)/.exec(window.location.hash || '');
  if (match) {
    try { token = decodeURIComponent(match[1]); } catch (_) { token = match[1]; }
    fromFragment = true;
    // Strip the token from the address bar before any network call, keeping
    // any other fragment the URL legitimately carries (#section-2).
    try {
      var rest = (window.location.hash || '').replace(/(^#|&)a11y-connect=[^&]*/, '').replace(/^[#&]+/, '');
      var clean = window.location.pathname + window.location.search + (rest ? '#' + rest : '');
      window.history.replaceState(null, '', clean);
    } catch (_) {}
  } else {
    try { token = window.localStorage.getItem(KEY) || ''; } catch (_) {}
  }

  if (!token) return;
  if (injectedOwnsPage && !fromFragment) return;

  /* Feedback for the activation flow only; passers-by never carry a
     fragment, so ordinary visitors never see it. */
  function notice(message, isError) {
    function show() {
      var el = document.createElement('div');
      el.setAttribute('role', 'status');
      el.style.cssText =
        'position:fixed;bottom:16px;right:16px;z-index:2147483647;' +
        'max-width:320px;padding:10px 14px;border-radius:8px;' +
        'font:13px/1.4 system-ui,sans-serif;color:#fff;' +
        'background:' + (isError ? '#8c2f39' : '#1f2937') + ';' +
        'box-shadow:0 4px 12px rgba(0,0,0,.25);';
      el.textContent = message;
      document.body.appendChild(el);
      setTimeout(function () { el.remove(); }, 6000);
    }
    if (document.body) { show(); } else { document.addEventListener('DOMContentLoaded', show); }
  }

  // ─── Resolve: authenticates the token and returns the overlay config ─────
  fetch(BOOT.resolveUrl, {
    method: 'POST',
    credentials: 'omit',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + token,
    },
    body: JSON.stringify({ url: window.location.href }),
  })
    .then(function (res) {
      if (res.status === 401 || res.status === 403) {
        // Revoked or wrong token: forget it so a public visitor's browser
        // stops calling home on every page view.
        try { window.localStorage.removeItem(KEY); } catch (_) {}
        if (fromFragment) {
          notice('Accessibility Audit: that activation token was not accepted. Generate a fresh one in the plugin settings.', true);
        }
        return null;
      }
      return res.ok ? res.json() : null;
    })
    .then(function (data) {
      if (!data || !data.success || !data.config) {
        if (fromFragment && data && data.error) {
          // e.g. Standard edition, or the feature switched off: say why
          // instead of silently doing nothing.
          notice('Accessibility Audit: ' + data.error, true);
        }
        return;
      }

      if (fromFragment) {
        try { window.localStorage.setItem(KEY, token); } catch (_) {}
        notice('Accessibility Audit overlay activated in this browser.');
      }

      // Token saved and confirmed; the injected overlay still owns this
      // page's panel.
      if (injectedOwnsPage) return;

      var cfg = data.config;
      cfg.token = token;
      window.__accessibilityAudit = cfg;

      (BOOT.cssUrls || []).forEach(function (href) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
      });

      // Overlay scripts in order: the shared helpers must execute before
      // frontend-axe.js reads them, so each script waits for the previous.
      var scripts = (BOOT.jsUrls || []).slice();
      (function next() {
        var src = scripts.shift();
        if (!src) return;
        var el = document.createElement('script');
        el.src = src;
        el.onload = next;
        document.head.appendChild(el);
      })();
    })
    .catch(function () {
      // Silent: the overlay is a dev tool; a failed boot must never surface
      // on someone's front end.
    });
})();
