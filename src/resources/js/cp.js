/**
 * Accessibility Audit: CP JavaScript
 * Handles the entry sidebar panel and scan triggers inside the CP.
 */
(function () {
  'use strict';

  // Use Craft's CP JS framework for URLs and CSRF, fall back to PHP-injected values
  function getActionUrl(action) {
    if (window.Craft && Craft.getActionUrl) {
      return Craft.getActionUrl(action);
    }
    const cfg = window.AccessibilityAudit || {};
    return cfg[action] || ('/actions/' + action);
  }

  function csrfAppend(fd) {
    const name  = (window.Craft && Craft.csrfTokenName)  || window.csrfTokenName  || 'CRAFT_CSRF_TOKEN';
    const value = (window.Craft && Craft.csrfTokenValue) || window.csrfTokenValue || '';
    fd.append(name, value);
  }

  const A11Y = {
    init() {
      this.bindScanButtons();
      this.bindScanAll();
      this.bindVueTableExpand();
      this.bindPanelTabs();
      this.bindVerifyKeyButton();
      this.bindReanalyseButtons();
      this.bindRestoreButtons();
      this.bindSortableTables();
      this.bindGroupedFilters();
    },

    /**
     * Wires up click-to-sort on every table's `th.orderable` columns. Any table
     * with the right markup (th.orderable > button, td[data-sort-value]) gets
     * the behaviour without duplicating this per template. Reuses Craft's own
     * native .orderable/.ordered/.desc CSS classes and CSS-drawn chevron:
     * Craft's own sort JS is tied to its Vue element-index component and
     * doesn't apply to a hand-rolled table.
     */
    bindSortableTables() {
      document.querySelectorAll('table').forEach((table) => {
        const headerRow = table.querySelector('thead tr');
        if (!headerRow) return;

        const headers = Array.prototype.slice.call(headerRow.querySelectorAll('th.orderable'));
        if (!headers.length) return;

        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        function sortValue(cell, type) {
          const raw = (cell && cell.dataset.sortValue) ?? '';
          if (type === 'number') return parseFloat(raw) || 0;
          return raw.toLowerCase();
        }

        headers.forEach((th, index) => {
          const btn = th.querySelector('button');
          if (!btn) return;

          btn.addEventListener('click', () => {
            const wasOrdered = th.classList.contains('ordered');
            const desc = wasOrdered ? !th.classList.contains('desc') : false;

            headers.forEach((h) => {
              h.classList.remove('ordered', 'desc');
              h.removeAttribute('aria-sort');
            });
            th.classList.add('ordered');
            if (desc) th.classList.add('desc');
            th.setAttribute('aria-sort', desc ? 'descending' : 'ascending');

            const type = th.dataset.sortType;
            const rows = Array.prototype.slice.call(tbody.children);
            rows.sort((rowA, rowB) => {
              const a = sortValue(rowA.children[index], type);
              const b = sortValue(rowB.children[index], type);
              if (a < b) return desc ? 1 : -1;
              if (a > b) return desc ? -1 : 1;
              return 0;
            });
            rows.forEach((row) => tbody.appendChild(row));
          });
        });

        // Reflect a server-rendered default sort in aria-sort on load, since
        // the .ordered/.desc classes can be present in the markup before
        // any click happens.
        const initial = table.querySelector('th.orderable.ordered');
        if (initial) {
          initial.setAttribute('aria-sort', initial.classList.contains('desc') ? 'descending' : 'ascending');
        }
      });
    },

    /**
     * Element-type filter chips for the grouped issue board (shared by the
     * dashboard overview card and the Issues page's Issues tab, rendered by
     * _includes/grouped-issues.twig). Each `.accessibility-audit-filter-chips` block controls
     * the `.accessibility-audit-grouped-table` in the same container: it hides rows whose
     * data-category doesn't match, then hides any severity band left empty.
     */
    bindGroupedFilters() {
      document.querySelectorAll('.accessibility-audit-filter-chips').forEach((chips) => {
        const table = chips.parentElement && chips.parentElement.querySelector('.accessibility-audit-grouped-table');
        if (!table) return;

        chips.addEventListener('click', (e) => {
          const btn = e.target.closest('[data-filter]');
          if (!btn) return;
          const filter = btn.dataset.filter;

          chips.querySelectorAll('[data-filter]').forEach((c) => {
            const on = c === btn;
            c.classList.toggle('active', on); // Craft .btngroup selected state
            c.setAttribute('aria-pressed', on ? 'true' : 'false');
          });

          table.querySelectorAll('[data-severity]').forEach((group) => {
            let visible = 0;
            group.querySelectorAll('[data-category]').forEach((row) => {
              const hide = filter !== 'all' && row.dataset.category !== filter;
              row.hidden = hide;
              if (!hide) { visible++; }
            });
            group.hidden = visible === 0;
          });
        });
      });
    },


    bindScanButtons() {
      document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-accessibility-audit-scan-entry], [data-accessibility-audit-scan-url]');
        if (btn) {
          e.preventDefault();
          const siteId = btn.dataset.siteId || '';
          // A page with no element behind it is re-scanned by its URL, which
          // the server only accepts if it is one this site already scans.
          const url = btn.dataset.accessibilityAuditScanUrl;
          this.scanEntry(
            url ? { url } : { entryId: btn.dataset.accessibilityAuditScanEntry },
            siteId,
            btn,
          );
        }
      });
    },

    bindScanAll() {
      document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-accessibility-audit-scan-all]');
        if (btn) {
          e.preventDefault();
          this.scanAll(btn);
        }
      });
    },

    async scanEntry(target, siteId, triggerEl) {
      // The buttons follow Craft's own queue-button pattern: data-icon on the
      // button draws the refresh glyph as a :before, and the spin class animates
      // that pseudo-element while the scan runs. Table buttons wrap their label
      // in a span (so it can collapse to icon-only on narrow screens); update
      // that span when present, else swap the whole button text.
      const label = triggerEl.querySelector('.accessibility-audit-rowbtn__label');
      const setText = (msg) => {
        if (label) { label.textContent = msg; } else { triggerEl.textContent = msg; }
      };

      triggerEl.disabled = true;
      triggerEl.classList.add('accessibility-audit-spin');
      setText(Craft.t('accessibility-audit', 'Scanning…'));

      try {
        const fd = new FormData();
        csrfAppend(fd);
        if (target.url) { fd.append('url', target.url); } else { fd.append('entryId', target.entryId); }
        if (siteId) fd.append('siteId', siteId);
        // On the Inspect page the preview iframe runs the browser pass itself
        // after the reload; skip the queued headless pass so exactly one
        // browser engine writes this scan's findings (no overwrite race).
        if (document.getElementById('accessibility-audit-preview-iframe')) {
          fd.append('skipHeadless', '1');
        }

        const action = target.url ? 'accessibility-audit/audit/scan-url' : 'accessibility-audit/audit/scan-entry';
        const res  = await fetch(getActionUrl(action), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        const data = await res.json();

        if (data.limitReached) {
          const limit = data.limit || 100;
          // The Standard cap counts distinct pages across every site on the
          // install, so on multi-site installs the refusal says so rather than
          // reading as an arbitrary per-site limit.
          const scope = (window.AccessibilityAudit && window.AccessibilityAudit.isMultiSite) ? ' across all sites' : '';
          const msg = `Scan limit reached (${limit} pages${scope}). Upgrade to Pro for unlimited scanning.`;
          if (window.Craft && Craft.cp) Craft.cp.displayError(msg);
          setText(Craft.t('accessibility-audit', 'Limit reached'));
          return;
        }

        if (data.success) {
          /* The queued headless pass was skipped above, and that pass is the
             one covering both viewports. Ask the preview to sweep them itself
             after the reload, so a re-scan means a re-scan of the page rather
             than of whichever width happens to be on screen. */
          if (document.getElementById('accessibility-audit-preview-iframe')) {
            try { sessionStorage.setItem('a11y_sweep_viewports', '1'); } catch (_) {}
          }

          /* Reload rather than patching the markup in place: the new scan ID has
             to be the one Twig rendered, or the contrast auto-store cycle writes
             against the previous scan. The sidebar panel deliberately does not
             re-render itself for the same reason. */
          window.location.reload();
          return;
        } else {
          const failure = data.error || Craft.t('accessibility-audit', 'Scan failed');
          setText(Craft.t('accessibility-audit', 'Scan failed'));
          if (window.Craft && Craft.cp) { Craft.cp.displayError(failure); }
        }
      } catch (err) {
        console.error('[a11y]', err);
        setText(Craft.t('accessibility-audit', 'Error, retry'));
        if (window.Craft && Craft.cp) { Craft.cp.displayError(Craft.t('accessibility-audit', 'Error, retry')); }
      } finally {
        triggerEl.disabled = false;
        triggerEl.classList.remove('accessibility-audit-spin');
      }
    },

    async scanAll(btn) {
      const originalText = btn.textContent;
      btn.disabled          = true;
      btn.textContent       = Craft.t('accessibility-audit', 'Queuing…');

      const siteId = btn.dataset.siteId || '';
      const fd     = new FormData();
      csrfAppend(fd);
      if (siteId) fd.append('siteId', siteId);

      // Success is a terminal state: the scans are queued, so the button stays
      // disabled with a "check back" label. Every failure path re-enables it and
      // surfaces the reason, so a transient error never strands the primary CTA.
      try {
        const res  = await fetch(getActionUrl('accessibility-audit/audit/scan-all'), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (data.success) {
          btn.textContent = Craft.t('accessibility-audit', 'Queued {n} scans, check back soon', { n: data.queued });
          return;
        }
        Craft.cp.displayError(data.error || Craft.t('accessibility-audit', 'Could not queue the scan.'));
      } catch (err) {
        Craft.cp.displayError(Craft.t('accessibility-audit', 'Could not queue the scan.'));
      }

      btn.disabled    = false;
      btn.textContent = originalText;
    },

    /**
     * Row expansion for the pages VueAdminTable: Craft's admin table is a flat
     * vuetable with no native detail rows, so the "issues on this page" panel is
     * injected as a sibling <tr> right after the clicked row and filled by the
     * loadPageIssues() path. The table's onLoaded hook purges these injected rows
     * on every reload (page / sort / search), so Vue only ever re-renders its own
     * rows and expansions collapse on navigation as expected.
     */
    bindVueTableExpand() {
      document.addEventListener('click', (e) => {
        const btn = e.target.closest('.accessibility-audit-vt-expand');
        if (!btn) return;

        const row = btn.closest('tr');
        if (!row) return;
        const expanded = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', String(!expanded));

        let detailRow = row.nextElementSibling;
        const hasDetail = detailRow && detailRow.classList.contains('accessibility-audit-vt-detail');

        if (expanded) {
          if (hasDetail) detailRow.hidden = true;
          return;
        }

        if (hasDetail) {
          detailRow.hidden = false;
          return;
        }

        detailRow = document.createElement('tr');
        detailRow.className = 'accessibility-audit-vt-detail';
        const cell = document.createElement('td');
        cell.colSpan = row.children.length || 1;
        cell.innerHTML = '<div class="accessibility-audit-issues-detail"><span class="accessibility-audit-loading">' +
          escHtml(Craft.t('accessibility-audit', 'Loading…')) + '</span></div>';
        detailRow.appendChild(cell);
        row.after(detailRow);

        this.loadPageIssues(cell.querySelector('.accessibility-audit-issues-detail'), btn.dataset.scanId, btn.dataset.siteId, {});
      });
    },

    async loadPageIssues(container, scanId, siteId, meta) {
      const cfg  = window.AccessibilityAudit || {};
      const base = cfg.pageIssuesUrl || '/actions/accessibility-audit/dashboard/page-issues';
      const sep  = base.includes('?') ? '&' : '?';
      const url  = base + sep + 'scanId=' + encodeURIComponent(scanId) + '&siteId=' + encodeURIComponent(siteId);

      try {
        const res = await fetch(url, {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }

        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Request failed');
        container.dataset.loaded = '1';
        container.innerHTML = this.renderPageIssuesTable(data.issues, meta);
      } catch (err) {
        console.error('[accessibility-audit-audit] loadPageIssues:', err);
        container.innerHTML = '<p class="error" style="padding:12px 0">Failed to load issues (' + escHtml(err.message) + ').</p>';
      }
    },

    renderPageIssuesTable(issues, meta) {
      if (!issues.length) {
        return '<p class="light" style="padding:10px 0">No issues found.</p>';
      }

      const cfg     = window.AccessibilityAudit || {};
      const detBase = cfg.issueDetailUrl || '';

      // No heading / legend / action toolbar here: the severity counts already
      // show in the row's Issues column, and Inspect / Edit entry / View page
      // are in the row's "..." menu, so repeating them inside the panel is
      // duplication.

      // Group rules by severity, errors first. Severity reads from a coloured
      // dot beside each rule, matching the grouped issue board. Each band is
      // its own <tbody>.
      const groups = [
        { key: 'error',   label: 'Error' },
        { key: 'warning', label: 'Warning' },
        { key: 'notice',  label: 'Notice' },
      ];
      const owners = {
        content:     ['content', 'Content'],
        design:      ['design', 'Design'],
        development: ['dev', 'Development'],
        technical:   ['technical', 'Technical'],
      };

      let body = '';
      groups.forEach((g) => {
        const gr = issues.filter((i) => i.severity === g.key);
        if (!gr.length) return;
        let rowsHtml = '';
        gr.forEach((issue) => {
          // detBase comes from UrlHelper::cpUrl(), which appends `site=<handle>`
          // on a multi-site install, so it may already carry a query string;
          // pick the separator accordingly. The site rides along in detBase,
          // so there is nothing further to append here.
          const sep = detBase.includes('?') ? '&' : '?';
          const detailUrl = detBase + sep + 'ruleId=' + encodeURIComponent(issue.ruleId);
          const owner = owners[issue.responsibility];
          const ownerHtml = owner
            ? `<span class="token accessibility-audit-owner--${owner[0]}">${owner[1]}</span>`
            : '<span class="light">—</span>';
          rowsHtml += `<tr>
              <th scope="row"><span class="accessibility-audit-rowline" style="gap:8px">
                <span class="accessibility-audit-legend__dot accessibility-audit-legend__dot--${g.key}" aria-hidden="true" title="${escHtml(g.label)}"></span>
                <span class="visually-hidden">${escHtml(g.label)}:</span>
                <a href="${escHtml(detailUrl)}">${escHtml(issue.ruleId)}</a>
              </span></th>
              <td class="code">${escHtml(issue.wcagCriterion || '—')}</td>
              <td>${ownerHtml}</td>
              <td style="text-align:right">${escHtml(String(issue.occurrences))}</td>
            </tr>`;
        });
        body += `<tbody data-severity="${g.key}">${rowsHtml}</tbody>`;
      });

      return `<table class="data fullwidth accessibility-audit-page-issues-table">
          <thead><tr>
            <th scope="col">Rule</th>
            <th scope="col">SC</th>
            <th scope="col">Owner</th>
            <th scope="col" style="text-align:right">Count</th>
          </tr></thead>${body}</table>`;
    },

    /**
     * Activates the given tab (and its matching panel) within a
     * `[data-accessibility-audit-panel]` instance: shared by click and the
     * WAI-ARIA Tabs keyboard pattern below, so both stay in sync.
     */
    activatePanelTab(panel, tab) {
      const target = tab.dataset.accessibilityAuditPanelTab;

      panel.querySelectorAll('[data-accessibility-audit-panel-tab]').forEach((t) => {
        const active = t.dataset.accessibilityAuditPanelTab === target;
        t.classList.toggle('accessibility-audit-panel-tab--active', active);
        t.setAttribute('aria-selected', String(active));
        // Roving tabindex: only the active tab is Tab-key focusable;
        // Left/Right/Home/End (below) move focus between tabs directly.
        t.setAttribute('tabindex', active ? '0' : '-1');
      });
      panel.querySelectorAll('[data-accessibility-audit-panel-pane]').forEach((p) => {
        p.hidden = p.dataset.accessibilityAuditPanelPane !== target;
      });
    },

    bindPanelTabs() {
      document.addEventListener('click', (e) => {
        const tab = e.target.closest('[data-accessibility-audit-panel-tab]');
        if (!tab) return;
        const panel = tab.closest('[data-accessibility-audit-panel]');
        if (!panel) return;
        this.activatePanelTab(panel, tab);
      });

      // WAI-ARIA Tabs keyboard pattern: Left/Right move focus and switch
      // tabs (not just Tab-key through each button in sequence), Home/End
      // jump to the first/last tab.
      document.addEventListener('keydown', (e) => {
        const tab = e.target.closest('[data-accessibility-audit-panel-tab]');
        if (!tab) return;
        const panel = tab.closest('[data-accessibility-audit-panel]');
        if (!panel) return;

        const tabs = Array.from(panel.querySelectorAll('[data-accessibility-audit-panel-tab]'));
        const index = tabs.indexOf(tab);
        let next;

        switch (e.key) {
          case 'ArrowLeft':
            next = tabs[(index - 1 + tabs.length) % tabs.length];
            break;
          case 'ArrowRight':
            next = tabs[(index + 1) % tabs.length];
            break;
          case 'Home':
            next = tabs[0];
            break;
          case 'End':
            next = tabs[tabs.length - 1];
            break;
          default:
            return;
        }

        e.preventDefault();
        this.activatePanelTab(panel, next);
        next.focus();
      });
    },



    bindVerifyKeyButton() {
      const btn = document.getElementById('accessibility-audit-verify-key-btn');
      if (!btn) return;

      btn.addEventListener('click', () => this.verifyKey(btn));
    },

    async verifyKey(btn) {
      const resultEl = document.getElementById('accessibility-audit-verify-key-result');
      const cfg      = window.AccessibilityAudit || {};
      const url      = cfg.verifyKeyUrl || '/actions/accessibility-audit/alt/verify-key';

      btn.disabled = true;
      if (resultEl) {
        resultEl.textContent = Craft.t('accessibility-audit', 'Verifying…');
        resultEl.className   = 'accessibility-audit-inline-result';
      }

      try {
        const fd = new FormData();
        csrfAppend(fd);

        const res  = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const data = await res.json();

        if (resultEl) {
          resultEl.textContent = data.success ? data.message : (data.error || 'Verification failed.');
          resultEl.className   = 'accessibility-audit-inline-result ' + (data.success ? 'accessibility-audit-inline-result--success' : 'accessibility-audit-inline-result--error');
        }
        // Toast as well as the inline line: the native notice is the feedback
        // pattern used everywhere else in the CP, and it's visible even if the
        // result line is scrolled out of view under a long settings page.
        if (window.Craft && Craft.cp) {
          if (data.success) {
            Craft.cp.displayNotice(data.message);
          } else {
            Craft.cp.displayError(data.error || Craft.t('accessibility-audit', 'Verification failed.'));
          }
        }
      } catch (err) {
        console.error('[a11y]', err);
        if (resultEl) {
          resultEl.textContent = Craft.t('accessibility-audit', 'Error, please try again.');
          resultEl.className   = 'accessibility-audit-inline-result accessibility-audit-inline-result--error';
        }
        if (window.Craft && Craft.cp) {
          Craft.cp.displayError(Craft.t('accessibility-audit', 'Error, please try again.'));
        }
      } finally {
        btn.disabled = false;
      }
    },

    /**
     * Restore, on the Potential issues → Dismissed tab.
     *
     * Posts an empty verdict to the same action the page report's Restore uses,
     * which clears the ruling and puts the question back into Needs review. The
     * row is removed on success rather than the page reloading, so working
     * through a batch does not cost a round trip each time.
     */
    bindRestoreButtons() {
      document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-accessibility-audit-restore]');
        if (!btn) return;

        const endpoint = (window.AccessibilityAudit || {}).setVerdictUrl;
        if (!endpoint) return;

        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = Craft.t('accessibility-audit', 'Saving…');

        try {
          const fd = new FormData();
          csrfAppend(fd);
          fd.append('elementId', btn.dataset.elementId);
          fd.append('ruleId', btn.dataset.ruleId);
          fd.append('context', btn.dataset.context || '');
          fd.append('siteId', btn.dataset.siteId);
          // Empty verdict = no ruling, which is what "restore" means here.
          fd.append('verdict', '');

          const res = await fetch(endpoint, { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
          const data = await res.json();

          if (data.success) {
            const row = btn.closest('tr');
            if (row) row.remove();
            if (window.Craft && Craft.cp) {
              (Craft.cp.displaySuccess || Craft.cp.displayNotice).call(
                Craft.cp,
                Craft.t('accessibility-audit', 'Restored to Needs review.'),
              );
            }
            return;
          }

          btn.disabled = false;
          btn.textContent = original;
          if (window.Craft && Craft.cp) {
            Craft.cp.displayError(data.error || Craft.t('accessibility-audit', 'Could not save that. Try again.'));
          }
        } catch (err) {
          btn.disabled = false;
          btn.textContent = original;
          if (window.Craft && Craft.cp) {
            Craft.cp.displayError(Craft.t('accessibility-audit', 'Could not save that. Try again.'));
          }
        }
      });
    },

    bindReanalyseButtons() {
      document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-accessibility-audit-reanalyse]');
        if (btn) {
          e.preventDefault();
          this.reanalyseRow(btn);
        }
      });
    },

    /**
     * Re-runs readability analysis for one row of the Page results table.
     * Rows resolved to one of this install's own elements (data-element-id)
     * go through analyse-entry, reading the element's live fields directly,
     * same as the entry's own field "Check" button. Anything else
     * (data-url) falls back to analyse, which re-fetches the URL: the same
     * split ReadabilityController::actionAnalyse() already makes via
     * resolveElementForUrl() when the row was first stored.
     */
    async reanalyseRow(btn) {
      const cfg       = window.AccessibilityAudit || {};
      const elementId = btn.dataset.elementId;
      const siteId    = btn.dataset.siteId;
      const url       = btn.dataset.url;

      const actionUrl = elementId
        ? (cfg.readabilityAnalyseEntryUrl || '/actions/accessibility-audit/readability/analyse-entry')
        : (cfg.readabilityAnalyseUrl || '/actions/accessibility-audit/readability/analyse');

      btn.disabled    = true;
      btn.textContent = Craft.t('accessibility-audit', 'Analysing…');

      try {
        const fd = new FormData();
        csrfAppend(fd);
        if (elementId) {
          fd.append('elementId', elementId);
          if (siteId) fd.append('siteId', siteId);
        } else {
          fd.append('url', url);
        }

        const res  = await fetch(actionUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const data = await res.json();

        if (data.success) {
          // The table row itself (score, word count, analysed date) is
          // server-rendered from the DB, reload so it reflects the fresh
          // result rather than re-deriving that markup here.
          window.location.reload();
          return;
        }

        // Throttled, not failed: count the wait down on the button and put
        // it back to normal, so a rate limit never reads as an error (and
        // never as the word "Failed" beside a column that says "Fail").
        if (data.retryAfter) {
          let remaining = parseInt(data.retryAfter, 10) || 5;
          const tick = () => {
            if (remaining <= 0) {
              btn.disabled = false;
              btn.textContent = Craft.t('accessibility-audit', 'Re-analyse');
              return;
            }
            btn.disabled = true;
            btn.textContent = `Wait ${remaining}s…`;
            remaining--;
            setTimeout(tick, 1000);
          };
          tick();
          return;
        }

        // A real failure: surface the reason as a CP toast, keep the button
        // usable, and avoid the word "Failed" (the WCAG column owns "Fail").
        console.error('[a11y] reanalyse:', data.error);
        if (window.Craft && Craft.cp && Craft.cp.displayError) {
          Craft.cp.displayError(data.error || 'Analysis failed.');
        }
        btn.textContent = Craft.t('accessibility-audit', 'Retry');
      } catch (err) {
        console.error('[a11y]', err);
        if (window.Craft && Craft.cp && Craft.cp.displayError) {
          Craft.cp.displayError(Craft.t('accessibility-audit', 'Analysis request failed. Check your connection and try again.'));
        }
        btn.textContent = Craft.t('accessibility-audit', 'Retry');
      } finally {
        btn.disabled = false;
      }
    },


  };

  /* Shared with the inline template JS and the frontend overlay via accessibility-audit-shared.js */
  const escHtml = window.AccessibilityAuditShared.escHtml;

  // Init on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => A11Y.init());
  } else {
    A11Y.init();
  }
})();

