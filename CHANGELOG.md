# Release Notes for Accessibility Audit

## 2.0.0 - 2026-11-25

> [!IMPORTANT]
> Things to check when you update:
> - The plugin now has three editions: Lite, Standard and Pro. New installs start on the free Lite edition, and existing sites keep the edition they're on.
> - `getAssets()->scanAsset()` is gone. Read PDF results from `getPdfAudit()`, and image alt text issues from the Assets listing methods.
> - The `accessibility-audit/alt/save` controller action is gone. Alt text is saved through `accessibility-audit/alt/save-screen` now.
> - The `AuditService::STANDARD_SCAN_LIMIT` and `AuditService::STANDARD_RETENTION_CAP` constants are gone. Read the caps from the plugin's `scanLimit()` and `retentionCap()`.
> - Generate for New Assets now saves its drafts for review instead of writing alt text to the image. Change the New uploads setting if you want alt text saved straight to the image.

### Added
- PDF checks, on Standard and Pro. Every PDF in the asset library is checked for the failures a machine can settle for itself: tags, language, title, form field names, images with no alt text, security settings that block screen readers, and a few more.
- With server-side browser scanning set up, PDFs also get their structure checked: headings, tables, lists, links, and alt text that says nothing.
- The Assets page lists each PDF once with the checks it fails, forms first, under All issues, Need fixing, Couldn't be checked and Passed, with a filter by problem.
- Each PDF has its own page listing every failing check with how to fix it, a sidebar with the file's details and a summary of the checks it fails and passes, and a Re-check button. Files that couldn't be checked say why.
- PDFs are checked when they're uploaded and when the file is replaced.
- Check all PDFs on the Assets page, and the `accessibility-audit/audit/check-pdfs` console command, check the PDFs already in the library.
- Check PDF Files and Largest PDF to Check (MB) settings, under Settings → Scanning.
- Draft alt text on the Assets page has Claude draft alt text in the background for every image missing it, in one volume or the lot. It asks first, and the drafts are saved for review, not to the images.
- A Drafts to review source on the Assets page lists the waiting drafts. Review opens each one with its Standard version already in the Alt text field and the other versions and notes beside it, so you can save it, swap it, edit it, or discard the draft.
- On the Assets page, an image with a draft waiting shows Craft's draft icon and a Review button, and its own edit screen opens with the draft ready to pick from.
- Approve drafts, under Drafts to review, saves the Standard version as alt text on every selected image Claude raised nothing about. Flagged drafts are left for you to check.
- The VPAT screen tells you how many criteria still need an answer or a remark.
- Export OpenACR and Export HTML ask before exporting a VPAT that still has gaps.
- An info icon beside the Overview's average page score explains how pages are scored.
- Every rule now has a short title in plain words, with its rule ID beside it in small type.
- A rule's page has a How to fix pane: plain advice for the plugin's own rules, and a link to Deque's guide for axe-core rules.
- Each page listed on a rule's page expands to show that rule's occurrences on it, with the markup and selector.
- The frontend overlay shows the lowest contrast ratio found for a contrast issue, with its text and background colours.
- The Issues tab can be sorted by occurrences and points, and searched by title or rule ID.
- Download fix guide, on Standard and Pro, gives you a Markdown to-do list for the site, a rule or a page, with an AI prompt for each developer fix.
- Tools for Craft's MCP server, on Standard and Pro with Craft 5.12 or later: an AI assistant can read the accessibility summary, the open rules and how to fix them, and a page's issues, and can re-scan a page.
- After updating, admins get a note under What's New in the control panel about the editions, PDF checks and reports.
- Remove scan data, in the Actions menu on the page tables, deletes the selected pages' scores, issues and scan history.
- Not an issue on every page, on a review question in the page report, answers the same element on every page of the site at once, including pages scanned later.
- Each site's average score and issue counts are saved once a day and kept after old scans are pruned, on every edition.
- The `accessibility-audit/audit/snapshot` console command saves today's figures for every site, for running from cron.
- A Reports screen, on Pro, with a directors summary, a fixed and new report for any period, and a list of pages below your target score, each ready to print, save as PDF or download as a CSV.
- The directors summary keeps a note from the team on what's being done, ready for the next time you run it.
- Modules can add their own reports through `ReportService::EVENT_REGISTER_REPORTS`.
- The directors summary can be emailed every week or month, on Pro, with the short version posted to Slack if you want it. Set it up under Settings → Notifications.
- The emailed summary comes with a PDF of it attached, ready to forward to people without a login. You can turn the attachment off.
- The `pdfPaperSize` and `pdfPaperOrientation` config settings set the summary PDF's paper, A4 portrait by default.
- The `accessibility-audit/audit/send-reports` console command sends any scheduled summary that's due, for running from cron.

