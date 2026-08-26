/**
 * Accessibility Audit: Frontend axe-core overlay
 * Injected on the live site for logged-in admins only.
 */
(function () {
  'use strict';

  // ─── Suppress overlay when page is loaded inside the CP page-report iframe ──
  if (new URLSearchParams(window.location.search).get('accessibility-audit-preview') === '1') return;

  // ─── Config injected by PHP ───────────────────────────────────────────────
  const cfg         = window.__accessibilityAudit || {};
  const storeUrl    = cfg.storeUrl    || '/accessibility-audit/store-axe-results';
  const csrfName    = cfg.csrfName    || window.csrfTokenName  || 'CRAFT_CSRF_TOKEN';
  const csrfValue   = cfg.csrfValue   || window.csrfTokenValue || '';
  /* Decoupled front ends authenticate with a bearer token (set by the
     overlay loader) instead of the session's CSRF pair. */
  const token       = cfg.token       || '';
  // let: storing results can create the scan (ensureScan) and hand back its id.
  let scanId        = cfg.scanId      || 0;
  const elementId   = cfg.elementId   || 0;
  const elementType = cfg.elementType || '';
  const siteId      = cfg.siteId      || 0;
  /* Resolved server-side (AuditService::getAxeTags) so the overlay scans with
     exactly the same tags as the Inspect preview and the headless scanner. */
  const axeTags     = cfg.axeTags     || ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];
  /* Excluded page furniture (consent banners and the like), resolved
     server-side so all engines skip the same elements. axe context shape:
     one selector per entry, each wrapped in its own array. */
  const axeExclude  = cfg.axeExclude  || [];
  /* The same list joined for Element.closest(), for the custom contrast
     scanner which walks elements outside axe. */
  const excludeJoined = axeExclude.map((pair) => pair[0]).join(', ');
  const collapseWhenIdle = cfg.collapseWhenIdle === true;
  const position    = cfg.position    || 'bottom-right';
  const reportUrl   = cfg.reportUrl   || '';
  const storedScan  = cfg.storedScan  || null;
  const pageIssuesUrl = cfg.pageIssuesUrl || '';
  // Mirrors the admin's "Use shapes to represent statuses" CP preference: when
  // on, the overlay's colour-only severity dots also take a shape (frontend-axe.css).
  const useShapes   = cfg.useShapes === true;
  /* False inside Craft's preview pane. The preview renders a draft, which the
     rest of the plugin does not scan, so findings are shown but never posted. */
  const storeResults = cfg.storeResults !== false;

  // Auto-scan only when the page has a known element to attach results to,
  // or when there is nothing to attach to but the scan is still worth running.
  const canAutoScan = (scanId > 0 || elementId > 0 || !storeResults);

  // Module-level refs set during buildPanel
  let panelEl   = null;
  let triggerEl = null;

  // Latest render state, so tab switches and Highlight clicks don't rescan.
  // liveTargets maps server ruleIds ('axe:target-size', 'color-contrast') to
  // the CSS selectors from the last in-browser axe run, so Highlight keeps
  // working after the panel repaints from the server's combined results.
  const state = { issues: [], passes: [], tab: 'issues', liveTargets: {} };

  // Idle-collapse timer (delay configurable via the overlayIdleSeconds setting)
  let idleTimer = null;
  const IDLE_MS = cfg.idleMs || 30000;

  // SessionStorage cache: skip auto-scan if already run within 1 hour for this URL
  const CACHE_KEY = 'a11y_scanned_' + location.pathname;
  const CACHE_TTL = 60 * 60 * 1000;

  // Persist the last render so a refresh restores it instead of blanking the
  // panel. Stores the full snapshot (score, issues, passes, label), not just
  // a timestamp, so results survive a page reload.
  function saveSnapshot(snap) {
    /* Not in a preview: a draft's findings share the canonical pathname, so
       caching them would restore draft results onto the published page. The
       draft also changes between reloads, which a cached snapshot would hide. */
    if (!storeResults) return;
    try { sessionStorage.setItem(CACHE_KEY, JSON.stringify({ ts: Date.now(), snap: snap })); } catch (_) {}
  }

  function readSnapshot() {
    if (!storeResults) return null;
    try {
      const raw = sessionStorage.getItem(CACHE_KEY);
      if (!raw) return null;
      const obj = JSON.parse(raw);
      if (!obj || !obj.snap || (Date.now() - obj.ts) > CACHE_TTL) return null;
      return obj.snap;
    } catch (_) { return null; }
  }

  // ─── Idle collapse ────────────────────────────────────────────────────────
  function startIdleTimer() {
    if (!collapseWhenIdle) return;
    clearTimeout(idleTimer);
    idleTimer = setTimeout(() => {
      if (panelEl && !panelEl.classList.contains('accessibility-audit-hidden')) hidePanel();
    }, IDLE_MS);
  }

  function stopIdleTimer() { clearTimeout(idleTimer); }

  // ─── Panel visibility ─────────────────────────────────────────────────────
  function showPanel() {
    if (!panelEl) return;
    panelEl.classList.remove('accessibility-audit-hidden');
    if (triggerEl) {
      triggerEl.classList.add('accessibility-audit-hidden');
      triggerEl.setAttribute('aria-expanded', 'true');
    }
    /* Move focus into the dialog */
    const focusTarget = document.getElementById('accessibility-audit-overlay-scan') || document.getElementById('accessibility-audit-overlay-close');
    if (focusTarget) focusTarget.focus();
    startIdleTimer();
  }

  function hidePanel() {
    if (!panelEl) return;
    stopIdleTimer();
    panelEl.classList.add('accessibility-audit-hidden');
    if (triggerEl) {
      triggerEl.classList.remove('accessibility-audit-hidden');
      triggerEl.setAttribute('aria-expanded', 'false');
      /* Return focus to the trigger */
      triggerEl.focus();
    }
  }

  // ─── Load axe-core ───────────────────────────────────────────────────────
  // Loaded from the plugin's own published copy (cfg.axeSrc), same-origin, so
  // no external script-src is needed in the site's CSP. The URL is always
  // injected by the PHP that registers this bundle; the guard is just in case.
  function loadAxe(callback) {
    if (window.axe) { callback(); return; }
    const src = cfg.axeSrc;
    if (!src) {
      const body = document.getElementById('accessibility-audit-overlay-body');
      if (body) body.innerHTML = '<p class="accessibility-audit-error">axe-core could not be located.</p>';
      return;
    }
    const s = document.createElement('script');
    s.src = src;
    s.onload = callback;
    document.head.appendChild(s);
  }

  // ─── Build the floating panel ────────────────────────────────────────────
  function buildPanel() {
    const posClass = 'accessibility-audit-pos--' + position + (useShapes ? ' accessibility-audit-use-shapes' : '');

    panelEl = document.createElement('div');
    panelEl.id = 'accessibility-audit-overlay';
    panelEl.className = posClass;
    panelEl.setAttribute('role', 'dialog');
    panelEl.setAttribute('aria-labelledby', 'accessibility-audit-overlay-title');
    panelEl.setAttribute('aria-modal', 'false');
    panelEl.classList.add('accessibility-audit-hidden');
    panelEl.innerHTML = `
      <div id="accessibility-audit-overlay-header">
        <span class="accessibility-audit-hdr-badge">A11Y</span>
        <h2 id="accessibility-audit-overlay-title">Accessibility Audit</h2>
        <button id="accessibility-audit-overlay-scan" class="accessibility-audit-btn">Re-scan</button>
        <button id="accessibility-audit-overlay-close" aria-label="Close panel">✕</button>
      </div>
      <div id="accessibility-audit-overlay-summary">
        <div class="accessibility-audit-score-wrap">
          <span class="accessibility-audit-score__number">–</span><span class="accessibility-audit-score__label">/100 est.</span>
        </div>
        <div class="accessibility-audit-tabs" role="tablist" aria-label="Results">
          <button class="accessibility-audit-tab accessibility-audit-tab--active" role="tab" data-tab="issues" aria-selected="true">Issues · <span data-count-issues>0</span></button>
          <button class="accessibility-audit-tab" role="tab" data-tab="passed" aria-selected="false">Passed · <span data-count-passed>0</span></button>
        </div>
      </div>
      <div id="accessibility-audit-overlay-body" aria-live="polite" aria-atomic="false">
        <p class="accessibility-audit-hint">Click "Re-scan" to analyse this page for WCAG issues.</p>
      </div>
      <div id="accessibility-audit-overlay-footer">
        <span class="accessibility-audit-scanned" data-scanned>${storeResults ? '' : 'Preview: results are not saved'}</span>
        ${reportUrl ? `<a class="accessibility-audit-report-link" href="${esc(reportUrl)}" target="_blank" rel="noopener">Open full report ↗</a>` : ''}
      </div>`;
    document.body.appendChild(panelEl);

    triggerEl = document.createElement('button');
    triggerEl.id = 'accessibility-audit-overlay-trigger';
    triggerEl.className = posClass;
    triggerEl.setAttribute('aria-expanded', 'false');
    triggerEl.setAttribute('aria-controls', 'accessibility-audit-overlay');
    document.body.appendChild(triggerEl);
    updateBadge(0, 'good');

    document.getElementById('accessibility-audit-overlay-close').addEventListener('click', hidePanel);
    triggerEl.addEventListener('click', showPanel);
    document.getElementById('accessibility-audit-overlay-scan').addEventListener('click', () => {
      showPanel();
      runScan({ manual: true });
    });

    /* Tab switching */
    panelEl.querySelectorAll('.accessibility-audit-tab').forEach((tab) => {
      tab.addEventListener('click', () => switchTab(tab.dataset.tab));
    });

    /* Highlight an issue's element on the page (event-delegated) */
    document.getElementById('accessibility-audit-overlay-body').addEventListener('click', (e) => {
      const btn = e.target.closest('.accessibility-audit-highlight');
      if (!btn) return;
      const issue = state.issues[parseInt(btn.dataset.hl, 10)];
      if (issue) highlightElement(issue.targets);
    });

    /* Any interaction resets the idle-collapse countdown */
    ['pointermove', 'keydown', 'focusin'].forEach((evt) => {
      panelEl.addEventListener(evt, startIdleTimer);
    });

    /* Close on Escape */
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && panelEl && !panelEl.classList.contains('accessibility-audit-hidden')) {
        hidePanel();
      }
    });
  }

  // ─── Run the scan ────────────────────────────────────────────────────────
  function runScan(opts) {
    opts = opts || {};
    const btn  = document.getElementById('accessibility-audit-overlay-scan');
    const body = document.getElementById('accessibility-audit-overlay-body');

    if (btn) { btn.textContent = 'Scanning…'; btn.disabled = true; }
    if (!opts.silent && body) body.innerHTML = '<p class="accessibility-audit-hint">Running axe-core…</p>';

    loadAxe(() => {
      window.axe.run(
        { include: [[document.documentElement]], exclude: [['#accessibility-audit-overlay'], ['#accessibility-audit-overlay-trigger']].concat(axeExclude) },
        {
          runOnly: { type: 'tag', values: axeTags },
          /* violations + incomplete drive the Issues tab; passes drive the Passed tab */
          resultTypes: ['violations', 'incomplete', 'passes'],
        },
        (err, results) => {
          if (btn) { btn.disabled = false; btn.textContent = 'Re-scan'; }

          if (err) {
            if (body) body.innerHTML = `<p class="accessibility-audit-error">axe-core error: ${esc(err.message)}</p>`;
            return;
          }

          /* Run our own contrast scan to catch failures axe-core may miss */
          const contrastFailures = collectContrastFailures();
          renderResults(results, contrastFailures, opts);

          /* Once stored, the server recalculates the scan's score across ALL
             sources (PHP scanner + axe). Repaint with that authoritative
             combined state so the overlay never disagrees with the CP. */
          postResults(results.violations, results.incomplete).then((resp) => {
            if (resp && resp.scan) {
              if (resp.scanId) scanId = resp.scanId;
              hydrateFromServer(resp.scan, { keepPasses: true });
            }
          });
        }
      );
    });
  }

  // ─── Render results into the panel ───────────────────────────────────────
  function renderResults(results, contrastFailures, opts) {
    opts = opts || {};
    const v = results.violations;

    /* Incomplete colour-contrast items = axe couldn't auto-determine pass/fail
       (gradient/image backgrounds). These show as "needs review". */
    const incompleteContrast = (results.incomplete || []).filter(
      (r) => r.id === 'color-contrast' || r.id === 'color-contrast-enhanced'
    );

    /* Axe already covers some definite contrast failures: collect their HTML
       snippets so the custom results can be deduplicated. */
    const axeContrastHtmls = new Set();
    v.forEach((viol) => {
      if (viol.id === 'color-contrast' || viol.id === 'color-contrast-enhanced') {
        viol.nodes.forEach((n) => { if (n.html) axeContrastHtmls.add(n.html.substring(0, 100)); });
      }
    });
    const extraContrast = contrastFailures.filter(
      (f) => !axeContrastHtmls.has((f.html || '').substring(0, 100))
    );

    /* Remember each violation's selectors under its server-side ruleId
       (mirrors AuditService::_axeRuleId: color-contrast keeps its name, the
       rest are prefixed 'axe:'), for Highlight after a server repaint. */
    state.liveTargets = {};
    v.forEach((viol) => {
      const key = (viol.id === 'color-contrast' || viol.id === 'color-contrast-enhanced')
        ? viol.id : 'axe:' + viol.id;
      state.liveTargets[key] = viol.nodes.map((n) => (n.target && n.target[0]) || '').filter(Boolean);
    });

    const issues = buildIssues(v, extraContrast, incompleteContrast);
    const passes = (results.passes || []).map((p) => ({
      title: p.help || p.description,
      count: p.nodes.length,
    }));
    const score = computeScore(v, extraContrast);
    const scoreClass = AccessibilityAuditShared.scoreClass(score);

    const snap = { score: score, scoreClass: scoreClass, issues: issues, passes: passes, scanned: formatNow(), estimated: true };
    paint(snap);
    saveSnapshot(snap);

    /* Open/collapse behaviour */
    if (opts.manual) {
      /* Panel is already open from the Re-scan click; just reset idle timer. */
      startIdleTimer();
    } else if (!collapseWhenIdle && issues.length > 0) {
      showPanel();
    }
    /* When collapseWhenIdle is on, an auto-scan leaves the panel collapsed:
       the badge shows the count and the admin expands it on demand. */
  }

  function setScoreHeader(score, scoreClass, scannedLabel, estimated) {
    const numEl = panelEl.querySelector('.accessibility-audit-score__number');
    if (numEl) numEl.textContent = (score === null || score === undefined) ? '–' : score;
    const wrap = panelEl.querySelector('.accessibility-audit-score-wrap');
    if (wrap) wrap.className = 'accessibility-audit-score-wrap' + (scoreClass ? ' accessibility-audit-score--' + scoreClass : '');
    /* Stored scans carry an authoritative score; live scans are an estimate. */
    const label = panelEl.querySelector('.accessibility-audit-score__label');
    if (label) label.textContent = estimated ? '/100 est.' : '/100';
    const scanned = panelEl.querySelector('[data-scanned]');
    if (scanned) {
      scanned.textContent = storeResults
        ? (scannedLabel ? 'Scanned ' + scannedLabel : '')
        : 'Preview: results are not saved';
    }
  }

  /* Apply a results snapshot (fresh scan, restored sessionStorage, or the
     server's stored scan) to the panel UI and the collapsed badge. */
  function paint(snap) {
    state.issues = snap.issues || [];
    state.passes = snap.passes || [];

    setScoreHeader(snap.score, snap.scoreClass, snap.scanned, snap.estimated !== false);

    const ci = panelEl.querySelector('[data-count-issues]');
    const cp = panelEl.querySelector('[data-count-passed]');
    if (ci) ci.textContent = state.issues.length;
    if (cp) cp.textContent = state.passes.length;

    renderTab(state.tab);

    const worst = state.issues.some((i) => i.sev === 'error') ? 'error'
      : state.issues.some((i) => i.sev === 'warning') ? 'warning'
      : state.issues.length ? 'notice' : 'good';
    updateBadge(state.issues.length, worst);
  }

  function mapServerIssue(row) {
    const sev = row.severity || 'notice';
    const wcag = row.wcagCriterion
      ? ('WCAG ' + row.wcagCriterion + (row.wcagLevel ? ' ' + String(row.wcagLevel).toUpperCase() : ''))
      : 'best practice';
    return {
      sev: sev,
      sevLabel: sevLabel(sev),
      title: row.ruleId,
      wcag: wcag,
      count: parseInt(row.occurrences, 10) || 0,
      /* Stored issues carry no live-DOM selector, but rows matching the last
         in-browser axe run reuse its selectors so Highlight still works. */
      targets: state.liveTargets[row.ruleId] || [],
    };
  }

  /* Hydrate from a stored scan summary so the overlay mirrors the CP. Score
     and counts paint instantly; the issue rows load from the page-issues endpoint. */
  function hydrateFromServer(summary, opts) {
    opts = opts || {};
    const scoreClass = AccessibilityAuditShared.scoreClass(summary.score);
    setScoreHeader(summary.score, scoreClass, summary.scannedLabel, false);

    const body = document.getElementById('accessibility-audit-overlay-body');
    if (body && !opts.keepPasses) body.innerHTML = '<p class="accessibility-audit-hint">Loading stored results…</p>';

    const sep = pageIssuesUrl.includes('?') ? '&' : '?';
    const url = pageIssuesUrl + sep + 'scanId=' + encodeURIComponent(scanId) + '&siteId=' + encodeURIComponent(siteId);

    const hydrateHeaders = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (token) hydrateHeaders['Authorization'] = 'Bearer ' + token;

    fetch(url, {
      credentials: token ? 'omit' : 'same-origin',
      headers: hydrateHeaders,
    })
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error('HTTP ' + res.status))))
      .then((data) => {
        if (!data.success) throw new Error(data.error || 'Request failed');
        const issues = (data.issues || []).map(mapServerIssue);
        const snap = {
          score: summary.score,
          scoreClass: scoreClass,
          issues: issues,
          passes: opts.keepPasses ? state.passes : [],
          scanned: summary.scannedLabel,
          estimated: false,
        };
        paint(snap);
        saveSnapshot(snap);
      })
      .catch(() => {
        if (body && !opts.keepPasses) body.innerHTML = '<p class="accessibility-audit-hint">Couldn\'t load the stored scan. Click "Re-scan" to run a live check.</p>';
      });
  }

  function buildIssues(violations, extraContrast, incompleteContrast) {
    const list = [];

    violations.forEach((viol) => {
      const sev = impactToSeverity(viol.impact);
      list.push({
        sev: sev,
        sevLabel: sevLabel(sev),
        title: viol.help || viol.description,
        wcag: wcagString(viol.tags),
        count: viol.nodes.length,
        targets: viol.nodes.map((n) => (n.target && n.target[0]) || '').filter(Boolean),
      });
    });

    if (extraContrast.length > 0) {
      list.push({
        sev: 'error',
        sevLabel: 'Error',
        title: 'Colour contrast below the minimum ratio',
        wcag: 'WCAG 1.4.3 AA',
        count: extraContrast.length,
        targets: [],
      });
    }

    if (incompleteContrast.length > 0) {
      const count = incompleteContrast.reduce((s, r) => s + r.nodes.length, 0);
      const targets = [];
      incompleteContrast.forEach((r) => r.nodes.forEach((n) => {
        if (n.target && n.target[0]) targets.push(n.target[0]);
      }));
      list.push({
        sev: 'notice',
        sevLabel: 'Review',
        title: 'Colour contrast needs manual review',
        wcag: 'WCAG 1.4.3 AA',
        count: count,
        targets: targets,
      });
    }

    return list;
  }

  function switchTab(tab) {
    state.tab = tab;
    panelEl.querySelectorAll('.accessibility-audit-tab').forEach((t) => {
      const active = t.dataset.tab === tab;
      t.classList.toggle('accessibility-audit-tab--active', active);
      t.setAttribute('aria-selected', String(active));
    });
    renderTab(tab);
  }

  function renderTab(tab) {
    const body = document.getElementById('accessibility-audit-overlay-body');
    if (!body) return;
    body.innerHTML = tab === 'passed'
      ? renderPassesHtml(state.passes)
      : renderIssuesHtml(state.issues);
  }

  function renderIssuesHtml(issues) {
    if (!issues.length) {
      return '<p class="accessibility-audit-clean">✓ No issues found on this page.</p>';
    }
    return '<ul class="accessibility-audit-issues">' + issues.map((it, i) => `
      <li class="accessibility-audit-issue">
        <span class="accessibility-audit-issue__dot accessibility-audit-dot--${it.sev}"></span>
        <span class="accessibility-audit-issue__main">
          <span class="accessibility-audit-issue__title">${esc(it.title)}</span>
          <span class="accessibility-audit-issue__meta">${esc(it.sevLabel)} · ${esc(it.wcag)} · ${it.count} element${it.count !== 1 ? 's' : ''}</span>
        </span>
        ${it.targets.length ? `<button type="button" class="accessibility-audit-highlight" data-hl="${i}">Highlight</button>` : ''}
      </li>`).join('') + '</ul>';
  }

  function renderPassesHtml(passes) {
    if (!passes.length) {
      return '<p class="accessibility-audit-hint">No passing checks recorded for this page.</p>';
    }
    return '<ul class="accessibility-audit-issues accessibility-audit-passes">' + passes.map((p) => `
      <li class="accessibility-audit-issue accessibility-audit-pass">
        <span class="accessibility-audit-issue__dot accessibility-audit-dot--good">✓</span>
        <span class="accessibility-audit-issue__main">
          <span class="accessibility-audit-issue__title">${esc(p.title)}</span>
          <span class="accessibility-audit-issue__meta">${p.count} element${p.count !== 1 ? 's' : ''}</span>
        </span>
      </li>`).join('') + '</ul>';
  }

  // ─── Highlight an offending element on the page ──────────────────────────
  function highlightElement(targets) {
    document.querySelectorAll('.accessibility-audit-hl-flash').forEach((el) => el.classList.remove('accessibility-audit-hl-flash'));
    let first = null;
    let firstVisible = null;
    (targets || []).forEach((sel) => {
      try {
        const el = document.querySelector(sel);
        if (el && !el.closest('#accessibility-audit-overlay')) {
          el.classList.add('accessibility-audit-hl-flash');
          if (!first) first = el;
          /* Prefer scrolling to an element still on screen: one may have
             gone hidden since the scan (a menu that has closed). */
          if (!firstVisible && el.getClientRects().length) firstVisible = el;
        }
      } catch (_) { /* invalid selector, skip */ }
    });
    const scrollTarget = firstVisible || first;
    if (scrollTarget) scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
    // Everything matched is off screen: say so, or the click looks inert.
    if (first && !firstVisible) {
      hiddenTargetNotice();
    }
  }

  /* Transient corner notice for a highlight that landed only on hidden
     elements. Same visual language as the loader's activation notice. */
  function hiddenTargetNotice() {
    const existing = document.getElementById('accessibility-audit-hl-note');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.id = 'accessibility-audit-hl-note';
    el.setAttribute('role', 'status');
    el.style.cssText =
      'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);z-index:2147483647;' +
      'max-width:340px;padding:10px 14px;border-radius:8px;' +
      'font:13px/1.4 -apple-system,BlinkMacSystemFont,sans-serif;color:#fff;background:#1f2937;' +
      'box-shadow:0 4px 12px rgb(0 0 0 / 25%);';
    el.textContent = 'The highlighted element is inside a collapsed menu or panel. Open it to see the flash.';
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 6000);
  }

  // ─── Collapsed badge ─────────────────────────────────────────────────────
  function updateBadge(count, worstSev) {
    if (!triggerEl) return;
    triggerEl.innerHTML = '<span class="accessibility-audit-trigger-label">A11Y</span>' +
      (count > 0
        ? `<span class="accessibility-audit-trigger-dot accessibility-audit-dot--${worstSev}"></span><span class="accessibility-audit-trigger-count">${count}</span>`
        : '');
    triggerEl.setAttribute('aria-label', count > 0
      ? `Open Accessibility Audit panel, ${count} issue${count !== 1 ? 's' : ''}`
      : 'Open Accessibility Audit panel');
  }

  // ─── Store violations via AJAX ────────────────────────────────────────────
  /* Returns the store response ({success, scanId, scan}) so the caller can
     repaint with the server's recalculated combined score, or null on failure. */
  /* Contrast is the only incomplete rule worth storing: axe returns "can't
     tell" when it cannot resolve what sits behind the text, which a person can
     settle by looking. Other rules' incomplete results aren't answerable that
     way, so they're dropped here rather than filling the needs-review list.
     Slimmed to the same payload shape the headless pass posts. */
  function contrastIncomplete(incomplete) {
    const maxNodes = cfg.axeMaxNodes || 50;
    const maxHtml  = cfg.axeMaxHtmlLength || 300;

    return (incomplete || [])
      .filter((v) => v.id === 'color-contrast')
      .map((v) => ({
        id: v.id,
        impact: v.impact,
        tags: v.tags,
        description: v.description,
        help: v.help,
        helpUrl: v.helpUrl,
        nodes: v.nodes.slice(0, maxNodes).map((n) => ({
          html: (n.html || '').slice(0, maxHtml),
          target: n.target,
          any: (n.any && n.any[0]) ? [{ data: n.any[0].data }] : [],
        })),
      }));
  }

  async function postResults(violations, incomplete) {
    if (!storeResults || !canAutoScan) return null;
    try {
      const fd = new FormData();
      // Token mode carries no session; the overlay endpoints don't check CSRF.
      if (!token) fd.append(csrfName, csrfValue);
      fd.append('scanId',      scanId);
      fd.append('elementId',   elementId);
      fd.append('elementType', elementType);
      fd.append('siteId',      siteId);
      /* The server buckets findings per viewport (desktop/mobile) from this
         width, so a re-scan from a narrow window only replaces the mobile
         bucket instead of overwriting the desktop findings. */
      fd.append('viewportWidth', String(window.innerWidth || 0));
      fd.append('violations',  JSON.stringify(violations));
      /* Contrast nodes axe couldn't measure, stored as needs-review items. */
      fd.append('incomplete',  JSON.stringify(contrastIncomplete(incomplete)));
      const storeHeaders = { 'Accept': 'application/json' };
      if (token) storeHeaders['Authorization'] = 'Bearer ' + token;
      const res = await fetch(storeUrl, {
        method: 'POST',
        body: fd,
        credentials: token ? 'omit' : 'same-origin',
        headers: storeHeaders,
      });
      return res.ok ? await res.json() : null;
    } catch (_) {
      // Non-critical: the live results stay shown in the panel
      return null;
    }
  }

  // ─── Custom contrast detection ────────────────────────────────────────────
  // The contrast math lives in accessibility-audit-shared.js (window.AccessibilityAuditShared), shared
  // with the CP's Inspect preview so the two engines can never drift.

  function collectContrastFailures() {
    if (!window.AccessibilityAuditShared) return [];
    return AccessibilityAuditShared.collectContrastFailures(document, {
      limit: 50,
      htmlLength: 150,
      /* Skip the overlay itself, and the excluded page furniture (consent
         banners etc.) that axe is skipping too. */
      skipEl: function (el) {
        if (el.closest('#accessibility-audit-overlay, #accessibility-audit-overlay-trigger')) return true;
        if (!excludeJoined) return false;
        try { return !!el.closest(excludeJoined); } catch (_) { return false; }
      },
    });
  }

  // ─── Helpers ──────────────────────────────────────────────────────────────
  function impactToSeverity(impact) {
    return { critical: 'error', serious: 'error', moderate: 'warning', minor: 'notice' }[impact] || 'notice';
  }

  function sevLabel(sev) {
    return { error: 'Error', warning: 'Warning', notice: 'Notice', review: 'Review' }[sev] || 'Notice';
  }

  /* Turn axe tags into "WCAG 2.5.8 AA", or "best practice" when no SC applies. */
  function wcagString(tags) {
    var sc = '';
    for (var i = 0; i < tags.length; i++) {
      var m = /^wcag(\d)(\d)(\d+)$/.exec(tags[i]);
      if (m) { sc = m[1] + '.' + m[2] + '.' + m[3]; break; }
    }
    if (!sc) return 'best practice';
    var level = 'A';
    if (tags.some((t) => /aaa$/.test(t))) level = 'AAA';
    else if (tags.some((t) => /aa$/.test(t))) level = 'AA';
    return 'WCAG ' + sc + ' ' + level;
  }

  function computeScore(violations, extraContrast) {
    var weight = { critical: 10, serious: 8, moderate: 4, minor: 1 };
    var penalty = violations.reduce((sum, viol) => sum + (weight[viol.impact] || 2) * viol.nodes.length, 0)
      + extraContrast.length * 8;
    return Math.max(0, 100 - penalty);
  }

  function formatNow() {
    try {
      var d = new Date();
      return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
        + ', ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    } catch (_) { return ''; }
  }

  function esc(s) {
    return window.AccessibilityAuditShared
      ? AccessibilityAuditShared.escHtml(s)
      : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ─── Boot ─────────────────────────────────────────────────────────────────
  function boot() {
    buildPanel();

    /* Prefer the server's stored scan so the overlay mirrors the CP and
       survives refresh across tabs and sessions. */
    if (storedScan && scanId > 0 && pageIssuesUrl) {
      hydrateFromServer(storedScan);
      return;
    }

    /* No stored scan yet: restore the last in-browser scan if we have one,
       otherwise run a live scan when the page maps to a scannable element. */
    const snap = readSnapshot();
    if (snap) {
      paint(snap);
      return;
    }

    if (canAutoScan) {
      setTimeout(() => runScan({ silent: true }), 2000);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
