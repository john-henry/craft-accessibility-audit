/**
 * Accessibility Audit: front-end overlay loader for cached pages.
 *
 * Registered by FrontendOverlayLoaderAsset on every site page, for every
 * visitor, so a full-page cache can hold it safely. A page rendered for an
 * admin already carries window.__accessibilityAudit and needs nothing from
 * here; a page served from the cache doesn't, so this asks the session config
 * endpoint for the overlay instead.
 *
 * Only a browser carrying the admin marker cookie makes that request. The
 * marker is a hint, not a credential: the endpoint decides from the session,
 * and expires the marker itself when the session is no admin's.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var configUrl = script ? script.getAttribute('data-config-url') : '';
  var marker = script ? script.getAttribute('data-marker') : '';

  if (!configUrl || !marker) return;

  // A fresh render for an admin injected the overlay already.
  if (window.__accessibilityAudit || window.__accessibilityAuditOverlayClaim) return;

  // The CP Inspect preview loads pages with this param and runs its own axe
  // pass; the overlay would double up (frontend-axe.js has the same guard).
  if (new URLSearchParams(window.location.search).get('accessibility-audit-preview') === '1') return;

  var hasMarker = document.cookie.split(';').some(function (pair) {
    return pair.trim().indexOf(marker + '=') === 0;
  });

  if (!hasMarker) return;

  var pageUrl = window.location.href.split('#')[0];
  var requestUrl = configUrl + (configUrl.indexOf('?') === -1 ? '?' : '&') + 'url=' + encodeURIComponent(pageUrl);

  fetch(requestUrl, {
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' },
  })
    .then(function (res) {
      return res.ok ? res.json() : null;
    })
    .then(function (data) {
      if (!data || !data.success || !data.active || !data.config) return;

      // The decoupled loader may share the page. Whichever claims the overlay
      // first boots it; the other stays out (overlay-loader.js does the same).
      if (window.__accessibilityAudit || window.__accessibilityAuditOverlayClaim) return;
      window.__accessibilityAuditOverlayClaim = 'session';

      window.__accessibilityAudit = data.config;

      (data.cssUrls || []).forEach(function (href) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
      });

      // The shared helpers must execute before frontend-axe.js reads them, so
      // each script waits for the one before it.
      var scripts = (data.jsUrls || []).slice();
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
      // Silent: a failed boot must never surface on someone's front end.
    });
})();
