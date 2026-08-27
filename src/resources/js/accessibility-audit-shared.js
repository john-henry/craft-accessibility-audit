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

  /* Colours reach here in whatever syntax the stylesheet used, and a contrast
     ratio needs sRGB channels. Canvas does the conversion in two stages below,
     so no colour-space maths lives here.

     A colour this cannot read is worse than useless: an unreadable background
     makes an element look transparent, so the walk climbs to a coloured
     ancestor and measures the text against that, and an unreadable foreground
     drops the element from the results entirely. Tailwind 4 ships its whole
     palette in oklch, so on those sites that is most colours on the page. */
  var _colorCtx;
  var _colorCache = {};

  function colorCtx() {
    if (_colorCtx === undefined) {
      try {
        var canvas = typeof document !== 'undefined' ? document.createElement('canvas') : null;
        if (canvas) {
          canvas.width = 1;
          canvas.height = 1;
        }
        _colorCtx = canvas && canvas.getContext ? canvas.getContext('2d', { willReadFrequently: true }) : null;
      } catch (e) {
        _colorCtx = null;
      }
    }
    return _colorCtx;
  }

  /* Stage one: round-trip through fillStyle, which rewrites the syntaxes canvas
     can express in sRGB ("rgb(196 26 16)" and "hsl(...)" come back "#c41a10").
     Returns null when the browser rejected the value: a rejected assignment
     leaves fillStyle at whatever it held, so two different sentinels tell a
     real colour from a bad one. */
  function normaliseColor(css) {
    var ctx = colorCtx();
    if (!ctx) return null;

    try {
      ctx.fillStyle = '#000000';
      ctx.fillStyle = css;
      var first = ctx.fillStyle;

      ctx.fillStyle = '#ffffff';
      ctx.fillStyle = css;

      return first === ctx.fillStyle ? first : null;
    } catch (e) {
      return null;
    }
  }

  /* Stage two, for colours the browser keeps in a space of their own. Canvas
     accepts oklch and returns it verbatim, so the round-trip above cannot read
     it; painting a pixel and sampling it gets sRGB channels out. Slightly lossy
     on partial alpha, which rounds through the un-premultiply. */
  function paintToRgb(css) {
    var ctx = colorCtx();
    if (!ctx) return null;

    try {
      ctx.clearRect(0, 0, 1, 1);
      ctx.fillStyle = css;
      ctx.fillRect(0, 0, 1, 1);
      var d = ctx.getImageData(0, 0, 1, 1).data;

      return { r: d[0], g: d[1], b: d[2], a: d[3] / 255 };
    } catch (e) {
      return null;
    }
  }

  function parseRgb(css) {
    if (!css) return null;
    if (_colorCache[css] !== undefined) return _colorCache[css];

    var parsed = null;
    var m = css.match(/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)(?:\s*,\s*([\d.]+))?\s*\)/);

    if (m) {
      parsed = { r: +m[1], g: +m[2], b: +m[3], a: m[4] !== undefined ? +m[4] : 1 };
    } else {
      var normalised = normaliseColor(css);

      if (normalised) {
        var hex = normalised.match(/^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i);

        if (hex) {
          parsed = { r: parseInt(hex[1], 16), g: parseInt(hex[2], 16), b: parseInt(hex[3], 16), a: 1 };
        } else if (normalised !== css) {
          parsed = parseRgb(normalised);
        } else {
          /* A valid colour the browser kept in its own space, such as oklch. */
          parsed = paintToRgb(css);
        }
      }
    }

    _colorCache[css] = parsed;
    return parsed;
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
  /* A selector that resolves to the one element it was built from. Tag and
     classes alone do not manage that on repeated markup, so each step carries
     its position among matching siblings and the path is only shortened as
     far as it stays unique. */
  function cssPath(el, doc) {
    var esc = (window.CSS && CSS.escape) ? CSS.escape : function (s) { return s; };
    var parts = [];
    var cur = el;

    while (cur && cur.nodeType === 1 && cur !== doc.body) {
      var part = cur.tagName.toLowerCase();

      if (cur.id) {
        parts.unshift('#' + esc(cur.id));
        break;
      }

      if (cur.className && typeof cur.className === 'string') {
        var cls = cur.className.trim().split(/\s+/).filter(Boolean).slice(0, 2);
        if (cls.length) part += '.' + cls.map(esc).join('.');
      }

      var parent = cur.parentElement;
      if (parent) {
        var same = 0;
        var nth = 0;
        for (var i = 0; i < parent.children.length; i++) {
          if (parent.children[i].tagName === cur.tagName) {
            same++;
            if (parent.children[i] === cur) nth = same;
          }
        }
        if (same > 1 && nth > 0) part += ':nth-of-type(' + nth + ')';
      }

      parts.unshift(part);
      cur = cur.parentElement;
    }

    if (!parts.length) return '';

    /* Shortest tail that still picks out this element and nothing else. The
       old three-level cap is kept as the floor, so paths do not get longer
       than they were unless being unique demands it. */
    for (var take = Math.min(3, parts.length); take <= parts.length; take++) {
      var candidate = parts.slice(-take).join(' > ');
      try {
        var found = doc.querySelectorAll(candidate);
        if (found.length === 1 && found[0] === el) return candidate;
      } catch (_) {
        break;
      }
    }

    return parts.join(' > ');
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
        if (!isPaintedText(el, win)) return;

        var style = win.getComputedStyle(el);
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

  /* ── Contrast in states the page is not currently in ──────────────────── */

  /* Hover, focus and selection colours never appear in a resting page, so no
     engine that reads the rendered DOM can see them: axe measures what is on
     screen, and what is on screen is the resting state. The declarations are
     in the stylesheet all the same, so they are read from there and measured
     against the background the element actually sits on.

     Deliberately conservative. A rule whose colours cannot be resolved to
     actual values (a var() this pass cannot evaluate, a shorthand it does not
     read) is skipped whole rather than measured half-known: a state failure
     the reader cannot reproduce is worse than one not reported, because they
     cannot even check it by hovering. */

  var STATE_PATTERNS = [
    { key: 'hover', re: /:hover\b/ },
    { key: 'focus', re: /:focus(?:-visible|-within)?\b/ },
    { key: 'selection', re: /::selection\b/ },
  ];

  /* Walks every style rule in the document, descending into @media, @supports
     and @layer blocks. Tailwind 4 puts the whole framework inside layers, so a
     pass that only reads top-level rules sees nothing on a modern site. */
  function eachStyleRule(node, win, fn, parentSel) {
    var rules;

    /* A cross-origin stylesheet throws on access and cannot be read at all. */
    try { rules = node.cssRules; } catch (_) { return; }
    if (!rules) return;

    for (var i = 0; i < rules.length; i++) {
      var rule = rules[i];
      var resolved = null;

      /* Style rules first, and never as an either/or with the recursion
         below. Browsers that support nested CSS give a CSSStyleRule its own
         cssRules list, so treating "has cssRules" as "is a grouping rule"
         walks straight past every declaration on the page. */
      if (rule.selectorText && rule.style) {
        resolved = resolveSelector(rule.selectorText, parentSel);
        fn(rule, parentSel);
      }

      if (rule.cssRules) {
        /* A media block that does not apply at this width is not part of what
           the reader sees, so its colours are not what they would get. */
        var mediaText = rule.media && rule.media.mediaText;
        if (mediaText && win.matchMedia && !win.matchMedia(mediaText).matches) continue;

        eachStyleRule(rule, win, fn, resolved || parentSel);
      }
    }
  }

  /* A nested rule's selectorText is relative to the rule it sits in: "& a" or,
     with the ampersand implied, "a". Neither means anything on its own, and
     handing "& a" to querySelectorAll is worse than useless, because the
     ampersand resolves to :scope and quietly matches every element in the
     document. A colour declared for one component is then measured against
     every element on the page.

     So each rule is resolved against the chain it is nested in before anything
     queries it. :is() carries a comma-separated parent through as one unit. */
  function resolveSelector(sel, parentSel) {
    if (!parentSel) return sel;

    return sel.split(',').map(function (part) {
      part = part.trim();

      return part.indexOf('&') === -1
        ? parentSel + ' ' + part
        : part.replace(/&/g, ':is(' + parentSel + ')');
    }).join(', ');
  }

  /* The element a state rule paints, as a selector: the state pseudo removed,
     everything else left alone. `.btn:hover .icon` styles `.icon` while `.btn`
     is hovered, so dropping the pseudo names the right target. */
  function baseSelectorFor(part, stateKey) {
    var base = part
      .replace(/::selection\b/g, '')
      .replace(/:hover\b/g, '')
      .replace(/:focus(?:-visible|-within)?\b/g, '')
      .trim();

    /* Another pseudo-element in the same compound (`a:hover::after`) styles
       generated content, which has no element to query and no text of its own
       that this pass can read. */
    if (base.indexOf('::') !== -1) return null;

    if (base === '') return stateKey === 'selection' ? 'body' : null;

    return base;
  }

  /* A colour a state rule declares, or null when it declares none. Returns
     false when it declares one that cannot be resolved, which is the caller's
     signal to skip the rule rather than guess. */
  function declaredColour(rule, property) {
    var raw = (rule.style.getPropertyValue(property) || '').trim();
    if (raw === '' || raw === 'inherit' || raw === 'currentColor') return null;
    if (raw === 'transparent') return null;

    var parsed = parseRgb(raw);

    return parsed || false;
  }

  /**
   * Contrast failures in hover, focus and selection states.
   *
   * @param {Document} doc
   * @param {Object} [opts] limit, htmlLength, perRule, skipEl
   * @returns {Array} occurrences, each carrying the state it was found in
   */
  function collectStateContrastFailures(doc, opts) {
    opts = opts || {};
    var limit = opts.limit || 40;
    var htmlLength = opts.htmlLength || 200;
    var perRule = opts.perRule || 30;
    var results = [];
    var seen = {};

    if (!doc || !doc.body) return results;
    var win = doc.defaultView;
    if (!win) return results;

    try {
      var sheets = doc.styleSheets;

      for (var s = 0; s < sheets.length && results.length < limit; s++) {
        eachStyleRule(sheets[s], win, function (rule, parentSel) {
          if (results.length >= limit) return;

          /* Split before resolving, never after: a resolved part can carry a
             comma inside :is(), and splitting that again produces two broken
             halves. */
          var parts = rule.selectorText.split(',');

          for (var p = 0; p < parts.length; p++) {
            var part = resolveSelector(parts[p].trim(), parentSel);
            var state = null;

            for (var st = 0; st < STATE_PATTERNS.length; st++) {
              if (STATE_PATTERNS[st].re.test(part)) { state = STATE_PATTERNS[st]; break; }
            }
            if (!state) continue;

            var base = baseSelectorFor(part, state.key);
            if (!base) continue;

            var fgDecl = declaredColour(rule, 'color');
            var bgDecl = declaredColour(rule, 'background-color');

            /* Declared but unreadable: skip rather than measure against a
               colour that is not the one the reader will see. */
            if (fgDecl === false || bgDecl === false) continue;

            /* A rule that changes neither colour cannot fail on contrast. */
            if (!fgDecl && !bgDecl) continue;

            var els;
            try { els = doc.querySelectorAll(base); } catch (_) { continue; }

            for (var e = 0; e < els.length && e < perRule && results.length < limit; e++) {
              var el = els[e];
              if (opts.skipEl && opts.skipEl(el)) continue;
              if (!isPaintedText(el, win)) continue;

              /* No text, nothing to measure the colour of. */
              if (!(el.textContent || '').trim()) continue;

              var style = win.getComputedStyle(el);
              var fg = fgDecl || parseRgb(style.color);
              if (!fg) continue;

              var beneath = effectiveBg(el, doc);
              if (!beneath) continue;

              var bg = bgDecl ? blendOver(bgDecl, beneath) : beneath;
              var ratio = Math.round(wcagRatio(fg.r, fg.g, fg.b, bg.r, bg.g, bg.b) * 100) / 100;
              var fs = parseFloat(style.fontSize);
              var fw = parseInt(style.fontWeight, 10) || 400;
              var isLarge = fs >= 24 || (fs >= 18.67 && fw >= 700);

              if (ratio >= (isLarge ? 3 : 4.5)) continue;

              /* One finding per distinct colour pair per rule, not one per
                 element: a hover colour on fifty nav links is one thing to
                 fix, and fifty identical cards is a queue nobody reads. */
              var key = state.key + '|' + rgbToHex(fg.r, fg.g, fg.b) + '|'
                + rgbToHex(bg.r, bg.g, bg.b) + '|' + base;
              if (seen[key]) break;
              seen[key] = true;

              results.push({
                state: state.key,
                fg: rgbToHex(fg.r, fg.g, fg.b),
                bg: rgbToHex(bg.r, bg.g, bg.b),
                ratio: ratio,
                expected: isLarge ? '3:1' : '4.5:1',
                /* A selection colour is usually declared once for the whole
                   page, so the rule that declares it says more than whichever
                   element happened to match it first. */
                html: state.key === 'selection'
                  ? part.slice(0, htmlLength)
                  : (el.outerHTML ? el.outerHTML.slice(0, htmlLength) : ''),
                selector: cssPath(el, doc) || 'body',
              });
              break;
            }
          }
        });
      }
    } catch (_) { /* collection must never break the caller's render */ }

    return results;
  }

  /* A state background may be translucent, in which case what the reader sees
     is it composited over whatever is already there. */
  function blendOver(top, beneath) {
    var a = top.a === undefined ? 1 : top.a;
    if (a >= 1) return top;

    return {
      r: Math.round(top.r * a + beneath.r * (1 - a)),
      g: Math.round(top.g * a + beneath.g * (1 - a)),
      b: Math.round(top.b * a + beneath.b * (1 - a)),
      a: 1,
    };
  }

  /* Whether an element's text is actually painted for a reader to see.
     Shared by both contrast passes so they can never disagree about what
     counts. aria-hidden goes because a screen reader never reads it and axe
     skips it too; fully transparent goes because hover-revealed text is not in
     the state being measured and its background is whatever it will eventually
     appear over. */
  function isPaintedText(el, win) {
    var style = win.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden') return false;
    if (!el.offsetWidth && !el.offsetHeight) return false;

    var cur = el;
    while (cur && cur.nodeType === 1) {
      if (cur.getAttribute('aria-hidden') === 'true') return false;
      if (parseFloat(win.getComputedStyle(cur).opacity) === 0) return false;
      cur = cur.parentElement;
    }

    return true;
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
    collectStateContrastFailures: collectStateContrastFailures,
    escHtml: escHtml,
    scoreClass: scoreClass,
  };
})();