### Changed
- Findings on a rule's page, in the page report and in the entry sidebar are easier to scan, with the contrast ratio picked out and the colours on their own line, each with a swatch.
- Lite, the new free edition, scans up to 25 pages on the primary site and keeps 7 days of history. It has scoring, EN 301 549, issues by rule and page, potential issues, page reports and Inspect, template and resolved issues, the admin and frontend overlays, colour tools, the accessibility statement, scan on save and the console commands.
- Standard has everything in Lite, scans any number of pages, keeps 90 days of history, and adds server-side browser scanning, AI alt text, PDF checks, email and Slack notifications, and readability.
- Pro has everything in Standard, keeps history for as long as you like, and adds multi-site scanning and reporting, VPAT conformance reporting, the CI/CD endpoint, the decoupled frontend overlay and a permanent record of resolved issues.
- Sites already on Standard get server-side browser scanning, PDF checks, notifications and readability, lose the 100-page cap, and keep 90 days of history instead of 30.
- Upgrade prompts now name the edition a feature needs, Standard or Pro.
- Edition refusals from the plugin's JSON endpoints, the CI/CD endpoint included, now carry an `editionRequired` key naming the edition needed.
- The plugin now pulls in `tecnickcom/tc-lib-pdf-parser` and `tecnickcom/tc-lib-pdf-filter` (both LGPL-3.0-or-later) through Composer, and bundles pdf.js (Apache-2.0).
- The Assets page is laid out like Craft's own asset index, with sources down the side and the search and filters in one bar.
- Add alt and Rewrite on the Assets page open the image in a side panel, with a large preview and Save and next to move down the list, instead of editing in the row.
- Generate with AI, in that panel and on an asset's own edit screen, now offers three versions to pick from (Short, Standard and Detailed) with notes on anything to check before saving, instead of writing straight into the field.
- Mark decorative and Clear decorative for selected images now sit in the footer under the list, as Craft's own actions do, and selected rows are highlighted.
- The Overview's assets panel now covers images and PDFs, counts drafts waiting for review, links each number to its list, and offers the next step.
- The All filter on the Assets page is now the All issues source, and lists the images and PDFs with something to fix.
- AI alt text aims for one short sentence ending with a full stop, follows set rules for people, logos, charts and text in images, takes account of the entry an image is used in, names well-known artworks and landmarks for you to confirm, and no longer calls artworks decorative.
- An AI draft that runs past 150 characters is flagged for you to shorten instead of being cut off part way through.
- Generate for New Assets now saves its drafts under Drafts to review by default, instead of saving alt text to the image. A new New uploads setting brings back saving straight to the image when Claude has nothing for you to check.
- Regenerating the CI or overlay token now asks before the old token stops working.
- Settings that only apply while a switch is on are hidden until it is.
- People who aren't admins see "Ask an admin to upgrade" in place of an upgrade button they can't use.
- The Overview's level cards now lead with the number of criteria failing, with the pages' average for that level underneath, and the target level says so in its label.
- Owner labels read Content, Design, Development and Technical everywhere, under one Owner heading.
- A potential issue's page is titled with its question and no longer shows points to gain.
- Columns that said Count now say Occurrences, and the Potential issues Pages tab says Question types and Needs review, matching the page report.
- About this rule now says what the rule checks, and an axe-core rule's page links to the plugin's own rule of the same name where there is one.
- The Ignored Rule IDs setting lists every rule the plugin's own checks can report, grouped by kind, and says how to name an axe-core rule.
- Browser checks for a page you re-scan yourself now run ahead of a site-wide scan already in the queue.
- Queued browser checks are skipped for a page that has been scanned again since they were queued.
- The occurrences under a page on a rule's page no longer repeat the question in the page title, and leave out the Selector column when no occurrence has one.

### Removed
- `getAssets()->scanAsset()`. PDF results come from `getPdfAudit()` now, and image alt text issues from the Assets listing methods.
- The `accessibility-audit/alt/save` controller action. The alt text panel saves through `accessibility-audit/alt/save-screen`.
- Generate all alt text and Save all drafts on the Assets page. Draft alt text and Drafts to review do the job across the whole library now.

