# Release Notes for Accessibility Audit

## 1.0.2-beta.1 - 2026-07-27

### Added
- Colour-contrast findings in the page report now show the failing element's markup, so you can tell which element carries the colours even when they render invisibly.

### Fixed
- The page report highlighted the wrong elements for a duplicate-id finding and highlighted nearly every element for a colour-contrast finding. Both now pinpoint the exact elements; re-scan a page for stored contrast findings to pick up their exact locations.

### Security
- Pages rendered for a logged-in admin with the frontend overlay enabled are no longer cacheable, so a full-page cache such as Blitz or a CDN cannot store the admin's overlay and serve it to ordinary visitors.

## 1.0.1-beta.1 - 2026-07-27

### Changed
- License type and some supporting github issue docs

## 1.0.0-beta.1 - 2026-07-12

### Added
- Initial release.
