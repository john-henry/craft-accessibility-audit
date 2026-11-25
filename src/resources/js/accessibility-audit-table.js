/**
 * Thin wrapper around Craft's VueAdminTable.
 *
 * Every table in this plugin wants the same things: the plugin's padding
 * defaults, a bulk re-scan action, and an "Export CSV" button. Craft's table
 * gives us the first two only via a workaround for a bug in Craft itself, so
 * this file owns them once instead of once per template.
 *
 * Usage:
 *
 *   AccessibilityAuditTable.create({
 *       container: '#accessibility-audit-sp-table',
 *       endpoint: 'accessibility-audit/dashboard/scanned-pages-table',
 *       siteId: 1,
 *       columns: [...],
 *       emptyMessage: '...',
 *       exportUrl: '...',
 *       buttons: [{label: '...', icon: 'download', href: '...'}],
 *   });
 */
(function () {
    'use strict';

    /* Craft's table posts a menu action as soon as it is clicked and offers no
       confirmation for one, so a destructive action is asked about here first.
       Listening in the capture phase puts this ahead of the table's own click
       handler, and stopping the event there means a cancelled click never
       reaches it. */
    document.addEventListener('click', function (e) {
        var item = e.target && e.target.closest ? e.target.closest('.accessibility-audit-confirm-remove') : null;

        if (!item) {
            return;
        }

        var scope = item.closest('.vue-admin-table') || document;
        var n = scope.querySelectorAll('tbody .checkbox.checked, tbody input[type="checkbox"]:checked').length;
        var question = n > 0
            ? Craft.t('accessibility-audit', '{n, plural, =1{Remove the scan data for this page?} other{Remove the scan data for these # pages?}}', { n: n })
            : Craft.t('accessibility-audit', 'Remove the scan data for the selected pages?');
        var warning = Craft.t('accessibility-audit', 'Their scores, issues and scan history are deleted and cannot be brought back. A page that can still be scanned comes back on its next scan, so exclude it under Settings → Scanning first if it should stay out.');

        if (!window.confirm(question + '\n\n' + warning)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    window.AccessibilityAuditTable = {
        /**
         * A rule's name for a table cell: its title, linked where a URL is
         * given, with the rule ID after it in small code type so developers
         * can still match it. The same markup as the ruleName Twig macro.
         */
        ruleName: function (title, ruleId, url) {
            var name = Craft.escapeHtml(title || ruleId || '');
            var label = url ? '<a href="' + Craft.escapeHtml(url) + '">' + name + '</a>' : '<span>' + name + '</span>';

            return title && ruleId && title !== ruleId
                ? label + ' <span class="light code smalltext">' + Craft.escapeHtml(ruleId) + '</span>'
                : label;
        },

        /**
         * Builds a VueAdminTable with this plugin's defaults and extras.
         */
        create: function (opts) {
            var settings = {
                columns: opts.columns,
                container: opts.container,
                checkboxes: opts.checkboxes !== false,
                search: opts.search !== false,
                padded: true,
                /* Matches DashboardController::TABLE_PER_PAGE. The table sends
                   this as per_page, so a mismatch means the server pages by one
                   size while the pagination is drawn for another. */
                perPage: opts.perPage || 100,
                emptyMessage: opts.emptyMessage,
                tableDataEndpoint: opts.endpoint + '?siteId=' + opts.siteId +
                    (opts.endpointParams ? '&' + opts.endpointParams : ''),
            };

            if (opts.onLoaded) {
                settings.onLoaded = opts.onLoaded;
            }

            // Actions always live in a menu, never as a lone action button:
            // there is more than one bulk action (re-scan on page listings,
            // restore on the Dismissed tab), and the menu binds the click correctly.
            //
            // opts.bulkActions replaces the default re-scan for tables whose
            // rows are not pages to scan (e.g. the Dismissed tab restores
            // verdicts). Each entry is {label, action}; siteId is forwarded
            // for all of them since every action here is site-scoped. An
            // optional `icon` fills the slot Craft's menu markup reserves;
            // see .accessibility-audit-menu-icon in the stylesheet.
            const bulkActions = opts.bulkActions || (opts.bulkRescan !== false ? [{
                label: Craft.t('accessibility-audit', 'Re-scan selected'),
                action: 'accessibility-audit/audit/scan-entries',
                icon: 'refresh',
            }, {
                label: Craft.t('accessibility-audit', 'Remove scan data'),
                action: 'accessibility-audit/audit/remove-scans',
                icon: 'trash',
                destructive: true,
            }] : []);

            if (bulkActions.length) {
                settings.actions = [{
                    label: Craft.t('app', 'Actions'),
                    actions: bulkActions.map((a) => ({
                        label: a.label,
                        action: a.action,
                        param: 'siteId',
                        value: opts.siteId,
                        ajax: true,
                        // Object, not a string: the component spreads this into
                        // Vue's class binding, and a string would spread to one
                        // class per character.
                        ...(a.destructive ? { separator: true } : {}),
                        ...(a.icon || a.destructive ? {
                            class: {
                                'accessibility-audit-menu-icon': !!a.icon,
                                ['accessibility-audit-menu-icon--' + a.icon]: !!a.icon,
                                // Craft's own red for a menu item that deletes.
                                'error': !!a.destructive,
                                'accessibility-audit-confirm-remove': !!a.destructive,
                            },
                        } : {}),
                    })),
                }];
            }

            // Plain anchors, which is all Craft's `buttons` are: each downloads
            // its file straight from an action and needs no JS. opts.buttons
            // adds more beside Export CSV, each {label, icon, href}.
            const buttons = (opts.buttons || []).slice();

            if (opts.exportUrl) {
                buttons.unshift({
                    label: Craft.t('accessibility-audit', 'Export CSV'),
                    icon: 'download',
                    href: opts.exportUrl,
                });
            }

            if (buttons.length) {
                settings.buttons = buttons;
            }

            return new Craft.VueAdminTable(settings);
        },
    };
})();
