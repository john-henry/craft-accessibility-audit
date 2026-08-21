(function () {
    'use strict';

    /* Server-injected config: see DashboardController::actionPageReport(). */
    var CFG = (window.AccessibilityAudit || {}).pageReport || {};

    // Programmatic scrolls honour the reader's motion preference: an accessibility
    // tool must not animate page-jumps for someone who asked for reduced motion.
    // Checked live rather than cached, so a mid-session preference change is picked up.
    function scrollToEl(el) {
        if (!el) { return; }
        var behavior = (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) ? 'auto' : 'smooth';
        try { el.scrollIntoView({ behavior: behavior, block: 'center' }); }
        catch (e) { el.scrollIntoView(); }
    }

    /* Shared with cp.js and the frontend overlay via accessibility-audit-shared.js */
    var escHtml = AccessibilityAuditShared.escHtml;

    var iframe          = document.getElementById('accessibility-audit-preview-iframe');
    var previewLoading  = document.getElementById('accessibility-audit-preview-loading');
    var paneContent     = document.getElementById('accessibility-audit-pane-content');
    var paneHtml        = document.getElementById('accessibility-audit-pane-html');
    var htmlSourceEl    = document.getElementById('accessibility-audit-html-source');
    var htmlCode        = htmlSourceEl ? htmlSourceEl.querySelector('.accessibility-audit-html-code') : null;
    var explorerPanel   = document.getElementById('accessibility-audit-explorer-panel');
    var explorerTrigger = document.getElementById('accessibility-audit-explorer-trigger');
    var explorerClose   = document.getElementById('accessibility-audit-explorer-close');
    var htmlLoaded          = false;
    var activeRuleId        = null;
    var _currentOccurrences = null;
    /* The rule the loaded occurrences belong to. Held here rather than read
       off the active row: clicking an occurrence bubbles to the row handler,
       which toggles that class off before the click can read it. */
    var _currentRuleId = null;

    /* ── Rule → CSS selector map ────────────────────────────────────── */
    /* Covers ContentScanner PHP rules + common axe-core rule IDs.
       Selectors target the elements that are checked for each rule.    */
    var RULE_SELECTORS = {
        /* PHP scanner rules */
        'image-alt':             'img:not([alt]), img[alt=""]',
        'image-alt-filename':    'img[alt]',
        'link-text':             'a[href]',
        'link-new-window':       'a[target="_blank"]',
        'heading-order':         'h1, h2, h3, h4, h5, h6',
        /* Broad on purpose: RULE_FILTERS narrows to headings with no visible
           text, which :empty can't (it misses whitespace and empty spans). */
        'empty-heading':         'h1, h2, h3, h4, h5, h6',
        'html-lang':             'html',
        'html-has-lang':         'html',
        'table-caption':         'table',
        'table-th-scope':        'th:not([scope])',
        'form-label':            'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]), select, textarea',
        'button-name':           'button',
        'frame-title':           'iframe, frame',
        'duplicate-id':          '[id]',
        'meta-refresh':          'meta[http-equiv="refresh"]',
        'tabindex':              '[tabindex]',
        'accesskey':             '[accesskey]',
        /* axe-core rules */
        'color-contrast':        'p, h1, h2, h3, h4, h5, h6, a, li, td, th, label, span, div',
        /* Contrast axe couldn't measure, stored as a needs-review item. Same
           candidate elements as color-contrast, since it is the same rule. */
        'potential:contrast-unmeasurable': 'p, h1, h2, h3, h4, h5, h6, a, li, td, th, label, span, div',
        'label':                 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]), select, textarea',
        'link-name':             'a[href]',
        'document-title':        'title',
        'region':                'header, nav, main, aside, footer, section, article, form',
        'landmark-one-main':     'main, [role="main"]',
        'aria-allowed-attr':     '[aria-*]',
        'aria-required-attr':    '[role]',
        'aria-roles':            '[role]',
        'aria-valid-attr':       '[aria-*]',
        'aria-valid-attr-value': '[aria-*]',
        'aria-hidden-body':      'body',
        'autocomplete-valid':    '[autocomplete]',
        'bypass':                'a, [href="#"]',
        'definition-list':       'dl',
        'dlitem':                'dt, dd',
        'duplicate-id-active':   '[id]',
        'duplicate-id-aria':     '[id]',
        'image-redundant-alt':   'img[alt]',
        'input-image-alt':       'input[type="image"]',
        'list':                  'ul, ol',
        'list-structure':        'li',
        'listitem':              'li',
        'meta-viewport':         'meta[name="viewport"]',
        'nested-interactive':    'a button, button a, a input, input a, a select',
        'object-alt':            'object',
        'role-img-alt':          '[role="img"]',
        'scope-attr-valid':      '[scope]',
        'scrollable-region-focusable': '[overflow], [style*="overflow"]',
        'select-name':           'select',
        'server-side-image-map': 'img[ismap]',
        'svg-img-alt':           'svg[role="img"], svg[aria-label]',
        'td-headers-attr':       'td[headers]',
        'th-has-data-cells':     'th',
        'valid-lang':            '[lang]',
        'video-caption':         'video',
        /* Additional rules that need a selector for highlighting to work. */
        'table-header':          'table',
        'multiple-h1':           'h1',
        'select-label':          'select',
        'link-generic':          'a[href]',
        'iframe-title':          'iframe, frame',
        'skip-link':             'a[href^="#"]',
        'meta-description':      'meta[name="description"]',
        'landmark-unique':       'header, nav, main, aside, footer, section, form',
        'target-size':           'a[href], button, input, select, textarea, [role="button"]',
        /* Potential rules need human judgement, so highlighting stays broad:
           point at all candidates rather than pick one. */
        'potential:identical-links':  'a[href]',
        'potential:url-as-link-text': 'a[href]',
        'potential:decorative-image': 'img',
        'potential:short-alt':        'img[alt]',
        'potential:long-alt':         'img[alt]',
        'potential:possible-heading': 'p strong, p b, p[style*="font-weight"]',
        'potential:table-layout':     'table',
        'potential:video-audio-desc': 'video',
    };

    /* Findings with no offending element to point at: something the page
       lacks, or (the axe document-level rules) the <html> element itself.
       selectorFor() returns null for these so no highlight runs. Exact ids
       only: axe's own `axe:skip-link` (a real skip link pointing nowhere)
       is a different finding with real nodes and keeps its fallback. */
    var ABSENCE_RULES = [
        'skip-link', 'page-title', 'meta-description', 'landmark-main', 'landmark-regions',
        'axe:document-title', 'axe:html-has-lang', 'axe:html-lang-valid', 'axe:aria-hidden-body',
    ];

    /* The selector for a rule, tolerating the axe: source prefix. Axe
       findings are stored as `axe:region` while the map above keys them by
       axe's own name (`region`). Exact key checked first, so a prefixed
       entry (the potential rules) still wins where one exists. */
    function selectorFor(ruleId) {
        if (!ruleId) return null;
        if (ABSENCE_RULES.indexOf(ruleId) !== -1) return null;

        return RULE_SELECTORS[ruleId]
            || RULE_SELECTORS[ruleId.replace(/^axe:/, '')]
            || null;
    }

    /* Predicates re-running a rule's own failure test in the preview, so
       fallback highlighting frames genuine offenders instead of everything
       the broad selector matches. Keyed like RULE_SELECTORS. */
    var RULE_FILTERS = {
        'button-name': function (el) {
            if (visibleText(el) || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title')) return false;
            var img = el.querySelector('img[alt]');
            return !(img && img.getAttribute('alt').trim());
        },
        'link-name': function (el) {
            if (visibleText(el) || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title')) return false;
            var img = el.querySelector('img[alt]');
            return !(img && img.getAttribute('alt').trim());
        },
        'empty-heading': function (el) {
            return !visibleText(el);
        },
        /* axe's list test: a list with element children that aren't li. */
        'list': function (el) {
            for (var lc = 0; lc < el.children.length; lc++) {
                if (!/^(li|script|template)$/i.test(el.children[lc].tagName)) return true;
            }
            return false;
        },
        /* axe's listitem test: an li whose parent is not a list. */
        'listitem': function (el) {
            var parent = el.parentElement;
            return !!parent && !/^(ul|ol|menu)$/i.test(parent.tagName);
        },
        'list-structure': function (el) {
            var parent = el.parentElement;
            return !!parent && !/^(ul|ol|menu)$/i.test(parent.tagName);
        },
        /* Approximates axe's 2.5.8 test (under 24×24 CSS px, inline links
           exempt). axe also weighs neighbour spacing, so a near-miss can be
           framed too. */
        'target-size': function (el) {
            var rect = el.getBoundingClientRect();
            if (!rect.width || !rect.height) return false;
            if (rect.width >= 24 && rect.height >= 24) return false;
            if (el.tagName.toLowerCase() === 'a') {
                try {
                    if (el.ownerDocument.defaultView.getComputedStyle(el).display === 'inline') return false;
                } catch (_) {}
            }
            return true;
        },
    };

    function filterFor(ruleId) {
        if (!ruleId) return null;

        return RULE_FILTERS[ruleId]
            || RULE_FILTERS[ruleId.replace(/^axe:/, '')]
            || null;
    }

    /* ── Iframe helpers ─────────────────────────────────────────────── */

    function iframeDoc() {
        if (!iframe) return null;
        try { return iframe.contentDocument || iframe.contentWindow.document; }
        catch (e) { return null; }
    }

    function ensureHighlightStyles(doc) {
        if (doc.getElementById('accessibility-audit-hl-styles')) return;
        var s = doc.createElement('style');
        s.id = 'accessibility-audit-hl-styles';
        s.textContent = [
            '@keyframes accessibility-audit-blink{',
            '  0%,100%{outline-color:#e11d48;box-shadow:0 0 0 5px rgba(225,29,72,.2);}',
            '  50%{outline-color:rgba(225,29,72,.2);box-shadow:none;}',
            '}',
            '[data-accessibility-audit-hl]{',
            '  outline:3px solid #e11d48!important;',
            '  outline-offset:2px!important;',
            '  position:relative!important;z-index:9998!important;',
            '  animation:accessibility-audit-blink .7s ease-in-out 4!important;',
            '}',
            /* Never paint a background on highlighted elements: the highlight
               must not alter the element's own rendering, and the contrast
               pass samples whatever is rendered. Outline and blink only. */
            /* Static outline only for users who asked for less motion */
            '@media (prefers-reduced-motion: reduce){',
            '  [data-accessibility-audit-hl]{animation:none!important;}',
            '}',
            '.accessibility-audit-hl-badge{',
            /* Sits just above the element's own top edge (not inside it as a
               first child overlapping the element's own text): small inline
               elements like nav links are otherwise unreadable once badged,
               since the badge would cover their first few characters. */
            '  position:absolute!important;top:0!important;left:-1px!important;',
            '  transform:translateY(-100%)!important;',
            '  background:#e11d48!important;color:#fff!important;',
            '  font:700 10px/18px system-ui,sans-serif!important;',
            '  padding:0 5px!important;border-radius:4px 4px 0 0!important;',
            '  z-index:9999!important;pointer-events:none!important;white-space:nowrap!important;',
            '}',
        ].join('');
        (doc.head || doc.documentElement).appendChild(s);
    }

    function clearHighlights(doc) {
        if (!doc) return;
        doc.querySelectorAll('[data-accessibility-audit-hl]').forEach(function (el) {
            el.removeAttribute('data-accessibility-audit-hl');
            var b = el.querySelector('.accessibility-audit-hl-badge');
            if (b) b.remove();
        });
    }

    function highlightInIframe(ruleId, selector) {
        var doc = iframeDoc();
        if (!doc) { showCrossOriginNotice(); return; }

        ensureHighlightStyles(doc);
        clearHighlights(doc);

        if (!selector) return;

        var elements;
        try { elements = doc.querySelectorAll(selector); }
        catch (e) { console.warn('[a11y] bad selector:', selector, e); return; }

        /* A rule with a failure predicate narrows to genuine offenders. */
        var ruleFilter = filterFor(ruleId);
        if (ruleFilter) {
            elements = Array.prototype.filter.call(elements, ruleFilter);
        }

        var count = 0;
        elements.forEach(function (el, i) {
            el.setAttribute('data-accessibility-audit-hl', i === 0 ? 'first' : 'other');
            if (el.tagName.toLowerCase() !== 'html') {
                var badge = doc.createElement('span');
                badge.className = 'accessibility-audit-hl-badge';
                badge.textContent = (i + 1) + '/' + elements.length;
                try { el.insertBefore(badge, el.firstChild); } catch (_) {}
            }
            count++;
        });

        if (count === 0) return;

        if (paneContent && paneContent.hidden) switchToView('content');

        scrollToEl(elements[0]);

        updateActiveRowBadge(count);
    }

    function showCrossOriginNotice() {
        showHighlightNote("(can't highlight, different domain)");
    }

    function showHighlightNote(text) {
        var active = document.querySelector('.accessibility-audit-pr-issue-row--active');
        if (!active) return;
        if (!active.querySelector('.accessibility-audit-pr-hl-note')) {
            var note = document.createElement('span');
            note.className = 'accessibility-audit-pr-hl-note';
            note.textContent = text;
            active.appendChild(note);
        }
    }

    /* Rules whose finding is about the page as a whole, not a specific element
       (e.g. "this page has no skip link"), so they always produce exactly one
       occurrence with no per-element context. The expand panel shows their
       message instead of the "1 occurrence" heading. Highlighting only runs
       for multiple-h1 (the extra h1s are the offenders); the rest are
       ABSENCE_RULES above, where selectorFor() returns null. */
    var PAGE_LEVEL_RULES = ['meta-description', 'skip-link', 'multiple-h1', 'page-title', 'landmark-main', 'landmark-regions'];

    function isPageLevelRule(ruleId) {
        return PAGE_LEVEL_RULES.indexOf(ruleId) !== -1;
    }

    function updateActiveRowBadge(count) {
        var active = document.querySelector('.accessibility-audit-pr-issue-row--active');
        if (!active) return;

        var existing = active.querySelector('.accessibility-audit-pr-hl-count');
        var stored = parseInt(active.querySelector('.accessibility-audit-pr-issue-count')?.textContent, 10);

        /* Say nothing when the number adds nothing: repeating the count already
           on the row just reads as two figures that happen to agree, and a
           page-level rule's match count isn't a count of anything real. */
        if (isPageLevelRule(active.dataset.ruleId) || count === stored) {
            if (existing) existing.remove();
            return;
        }

        var badge = existing;
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'accessibility-audit-pr-hl-count';
            var arrow = active.querySelector('.accessibility-audit-pr-detail-arrow');
            if (arrow) active.insertBefore(badge, arrow); else active.appendChild(badge);
        }

        /* "highlighted", not "found": these are the elements on screen right now,
           which is a different thing from how many the scan recorded. */
        badge.textContent = count + ' highlighted';
    }

    /* ── Expand panel helpers ────────────────────────────────────────── */

    function closeAllExpand() {
        document.querySelectorAll('.accessibility-audit-pr-issue-expand:not([hidden])').forEach(function (p) {
            p.hidden = true;
        });
        document.querySelectorAll('.accessibility-audit-pr-issue-row--active').forEach(function (r) {
            r.classList.remove('accessibility-audit-pr-issue-row--active');
            r.setAttribute('aria-expanded', 'false');
            var old = r.querySelector('.accessibility-audit-pr-hl-count, .accessibility-audit-pr-hl-note');
            if (old) old.remove();
        });
        /* Clear HTML view highlights */
        if (htmlCode) {
            htmlCode.querySelectorAll('.accessibility-audit-html-hl--active').forEach(function (m) {
                m.classList.remove('accessibility-audit-html-hl--active');
            });
        }
        _currentOccurrences = null;
        _currentRuleId = null;
    }

    /* Render a colour-contrast occurrence card from JSON context data */
    function renderContrastOccurrence(data) {
        var ratio   = data.ratio !== null ? (Math.round(+data.ratio * 100) / 100) + ':1' : '?:1';
        var preview = '<span class="accessibility-audit-pr-swatch-preview" aria-hidden="true" style="color:' + escHtml(data.fg || '#000') + ';background:' + escHtml(data.bg || '#fff') + '">Aa</span>';
        var swatches =
            '<div class="accessibility-audit-pr-swatch-row">' +
                '<span class="accessibility-audit-pr-swatch-dot" style="background:' + escHtml(data.fg || '') + '"></span>' +
                '<span class="accessibility-audit-pr-swatch-type">Text</span>' +
                '<code class="accessibility-audit-pr-swatch-hex">' + escHtml(data.fg || '') + '</code>' +
            '</div>' +
            '<div class="accessibility-audit-pr-swatch-row">' +
                '<span class="accessibility-audit-pr-swatch-dot" style="background:' + escHtml(data.bg || '') + ';border:1px solid #e2e8f0"></span>' +
                '<span class="accessibility-audit-pr-swatch-type">Background</span>' +
                '<code class="accessibility-audit-pr-swatch-hex">' + escHtml(data.bg || '') + '</code>' +
            '</div>';
        /* The failing element's markup, so the card identifies WHICH element
           carries these colours: a same-on-same pair is invisible on the page,
           making the snippet the only human-readable pointer. */
        var snippet = data.html
            ? '<code class="accessibility-audit-pr-occ-ctx" title="' + escHtml(data.html) + '">' + escHtml(data.html) + '</code>'
            : '';
        return '<div class="accessibility-audit-pr-swatch-pair">' +
            preview +
            '<div class="accessibility-audit-pr-swatch-details">' +
                '<div class="accessibility-audit-pr-contrast-ratio-inline">' + escHtml(ratio) +
                    '<span class="accessibility-audit-pr-contrast-req-inline"> · need ' + escHtml(data.expected || '4.5:1') + '</span>' +
                '</div>' +
                swatches +
                snippet +
            '</div>' +
        '</div>';
    }

    function renderExpandPanel(body, issue, occurrences) {
        var lvl = issue.wcagLevel ? '<span class="accessibility-audit-level">' + escHtml(issue.wcagLevel) + '</span>' : '<span class="accessibility-audit-level accessibility-audit-level--bp">BP</span>';
        var criterion = issue.wcagCriterion ? '<span class="light" style="font-size:11px;margin-left:4px">' + escHtml(issue.wcagCriterion) + '</span>' : '';
        var helpLink  = issue.helpUrl ? '<a href="' + escHtml(issue.helpUrl) + '" target="_blank" rel="noopener" class="accessibility-audit-pr-help-link">How to fix ↗</a>' : '';
        var isContrast = issue.ruleId === 'color-contrast' || issue.ruleId === 'color-contrast-enhanced';

        var occHtml = '';
        if (occurrences && occurrences.length) {
            occHtml = '<div class="accessibility-audit-pr-occ-list">';
            occurrences.forEach(function (occ, i) {
                var ctxHtml = '';
                if (isContrast && occ.context) {
                    try {
                        var cd = JSON.parse(occ.context);
                        if (cd && cd.fg) { ctxHtml = renderContrastOccurrence(cd); }
                    } catch (_) {}
                }
                if (!ctxHtml) {
                    /* title carries the full context string so it's reachable on hover
                       even after CSS truncates the single-line display to an ellipsis. */
                    ctxHtml = occ.context ? '<code class="accessibility-audit-pr-occ-ctx" title="' + escHtml(occ.context) + '">' + escHtml(occ.context) + '</code>' : '';
                }
                /* Template path + selector: populated by enrichOccurrencesWithTemplateInfo
                   when devMode is on and accessibility-audit-tpl comments are present in the iframe. */
                /* Browser-sourced occurrences carry the viewport bucket they
                   were found at; PHP findings are viewport-independent. */
                var viewportHtml = occ.viewport
                    ? '<span class="accessibility-audit-pr-occ-viewport accessibility-audit-pr-occ-viewport--' + escHtml(occ.viewport) + '">' + escHtml(occ.viewport) + '</span>'
                    : '';
                var selectorHtml = occ.selector
                    ? '<span class="accessibility-audit-pr-occ-selector" title="' + escHtml(occ.selector) + '">' + escHtml(occ.selector) + '</span>'
                    : '';
                var tplHtml = occ.template
                    ? '<span class="accessibility-audit-pr-review-tpl" title="Twig template: ' + escHtml(occ.template) + '">' + escHtml(occ.template) + '</span>'
                    : '';

                /* One layout for every occurrence, contrast included: number and
                   selector sit inline on the head row, detail stacks full-width
                   beneath it. */
                occHtml += '<div class="accessibility-audit-pr-occ-item" data-occ-idx="' + i + '">' +
                    '<div class="accessibility-audit-pr-occ-head">' +
                        '<span class="accessibility-audit-pr-occ-num">' + (i + 1) + '</span>' +
                        viewportHtml +
                        selectorHtml +
                    '</div>' +
                    tplHtml +
                    ctxHtml +
                    '</div>';
            });
            occHtml += '</div>';
        } else if (occurrences) {
            occHtml = '<p class="accessibility-audit-pr-expand-empty">No occurrence detail available.</p>';
        } else {
            occHtml = '<p class="accessibility-audit-pr-expand-empty accessibility-audit-loading">Loading…</p>';
        }

        /* Some rules (e.g. meta-description) can only ever produce one occurrence,
           showing a "1 OCCURRENCE" chip adds no value for those. */
        var singleOccurrenceRule = isPageLevelRule(issue.ruleId);

        /* Build "needs manual review" section for contrast issues */
        var reviewHtml = '';
        if (isContrast && _contrastNeedsReview && _contrastNeedsReview.length) {
            var reviewItems = '';
            _contrastNeedsReview.forEach(function (item, idx) {
                var desc = _REVIEW_REASONS[item.reason] || item.reason;
                var landmarkHtml = '';
                if (item.landmark) {
                    var lm = item.landmark;
                    landmarkHtml = '<span class="accessibility-audit-pr-review-landmark">' +
                        '&lt;' + escHtml(lm.tag) + '&gt;' +
                        (lm.name ? ' · ' + escHtml(lm.name) : '') +
                    '</span>';
                }
                var tplHtml = item.template
                    ? '<span class="accessibility-audit-pr-review-tpl" title="Twig template: ' + escHtml(item.template) + '">' + escHtml(item.template) + '</span>'
                    : '';
                reviewItems +=
                    '<div class="accessibility-audit-pr-review-item" data-review-idx="' + idx + '">' +
                        '<div class="accessibility-audit-pr-review-hdr">' +
                            '<div class="accessibility-audit-pr-review-location-wrap">' +
                                (item.selector ? '<span class="accessibility-audit-pr-review-location" title="' + escHtml(item.selector) + '">' + escHtml(item.selector) + '</span>' : '') +
                                landmarkHtml +
                                tplHtml +
                            '</div>' +
                            '<button class="accessibility-audit-pr-review-hl-btn" data-review-idx="' + idx + '" type="button">Highlight ↗</button>' +
                        '</div>' +
                        '<p class="accessibility-audit-pr-review-reason">' + escHtml(desc) + '</p>' +
                        '<pre class="accessibility-audit-pr-review-code"><code>' + escHtml(item.html) + '</code></pre>' +
                    '</div>';
            });
            reviewHtml =
                '<div class="accessibility-audit-pr-needs-review">' +
                    '<p class="accessibility-audit-pr-needs-review-title">⚠ ' + _contrastNeedsReview.length + ' element' + (_contrastNeedsReview.length !== 1 ? 's' : '') + ' need manual review</p>' +
                    '<p class="accessibility-audit-pr-needs-review-note">Background colour could not be determined automatically, contrast ratio cannot be calculated. Inspect these elements manually.</p>' +
                    reviewItems +
                '</div>';
        }

        /* Message first, then one meta row (level/criterion left, help link
           right), matching the entry sidebar panel. No severity dot: the row
           this panel hangs off already shows one. */
        body.innerHTML =
            (issue.message ? '<p class="accessibility-audit-pr-expand-msg">' + escHtml(issue.message) + '</p>' : '') +
            ((lvl || criterion || helpLink)
                ? '<div class="accessibility-audit-pr-expand-meta">' + lvl + criterion + helpLink + '</div>'
                : '') +
            (singleOccurrenceRule ? '' :
                '<p class="accessibility-audit-pr-expand-occ-heading">' + escHtml(String(issue.occurrences)) + ' occurrence' + (issue.occurrences !== 1 ? 's' : '') + '</p>' +
                occHtml) +
            reviewHtml;

        /* Click on needs-review Highlight button → scroll iframe to that element */
        body.querySelectorAll('.accessibility-audit-pr-review-hl-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var idx = parseInt(btn.dataset.reviewIdx, 10);
                var item = _contrastNeedsReview && _contrastNeedsReview[idx];
                if (!item) return;
                var doc = iframeDoc();
                if (!doc) { showCrossOriginNotice(); return; }
                ensureHighlightStyles(doc);
                clearHighlights(doc);
                var found = findElementByContext(doc, item.html);
                if (!found && item.selector) {
                    try { found = doc.querySelector(item.selector); } catch (_) {}
                }
                if (found) {
                    found.setAttribute('data-accessibility-audit-hl', 'first');
                    if (paneContent && paneContent.hidden) switchToView('content');
                    scrollToEl(found);
                }
            });
        });

        /* Click on occurrence → scroll iframe to that element */
        body.querySelectorAll('.accessibility-audit-pr-occ-item').forEach(function (item) {
            item.addEventListener('click', function () {
                var idx = parseInt(item.dataset.occIdx, 10);
                body.querySelectorAll('.accessibility-audit-pr-occ-item').forEach(function (i) { i.classList.remove('accessibility-audit-pr-occ-item--active'); });
                item.classList.add('accessibility-audit-pr-occ-item--active');

                /* Highlight in whichever view is showing now, not forced to
                   HTML just because it was loaded once. */
                if (paneHtml && !paneHtml.hidden) {
                    scrollHtmlViewToOccurrence(idx);
                } else {
                    scrollIframeToOccurrence(idx);
                }
            });
        });
    }

    /* Marks one element as the focused occurrence and scrolls to it, replaying
       its flash animation so a repeat click still reads as a response. */
    function focusOccurrenceElement(doc, el) {
        doc.querySelectorAll('[data-accessibility-audit-hl="first"]').forEach(function (e) {
            e.setAttribute('data-accessibility-audit-hl', 'other');
        });
        ensureLazyLoaded(el);
        el.setAttribute('data-accessibility-audit-hl', 'first');
        noticeIfAllHidden([el], doc);
        scrollToEl(el);
        el.style.animation = 'none';
        el.offsetHeight; // reflow
        el.style.animation = '';
    }

    function scrollIframeToOccurrence(idx) {
        /* Prefer context-based matching when occurrences have been loaded */
        if (_currentOccurrences && _currentOccurrences[idx]) {
            var doc = iframeDoc();
            var ctx = _currentOccurrences[idx].context;
            if (doc) {
                var el = findElementByContext(doc, ctx);
                if (el) {
                    focusOccurrenceElement(doc, el);
                    return;
                }

                /* Not markup: the potential checks store the element's own
                   text, so match by text instead of falling through to the
                   idx-th match of the broad selector below. */
                var byText = matchByText(doc, selectorFor(_currentRuleId), ctx);
                if (byText.length) {
                    focusOccurrenceElement(doc, byText[0]);
                    return;
                }
            }
        }

        /* Fallback: broad selector (used before occurrences load, or if context match fails) */
        var fallbackRuleId = _currentRuleId;
        if (!fallbackRuleId) {
            var row = document.querySelector('.accessibility-audit-pr-issue-row--active');
            fallbackRuleId = row ? row.dataset.ruleId : null;
        }
        var selector = selectorFor(fallbackRuleId);
        if (!selector) return;
        var doc2 = iframeDoc();
        if (!doc2) return;
        var elements = doc2.querySelectorAll(selector);
        if (elements[idx]) {
            focusOccurrenceElement(doc2, elements[idx]);
        }
    }

    /* Whether the element renders nowhere on the page right now: inside a
       collapsed menu, a closed accordion, a display:none panel. */
    function hiddenInPage(el, doc) {
        if (!el.getClientRects || !el.getClientRects().length) return true;
        try { return doc.defaultView.getComputedStyle(el).visibility === 'hidden'; }
        catch (_) { return false; }
    }

    /* Says so when EVERY matched element is hidden (a mixed set still shows
       something); the highlight stays applied for when the menu opens. */
    function noticeIfAllHidden(found, doc) {
        if (!found.length) return;
        for (var i = 0; i < found.length; i++) {
            if (!hiddenInPage(found[i], doc)) return;
        }
        if (window.Craft && Craft.cp && Craft.cp.displayNotice) {
            Craft.cp.displayNotice(Craft.t('accessibility-audit', 'Highlighted, but the element is inside a collapsed menu or panel. Open it on the page to see it.'));
        }
    }

    /* ── Context-based element matching ────────────────────────────── */

    /* Given stored context HTML (or JSON-wrapped contrast context), find the
       specific element in the iframe document. Returns null if not found. */
    function findElementByContext(doc, contextHtml) {
        if (!contextHtml || !doc) return null;

        /* Contrast issues store JSON: a stored CSS selector locates the exact
           element (colour pairs alone can't), and the inner html field feeds
           the attribute matchers below as a fallback. */
        var html = contextHtml;
        try {
            var cd = JSON.parse(contextHtml);
            if (cd && cd.selector) {
                try {
                    var bySelector = doc.querySelector(cd.selector);
                    if (bySelector) return bySelector;
                } catch (_) {}
            }
            if (cd && cd.html) html = cd.html;
        } catch (_) {}

        /* Parse the stored context inertly: a DOMParser document neither loads
           resources nor runs script, so an <img onerror> or <script> smuggled
           into the stored markup can't fire while we read attributes off it. A
           detached div with innerHTML would begin fetching/executing. */
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var el = parsed.body.firstElementChild;

        /* The length cap often cuts inside the opening tag, and the HTML
           parser's EOF-in-tag rule drops the whole token, leaving nothing to
           match on even though complete attributes survived. Close the tag
           (and a dangling attribute value, when the quote count says the cut
           fell inside one) and parse again. */
        var repaired = html;
        if (!el && repaired.charAt(0) === '<') {
            if (repaired.slice(-1) === '…') repaired = repaired.slice(0, -1);
            if (repaired.indexOf('>') === -1) {
                repaired += ((repaired.match(/"/g) || []).length % 2 === 1) ? '">' : '>';
                parsed = new DOMParser().parseFromString(repaired, 'text/html');
                el = parsed.body.firstElementChild;
            }
        }

        /* Table-scoped tags (td, tr, …) are dropped outright by the in-body
           parser: re-parse inside a table scaffold and descend to the tag. */
        if (!el) {
            var tableTag = /^<\s*(td|th|tr|tbody|thead|tfoot|caption|col|colgroup)\b/i.exec(repaired);
            if (tableTag) {
                parsed = new DOMParser().parseFromString('<table>' + repaired + '</table>', 'text/html');
                el = parsed.body.querySelector(tableTag[1].toLowerCase());
            }
        }
        if (!el) return null;

        var tag = el.tagName.toLowerCase();

        /* Document-level nodes (axe reports <html> for document-title and
           the lang rules): boxing the whole page marks nothing out, so these
           resolve to no element rather than to everything. */
        if (tag === 'html' || tag === 'body') return null;

        /* 1. Exact id match */
        if (el.id) {
            var byId = doc.getElementById(el.id);
            if (byId && byId.tagName.toLowerCase() === tag) return byId;
        }

        /* Attributes can be shared (a nav link and a CTA with the same href),
           so among candidates the one also matching the stored element's
           classes and text wins; a lone candidate is trusted as-is. */
        var wantClass = (el.getAttribute('class') || '').trim().split(/\s+/).filter(Boolean).sort().join(' ');
        var wantText = el.textContent.trim();
        /* A stored text preview is capped with a trailing ellipsis, so a
           capped want means prefix comparison, not equality. */
        function textMatchesWant(candText) {
            if (!wantText) return false;
            if (wantText.slice(-1) === '…') return candText.indexOf(wantText.slice(0, -1)) === 0;
            return candText === wantText;
        }
        function pickCandidate(cands) {
            if (!cands.length) return null;
            if (cands.length === 1) return cands[0];
            var best = cands[0];
            var bestScore = -1;
            for (var c = 0; c < cands.length; c++) {
                var score = 0;
                var candClass = (cands[c].getAttribute('class') || '').trim().split(/\s+/).filter(Boolean).sort().join(' ');
                if (wantClass && candClass === wantClass) score += 4;
                if (textMatchesWant(cands[c].textContent.trim())) score += 2;
                /* Visibility as the tiebreak: an equally good hidden twin
                   (a cloned slide, an off-state menu) loses to one on screen. */
                if (!hiddenInPage(cands[c], doc)) score += 1;
                if (score > bestScore) { bestScore = score; best = cands[c]; }
            }
            return best;
        }
        function attrCandidates(attrSelector) {
            var out = [];
            try {
                doc.querySelectorAll(attrSelector).forEach(function (cand) { out.push(cand); });
            } catch (_) {}
            return out;
        }

        /* 2. href match (links) */
        var href = el.getAttribute('href');
        if (href) {
            var links = doc.querySelectorAll(tag + '[href]');
            var hrefCands = [];
            for (var i = 0; i < links.length; i++) {
                if (links[i].getAttribute('href') === href) hrefCands.push(links[i]);
            }
            var byHref = pickCandidate(hrefCands);
            if (byHref) return byHref;
        }

        /* 3. src match (images, iframes) */
        var src = el.getAttribute('src');
        if (src) {
            var bySrc = pickCandidate(attrCandidates(tag + '[src=' + JSON.stringify(src) + ']'));
            if (bySrc) return bySrc;
        }

        /* 4. name match (form elements) */
        var name = el.getAttribute('name');
        if (name) {
            var byName = pickCandidate(attrCandidates(tag + '[name=' + JSON.stringify(name) + ']'));
            if (byName) return byName;
        }

        /* 5. aria-label match */
        var ariaLabel = el.getAttribute('aria-label');
        if (ariaLabel) {
            var byAria = pickCandidate(attrCandidates(tag + '[aria-label=' + JSON.stringify(ariaLabel) + ']'));
            if (byAria) return byAria;
        }

        /* 5b. Any remaining attributes, compounded: data-* hooks identify
           elements with no id, text, or distinctive class. Names CSS can't
           select on (Alpine's @click, :class) are skipped; a miss falls
           through to the class and text steps. */
        if (el.attributes && el.attributes.length) {
            var attrSel = tag;
            for (var ai = 0; ai < el.attributes.length; ai++) {
                var at = el.attributes[ai];
                if (at.name === 'class' || at.name === 'style' || !at.value) continue;
                if (!/^[a-zA-Z_][a-zA-Z0-9_-]*$/.test(at.name)) continue;
                attrSel += '[' + at.name + '=' + JSON.stringify(at.value) + ']';
            }
            if (attrSel !== tag) {
                var byAttrs = pickCandidate(attrCandidates(attrSel));
                if (byAttrs) return byAttrs;
            }
        }

        /* 6. Unique tag+class match: icon spans and styled boxes (contrast
           findings especially) often carry no id, href, name, or text, only
           classes. Trusted only when exactly one element matches, so an
           ambiguous class can never frame the wrong element. */
        if (el.classList && el.classList.length) {
            try {
                var classSel = tag;
                el.classList.forEach(function (cls) { classSel += '.' + CSS.escape(cls); });
                var byClass = doc.querySelectorAll(classSel);
                if (byClass.length === 1) return byClass[0];
            } catch (_) {}
        }

        /* 7. Text content match (last resort); a visible instance beats a
           hidden one, since the same text can render more than once. */
        if (wantText) {
            var candidates = doc.querySelectorAll(tag);
            var textMatches = [];
            for (var j = 0; j < candidates.length; j++) {
                if (textMatchesWant(candidates[j].textContent.trim())) textMatches.push(candidates[j]);
            }
            for (var v = 0; v < textMatches.length; v++) {
                if (!hiddenInPage(textMatches[v], doc)) return textMatches[v];
            }
            if (textMatches.length) return textMatches[0];
        }

        return null;
    }

    /* Highlight only the specific failing elements from stored occurrences,
       replacing the initial broad-selector highlights with accurate ones. */
    function highlightFromOccurrences(occurrences, ruleId) {
        if (!occurrences || !occurrences.length) return;
        var doc = iframeDoc();
        if (!doc) return; /* cross-origin: broad highlights already in place */

        ensureHighlightStyles(doc);
        clearHighlights(doc);

        /* The rule in view decides which elements a non-markup context can
           refer to, so its selector feeds the text fallback below. Caller
           passes it in; the active-row lookup is only a fallback, since the
           class may not be set yet when an async fetch resolves. */
        if (!ruleId) {
            var activeRow = document.querySelector('.accessibility-audit-pr-issue-row--active');
            ruleId = activeRow ? activeRow.dataset.ruleId : null;
        }
        var activeSelector = selectorFor(ruleId);

        var found = [];
        occurrences.forEach(function (occ) {
            if (!occ.context) return;

            /* An attribute-fragment context (the PHP duplicate-id rule stores
               `id="…"`) is not markup an element can be parsed from. It names
               every element carrying that id, and for a duplicate that is
               exactly the point: highlight them all. */
            var idFrag = occ.context.match(/^id="([^"]+)"$/);
            if (idFrag) {
                try {
                    Array.prototype.forEach.call(
                        doc.querySelectorAll('[id=' + JSON.stringify(idFrag[1]) + ']'),
                        function (el) { if (found.indexOf(el) === -1) found.push(el); }
                    );
                } catch (_) {}
                return;
            }

            var el = findElementByContext(doc, occ.context);
            if (el) {
                if (found.indexOf(el) === -1) found.push(el);
                return;
            }

            /* Not markup: a potential rule stores the element's own text,
               matched via matchByText instead of falling back to the broad
               selector. */
            matchByText(doc, activeSelector, occ.context).forEach(function (match) {
                if (found.indexOf(match) === -1) found.push(match);
            });

            /* Markup with a truncated URL (a long image src): same prefix
               fallback the potential path uses. */
            if (occ.context.charAt(0) === '<') {
                matchByUrlPrefix(doc, activeSelector, occ.context).forEach(function (match) {
                    if (found.indexOf(match) === -1) found.push(match);
                });
            }
        });

        if (found.length === 0) {
            /* A rule's failure predicate can still locate genuine offenders
               when the context itself was too anonymous to match. */
            var flt = filterFor(ruleId);
            var broadSel = selectorFor(ruleId);
            if (flt && broadSel) {
                try {
                    found = Array.prototype.filter.call(doc.querySelectorAll(broadSel), flt);
                } catch (_) {}
            }
        }

        if (found.length === 0) {
            /* Nothing locatable (script-injected element, or a state the
               preview isn't in): say so rather than box the broad selector. */
            clearHighlights(doc);
            showHighlightNote("(can't highlight, not in the preview)");
            return;
        }

        found.forEach(function (el, i) {
            /* Same reason as the potential-issue path: a lazy image is
               zero-sized until its URL is swapped in, so the highlight would
               have nothing to frame. */
            ensureLazyLoaded(el);
            el.setAttribute('data-accessibility-audit-hl', i === 0 ? 'first' : 'other');
            if (el.tagName.toLowerCase() !== 'html') {
                var badge = doc.createElement('span');
                badge.className = 'accessibility-audit-hl-badge';
                badge.textContent = (i + 1) + '/' + found.length;
                try { el.insertBefore(badge, el.firstChild); } catch (_) {}
            }
        });

        if (paneContent && paneContent.hidden) switchToView('content');

        noticeIfAllHidden(found, doc);

        var firstFound = found[0];
        scrollToEl(firstFound);
        if (firstFound.tagName.toLowerCase() === 'img' && firstFound.complete === false) {
            firstFound.addEventListener('load', function () { scrollToEl(firstFound); }, { once: true });
        }

        updateActiveRowBadge(found.length);
    }

    /* Forces a lazy-loaded image to load so it can be seen. The lazy-load
       script doesn't run inside the preview iframe, so the URL sits in
       data-src and the element stays zero-sized. Copying it to src renders
       it at real size; safe here since the iframe is a disposable copy. */
    function ensureLazyLoaded(el) {
        if (!el || el.tagName.toLowerCase() !== 'img') return;

        var lazySrc = el.getAttribute('data-src');
        if (lazySrc && !el.getAttribute('src')) {
            el.setAttribute('src', lazySrc);
        }

        var lazySrcset = el.getAttribute('data-srcset');
        if (lazySrcset && !el.getAttribute('srcset')) {
            el.setAttribute('srcset', lazySrcset);
        }
    }

    /* Matches elements whose src/data-src begins with the URL fragment left
       in a truncated context. data-src is checked first: that's where the
       real image lives under lazy loading, while src often holds a shared
       placeholder. The prefix must be long enough to identify something (a
       short one, a CDN root say, would match everything), so anything too
       short is treated as no match. */
    function matchByUrlPrefix(doc, selector, ctx) {
        var m = ctx.match(/data-src="([^"…]+)/) || ctx.match(/\ssrc="([^"…]+)/);
        if (!m) return [];

        var prefix = m[1];
        if (prefix.length < 24) return [];

        var out = [];
        try {
            Array.prototype.forEach.call(doc.querySelectorAll(selector), function (el) {
                var value = el.getAttribute('data-src') || el.getAttribute('src') || '';
                if (value.indexOf(prefix) === 0) out.push(el);
            });
        } catch (e) {
            return [];
        }

        /* A truncated URL prefix can match sibling images on a shared upload
           path; the class attribute survives truncation and tells them apart.
           Narrow only when it matches something, else the prefix set stands. */
        if (out.length > 1) {
            var cls = ctx.match(/class="([^"]*)"/);
            if (cls) {
                var want = cls[1].trim().split(/\s+/).filter(Boolean).sort().join(' ');
                var narrowed = out.filter(function (el) {
                    var candCls = (el.getAttribute('class') || '').trim().split(/\s+/).filter(Boolean).sort().join(' ');
                    return candCls === want;
                });
                if (narrowed.length) out = narrowed;
            }
        }

        return out;
    }

    /* Finds elements by their text, for contexts that are not markup: the
       potential checks store a plain string rather than HTML (a paragraph's
       own text, or a link's text wrapped in quotes), which findElementByContext
       can't parse. */
    function matchByText(doc, selector, ctx) {
        if (!doc || !selector || !ctx) return [];

        var quoted = ctx.match(/^"([^"]*)"/);
        var needle = (quoted ? quoted[1] : ctx).trim();
        if (!needle) return [];

        var out = [];
        try {
            Array.prototype.forEach.call(doc.querySelectorAll(selector), function (el) {
                if (visibleText(el) === needle) out.push(el);
            });
        } catch (e) {
            return [];
        }

        return out;
    }

    /* An element's own text, ignoring the highlight badge this tool injects
       as its first child (which would otherwise get counted as text). The
       clone is only paid for when a badge is actually present. */
    function visibleText(el) {
        if (!el.querySelector('.accessibility-audit-hl-badge')) {
            return el.textContent.trim();
        }

        var clone = el.cloneNode(true);
        clone.querySelectorAll('.accessibility-audit-hl-badge').forEach(function (badge) {
            badge.remove();
        });

        return clone.textContent.trim();
    }

    /* Highlights the exact element(s) a potential issue asks about: the broad
       rule selector answers nothing here (e.g. `a[href]` lights up every
       link on the page). Context comes as markup, quoted "text" plus urls, or
       plain text depending on the rule; each is resolved to real elements,
       with the broad selector kept only as a last resort. */
    function highlightPotential(ruleId, context) {
        var doc = iframeDoc();
        var selector = selectorFor(ruleId);
        if (!doc || !selector) return;

        var ctx = (context || '').trim();
        var found = [];

        if (ctx.charAt(0) === '<') {
            var el = findElementByContext(doc, ctx);
            if (el) {
                found.push(el);
                /* Box identical-text siblings too (a hero title repeated on a
                   listing card), so whichever copy the scan recorded, the one
                   on screen is among the boxed. */
                var twinText = el.textContent.trim();
                if (twinText) {
                    matchByText(doc, el.tagName.toLowerCase(), twinText).forEach(function (twin) {
                        if (found.indexOf(twin) === -1) found.push(twin);
                    });
                }
            } else {
                /* Context is length-capped, so a long URL may be cut off
                   mid-attribute, and a lazy-loaded image keeps its real URL
                   in data-src rather than src. Match on the leading part of
                   the URL that survived instead. */
                found = matchByUrlPrefix(doc, selector, ctx);
            }
        } else if (ctx) {
            found = matchByText(doc, selector, ctx);
        }

        if (found.length === 0) {
            /* A rule with a failure predicate can still locate genuine
               offenders by re-testing the candidates. */
            var flt = filterFor(ruleId);
            if (flt) {
                try { found = Array.prototype.filter.call(doc.querySelectorAll(selector), flt); } catch (_) {}
            }
        }

        if (found.length === 0) {
            /* No identifying signal survived in the snippet, or the element
               is gone: say so rather than box the broad selector. */
            clearHighlights(doc);
            if (window.Craft && Craft.cp && Craft.cp.displayNotice) {
                Craft.cp.displayNotice(Craft.t('accessibility-audit', "This occurrence couldn't be located in the preview. The page may have changed since the scan, or the element is added by a script that doesn't run here."));
            }
            return;
        }

        ensureHighlightStyles(doc);
        clearHighlights(doc);

        found.forEach(function (el, i) {
            ensureLazyLoaded(el);
            el.setAttribute('data-accessibility-audit-hl', i === 0 ? 'first' : 'other');
            if (el.tagName.toLowerCase() !== 'html') {
                var badge = doc.createElement('span');
                badge.className = 'accessibility-audit-hl-badge';
                badge.textContent = (i + 1) + '/' + found.length;
                try { el.insertBefore(badge, el.firstChild); } catch (_) {}
            }
        });

        if (paneContent && paneContent.hidden) switchToView('content');

        noticeIfAllHidden(found, doc);

        /* Scroll now for responsiveness, then again once a just-loaded image
           has real dimensions: until it does, the target sits at the wrong
           offset and the page lands in the wrong place. Scroll to the first
           VISIBLE match: travelling to a hidden twin looks like nothing
           happened. */
        var first = found[0];
        for (var fv = 0; fv < found.length; fv++) {
            if (!hiddenInPage(found[fv], doc)) { first = found[fv]; break; }
        }
        scrollToEl(first);
        if (first.tagName.toLowerCase() === 'img' && first.complete === false) {
            first.addEventListener('load', function () { scrollToEl(first); }, { once: true });
        }
    }

    /* Look up each occurrence's element in the iframe and attach template + selector info.
       Runs after occurrences arrive from the server. No-ops cross-origin or if devMode
       comments are absent (template field stays null, render just omits the pill). */
    function enrichOccurrencesWithTemplateInfo(occurrences) {
        var doc = iframeDoc();
        if (!doc) return;
        occurrences.forEach(function (occ) {
            if (occ.template !== undefined) return; /* already enriched */
            var contextHtml = _occContextHtml(occ);
            if (!contextHtml) return;
            var el = findElementByContext(doc, contextHtml);
            if (!el) return;
            occ.template = _findTemplate(el, doc);
            occ.selector = _cssPath(el, doc);
        });
    }

    async function loadAndShowOccurrences(row, expandBody) {
        var cfg    = window.AccessibilityAudit || {};
        var base   = cfg.pageRuleOccurrencesUrl || '';
        if (!base) return;
        var sep    = base.includes('?') ? '&' : '?';
        var url    = base + sep + 'scanId=' + encodeURIComponent(row.dataset.scanId) + '&ruleId=' + encodeURIComponent(row.dataset.ruleId);
        try {
            var res  = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            var data = await res.json();
            if (data.success) {
                _currentOccurrences = data.occurrences;
                _currentRuleId = row.dataset.ruleId;
                /* Attach template + selector to each occurrence before rendering */
                enrichOccurrencesWithTemplateInfo(data.occurrences);
                var issueObj = JSON.parse(row.dataset.issue || '{}');
                renderExpandPanel(expandBody, issueObj, data.occurrences);
                highlightHtmlViewContext(data.occurrences);
                /* Replace broad-selector highlights with accurate context-based ones */
                highlightFromOccurrences(data.occurrences, row.dataset.ruleId);
                /* If the iframe hasn't loaded yet and _contrastNeedsReview is still being
                   collected, re-render once it arrives (handled via iframe load callback) */
            }
        } catch (err) {
            expandBody.querySelector('.accessibility-audit-loading') && (expandBody.querySelector('.accessibility-audit-loading').textContent = Craft.t('accessibility-audit', 'Failed to load.'));
        }
    }

    /* ── HTML view: highlight occurrence context strings ────────────── */

    /* Extract the HTML fragment to search for in the source view.
       Contrast occurrences store JSON context: pull out the html field. */
    function _occContextHtml(occ) {
        if (!occ.context) return null;
        try {
            var cd = JSON.parse(occ.context);
            if (cd && cd.html) return cd.html;
        } catch (_) {}
        return occ.context;
    }

    function highlightHtmlViewContext(occurrences) {
        if (!htmlCode || !occurrences || !occurrences.length) return;
        var source = htmlCode.dataset.rawSource;
        if (!source) return;

        var highlighted = escHtml(source);
        var markIdx = 0;
        occurrences.forEach(function (occ) {
            var ctx = _occContextHtml(occ);
            if (!ctx) return;
            var escaped = escHtml(ctx);
            var idx = markIdx++;
            var parts = highlighted.split(escaped);
            if (parts.length > 1) {
                highlighted = parts[0] +
                    '<mark class="accessibility-audit-html-hl" data-html-occ-idx="' + idx + '">' + escaped + '</mark>' +
                    parts.slice(1).join(escaped);
            }
        });
        htmlCode.innerHTML = highlighted;
    }

    /* ── HTML view: scroll to a specific occurrence mark ───────────── */
    function scrollHtmlViewToOccurrence(idx) {
        if (!htmlCode) return;
        /* Remove active from all marks */
        htmlCode.querySelectorAll('.accessibility-audit-html-hl--active').forEach(function (m) {
            m.classList.remove('accessibility-audit-html-hl--active');
        });
        var mark = htmlCode.querySelector('.accessibility-audit-html-hl[data-html-occ-idx="' + idx + '"]');
        if (!mark) {
            /* Fallback: first mark if specific index not found */
            mark = htmlCode.querySelector('.accessibility-audit-html-hl');
        }
        if (!mark) return;
        mark.classList.add('accessibility-audit-html-hl--active');
        scrollToEl(mark);
    }

    /* ── Issue row click ────────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        if (e.target.closest('.accessibility-audit-pr-detail-arrow')) return;

        var row = e.target.closest('.accessibility-audit-pr-issue-row[data-rule-id]');
        if (!row) return;

        var ruleId      = row.dataset.ruleId;
        var expandPanel = row.closest('.accessibility-audit-pr-issue-item') ? row.closest('.accessibility-audit-pr-issue-item').querySelector('.accessibility-audit-pr-issue-expand') : null;
        var expandBody  = expandPanel ? expandPanel.querySelector('.accessibility-audit-pr-issue-expand-body') : null;

        /* Toggle off */
        if (activeRuleId === ruleId && row.classList.contains('accessibility-audit-pr-issue-row--active')) {
            row.classList.remove('accessibility-audit-pr-issue-row--active');
            row.setAttribute('aria-expanded', 'false');
            if (expandPanel) expandPanel.hidden = true;
            activeRuleId = null;
            var doc = iframeDoc();
            if (doc) clearHighlights(doc);
            return;
        }

        /* Close previous */
        closeAllExpand();
        var prevDoc = iframeDoc();
        if (prevDoc) clearHighlights(prevDoc);

        row.classList.add('accessibility-audit-pr-issue-row--active');
        row.setAttribute('aria-expanded', 'true');
        activeRuleId = ruleId;

        /* Open expand panel with loading state */
        if (expandPanel && expandBody) {
            var issue = {};
            try { issue = JSON.parse(row.dataset.issue || '{}'); } catch (_) {}
            var skipOccurrences = isPageLevelRule(ruleId);
            renderExpandPanel(expandBody, issue, skipOccurrences ? [] : null);
            expandPanel.hidden = false;
            if (!skipOccurrences) {
                loadAndShowOccurrences(row, expandBody);
            }
        }

        /* Highlight in iframe */
        var selector = selectorFor(ruleId);
        highlightInIframe(ruleId, selector);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var row = e.target.closest('.accessibility-audit-pr-issue-row[data-rule-id]');
        if (row) { e.preventDefault(); row.click(); }
    });

    /* ── View tabs ──────────────────────────────────────────────────── */
    function switchToView(view) {
        document.querySelectorAll('[data-pr-view]').forEach(function (t) {
            var active = t.dataset.prView === view;
            t.classList.toggle('accessibility-audit-pr-tab--active', active);
            /* WAI-ARIA tabs: selection state + roving tabindex */
            t.setAttribute('aria-selected', String(active));
            t.tabIndex = active ? 0 : -1;
        });
        if (paneContent) paneContent.hidden = (view !== 'content');
        if (paneHtml)    paneHtml.hidden    = (view !== 'html');
        if (view === 'html') {
            if (!htmlLoaded && iframe) {
                loadHtmlSource(iframe.dataset.pageUrl || iframe.src);
            } else if (htmlLoaded && activeRuleId && htmlCode && htmlCode.dataset.rawSource) {
                /* HTML already loaded: re-apply highlights using stored occurrences */
                if (_currentOccurrences && _currentOccurrences.length) {
                    highlightHtmlViewContext(_currentOccurrences);
                }
            }
        }
    }

    document.querySelectorAll('[data-pr-view]').forEach(function (tab) {
        tab.addEventListener('click', function () { switchToView(tab.dataset.prView); });
    });

    /* Arrow-key navigation between the view tabs (selection follows focus) */
    var viewTablist = document.querySelector('[data-pr-view]');
    viewTablist = viewTablist ? viewTablist.closest('[role="tablist"]') : null;
    if (viewTablist) {
        viewTablist.addEventListener('keydown', function (e) {
            if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(e.key) === -1) return;
            var tabs = Array.prototype.slice.call(viewTablist.querySelectorAll('[data-pr-view]'));
            var idx = tabs.indexOf(document.activeElement);
            if (idx === -1) return;
            e.preventDefault();
            if (e.key === 'ArrowLeft')  idx = (idx - 1 + tabs.length) % tabs.length;
            if (e.key === 'ArrowRight') idx = (idx + 1) % tabs.length;
            if (e.key === 'Home')       idx = 0;
            if (e.key === 'End')        idx = tabs.length - 1;
            tabs[idx].focus();
            switchToView(tabs[idx].dataset.prView);
        });
    }

    /* ── HTML source loader ─────────────────────────────────────────── */
    function loadHtmlSource(url) {
        if (!htmlCode) return;
        htmlCode.textContent = Craft.t('accessibility-audit', 'Loading…');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (text) {
                htmlCode.dataset.rawSource = text;
                htmlCode.textContent = text;
                htmlLoaded = true;
                /* Re-highlight if an issue is already active */
                if (activeRuleId && _currentOccurrences && _currentOccurrences.length) {
                    highlightHtmlViewContext(_currentOccurrences);
                }
            })
            .catch(function (err) {
                htmlCode.textContent = Craft.t('accessibility-audit', 'Failed to load: {error}', { error: err.message });
            });
    }

    /* ── SVG filter defs (injected into iframe so url(#id) resolves) ── */
    var filterDefsSrc = (document.getElementById('accessibility-audit-filter-defs-src') || {}).textContent || '';

    function ensureFilterDefsInIframe(doc) {
        if (!doc || doc.getElementById('accessibility-audit-cf-defs')) return;
        try {
            var wrap = doc.createElement('div');
            wrap.innerHTML = filterDefsSrc.trim();
            var svgEl = wrap.firstElementChild;
            if (svgEl) (doc.body || doc.documentElement).appendChild(svgEl);
        } catch (e) { /* cross-origin */ }
    }

    /* Apply / remove a colour filter on the entire iframe page */
    var currentFilter = 'none';

    function applyColourFilter(filterValue) {
        currentFilter = filterValue;
        var doc = iframeDoc();
        if (doc) {
            ensureFilterDefsInIframe(doc);
            doc.documentElement.style.filter = (filterValue === 'none') ? '' : filterValue;
            doc.documentElement.style.transition = 'filter 0.3s ease';
        }
    }

    /* ── WCAG colour-contrast helpers (used for auto-scan on iframe load) ───
       The contrast math lives in accessibility-audit-shared.js (window.AccessibilityAuditShared), shared
       with the frontend overlay so the two engines can never drift. Local
       aliases keep the page-specific helpers below readable. */

    var _parseRgb = AccessibilityAuditShared.parseRgb;
    var _rgbToHex = AccessibilityAuditShared.rgbToHex;

    /* Collect per-element contrast violations (capped at 150 to avoid huge POST) */
    /* Excluded page furniture (consent banners and the like), resolved
       server-side and shared with every engine. Joined for Element.closest();
       empty string when nothing is excluded. */
    function excludedJoined() {
        var pairs = (window.AccessibilityAudit || {}).axeExclude || [];
        return pairs.map(function (pair) { return pair[0]; }).join(', ');
    }

    function inExcluded(el) {
        var joined = excludedJoined();
        if (!joined || !el.closest) return false;
        try { return !!el.closest(joined); } catch (_) { return false; }
    }

    function collectContrastOccurrences(doc) {
        if (!doc || !doc.body) return null;
        return AccessibilityAuditShared.collectContrastFailures(doc, {
            limit: 150,
            htmlLength: 200,
            /* Skip anything this tool injected or is currently decorating:
               highlight badges carry their own text, and a highlighted
               element's rendering is ours, not the page's. Excluded page
               furniture is skipped too, matching the axe pass. */
            skipEl: function (el) {
                if (el.closest && el.closest('[data-accessibility-audit-hl], .accessibility-audit-hl-badge')) return true;
                return inExcluded(el);
            },
        });
    }

    /* Human-readable explanation of why the background colour is indeterminate */
    var _REVIEW_REASONS = {
        'pseudo-element background': 'A CSS ::before or ::after pseudo-element paints the background, so the actual colour cannot be read from the DOM.',
        'background image or gradient': 'A background image or CSS gradient is applied, the effective colour cannot be calculated automatically.',
        'fixed/sticky positioned ancestor': 'An ancestor element uses position: fixed or sticky. Its visual background comes from the viewport stacking context, not DOM ancestry.',
    };

    /* Returns WHY _effectiveBg would bail on an element, or null if it can resolve the bg.
       Returns one of the _REVIEW_REASONS keys. */
    function _whyIndeterminate(el, doc) {
        var cur = el;
        while (cur && cur.nodeType === 1) {
            var style = doc.defaultView.getComputedStyle(cur);
            if (style.position === 'fixed' || style.position === 'sticky') return 'fixed/sticky positioned ancestor';
            var bgImg = style.backgroundImage;
            if (bgImg && bgImg !== 'none') return 'background image or gradient';
            var pseudos = ['::before', '::after'];
            for (var pi = 0; pi < pseudos.length; pi++) {
                var ps = doc.defaultView.getComputedStyle(cur, pseudos[pi]);
                if (ps.display !== 'none') {
                    var pBg = _parseRgb(ps.backgroundColor);
                    if (pBg && pBg.a > 0) return 'pseudo-element background';
                }
            }
            var bg = _parseRgb(style.backgroundColor);
            if (bg && bg.a >= 1) return null; /* opaque bg found: _effectiveBg resolves from here */
            cur = cur.parentElement;
        }
        return null;
    }

    /* Walk the DOM to find the innermost <!-- accessibility-audit-tpl:filename.twig --> comment
       that wraps the element. These comments are injected at compile time by
       A11yTemplateNodeVisitor when devMode is true. Returns template path or null. */
    function _findTemplate(el, doc) {
        var cur = el;
        while (cur && cur !== doc.documentElement) {
            /* Scan previous siblings at this level for an opening accessibility-audit-tpl comment */
            var sib = cur.previousSibling;
            while (sib) {
                if (sib.nodeType === 8) { /* COMMENT_NODE */
                    var txt = sib.nodeValue ? sib.nodeValue.trim() : '';
                    if (txt.indexOf('accessibility-audit-tpl:') === 0) {
                        return txt.substring('accessibility-audit-tpl:'.length).trim();
                    }
                }
                sib = sib.previousSibling;
            }
            cur = cur.parentNode;
        }
        return null;
    }

    /* Find the nearest semantic landmark ancestor and return a display label */
    function _nearestLandmark(el, doc) {
        var LANDMARKS = ['nav', 'header', 'main', 'aside', 'footer', 'section', 'article', 'form'];
        var cur = el.parentElement;
        while (cur && cur !== doc.body) {
            var tag = cur.tagName.toLowerCase();
            if (LANDMARKS.indexOf(tag) !== -1) {
                /* Prefer aria-label, then aria-labelledby text, then id, then first class */
                var name = cur.getAttribute('aria-label');
                if (!name) {
                    var lbId = cur.getAttribute('aria-labelledby');
                    if (lbId) {
                        var lbEl = doc.getElementById(lbId);
                        if (lbEl) name = lbEl.textContent.trim();
                    }
                }
                if (!name) name = cur.id || (cur.className && typeof cur.className === 'string' && cur.className.trim().split(/\s+/)[0]) || null;
                return { tag: tag, name: name };
            }
            cur = cur.parentElement;
        }
        return null;
    }

    /* Build a short CSS selector path for the element location label (up to 3 levels) */
    function _cssPath(el, doc) {
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

    /* Collect text elements whose contrast cannot be auto-checked (capped at 50) */
    function collectContrastNeedsReview(doc) {
        if (!doc || !doc.body) return [];
        var results = [];
        var walker = doc.createTreeWalker(doc.body, 4 /* SHOW_TEXT */, {
            acceptNode: function (n) { return n.textContent.trim() ? 1 : 3; }
        });
        var parents = [];
        var n;
        while ((n = walker.nextNode())) { if (n.parentElement) parents.push(n.parentElement); }
        parents = parents.filter(function (el, i, a) { return a.indexOf(el) === i; });

        parents.forEach(function (el) {
            if (results.length >= 50) return;
            /* Excluded page furniture: same skip as the axe pass and the
               definite-contrast collector, or the needs-review list fills up
               with consent-banner text nobody can act on. */
            if (inExcluded(el)) return;
            var style = doc.defaultView.getComputedStyle(el);
            if (style.display === 'none' || style.visibility === 'hidden') return;
            if (!el.offsetWidth && !el.offsetHeight) return;
            var cur = el, ariaHidden = false;
            while (cur && cur.nodeType === 1) {
                if (cur.getAttribute('aria-hidden') === 'true') { ariaHidden = true; break; }
                cur = cur.parentElement;
            }
            if (ariaHidden) return;
            var fg = _parseRgb(style.color);
            if (!fg) return;
            var reason = _whyIndeterminate(el, doc);
            if (!reason) return;
            results.push({
                html:     el.outerHTML || '',
                selector: _cssPath(el, doc),
                landmark: _nearestLandmark(el, doc),
                template: _findTemplate(el, doc),
                fg:       _rgbToHex(fg.r, fg.g, fg.b),
                reason:   reason,
            });
        });
        return results;
    }

    var _contrastStored = {}; /* per-viewport: run once per page load each */
    var _contrastNeedsReview = null; /* elements where bg colour is indeterminate */

    function contrastSessionKey() {
        return 'accessibility-audit-contrast-stored-' + CFG.scanId + '-' + activeViewport;
    }

    /* Inject the needs-review section into the contrast expand panel if it is currently open.
       Called both after the POST completes and after the sessionStorage early-return path. */
    function _injectNeedsReviewIfOpen() {
        if (!_contrastNeedsReview || !_contrastNeedsReview.length) return;
        var activeRow = document.querySelector(
            '.accessibility-audit-pr-issue-row--active[data-rule-id="color-contrast"],' +
            '.accessibility-audit-pr-issue-row--active[data-rule-id="color-contrast-enhanced"]'
        );
        if (!activeRow) return;
        var item = activeRow.closest('.accessibility-audit-pr-issue-item');
        var expandBody = item && item.querySelector('.accessibility-audit-pr-issue-expand-body');
        if (!expandBody || expandBody.querySelector('.accessibility-audit-pr-needs-review')) return;
        var issueObj = {};
        try { issueObj = JSON.parse(activeRow.dataset.issue || '{}'); } catch (_) {}
        renderExpandPanel(expandBody, issueObj, _currentOccurrences || []);
    }

    /* Resolves once every stylesheet link has applied (a link's `sheet` is
       null until fetched and parsed). The iframe's load event only covers
       stylesheets present in the HTML; one injected by the page's own JS
       lands after load, and sampling before it applies reads UA defaults.
       Capped at ~3s so a broken stylesheet reference cannot stall the pass. */
    function stylesheetsSettled(doc) {
        return new Promise(function (resolve) {
            var tries = 0;
            (function check() {
                var pending = false;
                try {
                    var links = doc.querySelectorAll('link[rel="stylesheet"]');
                    for (var i = 0; i < links.length; i++) {
                        if (!links[i].disabled && !links[i].sheet) { pending = true; break; }
                    }
                } catch (_) {}
                if (!pending || tries++ >= 10) { resolve(); return; }
                setTimeout(check, 300);
            })();
        });
    }

    async function autoStoreContrastResults() {
        /* Snapshot the viewport up front: the layout being measured belongs to
           it, and a mid-await switch must not mislabel the results. */
        var viewport = activeViewport;
        if (_contrastStored[viewport]) return;
        var doc = iframeDoc();
        if (!doc) return; /* cross-origin: skip silently */

        await stylesheetsSettled(doc);

        /* Always collect needs-review items: needed for the expand panel regardless of
           whether we need to POST violations to the server. */
        _contrastNeedsReview = collectContrastNeedsReview(doc);

        /* Skip the server POST if we already stored violations in this browser session */
        if (sessionStorage.getItem(contrastSessionKey())) {
            _injectNeedsReviewIfOpen();
            return;
        }

        var occurrences = collectContrastOccurrences(doc);
        if (!occurrences) return;
        _contrastStored[viewport] = true;

        var cfg      = window.AccessibilityAudit || {};
        var storeUrl = cfg.storeContrastUrl || '';
        var scanId   = CFG.scanId;
        var elementId = CFG.elementId;
        var siteId    = CFG.siteId;

        /* Need at least a scanId OR an elementId so the server can find/create a scan */
        if (!storeUrl || (scanId === 0 && elementId === 0)) return;

        var csrfName  = (window.Craft && Craft.csrfTokenName)  || 'CRAFT_CSRF_TOKEN';
        var csrfValue = (window.Craft && Craft.csrfTokenValue) || '';

        try {
            var fd = new FormData();
            fd.append(csrfName, csrfValue);
            fd.append('scanId',      String(scanId));
            fd.append('elementId',   String(elementId));
            fd.append('elementType', CFG.elementType);
            fd.append('siteId',      String(siteId));
            fd.append('viewport',    viewport);
            /* Send occurrences as JSON string: FormData can't nest arrays natively */
            fd.append('occurrences', JSON.stringify(occurrences));

            var res  = await fetch(storeUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
            var data = await res.json();

            if (data.success) {
                /* Always mark as done for this scan + viewport: prevents repeated checks on passes */
                sessionStorage.setItem('accessibility-audit-contrast-stored-' + CFG.scanId + '-' + viewport, String(data.stored || 0));
                if (data.stored > 0) {
                    /* Reload so the Twig-rendered sidebar reflects the new issues */
                    window.location.reload();
                } else {
                    /* No new violations stored: inject needs-review into any open panel */
                    _injectNeedsReviewIfOpen();
                }
            }
        } catch (err) {
            console.error('[a11y] contrast store failed:', err);
        }
    }

    /* ── Auto-run the full axe pass in the preview iframe ─────────────────
       The preview is a real same-origin browser render, so both engines run
       from a Re-scan: the PHP scan reloads this page, then the fresh iframe
       gets a full axe pass right here, stored through the same endpoint (and
       cross-engine dedup) as the overlay and headless scanners. Keyed per
       scan + viewport in sessionStorage so each scan gets exactly one pass
       per viewport bucket. */
    var _axeRunning = false;

    function axeSessionKey(viewport) {
        return 'a11y_axe_stored_' + CFG.scanId + '_' + viewport;
    }

    async function autoRunAxeInIframe() {
        /* Snapshot the viewport up front: the layout axe measures belongs to
           it, and a mid-await switch must not mislabel the results. */
        var viewport = activeViewport;
        var doc = iframeDoc();
        if (!doc || _axeRunning) return;
        if (sessionStorage.getItem(axeSessionKey(viewport))) return;

        var scanId    = CFG.scanId;
        var elementId = CFG.elementId;
        if (scanId === 0 && elementId === 0) return;
        _axeRunning = true;

        try {
            var win = iframe.contentWindow;

            /* Inject the bundled axe-core into the iframe (same-origin) */
            if (!win.axe) {
                await new Promise(function (resolve, reject) {
                    var s = doc.createElement('script');
                    s.src = CFG.axeUrl;
                    s.onload = resolve;
                    s.onerror = reject;
                    doc.head.appendChild(s);
                });
            }

            /* Same settle delay as the overlay and headless engines: themes
               that build headings or shuffle landmarks with JS need a beat
               before the DOM is representative, and sampling earlier than the
               other engines is exactly what makes results flip between them. */
            await new Promise(function (resolve) { setTimeout(resolve, 2000); });

            /* axe computes styles too, so it needs the same stylesheet guard. */
            await stylesheetsSettled(doc);

            var sharedCfg = window.AccessibilityAudit || {};
            /* Same exclusions as the headless pass and the overlay, in axe's
               context shape, so a consent banner can't fail one engine and
               pass another. An empty exclude leaves the context as the whole
               iframe document. */
            var axeContext = { exclude: sharedCfg.axeExclude || [] };
            var results = await win.axe.run(axeContext, {
                runOnly: { type: 'tag', values: sharedCfg.axeTags || ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] },
                resultTypes: ['violations', 'incomplete'],
            });

            /* Slim to the payload shape storeAxeIssues() consumes; the caps
               come from HeadlessScanner's constants via window.AccessibilityAudit so
               the two passes can't drift apart. */
            var maxNodes = sharedCfg.axeMaxNodes || 50;
            var maxHtml = sharedCfg.axeMaxHtmlLength || 300;
            var slim = function (v) {
                return {
                    id: v.id,
                    impact: v.impact,
                    tags: v.tags,
                    description: v.description,
                    help: v.help,
                    helpUrl: v.helpUrl,
                    nodes: v.nodes.slice(0, maxNodes).map(function (n) {
                        return {
                            html: (n.html || '').slice(0, maxHtml),
                            target: n.target,
                            any: (n.any && n.any[0]) ? [{ data: n.any[0].data }] : [],
                        };
                    }),
                };
            };

            var violations = results.violations.map(slim);
            /* Contrast is the only incomplete rule stored, as a needs-review
               item: axe returns "can't tell" when it cannot resolve what sits
               behind the text, and a person can settle that by looking. */
            var incomplete = (results.incomplete || []).filter(function (v) {
                return v.id === 'color-contrast';
            }).map(slim);

            var csrfName  = (window.Craft && Craft.csrfTokenName)  || 'CRAFT_CSRF_TOKEN';
            var csrfValue = (window.Craft && Craft.csrfTokenValue) || '';
            var fd = new FormData();
            fd.append(csrfName, csrfValue);
            fd.append('scanId',      String(scanId));
            fd.append('elementId',   String(elementId));
            fd.append('elementType', CFG.elementType);
            fd.append('siteId',      String(CFG.siteId));
            fd.append('viewport',    viewport);
            fd.append('violations',  JSON.stringify(violations));
            fd.append('incomplete',  JSON.stringify(incomplete));

            var res  = await fetch(CFG.storeAxeUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
            var data = await res.json();

            if (data.success) {
                sessionStorage.setItem(axeSessionKey(viewport), '1');
                /* Reload only when the stored scan actually changed, so the
                   Twig-rendered sidebar and score reflect the browser pass. */
                var current = CFG.current;
                var next = data.scan
                    ? [data.scan.score, data.scan.errorCount, data.scan.warningCount, data.scan.noticeCount]
                    : null;
                if (next && JSON.stringify(next) !== JSON.stringify(current)) {
                    window.location.reload();
                }
            }
        } catch (err) {
            console.error('[a11y] iframe axe pass failed:', err);
        } finally {
            _axeRunning = false;
            /* A viewport switch during this pass was swallowed by the
               _axeRunning guard: pick it up now so the new bucket still runs. */
            if (activeViewport !== viewport) {
                autoRunAxeInIframe();
            }
        }
    }

    /* ── Fixed logical viewports for the preview ──────────────────────────
       axe findings are viewport-sensitive (landmarks, heading visibility,
       target sizes all change across breakpoints), so the preview renders at
       a fixed logical width per viewport bucket (the same widths the
       server-side headless scanner uses), scaled down to fit the pane.
       Browser passes from the Inspect page and the queue then agree instead
       of flip-flopping, and each pass stores into its own viewport bucket;
       only the overlay reflects the admin's actual window, which is its job. */
    var previewPane = document.getElementById('accessibility-audit-pane-content');
    var VIEWPORT_WIDTHS = { desktop: 1280, mobile: 375 };
    var _viewportSessionKey = 'a11y_pr_viewport_' + CFG.elementId + '_' + CFG.siteId;
    var activeViewport = (function () {
        /* Survives the post-store reload, so a switch to Mobile isn't undone
           when the sidebar refreshes with the recalculated scan. */
        try {
            var v = sessionStorage.getItem(_viewportSessionKey);
            return VIEWPORT_WIDTHS[v] ? v : 'desktop';
        } catch (_) { return 'desktop'; }
    })();

    function fitPreviewScale() {
        if (!iframe || !previewPane) return;
        var w = previewPane.clientWidth;
        var h = previewPane.clientHeight;
        if (w <= 0 || h <= 0) return;

        var logicalWidth = VIEWPORT_WIDTHS[activeViewport] || VIEWPORT_WIDTHS.desktop;

        if (activeViewport === 'desktop' && w >= logicalWidth) {
            /* Pane is already desktop-wide: render natively */
            iframe.style.width = '';
            iframe.style.height = '';
            iframe.style.flex = '';
            iframe.style.margin = '';
            iframe.style.transform = '';
            return;
        }

        if (w >= logicalWidth) {
            /* Mobile in a wide pane: render at the exact logical width,
               centred, unscaled: a phone-sized page inside a desktop pane. */
            iframe.style.flex = '0 0 auto';
            iframe.style.width = logicalWidth + 'px';
            iframe.style.height = h + 'px';
            iframe.style.margin = '0 auto';
            iframe.style.transform = '';
            return;
        }

        var scale = w / logicalWidth;
        iframe.style.flex = '0 0 auto';
        iframe.style.width = logicalWidth + 'px';
        iframe.style.height = (h / scale) + 'px';
        iframe.style.margin = '';
        iframe.style.transformOrigin = '0 0';
        iframe.style.transform = 'scale(' + scale + ')';
    }

    function paintViewportButtons() {
        document.querySelectorAll('[data-pr-viewport]').forEach(function (btn) {
            var active = btn.dataset.prViewport === activeViewport;
            btn.classList.toggle('accessibility-audit-pr-tab--active', active);
            btn.setAttribute('aria-pressed', String(active));
        });
    }

    function setViewport(viewport) {
        if (!VIEWPORT_WIDTHS[viewport] || viewport === activeViewport) return;
        activeViewport = viewport;
        try { sessionStorage.setItem(_viewportSessionKey, viewport); } catch (_) {}
        paintViewportButtons();
        if (paneContent && paneContent.hidden) switchToView('content');
        fitPreviewScale();
        /* The iframe reflows to the new width in place: run this viewport's
           browser passes against the fresh layout. Each pass is session-keyed
           per scan + viewport, so already-stored buckets don't re-run. */
        autoStoreContrastResults();
        autoRunAxeInIframe();
    }

    document.querySelectorAll('[data-pr-viewport]').forEach(function (btn) {
        btn.addEventListener('click', function () { setViewport(btn.dataset.prViewport); });
    });

    paintViewportButtons();
    fitPreviewScale();
    if (previewPane && typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(fitPreviewScale).observe(previewPane);
    } else {
        window.addEventListener('resize', fitPreviewScale);
    }

    /* Re-apply filter + highlights after iframe navigation, and auto-run contrast */
    if (iframe) {
        iframe.addEventListener('load', function () {
            /* Hide the loading overlay once the embedded page has actually finished
               loading, so users never see it mid-render (e.g. before its own web
               fonts/layout have settled) and mistake that for a plugin bug. */
            if (previewLoading) previewLoading.hidden = true;

            if (currentFilter !== 'none') applyColourFilter(currentFilter);
            if (activeRuleId) {
                var selector = selectorFor(activeRuleId);
                highlightInIframe(activeRuleId, selector);
            }
            /* Auto-store contrast results on first load */
            autoStoreContrastResults();
            /* And the full axe pass, once per scan */
            autoRunAxeInIframe();
        });
    }

    /* ── Accessibility Explorer ─────────────────────────────────────── */
    function openExplorer() {
        if (!explorerPanel || !explorerPanel.hidden) return;
        explorerPanel.hidden = false;
        if (explorerTrigger) explorerTrigger.setAttribute('aria-expanded', 'true');
        explorerTrigger && explorerTrigger.classList.add('accessibility-audit-pr-explorer-btn--open');

        /* Escape goes through Craft's UI layer stack, so an open slideout or
           HUD above this panel wins the keypress instead of both closing. */
        if (window.Garnish && Garnish.uiLayerManager) {
            Garnish.uiLayerManager.addLayer(explorerPanel);
            Garnish.uiLayerManager.registerShortcut(Garnish.ESC_KEY, closeExplorer);
        }

        /* Move focus into the panel (mirrors the frontend overlay's showPanel) */
        var focusTarget = explorerPanel.querySelector('[data-colour-filter]') || explorerClose;
        if (focusTarget) focusTarget.focus();
    }

    function closeExplorer() {
        if (!explorerPanel || explorerPanel.hidden) return;
        explorerPanel.hidden = true;
        if (explorerTrigger) explorerTrigger.setAttribute('aria-expanded', 'false');
        explorerTrigger && explorerTrigger.classList.remove('accessibility-audit-pr-explorer-btn--open');

        if (window.Garnish && Garnish.uiLayerManager) {
            Garnish.uiLayerManager.removeLayer();
        }

        /* Return focus to the trigger so keyboard users keep their place */
        if (explorerTrigger) explorerTrigger.focus();
    }

    if (explorerTrigger) {
        explorerTrigger.addEventListener('click', function () {
            explorerPanel.hidden ? openExplorer() : closeExplorer();
        });
    }
    if (explorerClose) {
        explorerClose.addEventListener('click', closeExplorer);
    }
    if (!(window.Garnish && Garnish.uiLayerManager)) {
        /* Fallback only when Garnish is unavailable */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && explorerPanel && !explorerPanel.hidden) closeExplorer();
        });
    }

    /* ── Colour filters ─────────────────────────────────────────────── */
    document.querySelectorAll('[data-colour-filter]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-colour-filter]').forEach(function (b) {
                b.classList.remove('accessibility-audit-filter-row--active');
                b.setAttribute('aria-pressed', 'false');
            });
            btn.classList.add('accessibility-audit-filter-row--active');
            btn.setAttribute('aria-pressed', 'true');
            applyColourFilter(btn.dataset.colourFilter);

            /* Reflect active filter on the trigger button */
            var isFiltered = btn.dataset.colourFilter !== 'none';
            explorerTrigger && explorerTrigger.classList.toggle('accessibility-audit-pr-explorer-btn--filtering', isFiltered);
        });
    });

    /* ── Sidebar accordions ─────────────────────────────────────────── */
    document.querySelectorAll('[data-accessibility-audit-pr-accordion]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var body     = btn.nextElementSibling;
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', String(!expanded));
            btn.classList.toggle('accessibility-audit-pr-acc-open', !expanded);
            if (body) body.hidden = expanded;
        });
    });

    /* ── Resizable sidebar ──────────────────────────────────────────────── */
    (function () {
        var handle  = document.getElementById('accessibility-audit-pr-resize-handle');
        var sidebar = document.querySelector('.accessibility-audit-pr-sidebar');
        if (!handle || !sidebar) return;

        var MIN_WIDTH = 200;
        var MAX_WIDTH = 560;
        var KEY_STEP  = 16;

        function setSidebarWidth(w) {
            w = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, w));
            sidebar.style.width = w + 'px';
            handle.setAttribute('aria-valuenow', String(w));
            return w;
        }

        var dragging = false;
        var startX, startWidth;

        handle.addEventListener('mousedown', function (e) {
            dragging   = true;
            startX     = e.clientX;
            startWidth = sidebar.offsetWidth;
            handle.classList.add('accessibility-audit-pr-resize-handle--active');
            document.body.style.cursor     = 'col-resize';
            document.body.style.userSelect = 'none';
            /* Prevent iframe from swallowing pointer events during drag */
            if (iframe) iframe.style.pointerEvents = 'none';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            setSidebarWidth(startWidth + (e.clientX - startX));
        });

        document.addEventListener('mouseup', function () {
            if (!dragging) return;
            dragging = false;
            handle.classList.remove('accessibility-audit-pr-resize-handle--active');
            document.body.style.cursor     = '';
            document.body.style.userSelect = '';
            if (iframe) iframe.style.pointerEvents = '';
        });

        /* Keyboard resizing: the handle is a focusable separator, so arrow
           keys must do what the drag does (WCAG 2.1.1). */
        handle.addEventListener('keydown', function (e) {
            var w = sidebar.offsetWidth;
            switch (e.key) {
                case 'ArrowLeft':  w -= KEY_STEP; break;
                case 'ArrowRight': w += KEY_STEP; break;
                case 'Home':       w = MIN_WIDTH; break;
                case 'End':        w = MAX_WIDTH; break;
                default: return;
            }
            e.preventDefault();
            setSidebarWidth(w);
        });

        /* Reflect the real starting width (the CSS default) in aria-valuenow */
        handle.setAttribute('aria-valuenow', String(sidebar.offsetWidth));
    })();

    /* ── Potential issue review ──────────────────────────────────────────────
       Records the author's answer to a potential-issue question. The verdict is
       stored against the element, the rule and this occurrence's markup, so it
       survives the next scan instead of the question coming straight back. */
    (function reviewActions() {
        var wrap = document.getElementById('accessibility-audit-pr-acc-potential');
        if (!wrap) { return; }

        var endpoint = (window.AccessibilityAudit || {}).setVerdictUrl;
        if (!endpoint) { return; }

        function csrf() {
            var name = (window.Craft && Craft.csrfTokenName) || window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
            var value = (window.Craft && Craft.csrfTokenValue) || window.csrfTokenValue || '';
            return { name: name, value: value };
        }

        /* Points the preview at the elements the question concerns, since the
           sidebar can't show them. A real button keeps it keyboard-reachable;
           clicking the card is just a mouse shortcut and must not fire when a
           verdict button was the target. */
        wrap.addEventListener('click', function (e) {
            var card = e.target.closest('[data-accessibility-audit-review]');
            if (!card) { return; }

            var viaButton = e.target.closest('[data-highlight]');
            if (!viaButton && e.target.closest('button')) { return; }

            highlightPotential(card.dataset.ruleId, card.dataset.context || '');
        });

        wrap.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-verdict]');
            if (!btn) { return; }

            var row = btn.closest('[data-accessibility-audit-review]');
            if (!row) { return; }

            var status = row.querySelector('.accessibility-audit-pr-review__status');
            var buttons = row.querySelectorAll('button[data-verdict]');
            buttons.forEach(function (b) { b.disabled = true; });
            if (status) { status.textContent = Craft.t('accessibility-audit', 'Saving…'); }

            var token = csrf();
            var body = new FormData();
            body.append(token.name, token.value);
            body.append('elementId', CFG.elementId);
            body.append('siteId', CFG.siteId);
            body.append('ruleId', row.dataset.ruleId || '');
            body.append('context', row.dataset.context || '');
            body.append('verdict', btn.dataset.verdict || '');

            fetch(endpoint, {
                method: 'POST',
                body: body,
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        throw new Error((data && data.error) || 'failed');
                    }
                    // Answered, so it leaves the queue. The score changed if it
                    // was confirmed, and the surrounding counts are rendered
                    // server-side, so reload rather than patch them by hand.
                    row.remove();
                    window.location.reload();
                })
                .catch(function () {
                    buttons.forEach(function (b) { b.disabled = false; });
                    if (status) { status.textContent = ''; }
                    if (window.Craft && Craft.cp) {
                        Craft.cp.displayError(Craft.t('accessibility-audit', 'Could not save that. Try again.'));
                    }
                });
        });
    })();

})();
