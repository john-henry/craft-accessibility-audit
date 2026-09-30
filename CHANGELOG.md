# Release Notes for Accessibility Audit

## Unreleased

### Added
- An optional Organisation Name, shared by the VPAT and the accessibility statement. It goes in the title of the exported VPAT and the OpenACR file.
- The accessibility statement names the organisation, where one is given, as the one committed to accessibility.

### Changed
- The exported VPAT prints in landscape, the way published conformance reports do.
- Drafted remarks no longer claim "all" or "every" for the whole site unless the evidence covers the whole of it.
- The statement's "Website or organisation name" field is now "Website name".

## 1.5.0 - 2026-09-25 [CRITICAL]

### Added
- German, French, Spanish, Italian and Dutch translations of the control panel and the VPAT export. Scan findings are still in English.
- A Readability view in the Preview menu of every entry the scanner covers, on Pro. It marks hard sentences, adverbs, the passive voice and plainer alternatives as you write. English sites only.
- Suggest plainer wording in the Readability preview, using Claude. Needs an Anthropic API key and the Run scans permission.
- A target readability (Accessible, Default or Technical) under Settings > General, which anyone can change for themselves in the preview.
- Analyse every page on the Readability page, which scores every page the scanner covers in the background.
- Readability is recorded when an entry is saved, on every site the entry is on.
- Checkboxes and a Re-analyse selected action on the Readability page's results table.
- Hard and Very hard columns on the Readability page, counting the sentences the Readability preview would mark on each page.
- A Commerce product's readability includes its variants' text.
- The CI endpoint returns `targetConfigured` alongside `passing`.

### Changed
- The Readability page puts its results table first, with failing pages at the top, and Analyse every page moves to the top of the page.
- Analyse every page also scores the Additional URLs on the site.
- The Readability table shows Pass or Fail with the grade, in place of its Ease and WCAG 3.1.5 columns.
- Permission handles are now kebab-case: `accessibility-audit:view-reports`, `accessibility-audit:run-scans`, `accessibility-audit:manage-vpat` and `accessibility-audit:manage-statement`. A migration carries existing grants over; update any code that checks the old handles.
- The homepage is now excluded with the pattern `^$`, and a blank pattern matches nothing. Blank rows are rewritten on update, but change any blank pattern in `config/accessibility-audit.php` to `^$` by hand.
- Excluded URI Patterns and Additional URLs store their site by UID. A migration converts existing rows; in `config/accessibility-audit.php`, use `siteUid` instead of `siteId`.
- Site Target Score defaults to 90 on new installs. Existing installs keep the target they had, including none.
- axe-core goes from 4.9.1 to 4.13.0, so expect scores to move on the next scan: two new checks (ARIA tabs and disclosure summaries), more accurate contrast where elements stack, and fewer false positives on web components.
- The public accessibility statement is published in English on every site until its translations have been checked by a native speaker.
- Trashed, disabled and archived pages no longer count towards the score, the listings or the CI check.
- Scans only run on elements the person asking can view.
- The sidebar's Readability tab shows the stored result and links to the report; checking text as you write is done in the Readability preview.
- Analysing a URL on the Readability page scores one of your own entries from its text, the same way saving does.
- The private-address check now comes from the shared `johnhenry/craft-ip-guard` package, version 1.3 or later.
- `audit/prune-excluded` now defaults its confirmation to no.
- `audit/scan-all` reads pages in batches, so it no longer runs out of memory on large sites.
- The overview, listings and exports are faster on large sites, and so are bulk restores and bulk decorative marking.

### Removed
- Analyse a page on the Readability page. Pages that aren't entries are scored through Additional URLs.
- The "elements need manual review" list under color contrast in the page report. Contrast that can't be measured is in Needs review, where it can be answered.