### Fixed
- The VPAT editor's remarks box and Draft with AI button are now labelled for screen readers in the control panel's language.
- The empty Issues list on a page now shows its message in the control panel's language.
- The frontend overlay's grey text (the score label, each issue's details and the scan time) now meets the 4.5:1 contrast minimum.
- Severity and difficulty markers no longer get squashed when a rule's title wraps onto two lines.
- Server-side browser scans wait for web fonts and skip animations to their end before checking, so text caught halfway through a fade-in is no longer reported for colour contrast, and the same page gives the same results from one scan to the next.
- Occurrences in the page report can now be picked with the keyboard, not only the mouse.
- Screen readers now announce the upgrade button by the words it shows, and the locked-feature notices have an upgrade link for admins.
- Occurrence detail that fails to load in the page report now says so, instead of showing Loading… for good.
- Analyse every page on the Readability screen can be used again once the analysis finishes.
- The entry sidebar no longer shows "analysed ." when a readability result has no date.
- The dashboard widget no longer shows an empty row when there are no errors or warnings.
- Links that open in a new tab now say so to screen readers.
- Status messages, labels and criteria counts on the VPAT, page report and Colour Tools screens now show in the control panel's language.
- On multi-site installs, assets that exist in only some sites are now listed, scanned, checked and given AI alt text. From Craft 5.12, a volume set to let each asset choose its sites saves a new upload to the site it was uploaded from only.
- New uploads in a volume left out of the audit no longer get AI alt text.
- The EN 301 549 note on the Overview is now at normal text size.
- Severity dots in the issue tables are now read out to screen readers in the control panel's language.
- Re-scan, Re-analyse and View report buttons in the page tables now tell screen readers which page they're for.
- Show on page in the page report now finds the table behind a data or layout table question, and links and headings whose text was cut short or spaced differently.
- An ignored axe-core or contrast rule no longer counts against page scores and error counts, or shows on the page report and entry sidebar.
- Links, buttons, images and form fields hidden from screen readers with `aria-hidden` or `hidden` are no longer reported for a missing name, label, alt text or new-tab warning.
- A button whose only content is hidden from screen readers is now reported as having no name.
- A link marked `aria-hidden` that can still be reached with the Tab key is now reported, and one taken out of the tab order with `tabindex="-1"` no longer is.
- The page report now names the template an element came from, instead of the last partial rendered above it.
- Content written in an entry template's blocks is now traced back to that template in the page report.
- With devMode on, a layout that sets variables before its doctype no longer renders in quirks mode.
- The Overview's average score bar is now green, amber or red to match the score, instead of always orange.
- The Overview's With alt text count no longer includes decorative images, and the Assets pane says so when every image is covered.
- Show on page now finds the right element for a contrast question about a plain tag such as `<dt>` that appears more than once on the page. Re-scan a page to pick this up.
- Text under an element with a `::before` or `::after` that doesn't reach it, such as a thin line along a fixed header, is now measured for contrast instead of being sent for review.

## 1.6.0 - 2026-10-08

### Added
- An optional Organisation Name, shared by the VPAT and the accessibility statement. It goes in the title of the exported VPAT and the OpenACR file.
- The accessibility statement names the organisation, where one is given, as the one committed to accessibility.
- The Inspect report asks about stylesheet rules that remove the focus outline with nothing in its place (2.4.7).
- The server-side browser pass walks keyboard focus through each page and asks about controls that show no visible change when focused (2.4.7), on Pro.
- The same walk asks about fixed or sticky elements that completely hide a focused control (2.4.11), on Pro.
- With server-side browser scanning on, the VPAT rows for 2.4.7 and 2.4.11 show what the browser checked.
- Every VPAT row says how many questions from the scans are still waiting for an answer.

### Changed
- Repeated questions in the page report share one card: the explanation is shown once, and each element is a compact row behind a clearer toggle.
- Server-side browser passes take a bit longer per page, for the keyboard walk.
- The exported VPAT prints in landscape, the way published conformance reports do.
- Drafted remarks no longer claim "all" or "every" for the whole site unless the evidence covers the whole of it.
- The statement's "Website or organisation name" field is now "Website name".
- The conformance terms in the VPAT editor and export use the ITI template's own definitions, and the scope note is printed in full.
- With the front-end overlay on, every site page loads a small script, and admins get a functional `a11yOverlay` cookie.

### Fixed
- The front-end overlay now shows for admins on pages served from a full-page cache such as Blitz. Clear the cache once after updating.
- Answering a group of repeated questions with Not an issue or Confirm as failure no longer fails with an error.
- Without a server-side browser set up, the VPAT no longer says the colour contrast and target size checks covered every page. It says they covered the pages viewed in the Inspect preview or with the front-end overlay.
- VPAT criterion 2.4.11 is now Focus Not Obscured (Minimum), as WCAG 2.2 has it. It was listed under an old draft name and described a different check, so if you've answered 2.4.11 already, have another look at it.
- Ignored Rules now covers the potential-issue checks as well.
- Questions dismissed on a page no longer come back after a re-scan from the Inspect report.
- The VPAT editor no longer says Non-text Contrast and Target Size mark themselves Supports.
- The VPAT shows the real number of open findings and questions against each criterion, and warns when a saved remark no longer matches them.
- A re-scan no longer carries over browser findings for rules on Ignored Rules.
- Tabs on the plugin's screens now point screen readers at the content they show, rather than at nothing.
- The "Open the VPAT" links on the Statement screen are underlined, so they don't rely on colour alone.
- Several faint labels in the control panel now meet 4.5:1 contrast.
- A setting that points at an empty environment variable, such as the Chrome path, no longer raises a PHP deprecation.
- The Context column of the CSV export shows the element's markup for colour contrast findings, not raw JSON.
- When the page is on a different domain from the control panel, the Inspect report says so and switches off the tools that can't reach the preview, instead of them doing nothing.
- Re-scanning a page on a different domain from the control panel, from the Inspect report, now refreshes its browser findings when server-side browser scanning is set up.

### Security
- Someone with the Run scans permission can no longer store browser findings, or answer questions, for a page they aren't allowed to view.

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
