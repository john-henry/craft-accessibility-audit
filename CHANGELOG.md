# Release Notes for Accessibility Audit

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
