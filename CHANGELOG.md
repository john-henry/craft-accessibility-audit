# Release Notes for Accessibility Audit

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