// ── Asset alt-text button ──────────────────────────────────────────────────────
// Injected into the alt-text field on asset edit pages (full-page and slideout).
// Called immediately on load and again after the assetId is set by PHP (POS_END).
window.AccessibilityAuditInjectAltBtn = (function () {
  function csrfAppend(fd) {
    const name  = (window.Craft && Craft.csrfTokenName)  || 'CRAFT_CSRF_TOKEN';
    const value = (window.Craft && Craft.csrfTokenValue) || '';
    fd.append(name, value);
  }

  // Locate the field the alt text lives in. On a full edit page Craft's
  // built-in field is textarea#alt and a custom field is fields[handle] /
  // #fields-handle; inside a slideout editor every input is namespaced, so the
  // selectors also match by suffix (name$="[alt]", id$="-fields-handle").
  // Handles are restricted to [a-zA-Z0-9_], so no CSS escaping is needed.
  function findAltField() {
    const handle = (window.AccessibilityAudit || {}).altField || 'alt';
    let sel;
    if (handle === 'alt') {
      sel = 'textarea#alt, input#alt, textarea[name$="[alt]"], input[name$="[alt]"]';
    } else {
      sel =
        'textarea[name="fields[' + handle + ']"], input[name="fields[' + handle + ']"], ' +
        'textarea[name$="[fields][' + handle + ']"], input[name$="[fields][' + handle + ']"], ' +
        'textarea#fields-' + handle + ', input#fields-' + handle + ', ' +
        'textarea[id$="-fields-' + handle + '"], input[id$="-fields-' + handle + '"]';
    }

    // Prefer the topmost slideout, so an editor opened over a page that also
    // carries the field gets the button, not the page behind it.
    const slideouts = document.querySelectorAll('.slideout');
    for (let i = slideouts.length - 1; i >= 0; i--) {
      const el = slideouts[i].querySelector(sel);
      if (el) return el;
    }
    return document.querySelector(sel);
  }

  function inject() {
    const cfg = window.AccessibilityAudit || {};
    if (!cfg.assetId || !cfg.generateAltUrl) return;

    const textarea = findAltField();
    // The wired flag lives on the field itself, so the page's field and a
    // slideout's field are tracked separately (a document-wide id check would
    // block the slideout whenever the page behind it was already wired).
    if (!textarea || textarea.dataset.aaAltWired) return;
    textarea.dataset.aaAltWired = '1';

    // A decorative image is meant to have no alt text, so a Generate button
    // would contradict the flag. Show the state instead.
    if (cfg.assetDecorative) {
      const note = document.createElement('p');
      note.className = 'light accessibility-audit-gen-alt-note';
      note.style.cssText = 'margin-top:6px;display:block;';
      note.textContent = Craft.t('accessibility-audit', 'Marked decorative, no alt text needed.') + ' ' +
        Craft.t('accessibility-audit', 'You can change that on the Assets page.');
      textarea.closest('.input') && textarea.closest('.input').after(note)
        || textarea.parentElement.appendChild(note);
      return;
    }

    const btn = document.createElement('button');
    btn.type      = 'button';
    btn.className = 'btn small accessibility-audit-gen-alt-btn';
    btn.textContent = Craft.t('accessibility-audit', 'Generate Alt Text');
    btn.style.cssText = 'margin-top:6px;display:block;';

    textarea.closest('.input') && textarea.closest('.input').after(btn)
      || textarea.parentElement.appendChild(btn);

    btn.addEventListener('click', async () => {
      btn.textContent = Craft.t('accessibility-audit', 'Generating…');
      btn.disabled    = true;

      try {
        const fd = new FormData();
        csrfAppend(fd);
        fd.append('assetId', cfg.assetId);

        const res  = await fetch(cfg.generateAltUrl, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        const data = await res.json();

        if (data.success) {
          textarea.value = data.alt;
          textarea.dispatchEvent(new Event('input', { bubbles: true }));
          textarea.dispatchEvent(new Event('change', { bubbles: true }));
          btn.textContent = Craft.t('accessibility-audit', 'Regenerate');
          btn.disabled    = false;
          if (window.Craft && Craft.cp) {
            Craft.cp.displayNotice(Craft.t('accessibility-audit', 'Alt text generated, save the asset to apply it.'));
          }
        } else {
          btn.textContent = Craft.t('accessibility-audit', 'Retry');
          btn.disabled    = false;
          if (window.Craft && Craft.cp) Craft.cp.displayError(data.error);
        }
      } catch (err) {
        console.error('[a11y]', err);
        btn.textContent = Craft.t('accessibility-audit', 'Error, retry');
        btn.disabled    = false;
      }
    });
  }

  // Run on DOMContentLoaded (full-page renders) and via MutationObserver (slideouts)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inject);
  } else {
    inject();
  }

  // Watch for slideout/drawer opens that add the alt field to the DOM. Skips
  // batches with no added element nodes and coalesces the rest into one
  // inject() per animation frame to stay cheap on mutation-heavy screens.
  let scheduled = false;
  function scheduleInject() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
      scheduled = false;
      if (!document.getElementById('accessibility-audit-gen-alt-btn')) inject();
    });
  }

  const observer = new MutationObserver((records) => {
    const addedElement = records.some((rec) =>
      Array.prototype.some.call(rec.addedNodes, (n) => n.nodeType === 1)
    );
    if (addedElement) scheduleInject();
  });
  observer.observe(document.body, { childList: true, subtree: true });

  return inject;
}());
