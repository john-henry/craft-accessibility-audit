(function () {
    'use strict';

    /* Server-injected config: see DashboardController::actionVpat(). */
    var CFG = (window.AccessibilityAudit || {}).vpat || {};

    const csrfName  = (window.Craft && Craft.csrfTokenName)  || 'CRAFT_CSRF_TOKEN';
    const csrfValue = (window.Craft && Craft.csrfTokenValue) || '';
    const siteId    = CFG.siteId;

    async function post(url, data) {
        const fd = new FormData();
        fd.append(csrfName, csrfValue);
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        const res = await fetch(url, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        return res.json();
    }

    function setStatus(el, msg, isError) {
        el.textContent = msg;
        el.style.color = isError ? '#9b1c1c' : '#1b6b35';
        if (!isError) {
            setTimeout(() => { el.textContent = ''; }, 2500);
        }
    }

    // ── Product info save ───────────────────────────────────────────────────

    document.getElementById('vpat-save-meta').addEventListener('click', async () => {
        const status = document.getElementById('vpat-meta-status');
        const fields = ['productName','productDescription','productVersion',
                        'contactName','contactEmail','contactPhone',
                        'evalMethodology','notes','legalDisclaimer','scopePages'];
        const data = { siteId };
        fields.forEach(f => {
            const el = document.getElementById('vpat-' + f);
            if (el) data[f] = el.value;
        });

        // Date fields render as Craft dateFields (localised text input plus
        // hidden locale/timezone), so post their real bracketed inputs verbatim;
        // the controller parses that payload back to a flat Y-m-d string.
        ['reportDate','reportPeriodFrom','reportPeriodTo'].forEach(f => {
            document.querySelectorAll('[name^="' + f + '["]').forEach(input => {
                data[input.name] = input.value;
            });
        });

        // Evaluation methods: ticked boxes plus comma-separated custom
        // entries, posted one per line (the controller splits on newlines).
        const methods = Array.from(
            document.querySelectorAll('#vpat-evalMethods input[type="checkbox"]:checked')
        ).map(cb => cb.value);
        (document.getElementById('vpat-evalMethodsCustom')?.value || '')
            .split(',')
            .map(m => m.trim())
            .filter(m => m !== '')
            .forEach(m => methods.push(m));
        data.evalMethods = methods.join('\n');

        try {
            const result = await post(CFG.saveMetaUrl, data);
            if (result.success) {
                setStatus(status, 'Saved ✓', false);
                if (window.Craft && Craft.cp) {
                    (Craft.cp.displaySuccess || Craft.cp.displayNotice).call(Craft.cp, CFG.savedMessage);
                }
            } else {
                // result.errors is a Yii model-errors object keyed by field name;
                // flatten it into one readable message since this is an AJAX
                // save with no page re-render to surface it otherwise.
                const messages = result.errors
                    ? Object.values(result.errors).flat()
                    : (result.error ? [result.error] : ['Error saving']);
                setStatus(status, messages.join(' '), true);
            }
        } catch (e) {
            setStatus(status, 'Network error', true);
        }
    });

    // ── Collapsible cards ───────────────────────────────────────────────────
    // Any card carrying data-vpat-collapsible is foldable: the product-info
    // card (one-time setup, folds out of the way) and each criteria level. The
    // chosen state persists per card across reloads within the session.
    document.querySelectorAll('[data-vpat-collapsible]').forEach((card) => {
        const toggle = card.querySelector('.accessibility-audit-vpat-card__toggle');
        const body   = card.querySelector('.accessibility-audit-vpat-card__body');
        if (!toggle || !body) return;

        // Only the product-info card has a summary line to show when folded.
        const summary  = card.querySelector('.accessibility-audit-vpat-meta-summary');
        const stateKey = 'a11y_vpat_' + card.dataset.vpatCollapsible + '_collapsed_' + siteId;

        function refreshSummary() {
            if (!summary) return;
            const name = (document.getElementById('vpat-productName')?.value || '').trim();
            const ver  = (document.getElementById('vpat-productVersion')?.value || '').trim();
            summary.textContent = name
                ? (ver ? name + ' · v' + ver : name)
                : 'No product details yet';
        }

        function setCollapsed(collapsed) {
            card.classList.toggle('is-collapsed', collapsed);
            toggle.setAttribute('aria-expanded', String(!collapsed));
            body.hidden = collapsed;
            if (collapsed) refreshSummary();
            try { sessionStorage.setItem(stateKey, collapsed ? '1' : '0'); } catch (e) {}
        }

        // A stored choice wins over the server default so state survives reloads.
        try {
            const stored = sessionStorage.getItem(stateKey);
            if (stored !== null) {
                setCollapsed(stored === '1');
            } else if (card.classList.contains('is-collapsed')) {
                refreshSummary();
            }
        } catch (e) {
            if (card.classList.contains('is-collapsed')) refreshSummary();
        }

        toggle.addEventListener('click', () => {
            setCollapsed(!card.classList.contains('is-collapsed'));
        });
    });

    // ── Scope: fill from scanned pages ──────────────────────────────────────
    // Appends pages the scanner has covered, keeping whatever the admin has
    // already typed and skipping duplicates. Nothing is saved until the
    // "Save information" button is used, same as every other meta field.

    const scopeFillBtn = document.getElementById('vpat-scope-fill');
    if (scopeFillBtn) {
        const scopeSuggestions = CFG.scopeSuggestions;
        scopeFillBtn.addEventListener('click', () => {
            const textarea = document.getElementById('vpat-scopePages');
            const existing = textarea.value.split('\n').map(l => l.trim()).filter(l => l !== '');
            const merged = existing.concat(scopeSuggestions.filter(s => !existing.includes(s)));
            textarea.value = merged.join('\n');
        });
    }

    // ── Per-criterion auto-save (debounced) ─────────────────────────────────

    const timers = {};

    // Recounts one table's completed rows and redraws its heading count,
    // progress bar, and remark nudges. A row counts as completed when a level
    // is selected, or when the dropdown is empty but the scan suggestion
    // still applies (clearing an override falls back to it server-side).
    const REMARK_REQUIRED_LEVELS = ['Not Applicable', 'Partially Supports', 'Does Not Support'];

    function updateTableProgress(card) {
        const rows = card.querySelectorAll('.accessibility-audit-vpat-row');
        let completed = 0;
        let needRemarks = 0;

        rows.forEach(row => {
            const level = row.querySelector('.accessibility-audit-vpat-select')?.value || row.dataset.autoLevel || '';
            if (level !== '') completed++;

            const remarks = (row.querySelector('.accessibility-audit-vpat-remarks')?.value || '').trim();
            const needs = REMARK_REQUIRED_LEVELS.includes(level) && remarks === '';
            row.querySelector('.accessibility-audit-vpat-remark-nudge')?.classList.toggle('hidden', !needs);
            if (needs) needRemarks++;
        });

        const countEl = card.querySelector('.accessibility-audit-vpat-completed-count');
        if (countEl) countEl.textContent = completed;

        const needsWrap = card.querySelector('.accessibility-audit-vpat-needs-remarks');
        if (needsWrap) {
            needsWrap.classList.toggle('hidden', needRemarks === 0);
            const needsCount = needsWrap.querySelector('.accessibility-audit-vpat-needs-remarks-count');
            if (needsCount) needsCount.textContent = needRemarks;
        }

        const fill = card.querySelector('.accessibility-audit-vpat-progress__fill');
        if (fill) {
            fill.style.width = rows.length ? Math.round((completed / rows.length) * 100) + '%' : '0%';
            fill.classList.toggle('accessibility-audit-vpat-progress__fill--complete', completed === rows.length);
        }
    }

    function saveCriterion(criterion, level, remarks, statusEl) {
        clearTimeout(timers[criterion]);
        timers[criterion] = setTimeout(async () => {
            try {
                const result = await post(CFG.saveCriterionUrl, { siteId, criterion, level, remarks });
                if (statusEl) setStatus(statusEl, result.success ? 'Saved ✓' : (result.error || 'Error'), !result.success);
                if (result.success) {
                    const card = statusEl?.closest('.accessibility-audit-vpat-card');
                    if (card) updateTableProgress(card);
                }
            } catch (e) {
                if (statusEl) setStatus(statusEl, 'Network error', true);
            }
        }, 600);
    }

    document.querySelectorAll('.accessibility-audit-vpat-select').forEach(select => {
        select.addEventListener('change', () => {
            const criterion = select.dataset.criterion;
            const row       = select.closest('.accessibility-audit-vpat-row');
            const remarks   = row.querySelector('.accessibility-audit-vpat-remarks')?.value || '';
            const statusEl  = row.querySelector('.accessibility-audit-vpat-row-status');
            saveCriterion(criterion, select.value, remarks, statusEl);
        });
    });

    document.querySelectorAll('.accessibility-audit-vpat-remarks').forEach(textarea => {
        textarea.addEventListener('input', () => {
            const criterion = textarea.dataset.criterion;
            const row       = textarea.closest('.accessibility-audit-vpat-row');
            const level     = row.querySelector('.accessibility-audit-vpat-select')?.value || '';
            const statusEl  = row.querySelector('.accessibility-audit-vpat-row-status');
            saveCriterion(criterion, level, textarea.value, statusEl);
        });
    });

    // ── AI remark drafting ────────────────────────────────────────────────────
    // Rows with scanner findings draft from the evidence; manual rows tidy the
    // author's rough notes into VPAT wording. The draft lands in the textarea
    // without saving; it saves once on blur, and "Restore your notes" brings
    // back the original text since the draft can replace hand-typed notes.

    document.querySelectorAll('.accessibility-audit-vpat-draft-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const criterion = btn.dataset.criterion;
            const row       = btn.closest('.accessibility-audit-vpat-row');
            const textarea  = row.querySelector('.accessibility-audit-vpat-remarks');
            const level     = row.querySelector('.accessibility-audit-vpat-select')?.value || '';
            const statusEl  = row.querySelector('.accessibility-audit-vpat-row-status');
            const notes     = textarea.value.trim();

            btn.disabled    = true;
            btn.textContent = Craft.t('accessibility-audit', 'Drafting…');

            try {
                const result = await post(CFG.draftRemarkUrl, { siteId, criterion, level, notes });

                if (result.success && result.remark) {
                    textarea.value = result.remark;
                    // Grow the box to fit the draft so it never arrives
                    // half-hidden behind a scrollbar.
                    textarea.style.height = 'auto';
                    textarea.style.height = (textarea.scrollHeight + 2) + 'px';
                    textarea.focus();

                    statusEl.textContent = Craft.t('accessibility-audit', 'AI draft: review and edit before moving on. ');
                    statusEl.style.color = '#7a5200';
                    if (notes !== '') {
                        const undo = document.createElement('a');
                        undo.href = '#';
                        undo.textContent = Craft.t('accessibility-audit', 'Restore your notes');
                        undo.addEventListener('click', (e) => {
                            e.preventDefault();
                            textarea.value = notes;
                            textarea.focus();
                            saveCriterion(criterion, level, notes, statusEl);
                        });
                        statusEl.appendChild(undo);
                    }

                    textarea.addEventListener('blur', () => {
                        saveCriterion(criterion, level, textarea.value, statusEl);
                    }, { once: true });
                } else if (result.hint) {
                    // Guidance ("add notes first"), not a failure: amber nudge
                    // rather than an error, and it points at the box it means.
                    statusEl.textContent = result.error;
                    statusEl.style.color = '#7a5200';
                    textarea.focus();
                } else {
                    setStatus(statusEl, result.error || 'Drafting failed', true);
                }
            } catch (e) {
                setStatus(statusEl, 'Network error', true);
            } finally {
                btn.disabled    = false;
                btn.textContent = Craft.t('accessibility-audit', 'Draft with AI');
            }
        });
    });

    /* Each level's card heading is sticky so the level and its progress stay in
       view down a long criteria table. Measures Craft's page header height at
       runtime rather than guessing an offset, since --header-height belongs to
       a different element. */
    const cpHeader = document.getElementById('header');

    if (cpHeader) {
        const syncHeaderHeight = () => {
            document.documentElement.style.setProperty(
                '--aa-cp-header-height',
                cpHeader.offsetHeight + 'px'
            );
        };

        syncHeaderHeight();

        if (window.ResizeObserver) {
            new ResizeObserver(syncHeaderHeight).observe(cpHeader);
        } else {
            window.addEventListener('resize', syncHeaderHeight);
        }
    }

    /* Marks a sticky level heading with .is-stuck while rows are passing
       beneath it, which is the only time its hairline divider is drawn. The
       observer clips the heading at the sticky offset: at rest the heading is
       fully visible (ratio 1), stuck it is partially above the line (ratio
       below 1). Without IntersectionObserver the heading simply never carries
       the divider, which is the quieter failure. */
    if (window.IntersectionObserver) {
        const stickyOffset = (cpHeader ? cpHeader.offsetHeight : 50) + 1;
        document.querySelectorAll('.accessibility-audit-vpat-card__heading--table').forEach((heading) => {
            new IntersectionObserver(
                ([entry]) => heading.classList.toggle('is-stuck', entry.intersectionRatio < 1),
                { threshold: [1], rootMargin: `-${stickyOffset}px 0px 0px 0px` },
            ).observe(heading);
        });
    }

})();
