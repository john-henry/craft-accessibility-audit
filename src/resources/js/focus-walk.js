/**
 * Accessibility Audit: keyboard focus walk, run by the server-side browser
 * pass after axe. Needs accessibility-audit-shared.js loaded first.
 *
 * window.__aaFocusWalk.prepare(config) readies the page before the pass
 * presses Tab; run(config) then walks focus through every focusable element
 * and reports controls with no visible indicator (2.4.7) and fixed or sticky
 * elements that covered a control completely (2.4.11).
 */
(function () {
  'use strict';

  var Shared = window.AccessibilityAuditShared;
  var STYLE_ID = 'accessibility-audit-focus-walk';

  var FOCUSABLE = 'a[href], area[href], button, input, select, textarea, iframe, summary, '
    + '[tabindex], [contenteditable]:not([contenteditable="false"]), audio[controls], video[controls]';

  var SIDES = ['Top', 'Right', 'Bottom', 'Left'];

  function isTransparent(colour) {
    var parsed = Shared.parseRgb(colour);
    return !!parsed && parsed.a === 0;
  }

  function splitTopLevel(value) {
    var parts = [];
    var depth = 0;
    var current = '';

    for (var i = 0; i < value.length; i++) {
      var ch = value.charAt(i);
      if (ch === '(') depth++;
      if (ch === ')') depth--;
      if (ch === ',' && depth === 0) {
        parts.push(current.trim());
        current = '';
        continue;
      }
      current += ch;
    }

    if (current.trim() !== '') parts.push(current.trim());
    return parts;
  }

  /* Frameworks keep transparent, zero-sized shadows in place at rest, so
     only shadows that paint something are compared. */
  function visibleShadows(value) {
    if (!value || value === 'none') return 'none';

    var kept = splitTopLevel(value).filter(function (shadow) {
      var colour = (shadow.match(/(?:rgba?|hsla?|oklch|oklab|lab|lch|color)\([^)]*\)|#[0-9a-f]{3,8}\b/i) || [''])[0];
      if (colour && isTransparent(colour)) return false;

      var lengths = shadow.replace(colour, '').match(/-?[\d.]+px/g) || [];
      return lengths.some(function (len) { return parseFloat(len) !== 0; });
    });

    return kept.length ? kept.join(', ') : 'none';
  }

  /* What a box draws that could show focus, normalised so a change nobody
     can see compares equal. */
  function signature(style, pseudo) {
    if (!style) return '';
    if (pseudo && (style.content === 'none' || style.content === 'normal' || style.display === 'none')) {
      return '-';
    }

    var parts = [];
    var outlineWidth = parseFloat(style.outlineWidth) || 0;

    parts.push(style.outlineStyle === 'none' || outlineWidth === 0 || isTransparent(style.outlineColor)
      ? 'o:none'
      : 'o:' + style.outlineStyle + outlineWidth + style.outlineColor + style.outlineOffset);

    parts.push('s:' + visibleShadows(style.boxShadow));

    SIDES.forEach(function (side) {
      var width = parseFloat(style['border' + side + 'Width']) || 0;
      var borderStyle = style['border' + side + 'Style'];
      var colour = style['border' + side + 'Color'];

      parts.push(borderStyle === 'none' || borderStyle === 'hidden' || width === 0 || isTransparent(colour)
        ? 'b:none'
        : 'b:' + borderStyle + width + colour);
    });

    parts.push('bg:' + (isTransparent(style.backgroundColor) ? 'none' : style.backgroundColor) + style.backgroundImage);
    parts.push('c:' + style.color);

    var line = style.textDecorationLine || 'none';
    parts.push('td:' + (line === 'none'
      ? 'none'
      : line + style.textDecorationStyle + style.textDecorationColor + style.textDecorationThickness));

    parts.push('ts:' + visibleShadows(style.textShadow));
    parts.push('fw:' + style.fontWeight);
    parts.push('f:' + style.filter);
    parts.push('op:' + style.opacity);
    parts.push('t:' + style.transform + style.scale + style.translate + style.rotate);
    parts.push('cl:' + style.position + style.clip + style.clipPath);

    if (pseudo) parts.push('p:' + style.content + style.width + style.height);

    return parts.join('|');
  }

  /* Where a focus style can paint: ancestors (:focus-within), siblings
     (`input:focus + label`), labels and the first few descendants. */
  function neighbourhood(el) {
    var nodes = [el];
    var cur = el.parentElement;

    for (var up = 0; up < 3 && cur && cur !== document.documentElement; up++) {
      nodes.push(cur);
      cur = cur.parentElement;
    }

    if (el.previousElementSibling) nodes.push(el.previousElementSibling);
    if (el.nextElementSibling) nodes.push(el.nextElementSibling);

    if (el.labels) {
      for (var l = 0; l < el.labels.length; l++) nodes.push(el.labels[l]);
    }

    var descendants = el.querySelectorAll('*');
    for (var d = 0; d < descendants.length && d < 10; d++) nodes.push(descendants[d]);

    return nodes;
  }

  /* The control's own size, without its position: focus scrolls the window
     or an overflow container to bring the control into view, and that moves
     it without showing anything. A skip link revealed on focus grows, and its
     position and clip change in the signature. */
  function box(el) {
    var rect = el.getBoundingClientRect();

    return 'r:' + [rect.width, rect.height].map(Math.round).join(',');
  }

  /* nodes[0] is the control itself. */
  function snapshot(nodes) {
    var out = [box(nodes[0])];

    nodes.forEach(function (node) {
      out.push(signature(window.getComputedStyle(node), false));
      out.push(signature(window.getComputedStyle(node, '::before'), true));
      out.push(signature(window.getComputedStyle(node, '::after'), true));
    });

    return out.join('\n');
  }

  function isRendered(el) {
    if (!el.getClientRects().length) return false;
    return window.getComputedStyle(el).visibility !== 'hidden';
  }

  function candidates() {
    var all = document.querySelectorAll(FOCUSABLE);
    var out = [];

    for (var i = 0; i < all.length; i++) {
      var el = all[i];

      if (el.tabIndex < 0) continue;
      if (el.tagName === 'INPUT' && (el.getAttribute('type') || '').toLowerCase() === 'hidden') continue;
      if (el.matches(':disabled') || el.closest('[inert]')) continue;
      if (el.tagName === 'SUMMARY' && el.parentElement && el.parentElement.querySelector('summary') !== el) continue;
      if (!isRendered(el)) continue;

      out.push(el);
    }

    return out;
  }

  function positionOf(el) {
    var position = window.getComputedStyle(el).position;
    return position === 'fixed' || position === 'sticky' ? position : null;
  }

  /* Only elements fixed or sticky when the walk began can be blamed: a menu
     that focus itself opened is the page responding, not hiding focus. */
  function startingCoverers() {
    var found = new Set();
    var all = document.body ? document.body.querySelectorAll('*') : [];

    for (var i = 0; i < all.length; i++) {
      if (positionOf(all[i]) && isRendered(all[i])) found.add(all[i]);
    }

    return found;
  }

  function covererFor(hit, el, starting) {
    for (var cur = hit; cur && cur !== document.documentElement; cur = cur.parentElement) {
      if (starting.has(cur)) {
        /* A control inside the fixed element itself is not covered by it. */
        return cur.contains(el) ? null : cur;
      }
    }

    return null;
  }

  /* Covered only when all nine sample points over the on-screen part land
     on fixed or sticky content. */
  function fullyCoveredBy(el, starting) {
    var rect = el.getBoundingClientRect();
    var left = Math.max(rect.left, 0);
    var right = Math.min(rect.right, window.innerWidth);
    var top = Math.max(rect.top, 0);
    var bottom = Math.min(rect.bottom, window.innerHeight);

    if (right - left < 1 || bottom - top < 1) return null;

    var coverer = null;
    var fractions = [1 / 6, 1 / 2, 5 / 6];

    for (var y = 0; y < 3; y++) {
      for (var x = 0; x < 3; x++) {
        var hit = document.elementFromPoint(
          left + (right - left) * fractions[x],
          top + (bottom - top) * fractions[y]
        );

        if (!hit || hit === el || el.contains(hit) || hit.contains(el)) return null;

        var by = covererFor(hit, el, starting);
        if (!by) return null;
        coverer = coverer || by;
      }
    }

    return coverer;
  }

  function openDialogs() {
    var found = [];
    var all = document.querySelectorAll('dialog[open], [aria-modal="true"]');

    for (var i = 0; i < all.length; i++) {
      if (isRendered(all[i])) found.push(all[i]);
    }

    return found;
  }

  /* A background tab may never fire animation frames. */
  function nextFrame() {
    return new Promise(function (resolve) {
      var done = false;
      var finish = function () {
        if (!done) {
          done = true;
          resolve();
        }
      };

      window.requestAnimationFrame(finish);
      window.setTimeout(finish, 50);
    });
  }

  function blurActive() {
    var active = document.activeElement;
    if (active && active !== document.body && active.blur) active.blur();
  }

  function prepare(config) {
    config = config || {};

    var style = document.getElementById(STYLE_ID);
    if (!style) {
      style = document.createElement('style');
      style.id = STYLE_ID;
      (document.head || document.documentElement).appendChild(style);
    }

    var sheet = style.sheet;

    /* A running transition reads back as its starting, unfocused value. */
    sheet.insertRule('*, *::before, *::after { transition: none !important; }', sheet.cssRules.length);
    sheet.insertRule('html { scroll-behavior: auto !important; }', sheet.cssRules.length);

    /* Hidden rather than skipped, so excluded furniture can't cover anything
       either. One rule each, so a bad selector can't take the rest down. */
    (config.exclude || []).forEach(function (selector) {
      try {
        sheet.insertRule(selector + ' { display: none !important; }', sheet.cssRules.length);
      } catch (_) {}
    });

    blurActive();
    window.scrollTo(0, 0);
  }

  async function run(config) {
    config = config || {};
    var max = config.max || 150;
    var budgetMs = config.budgetMs || 15000;
    var started = Date.now();
    var first = document.activeElement;

    if (!first || first === document.body || first === document.documentElement || !first.matches(':focus')) {
      return { ran: false, reason: 'Keyboard focus did not reach the page.' };
    }

    var focusVisible = first.matches(':focus-visible');

    var list = candidates();
    var startHref = window.location.href;
    var startDialogs = openDialogs();
    var starting = startingCoverers();
    var notVisible = [];
    var coverers = new Map();
    var tried = 0;
    var refused = 0;
    var stopped = null;

    for (var i = 0; i < list.length && i < max; i++) {
      if (Date.now() - started > budgetMs) {
        stopped = 'budget';
        break;
      }

      var el = list[i];
      if (!el.isConnected) continue;

      blurActive();

      var nodes = neighbourhood(el);
      var before = snapshot(nodes);

      tried++;
      el.focus();
      await nextFrame();

      if (window.location.href !== startHref) {
        stopped = 'navigation';
        break;
      }

      if (openDialogs().some(function (d) { return startDialogs.indexOf(d) === -1; })) {
        stopped = 'dialog';
        break;
      }

      if (document.activeElement !== el) {
        refused++;
        if (tried >= 10 && refused > tried / 2) {
          stopped = 'refused';
          break;
        }
        continue;
      }

      /* A text caret is an indicator in its own right. */
      if (!Shared.isTextEntryControl(el) && el.tagName !== 'IFRAME' && snapshot(nodes) === before) {
        notVisible.push({ html: Shared.openingTagOf(el, 300), selector: Shared.cssPath(el, document) });
      }

      if (!Shared.isVisuallyHidden(el, window.getComputedStyle(el))) {
        var coverer = fullyCoveredBy(el, starting);

        if (coverer) {
          var entry = coverers.get(coverer);
          if (!entry) {
            entry = {
              html: Shared.openingTagOf(coverer, 300),
              selector: Shared.cssPath(coverer, document),
              position: positionOf(coverer) || 'fixed',
              count: 0,
              examples: [],
            };
            coverers.set(coverer, entry);
          }
          entry.count++;
          if (entry.examples.length < 3) entry.examples.push(Shared.cssPath(el, document));
        }
      }
    }

    blurActive();

    return {
      ran: true,
      focusVisible: focusVisible,
      total: list.length,
      limit: max,
      checked: tried,
      refused: refused,
      stopped: stopped,
      notVisible: notVisible,
      obscured: Array.from(coverers.values()),
    };
  }

  window.__aaFocusWalk = { prepare: prepare, run: run };
})();
