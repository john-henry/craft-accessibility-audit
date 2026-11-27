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

  /* Whether the Inspect preview can run the browser pass itself. A frame on
     another domain can't be read from here, so it never runs. */
  function previewRunsBrowserPass() {
    const iframe = document.getElementById('accessibility-audit-preview-iframe');
    if (!iframe) return false;
    try {
      return new URL(iframe.dataset.pageUrl || iframe.src, window.location.href).origin === window.location.origin;
    } catch (_) {
      return false;
    }
  }

  /*
     The page's status region, created once so it is in the DOM well before
     anything writes to it: a live region inserted and filled in the same tick
     is not reliably announced.

     Progress used to be announced by putting aria-live on the scan buttons
     themselves and letting their label change. That works, but a live region on
     the control the reader is sitting on is announced twice by some screen
     readers, and it is not what the rest of this plugin does. Every other status
     on these screens is a separate role="status" element.
  */
  const LIVE_REGION_ID = 'accessibility-audit-live';

  function liveRegion() {
    let region = document.getElementById(LIVE_REGION_ID);

    if (!region) {
      region = document.createElement('p');
      region.id = LIVE_REGION_ID;
      region.setAttribute('role', 'status');
      region.setAttribute('aria-live', 'polite');
      region.className = 'visually-hidden';
      document.body.appendChild(region);
    }

    return region;
  }

  function announce(message) {
    liveRegion().textContent = message;
  }

  const A11Y = {
    init() {
      // Built first, so it is already in the document when a scan reports back.
      liveRegion();

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

        // Narrow panes show a select in place of the buttons; it presses the
        // matching button, so there is still only one filter.
        const select = chips.parentElement.querySelector('.accessibility-audit-filter-select select');
        if (select) {
          select.addEventListener('change', () => {
            const target = [...chips.querySelectorAll('[data-filter]')].find((c) => c.dataset.filter === select.value);
            if (target) target.click();
          });
        }

        chips.addEventListener('click', (e) => {
          const btn = e.target.closest('[data-filter]');
          if (!btn) return;
          const filter = btn.dataset.filter;
          if (select) select.value = filter;

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
        // The label is the visible affordance; the status region is what is read
        // out. The button is disabled while the scan runs, so its own text is no
        // longer a reliable thing to announce from.
        announce(msg);
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
        // A preview on another domain can't run it, so the queued pass stays.
        const previewRunsPass = previewRunsBrowserPass();
        if (previewRunsPass) {
          fd.append('skipHeadless', '1');
        }

        const action = target.url ? 'accessibility-audit/audit/scan-url' : 'accessibility-audit/audit/scan-entry';
        const res  = await fetch(getActionUrl(action), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        const data = await res.json();

        if (data.limitReached) {
          const limit = data.limit || 25;
          // The Lite cap counts distinct pages across every site on the
          // install, so on multi-site installs the refusal says so rather than
          // reading as an arbitrary per-site limit.
          const scope = (window.AccessibilityAudit && window.AccessibilityAudit.isMultiSite) ? ' across all sites' : '';
          const msg = `Scan limit reached (${limit} pages${scope}). Upgrade to Plus for unlimited scanning.`;
          if (window.Craft && Craft.cp) Craft.cp.displayError(msg);
          setText(Craft.t('accessibility-audit', 'Limit reached'));
          return;
        }

        if (data.excluded) {
          if (window.Craft && Craft.cp) Craft.cp.displayNotice(data.error);
          setText(Craft.t('accessibility-audit', 'Excluded'));
          return;
        }

        if (data.success) {
          /* The queued headless pass was skipped above, and that pass is the
             one covering both viewports. Ask the preview to sweep them itself
             after the reload, so a re-scan means a re-scan of the page rather
             than of whichever width happens to be on screen. */
          if (previewRunsPass) {
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
      const setText = (msg) => {
        btn.textContent = msg;
        announce(msg);
      };

      btn.disabled = true;
      setText(Craft.t('accessibility-audit', 'Queuing…'));

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
          setText(Craft.t('accessibility-audit', 'Queued {n} scans, check back soon', { n: data.queued }));
          return;
        }
        Craft.cp.displayError(data.error || Craft.t('accessibility-audit', 'Could not queue the scan.'));
      } catch (err) {
        Craft.cp.displayError(Craft.t('accessibility-audit', 'Could not queue the scan.'));
      }

      btn.disabled = false;
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
        return '<p class="light accessibility-audit-emptyok" style="padding:10px 0">'
          + '<span class="accessibility-audit-resolved-check" data-icon="check" aria-hidden="true"></span>'
          + Craft.t('accessibility-audit', 'No issues found.') + '</p>';
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
        { key: 'error',   label: Craft.t('accessibility-audit', 'Error') },
        { key: 'warning', label: Craft.t('accessibility-audit', 'Warning') },
        { key: 'notice',  label: Craft.t('accessibility-audit', 'Notice') },
      ];
      // Injected by the plugin so this reads the same labels, in the same
      // language, as the server-rendered badge.
      const owners = (window.AccessibilityAudit && window.AccessibilityAudit.responsibilities) || {};

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
            ? `<span class="token accessibility-audit-owner--${owner.modifier}">${escHtml(owner.label)}</span>`
            : '<span class="light">—</span>';
          rowsHtml += `<tr>
              <th scope="row"><span class="accessibility-audit-rowline" style="gap:8px">
                <span class="accessibility-audit-legend__dot accessibility-audit-legend__dot--${g.key}" role="img" aria-label="${escHtml(g.label)}"></span>
                <span>${window.AccessibilityAuditTable.ruleName(issue.title, issue.ruleId, detailUrl)}</span>
              </span></th>
              <td class="code">${escHtml(issue.wcagCriterion || '—')}</td>
              <td>${ownerHtml}</td>
              <td style="text-align:right">${escHtml(String(issue.occurrences))}</td>
            </tr>`;
        });
        body += `<tbody data-severity="${g.key}">${rowsHtml}</tbody>`;
      });

      // Translated, as the server-rendered table beside this one is. Written
      // out one call at a time on purpose: JsTranslationsTest finds these by
      // reading the source for a literal message, so a helper taking the string
      // as a variable would hide them from the very check that keeps them
      // registered.
      return `<table class="data fullwidth accessibility-audit-page-issues-table">
          <thead><tr>
            <th scope="col">${escHtml(Craft.t('accessibility-audit', 'Rule'))}</th>
            <th scope="col">${escHtml(Craft.t('accessibility-audit', 'SC'))}</th>
            <th scope="col">${escHtml(Craft.t('accessibility-audit', 'Owner'))}</th>
            <th scope="col" style="text-align:right">${escHtml(Craft.t('accessibility-audit', 'Occurrences'))}</th>
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
          resultEl.textContent = data.success ? data.message : (data.error || Craft.t('accessibility-audit', 'Verification failed.'));
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

        const everyPage = btn.dataset.everyPage === '1';
        const endpoint = everyPage
          ? (window.AccessibilityAudit || {}).setVerdictEverywhereUrl
          : (window.AccessibilityAudit || {}).setVerdictUrl;
        if (!endpoint) return;

        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = Craft.t('accessibility-audit', 'Saving…');

        /* Dismissed for every page, so restoring it puts the question back on
           every page, and the table is reloaded to drop all of its rows. */
        if (everyPage) {
          try {
            const fd = new FormData();
            csrfAppend(fd);
            fd.append('siteId', btn.dataset.siteId);
            fd.append('verdict', '');
            fd.append('items', JSON.stringify([{
              ruleId: btn.dataset.ruleId,
              context: btn.dataset.context || '',
              selector: btn.dataset.selector || '',
            }]));

            const res = await fetch(endpoint, { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
            const data = await res.json();

            if (data.success) {
              window.location.reload();
              return;
            }

            throw new Error(data.error || 'failed');
          } catch (err) {
            btn.disabled = false;
            btn.textContent = original;
            if (window.Craft && Craft.cp) {
              Craft.cp.displayError(Craft.t('accessibility-audit', 'Could not save that. Try again.'));
            }
            return;
          }
        }

        try {
          const fd = new FormData();
          csrfAppend(fd);
          fd.append('elementId', btn.dataset.elementId);
          /* A dismissal made on a page with no element behind it is keyed to
             the URL, so restoring it has to name the same page. */
          if (btn.dataset.url) { fd.append('url', btn.dataset.url); }
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

      // Kept so the button comes back as it was, icon and hidden label included.
      const original = btn.innerHTML;
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
              btn.innerHTML = original;
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
          Craft.cp.displayError(data.error || Craft.t('accessibility-audit', 'Analysis failed. Check Craft logs for details.'));
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

// ── Asset alt-text field ───────────────────────────────────────────────────────
// Adds the character count and the AI suggestions under the alt text field on
// asset edit pages (full-page and slideout).
// Called immediately on load and again once PHP has added the screen's marker (POS_END).
window.AccessibilityAuditInjectAltBtn = (function () {

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

  // The asset this field belongs to, read from the marker PHP adds to the
  // same screen's sidebar: the slideout the field sits in, or the page itself
  // for a field outside any slideout. Read per screen, never from a page-wide
  // setting, so one image's draft can't turn up in the next image's editor.
  function assetFor(textarea) {
    const slideout = textarea.closest('.slideout');
    const marker = slideout
      ? slideout.querySelector('.accessibility-audit-asset-alt')
      : [...document.querySelectorAll('.accessibility-audit-asset-alt')].find((el) => !el.closest('.slideout'));
    if (!marker) return null;

    return {
      id: Number(marker.dataset.assetId) || 0,
      decorative: marker.dataset.decorative === '1',
      generate: marker.dataset.generate === '1',
      draft: parseDraft(marker.dataset.draft),
    };
  }

  function parseDraft(json) {
    if (!json) return null;
    try {
      return JSON.parse(json);
    } catch {
      return null;
    }
  }

  function inject() {
    const cfg = window.AccessibilityAudit || {};
    if (!cfg.generateAltUrl) return;

    const textarea = findAltField();
    // The wired flag lives on the field itself, so the page's field and a
    // slideout's field are tracked separately (a document-wide id check would
    // block the slideout whenever the page behind it was already wired).
    if (!textarea || textarea.dataset.aaAltWired) return;

    // Not wired until its marker is in: the field can arrive a frame before
    // the sidebar does, and the next pass picks it up.
    const asset = assetFor(textarea);
    if (!asset || !asset.id) return;
    textarea.dataset.aaAltWired = '1';

    // A decorative image is meant to have no alt text, so a Generate button
    // would contradict the flag. Show the state instead.
    if (asset.decorative) {
      const note = document.createElement('p');
      note.className = 'light accessibility-audit-gen-alt-note';
      note.style.cssText = 'margin-top:6px;display:block;';
      note.textContent = Craft.t('accessibility-audit', 'Marked decorative, no alt text needed.') + ' ' +
        Craft.t('accessibility-audit', 'You can change that on the Assets page.');
      textarea.closest('.input') && textarea.closest('.input').after(note)
        || textarea.parentElement.appendChild(note);
      return;
    }

    // Counted here as well as on the Assets page, because this is the screen
    // most alt text actually gets written on. Counts up against the guideline
    // the long-alt check reports on: there is no cap to run out of, and the
    // useful number is how far past it the description sits.
    const guideline = Number(cfg.altGuideline) || 0;
    const count = document.createElement('span');
    count.className = 'light accessibility-audit-alt-count';
    count.setAttribute('role', 'status');
    count.setAttribute('aria-live', 'polite');

    const renderCount = () => {
      if (!guideline) return;
      const n = [...textarea.value].length;
      const over = n - guideline;

      count.textContent = over > 0
        ? Craft.t('accessibility-audit', '{n} characters, {over} over', { n: n, over: over })
        : Craft.t('accessibility-audit', '{n} characters', { n: n });
      count.classList.toggle('accessibility-audit-alt-count--low', over > 0);
    };

    const anchor = textarea.closest('.input') || textarea.parentElement;
    anchor.after ? anchor.after(count) : anchor.appendChild(count);

    textarea.addEventListener('input', renderCount);
    renderCount();

    // Generating needs a key, but a draft already waiting in the review queue
    // can be shown without one.
    const draft = asset.draft;
    if ((!asset.generate && !draft) || !window.AccessibilityAuditAltSuggestions) return;

    const ui = window.AccessibilityAuditAltSuggestions.build();
    ui.style.marginTop = '6px';
    if (!asset.generate) {
      const gen = ui.querySelector('.accessibility-audit-alt-editor__generate');
      if (gen) gen.remove();
    }
    if (draft) {
      const note = document.createElement('p');
      note.className = 'light smalltext accessibility-audit-alt-editor__draft-note';
      note.textContent = Craft.t('accessibility-audit', 'A draft is waiting for review. Pick a version, then save the image.');
      ui.prepend(note);
    }
    count.after(ui);
    window.AccessibilityAuditAltSuggestions.wire(ui, {
      assetId: asset.id,
      url: cfg.generateAltUrl,
      input: textarea,
      guideline: guideline,
      initial: draft,
      onUse: () => {
        renderCount();
        if (window.Craft && Craft.cp) {
          Craft.cp.displayNotice(Craft.t('accessibility-audit', 'Alt text generated, save the asset to apply it.'));
        }
      },
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
      inject();
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

// AI alt text suggestions, shared by the Assets page's alt text panel and the
// asset edit screen: three versions to pick from, the notes on what to check,
// and progress while the request runs. A draft written in the background is
// passed in as `initial` and shown straight away, with no request. Nothing
// goes into the field until the editor picks a version, unless the caller
// asks for `prefill`.
(function () {
  'use strict';

  function csrfAppend(fd) {
    const name = (window.Craft && Craft.csrfTokenName) || window.csrfTokenName;
    const value = (window.Craft && Craft.csrfTokenValue) || window.csrfTokenValue;
    if (name && value) fd.append(name, value);
  }

  // An option past the guideline is not wrong, so its length reads as a note.
  function lengthNote(text, guideline) {
    const n = [...text].length;
    return guideline && n > guideline
      ? Craft.t('accessibility-audit', '{n} characters, longer than the {max} guideline', { n: n, max: guideline })
      : Craft.t('accessibility-audit', '{n} characters', { n: n });
  }

  // The markup the panel's template renders, for screens that have no template
  // of their own. Craft's own button, with the spinner its loading state shows.
  function build() {
    const root = document.createElement('div');
    root.className = 'accessibility-audit-alt-editor__ai';

    const row = document.createElement('div');
    row.className = 'flex';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn small accessibility-audit-alt-editor__generate';
    const label = document.createElement('div');
    label.className = 'label';
    label.textContent = Craft.t('accessibility-audit', 'Generate with AI');
    const spinner = document.createElement('div');
    spinner.className = 'spinner spinner-absolute';
    btn.append(label, spinner);

    const status = document.createElement('span');
    status.className = 'light smalltext accessibility-audit-alt-editor__status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    row.append(btn, status);

    const reasons = document.createElement('div');
    reasons.className = 'accessibility-audit-alt-editor__reasons';
    reasons.hidden = true;
    const heading = document.createElement('p');
    heading.className = 'smalltext warning has-icon';
    const headingIcon = document.createElement('span');
    headingIcon.className = 'icon';
    headingIcon.setAttribute('aria-hidden', 'true');
    heading.append(headingIcon);
    const strong = document.createElement('strong');
    strong.textContent = Craft.t('accessibility-audit', 'Check before saving');
    heading.append(strong);
    const reasonList = document.createElement('ul');
    reasonList.className = 'smalltext';
    reasons.append(heading, reasonList);

    const box = document.createElement('div');
    box.className = 'accessibility-audit-alt-editor__suggestion';
    box.setAttribute('role', 'region');
    box.setAttribute('aria-label', Craft.t('accessibility-audit', 'AI suggestions'));
    box.tabIndex = -1;
    box.hidden = true;
    const options = document.createElement('ul');
    options.className = 'accessibility-audit-alt-editor__options';
    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'btn small chromeless accessibility-audit-alt-editor__dismiss';
    dismiss.textContent = Craft.t('accessibility-audit', 'Dismiss');
    box.append(options, dismiss);

    root.append(row, reasons, box);
    return root;
  }

  // opts: { assetId, url, input, guideline, onUse, initial, prefill }
  // The Generate button is optional: without an API key a stored draft can
  // still be shown and used.
  //
  // With `prefill`, a stored draft's Standard version goes into an empty field
  // as soon as the panel opens, and the versions stay open to swap between.
  // Only for the plugin's own editor: on Craft's asset edit screen the change
  // would mark the element form as edited before the editor has done anything.
  //
  // Returns { undoPrefill }, which empties the field again if it still holds
  // the pre-filled text, for when the draft is discarded.
  function wire(root, opts) {
    const genBtn = root.querySelector('.accessibility-audit-alt-editor__generate');
    const status = root.querySelector('.accessibility-audit-alt-editor__status');
    const reasons = root.querySelector('.accessibility-audit-alt-editor__reasons');
    const box = root.querySelector('.accessibility-audit-alt-editor__suggestion');
    const options = box && box.querySelector('.accessibility-audit-alt-editor__options');
    const dismiss = box && box.querySelector('.accessibility-audit-alt-editor__dismiss');
    const input = opts.input;
    if (!box || !options || !input) return null;

    const labels = { short: Craft.t('accessibility-audit', 'Short'), standard: Craft.t('accessibility-audit', 'Standard'), detailed: Craft.t('accessibility-audit', 'Detailed') };
    // Each rendered option's button, its version name and its text, so the
    // one matching the field can read "In use".
    let choices = [];
    let prefilled = null;

    function setInput(text) {
      input.value = text;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function labelButton(btn, text, name) {
      btn.textContent = text;
      if (name) {
        const hidden = document.createElement('span');
        hidden.className = 'visually-hidden';
        hidden.textContent = ' (' + name + ')';
        btn.append(hidden);
      }
    }

    function markInUse() {
      const current = input.value.trim();
      choices.forEach((c) => {
        const inUse = current !== '' && c.alt.trim() === current;
        if (c.btn.disabled === inUse) return;
        c.btn.disabled = inUse;
        labelButton(c.btn, inUse ? Craft.t('accessibility-audit', 'In use') : Craft.t('accessibility-audit', 'Use this'), c.name);
      });
    }

    function use(text) {
      setInput(text);
      if (!opts.prefill) box.hidden = true;
      input.focus();
      if (opts.onUse) opts.onUse(text);
    }

    function render(data) {
      const list = (data.variants && data.variants.length) ? data.variants : [{ key: '', alt: data.alt }];
      choices = [];

      options.replaceChildren(...list.map((v) => {
        const li = document.createElement('li');
        li.className = 'accessibility-audit-alt-editor__option';

        const head = document.createElement('p');
        head.className = 'smalltext';
        const name = labels[v.key] || '';
        if (name) {
          const strong = document.createElement('strong');
          strong.textContent = name;
          head.append(strong, ' · ');
        }
        const count = document.createElement('span');
        count.className = 'light';
        count.textContent = lengthNote(v.alt, opts.guideline);
        head.append(count);

        const text = document.createElement('p');
        text.className = 'accessibility-audit-alt-editor__option-text';
        text.textContent = v.alt;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn small';
        labelButton(btn, Craft.t('accessibility-audit', 'Use this'), name);
        btn.addEventListener('click', () => use(v.alt));
        choices.push({ btn: btn, name: name, alt: v.alt });

        li.append(head, text, btn);
        return li;
      }));
      markInUse();

      if (reasons) {
        const ul = reasons.querySelector('ul');
        ul.replaceChildren(...(data.reviewReasons || []).map((r) => {
          const li = document.createElement('li');
          li.textContent = r.label;
          return li;
        }));
        reasons.hidden = ul.children.length === 0;
      }
    }

    input.addEventListener('input', markInUse);

    // A stored draft has Discard draft beside it where the editor offers one,
    // which already puts it away, so Dismiss is only shown for fresh results.
    const showDismiss = (fromDraft) => {
      if (dismiss) dismiss.hidden = fromDraft && !!root.querySelector('.accessibility-audit-alt-editor__discard');
    };

    if (opts.initial) {
      render(opts.initial);
      box.hidden = false;
      showDismiss(true);

      if (opts.prefill && input.value.trim() === '' && opts.initial.alt) {
        prefilled = opts.initial.alt;
        setInput(prefilled);
      }
    }

    if (genBtn) genBtn.addEventListener('click', async () => {
      genBtn.disabled = true;
      genBtn.classList.add('loading');
      if (status) status.textContent = Craft.t('accessibility-audit', 'Writing three versions. This takes a few seconds.');

      try {
        const fd = new FormData();
        csrfAppend(fd);
        fd.append('assetId', opts.assetId);
        fd.append('variants', '1');
        const res = await fetch(opts.url, {
          method: 'POST',
          body: fd,
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();

        if (data.success) {
          render(data);
          box.hidden = false;
          showDismiss(false);
          box.focus();
        } else if (Craft.cp) {
          Craft.cp.displayError(data.error);
        }
      } catch (e) {
        if (Craft.cp) Craft.cp.displayError(Craft.t('accessibility-audit', 'Network error. Check your connection and try again.'));
      } finally {
        genBtn.disabled = false;
        genBtn.classList.remove('loading');
        if (status) status.textContent = '';
      }
    });

    if (dismiss) dismiss.addEventListener('click', () => {
      box.hidden = true;
      if (reasons) reasons.hidden = true;
      (genBtn || input).focus();
    });

    return {
      undoPrefill: () => {
        if (prefilled !== null && input.value === prefilled) setInput('');
        prefilled = null;
      },
    };
  }

  window.AccessibilityAuditAltSuggestions = { build: build, wire: wire };
})();
