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
 *   });
 */
(function () {
    'use strict';

    window.AccessibilityAuditTable = {
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
                        ...(a.icon ? {
                            class: {
                                'accessibility-audit-menu-icon': true,
                                ['accessibility-audit-menu-icon--' + a.icon]: true,
                            },
                        } : {}),
                    })),
                }];
            }

            // A plain anchor, which is all Craft's `buttons` are: it downloads
            // the full CSV straight from the export action and needs no JS.
            if (opts.exportUrl) {
                settings.buttons = [{
                    label: Craft.t('accessibility-audit', 'Export CSV'),
                    icon: 'download',
                    href: opts.exportUrl,
                }];
            }

            return new Craft.VueAdminTable(settings);
        },
    };
})();