### Fixed
- Re-analysing a page that isn't an entry no longer drops it from the Readability table.
- Browser checks no longer restart in a loop on slow pages.
- Highlights in the page report and the front-end overlay are no longer cut off inside boxes that hide their overflow, like quantity steppers, and no longer shift positioned elements.
- Highlights in the page report and the front-end overlay now show on any background, red and dark ones included, and blink as they were meant to.
- In the page report, Show on page and clicking a single occurrence now highlight just that element.
- Highlighting in the page report now scrolls the preview, not the whole control panel page.
- Ticking a group's checkbox in Needs review now enables Dismiss selected.
- An occurrence found in the other view now says to switch to Desktop or Mobile to see it, and names the template it actually came from.
- The page report's HTML view now marks the whole element for each occurrence, and the right one, rather than the first matching tag in the source.
- Occurrence cards show the element with the start of its text, not just its opening tag.
- The page report no longer boxes every element a rule could apply to when an issue is opened or the preview reloads.
- The CI endpoint answers an unknown site ID with a bad request instead of a failing score.
- Listing endpoints and the rule trend cap how much a single request can ask for.
- Invalid regular expressions are rejected when saving Excluded URI Patterns.
- The score-drop notification threshold can no longer be set to 0.
- Long VPAT, statement and organisation text is no longer cut short or refused. Run `craft migrate/up` after updating.
- Very short alt text and the readability minimum are counted in characters, so they work in every writing system.
- Contrast that can't be measured because of an unreadable colour format now says so.
- The colour-vision simulator's hex field and the charts' data tables have proper labels.
- The exported VPAT declares its language, and its dates follow the site's language.
- The Accessibility Audit nav item no longer shows for people who can't open any of it.
- The plugin's permissions now work in console commands and queue jobs.
- `craft.a11y.scan()` and `craft.a11y.issues()` accept any element the scanner covers, not only entries.
- The score widget says which site it's for, and shows the site the plugin actually reports on.
- New rows in Excluded URI Patterns and Additional URLs are switched on by default.
- The settings now fire Craft's `defineRules` event.
- The statement preview no longer switches to a dark colour scheme.
- Uninstalling removes the score widgets and the plugin's queued jobs.
- A score widget that can't render no longer takes the dashboard down.
- A failure to queue a scan or alt-text draft can no longer fail an entry save or an image upload.
- Identical links hidden with an inline `visibility:hidden` are no longer reported as duplicates.
- On multi-site installs, each site's statement and VPAT now show that site's own details instead of the primary site's. Check the statement on each site.
- Scan progress is announced once, from a status message.
- Craft's temporary uploads are no longer audited, and rows already recorded for them are removed on update.
- The "decorative" label on the Images screen now meets contrast requirements.
- The VPAT screen respects reduced motion.
- Console commands refuse a `--site` handle that names no site.
- `audit/prune --days=0` keeps all history instead of deleting it.
- `audit/scan-element` says when an element has no page, is excluded or is over the page limit.
- Statement entries are validated when saved, and a disproportionate burden needs a reason.
- Empty statement entries are left out of the published statement.
- Allowed origins for the decoupled overlay are validated when saved.
- An excluded selector that matches `html` or `body` no longer empties the scan.
- The front-end overlay now speaks the language its text was translated into, and every label, button and status message in it is now translatable.
- The overlay's close button now meets the minimum touch target size.
- Severity and pass indicators in the overlay are hidden from screen readers, matching the control panel.
- A landmark with both `aria-labelledby` and `aria-label` is reported under the right one.
- A control labelled by an `aria-hidden` element is no longer reported as having no name.
- Alt text generation gives up after 30 seconds if the API stops responding.
- A remote browser whose handshake arrives in pieces is no longer treated as unreachable.
- Site-wide scans log a page they can't read and carry on with the rest.
- Scan All Pages no longer starts a second sweep of a site that is already being scanned.
- Scan All Pages and Analyse every page no longer stay locked after a failed or deleted job.
- Drafts are no longer scanned as pages of their own.
- The sidebar shows readability dates in the right time zone.
- Pages the scanner can't fetch are now logged in the plugin's own log.
- An Anthropic API error that isn't JSON no longer breaks the error handling.
- Images uploaded during an asset sweep no longer shift the pages after them.
- The Anthropic API key setting now points to platform.claude.com for a key, not the old console address.
- With devMode on, JSON and other non-HTML responses no longer carry the plugin's template markers, which made them unreadable.

