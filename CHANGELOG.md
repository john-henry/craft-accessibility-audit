# Release Notes for Accessibility Audit

## 1.0.9-beta.1 - 2026-08-13

### Added
- A new **Browser Settle Time** setting under **Settings → Scanning** controls how long the browser pass waits after a page loads before running its checks, so late-rendering JavaScript can finish. It has always waited 2 seconds, and that is still the default, but the wait is paid on every pass, so on a big site it adds up to hours. Plenty of sites are fine at 500 milliseconds, and you can set 0 to skip the wait entirely.

### Changed
- The browser pass now renders the desktop and mobile checks for a page in one Chrome session instead of starting a fresh one for each. On a site with thousands of pages that halves the browser starts, which takes a serious chunk off the total scan time. Nothing changes in the results themselves.

### Fixed
- On large sites, a queued site-wide scan could quietly miss some pages and scan others twice, because the database was free to hand the pages back in a different order for each batch. The sweep now works through pages in a fixed order, so every page is scanned exactly once.

## 1.0.8-beta.1 - 2026-08-12

### Fixed
- Server-side browser scans could crash partway through a page on servers with limited shared memory, a common setup on containers and managed VPS hosting. Chrome is now told to keep its working memory out of the shared memory area, so those scans complete instead of dying quietly.

## 1.0.7-beta.1 - 2026-08-07

### Added
- You can now point the scanner at a Chrome running somewhere else instead of installing one on your own server. Set **Settings → Scanning → Remote Chrome Endpoint** to a browserless account, a container of your own, or anything else speaking the DevTools protocol, and the browser pass runs there. This is the only way to get server-side browser scanning on hosts where you cannot install a binary, Craft Cloud among them. Store the URI in an environment variable if it carries a token.
- Contrast that axe-core could not measure now lands under **Needs review** instead of being thrown away. axe hands a node back undecided when it cannot work out what is actually behind the text, which happens when another element sits over it, or the text is on an image or a gradient. Those results used to vanish, so a page could look clean on contrast while the hardest parts of it had never really been checked. They now arrive as a question, with the reason axe gave and the ratio the text needs, and they stay out of your score until you confirm one.

### Changed
- `storeAxeIssues()` takes a fourth argument, the undecided results from axe, defaulting to an empty array. Existing calls keep working unchanged.

### Fixed
- On sites using modern CSS colour syntax, which means any site built with Tailwind 4, the contrast check misread colours it could not parse. A button with its own white background could be reported as failing against the section colour behind it, and text whose colour could not be read was skipped altogether, so genuine failures went unreported. Colours are now read in any syntax the browser understands, `oklch` included.

## 1.0.6-beta.1 - 2026-07-27

### Fixed
- The accessibility statement preview and the published statement failed with a template loading error, because the built-in statement template was missing from the release package.

## 1.0.5-beta.1 - 2026-07-27

### Fixed
- On the accessibility statement, the Add an entry button and the scan-suggestion chips only saved the statement without adding the entry when the non-accessible content list was still empty.

## 1.0.4-beta.1 - 2026-07-27

### Changed
- Programmatic bulk resaves (the resave commands, migrations) no longer queue a scan per element. Use Scan All for a deliberate site-wide sweep.

### Fixed
- A scan could fail entirely on a page whose alt text or markup contained multibyte characters (curly quotes, accents, emoji) near a truncation point, stalling a site-wide scan with a database error. Truncation is now multibyte-safe, and issue text is sanitised before storage so one bad string can never fail a scan.
- Saving an entry queued duplicate scans and browser checks for the same URL, because the entry's revision was scanned alongside it. Revisions are no longer scanned.

## 1.0.3-beta.1 - 2026-07-27

### Fixed
- Page report highlights no longer repaint the element's background, and the contrast check ignores the report's own highlights and badges.
- The page report's contrast and axe passes now wait for JavaScript-injected stylesheets to apply, so styled elements are no longer reported at browser-default colours.

## 1.0.2-beta.1 - 2026-07-27

### Added
- Colour-contrast findings in the page report now show the failing element's markup.

### Fixed
- Page report highlighting now pinpoints the exact elements for duplicate-id and colour-contrast findings. Re-scan a page to update stored contrast findings.

### Security
- Pages rendered for a logged-in admin with the frontend overlay enabled are no longer cacheable, so a full-page cache such as Blitz or a CDN cannot serve the admin's overlay to visitors.

## 1.0.1-beta.1 - 2026-07-27

### Changed
- License type and some supporting github issue docs

## 1.0.0-beta.1 - 2026-07-12

### Added
- Initial release.
