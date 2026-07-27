/**
 * Accessibility Audit: shared browser helpers.
 *
 * The single source for the WCAG contrast math and small utilities that both
 * runtimes need: the frontend overlay (frontend-axe.js, running on the live
 * site) and the CP (cp.js and the Inspect page's inline JS, running against
 * the preview iframe's document). Everything here is a pure function
 * parameterised on the document it inspects, so callers pass their own.
 *
 * Loaded before frontend-axe.js and cp.js by their asset bundles; exposed as
 * window.AccessibilityAuditShared.
 */
(function () {
  'use strict';

  /* ── Colour parsing ──────────────────────────────────────────────────── */

  function parseRgb(css) {
    if (!css) return null;
    var m = css.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?\s*\)/);
    if (!m) return null;
    return { r: +m[1], g: +m[2], b: +m[3], a: m[4] !== undefined ? +m[4] : 1 };
  }

  function rgbToHex(r, g, b) {
    return '#' + [r, g, b].map(function (x) {
      return Math.round(x).toString(16).padStart(2, '0').toUpperCase();
    }).join('');
  }

  /* ── WCAG relative luminance and contrast ratio ──────────────────────── */

  function lin(c) {
    c /= 255;
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  }

  function lum(r, g, b) {
    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  }

  function wcagRatio(r1, g1, b1, r2, g2, b2) {
    var l1 = lum(r1, g1, b1) + 0.05;
    var l2 = lum(r2, g2, b2) + 0.05;
    return l1 > l2 ? l1 / l2 : l2 / l1;
  }

  /* ── Effective background resolution ─────────────────────────────────── */

  /* Walks up the DOM compositing translucent backgrounds until an opaque one
     is found. Returns null when the visual background can't be determined
     (fixed/sticky ancestors, background images or gradients, pseudo-element
     backgrounds): the same cases axe flags as needs-review. */
  function effectiveBg(el, doc) {
    doc = doc || el.ownerDocument;
    var win = doc.defaultView;
    var r = 255, g = 255, b = 255;
    var layers = [];
    var cur = el;
    while (cur && cur.nodeType === 1) {
      var style = win.getComputedStyle(cur);
      /* fixed/sticky: visual background cannot be determined from DOM ancestry */
      var pos = style.position;
      if (pos === 'fixed' || pos === 'sticky') return null;
      /* background-image/gradient: effective colour is indeterminate */
      var bgImg = style.backgroundImage;
      if (bgImg && bgImg !== 'none') return null;
      /* pseudo-element background: ::before/::after may paint the background
         (matches the exact case axe-core flags as "needs review") */
      var pseudos = ['::before', '::after'];
      for (var pi = 0; pi < pseudos.length; pi++) {
        var ps = win.getComputedStyle(cur, pseudos[pi]);
        if (ps.display !== 'none') {
          var pBg = parseRgb(ps.backgroundColor);
          if (pBg && pBg.a > 0) return null;
        }
      }
      var bg = parseRgb(style.backgroundColor);
      if (bg && bg.a > 0) {
        layers.unshift(bg);
        if (bg.a >= 1) break; /* opaque: ancestors are hidden behind it */
      }
      cur = cur.parentElement;
    }
    if (layers.length === 0) return null;
    layers.forEach(function (l) {
      r = l.r * l.a + r * (1 - l.a);
      g = l.g * l.a + g * (1 - l.a);
      b = l.b * l.a + b * (1 - l.a);
    });
    return { r: Math.round(r), g: Math.round(g), b: Math.round(b) };
  }

  /* ── Contrast failure collection ─────────────────────────────────────── */

  /* Walks every text-bearing element in doc and returns the ones whose
     text/background contrast falls below the WCAG minimum, in the shape the
     store-contrast-results endpoint consumes. Options:
       limit: max failures collected (default 150)
       htmlLength: outerHTML snippet cap per failure (default 200)
       skipEl: optional fn(el), true to exclude an element (e.g. the
               overlay's own panel) */
  /* A short, stable-enough CSS path for a failing element (same shape as the
     page report's needs-review paths): nearest id wins, else up to three
     tag.class segments. Stored with each failure so the report can highlight
     the exact element instead of guessing from its colours. */
  function cssPath(el, doc) {
    var parts = [];
    var cur = el;
    while (cur && cur.nodeType === 1 && cur !== doc.body) {
      var part = cur.tagName.toLowerCase();
      if (cur.id) {
        parts.unshift('#' + cur.id);
        break;
      }
      if (cur.className && typeof cur.className === 'string') {
        var cls = cur.className.trim().split(/\s+/).filter(Boolean).slice(0, 2);
        if (cls.length) part += '.' + cls.join('.');
      }
      parts.unshift(part);
      cur = cur.parentElement;
    }
    return parts.slice(-3).join(' > ');
  }

  function collectContrastFailures(doc, opts) {
    opts = opts || {};
    var limit = opts.limit || 150;
    var htmlLength = opts.htmlLength || 200;
    var results = [];
    if (!doc || !doc.body) return results;
    var win = doc.defaultView;

    try {
      var walker = doc.createTreeWalker(doc.body, 4 /* SHOW_TEXT */, {
        acceptNode: function (n) { return n.textContent.trim() ? 1 : 3; },
      });
      var parents = [];
      var n;
      while ((n = walker.nextNode())) { if (n.parentElement) parents.push(n.parentElement); }
      parents = parents.filter(function (el, i, a) { return a.indexOf(el) === i; });

      parents.forEach(function (el) {
        if (results.length >= limit) return;
        if (opts.skipEl && opts.skipEl(el)) return;
        var style = win.getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden') return;
        if (!el.offsetWidth && !el.offsetHeight) return;
        /* Skip aria-hidden subtrees: axe-core ignores these for contrast too,
           since screen readers don't read them. */
        var cur = el;
        while (cur && cur.nodeType === 1) {
          if (cur.getAttribute('aria-hidden') === 'true') return;
          cur = cur.parentElement;
        }
        var fg = parseRgb(style.color);
        if (!fg) return;
        var bg = effectiveBg(el, doc);
        if (!bg) return; /* background indeterminate: needs-review, not failure */
        var ratio = Math.round(wcagRatio(fg.r, fg.g, fg.b, bg.r, bg.g, bg.b) * 100) / 100;
        var fs = parseFloat(style.fontSize);
        var fw = parseInt(style.fontWeight, 10) || 400;
        var isLarge = fs >= 24 || (fs >= 18.67 && fw >= 700);
        if (ratio >= (isLarge ? 3 : 4.5)) return; /* passes */
        results.push({
          fg: rgbToHex(fg.r, fg.g, fg.b),
          bg: rgbToHex(bg.r, bg.g, bg.b),
          ratio: ratio,
          expected: isLarge ? '3:1' : '4.5:1',
          html: el.outerHTML ? el.outerHTML.slice(0, htmlLength) : '',
          selector: cssPath(el, doc),
        });
      });
    } catch (_) { /* collection must never break the caller's render */ }
    return results;
  }

  /* ── Small shared utilities ──────────────────────────────────────────── */

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* The one 80/50 score-colour threshold, everywhere a score is painted. */
  function scoreClass(score) {
    return score >= 80 ? 'good' : score >= 50 ? 'fair' : 'poor';
  }

  window.AccessibilityAuditShared = {
    parseRgb: parseRgb,
    rgbToHex: rgbToHex,
    lum: lum,
    wcagRatio: wcagRatio,
    effectiveBg: effectiveBg,
    cssPath: cssPath,
    collectContrastFailures: collectContrastFailures,
    escHtml: escHtml,
    scoreClass: scoreClass,
  };
})();