### Security
- Fixed a stored XSS through issue help links, which could run script in the control panel.
- Fixed CSS injection through contrast colours in the page report.
- The SSRF guard's own-site exemption now needs the site's exact scheme, host and port, and is off when `@web` is taken from the request.
- Slack notifications and local image fetches connect only to the address that was checked, and don't follow redirects.
- IPv4 addresses written inside IPv6 ones (mapped, NAT64, 6to4) are now caught by the SSRF guard, along with multicast, broadcast and documentation ranges.
- Slack webhook URLs are now checked by the SSRF guard.
- Outbound requests verify TLS certificates everywhere except devMode, Craft Cloud included.
- Saving settings no longer copies values from `config/accessibility-audit.php`, secrets included, into project config.
- Pages and images are read up to a size limit, so a huge response can't exhaust memory.
- CSV exports send `X-Content-Type-Options: nosniff`.
- VPAT remark drafting is rate limited per person and sends at most 5,000 characters of notes.
- Filenames and titles sent with alt text requests are marked as information, not instructions.

## 1.4.0 - 2026-09-15

### Added
- Export the VPAT as an OpenACR YAML file beside the HTML export. It needs a contact email set. See https://johnhenry.ie/plugins/accessibility-audit/docs/reporting-compliance/vpat-report

### Changed
- The Support tab now says plainly that the checks are run by axe-core, with the plugin's own checks added on top.

### Fixed
- Saving the General, Maintenance, Tools or Notifications settings no longer empties Excluded URI Patterns, Additional URLs and Ignored Rule IDs. Put back anything lost under Settings > Scanning.
- Pages matched by Excluded Pages no longer show the Accessibility panel in their edit screen.
- Re-scanning an excluded page now says the page is excluded.
- The frontend overlay no longer appears on pages matched by Excluded Pages, decoupled front ends included.

## 1.3.0 - 2026-09-10

> [!IMPORTANT]
> Run `craft migrate/up` after updating. It also applies a migration from 1.2.1 that never ran, which fixes scanning by URL on installs that started on 1.2.0 or later.

### Added
- `vpat/revisions`, `vpat/delete-revision` and `vpat/clear-revisions` console commands for managing the VPAT's revision history.
- Undo the most recent recorded revision from the VPAT editor.
- A next review date on the statement, printed beside the date it was last reviewed.
- The exported VPAT gives the EN 301 549 clause beside each WCAG criterion on reports that claim the European standard.
- The exported VPAT includes a revision history, recorded with a button in the VPAT editor.
- The exported VPAT is translatable, and is written in the language of the site it describes.
- The published accessibility statement is translatable.

### Changed
- Additional URLs is now a table. Existing lines are carried over, and commented-out ones come across switched off.
- Each Additional URL can be switched off or limited to one site.
- Dates on the published statement follow the site's language, so check your statement after updating.

### Fixed
- Dev mode no longer adds template markers to `{% css %}` and `{% js %}` output, which was dropping CSS rules on local sites.
- Scan All Pages now includes the Additional URLs listed under Settings.
- The published statement no longer shows a stray comma when the site's profile names no legislation.
- Exclusions on the statement no longer end with a double full stop.
- The exported VPAT's conformance table breaks across printed pages and repeats its header.
- The VPAT's Back and Print buttons no longer end up in saved or converted documents.
- The statement no longer accepts a future date for when it was prepared or last reviewed.
- The VPAT no longer accepts a future report date or evaluation period, and the period has to end on or after it starts.

## 1.2.2 - 2026-09-09

### Added
- Score history charts Level A and Level AA as well as the overall score.
- Score history shades the gap to your target, green above it and amber below.
- Score history says in words how much the score moved over the period.

### Changed
- The note about unanswered questions counts the kinds of question as well as the total.
- The alt text panel's three states share one layout and link, coloured red, amber or green.
- Score history now sits further down the Overview, beside Resolved issues.

### Fixed
- Markup inside a `<noscript>` is no longer scanned as part of the page. ([#13](https://github.com/john-henry/craft-accessibility-audit/issues/13))
- The Score history panel now has a proper heading.

## 1.2.1 - 2026-09-07

### Fixed
- Markup inside a `<template>` is no longer scanned as part of the page. ([#12](https://github.com/john-henry/craft-accessibility-audit/issues/12))
- Words inside a `<template>` no longer count towards the readability score.
- Scanning a page by its URL no longer fails on fresh installs. Update to 1.3.0 or later for this to take effect.

## 1.2.0 - 2026-08-30

> [!IMPORTANT]
> Extending this plugin, or reading its tables directly? Some public service signatures and two database columns changed. See [UPGRADE.md](https://github.com/john-henry/craft-accessibility-audit/blob/craft-5/UPGRADE.md).

> [!WARNING]
> Scan history older than **Retain Scan Results** (90 days by default) is deleted at the first garbage collection after this update. To keep it, raise the setting first, or set it to 0 on Pro.

### Added
- The Overview shows how many questions are still waiting on an answer, with a link to them.
- The Overview marks an all-clear with a tick once nothing is failing and every question is answered.
- A confetti button beside the all-clear, hidden from anyone who prefers reduced motion.
- The Overview shows when a scan is running and how far through it is.
- A VPAT remark shows when the findings count has changed since it was written.
- VPAT remark drafting works on criteria with no findings, saying what testing has and hasn't covered.
- Every VPAT criterion shows what the scans covered and what's left to check by hand.
- Browser findings say why the element failed, not only which rule it broke.
- Long alt text findings give the length and how far over 150 characters it is.
- The alt text field on the Assets page and the asset edit screen counts characters as you type.
- A new rule for table cells, links and buttons whose whole name is a symbol, like a tick or a cross.
- Questions that only come up at desktop or mobile width say which.
- A check for HTML that renders inside `<code>` instead of showing as text.
- Contrast checks for hover, focus and text selection, as the rules `contrast-hover`, `contrast-focus` and `contrast-selection`.
- `craft.a11y.accessibilityStatementHtml()` takes `headingLevel` and `title` options.
- AI alt text works on SVGs.
- `craft.a11y.isDecorative(image)` and `craft.a11y.decorativeAssetIds()` for front-end templates.
- The Statement and the VPAT say which scan their figures came from.
- A **Save all drafts** button on the Assets page. ([#6](https://github.com/john-henry/craft-accessibility-audit/issues/6))
- A check for block content nested inside a paragraph.
- Scan pages with no element behind them by listing them under Additional URLs, or with `craft accessibility-audit/audit/scan-url`.

### Changed
- No VPAT criterion is set to Supports by the scanner any more: 2.4.2, 3.1.1, 1.4.3, 1.4.11 and 2.5.8 now wait for your answer. Answers already given stand.
- Redrafting a VPAT remark takes its numbers from the current findings. Clear an out-of-date remark before redrafting it.
- Drafted VPAT remarks follow the way published conformance reports are written.
- A question that comes up at both desktop and mobile width is asked once.
- Repeated occurrences of the same question are grouped into one card in the review queue.
- The identical links check grades each link by how well its surroundings tell it apart.
- Where a link sits in an unnamed region, the report suggests naming the region as a fix.
- Identical links findings say which kind of problem was found and list the fixes in order.
- Running a readability analysis needs the **Run scans** permission instead of **View reports**. Give Run scans to editors who analyse pages.
- Opening Readability from an entry's accessibility panel fills in the page URL. ([#4](https://github.com/john-henry/craft-accessibility-audit/issues/4))
- The accessibility panel sits at the top of the element sidebar.
- **Edit element** on a page report opens in a new tab. ([#2](https://github.com/john-henry/craft-accessibility-audit/issues/2))

### Fixed
- Answered contrast questions no longer come back after the next scan.
- Contrast answers given from the page report stay answered.
- Clicking Show on page no longer turns an answered question into a new one.
- Dismissals stick on pages built with Formie, or anything else that generates fresh ids. Existing dismissals are carried over.
- The statement no longer says nothing has been scanned on a clean, fully scanned site.
- The Overview's "Fix these issues" heading counts what's actually listed.
- The statement and the VPAT no longer count answered questions or fixed issues against a criterion.
- The statement and the VPAT include findings from every page scanned by URL.
- Drafted VPAT remarks no longer count questions you've already answered.
- An entry whose URL redirects elsewhere is no longer scanned as a page of its own.
- A page report always shows the latest scan.
- Listings show each page's address as well as its title.
- The Pages with Issues tab only lists pages that have issues.
- The Issues tab tells a site that passed apart from one that hasn't been scanned.
- Contrast questions are no longer raised about text outside the area the scanner could see.
- Contrast questions are no longer raised about text that isn't visible.
- Visually hidden text is no longer reported as a contrast failure.
- Contrast questions give the reason they were asked.
- The alt text field on the Assets page no longer stops at 125 characters.
- A page that redirects off the site is skipped, and the scan says so.
- A page that redirects is recorded against the address it ends on.
- Missing or broken pages are skipped, and the report gives the status code.
- An entry whose page couldn't be read no longer scores 100.
- Rule pages describe the rule instead of quoting one page's findings.
- Show on page finds elements whose markup was too long to store whole.
- Dismissing a whole group is faster.
- A group dismissal that fails part way says how many were saved instead of showing a blank error page.
- Adding a non-accessible content entry from a scan suggestion leaves the description for you to write.
- Deleted pages no longer count towards the Standard edition's page limit.
- Twelve control panel strings that stayed in English on translated installs are now translated.
- Markup with `aria-hidden="true"` is no longer checked for contrast, and findings already recorded against it are cleared on update.
- The identical links check compares what's announced, so links with distinct aria-labels are no longer reported.
- Alt text that matches the image's filename is caught on the page as well as on the asset.
- A link that warns about a new tab in visually hidden text is no longer reported as missing the warning.
- Trashed images no longer count towards the missing alt text figure on the Assets page.
- AI alt text works on images over 8000 pixels on a side. ([#3](https://github.com/john-henry/craft-accessibility-audit/issues/3))
- Links are judged on the name a screen reader announces, aria-label included. ([#7](https://github.com/john-henry/craft-accessibility-audit/issues/7))
- Links named by an SVG title or `aria-labelledby` are no longer reported as having no name.
- Contrast findings on repeated markup each point at their own element.
- The Inspect view no longer boxes the wrong element when two share an attribute.
- Vague link text followed only by a new-tab notice is reported again.
- The Inspect view frames only the links a link finding is about. ([#7](https://github.com/john-henry/craft-accessibility-audit/issues/7))
- AI alt text for screenshots describes the interface, not the pictures inside it. ([#10](https://github.com/john-henry/craft-accessibility-audit/issues/10))
- AI alt text stays within its 125 character limit.
- The Generate button on an asset gets the same context as queued alt text jobs.
- The overlay in Craft's preview pane no longer saves results against the draft. ([#9](https://github.com/john-henry/craft-accessibility-audit/issues/9))
- Text that only appears on hover is no longer checked in its hidden state.
- Pages that load their full stylesheet after inline critical CSS no longer get false contrast failures. Findings already recorded are cleared on update.
- Re-scanning as soon as a page report opens no longer records findings from the blank preview.
- **Retain Scan Results** now deletes old scan history on its own during Craft's garbage collection. See the warning above.
- The Overview score stops at 99 unless every page is clean.
- Re-scan on a page report checks both desktop and mobile widths.
- The accessibility panel on an entry no longer scrolls sideways on narrow screens. ([#5](https://github.com/john-henry/craft-accessibility-audit/issues/5))

### Security
- Outbound fetches connect only to the addresses the SSRF check approved, and every redirect is checked.

## 1.1.0 - 2026-08-21

First stable release. No breaking changes and no migrations, so update from any beta build.

### Added
- **Dismiss selected** on a page report's needs-review queue, for answering several occurrences at once.

### Changed
- Not an issue and Confirm as failure now sit side by side, with equal weight, on the needs-review cards.

### Fixed
- Show on page now works for alt text questions.

## 1.0.11-beta.1 - 2026-08-21

### Added
- Pro: the admin overlay works on decoupled front ends, with a script tag and a token from **Settings → Tools**. See the Decoupled Frontends page in the docs.
- Common cookie consent banners, such as OneTrust and Cookiebot, are left out of every scan.
- An **Excluded Elements** setting under **Settings → Scanning** for CSS selectors to leave out of scans.

### Fixed
- A finding whose element can't be found in the Inspect preview no longer highlights every element the rule could apply to.
- Browser findings describe the requirement that failed, not axe's rule summary. Applies to new scans.
- The frontend overlay's Highlight scrolls to a match that's on screen.
- Highlighting an element inside a collapsed menu or panel now says where it is.
- The site's own styles no longer leak into the frontend overlay panel.
- Findings about something missing from the page, like a skip link, no longer highlight unrelated elements.
- The Inspect preview highlights the right link when two links share a URL.
- Findings about the document itself, like a missing page title, no longer try to highlight anything.
- Occurrences on elements with no attributes show a short text preview.
- Not an issue and Confirm as failure now stick on findings whose snippet spans several lines. Redo any that bounced back.
- Settings pages open read-only when admin changes are disabled, instead of refusing to open.
- Empty `id=""` attributes are no longer reported as duplicate ids.
- Show on page boxes every copy of repeated content and scrolls to a visible one.
- Show on page for an image question no longer highlights every image sharing an upload path.

## 1.0.10-beta.1 - 2026-08-21

### Fixed
- The Accessibility Audit link on **Settings → Plugins** opens the plugin settings instead of a 404. ([#1](https://github.com/john-henry/craft-accessibility-audit/issues/1))

## 1.0.9-beta.1 - 2026-08-13

### Added
- A **Browser Settle Time** setting under **Settings → Scanning** for how long the browser pass waits after a page loads. It defaults to 2 seconds.

### Changed
- The browser pass checks desktop and mobile in one Chrome session, so site-wide scans are faster.

### Fixed
- Queued site-wide scans on large sites no longer skip some pages and scan others twice.

## 1.0.8-beta.1 - 2026-08-12

### Fixed
- Server-side browser scans no longer crash on servers with limited shared memory.

## 1.0.7-beta.1 - 2026-08-07

### Added
- A **Remote Chrome Endpoint** setting under **Settings → Scanning** to run the browser pass on a Chrome elsewhere, which is how to get browser scanning on Craft Cloud. Keep the URI in an environment variable if it carries a token.
- Contrast that axe-core can't measure goes under **Needs review** instead of being dropped.

### Changed
- `storeAxeIssues()` takes an optional fourth argument for axe's undecided results.

### Fixed
- The contrast check reads modern CSS colour syntax, `oklch` included, so Tailwind 4 sites get correct results.

## 1.0.6-beta.1 - 2026-07-27

### Fixed
- The statement preview and the published statement no longer fail with a template loading error.

## 1.0.5-beta.1 - 2026-07-27

### Fixed
- Add an entry and the scan-suggestion chips on the statement now add the entry when the list is empty.

## 1.0.4-beta.1 - 2026-07-27

### Changed
- Bulk resaves, like the resave commands and migrations, no longer queue a scan per element. Use Scan All for a site-wide sweep.

### Fixed
- A scan no longer fails on pages with multibyte characters near a truncation point.
- Saving an entry no longer queues duplicate scans for its revision.

## 1.0.3-beta.1 - 2026-07-27

### Fixed
- Page report highlights no longer change the element's background.
- The contrast check ignores the page report's own highlights and badges.
- The page report's contrast and axe checks wait for stylesheets added by JavaScript.

## 1.0.2-beta.1 - 2026-07-27

### Added
- Colour contrast findings in the page report show the failing element's markup.

### Fixed
- Page report highlighting finds the exact elements for duplicate id and colour contrast findings. Re-scan a page to update stored contrast findings.

### Security
- Pages rendered for a logged-in admin with the frontend overlay on are no longer cacheable, so Blitz or a CDN can't serve the overlay to visitors.

## 1.0.1-beta.1 - 2026-07-27

### Changed
- Updated the license type.

## 1.0.0-beta.1 - 2026-07-12

### Added
- Initial release.
