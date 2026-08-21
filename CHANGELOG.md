# Release Notes for Accessibility Audit

## 1.0.11-beta.1 - 2026-08-21

### Added
- Pro: the admin overlay now works on decoupled frontends. If your site is headless (Next, Nuxt, Astro or the like), Craft never renders your pages, so the overlay could never appear there. Now you add one script tag to your frontend, generate a token under **Settings → Tools**, and open the site through an activation link: the overlay runs on your real frontend with the same axe engine and rules, and stores results against the same scans you see in the control panel. The script does nothing at all for ordinary visitors, so it is safe to ship in production builds, and it suits heavily cached sites just as well: pages served from Blitz or a CDN edge never run Craft, so this is the way to get the overlay onto them too. The new Decoupled Frontends page in the docs has the setup.
- The common consent-management banners (OneTrust, Cookiebot, Ketch, Usercentrics, Didomi, TrustArc, Osano, Complianz, CookieYes, Iubenda, Termly) are now excluded from every scan surface. Their markup is third-party UI you can neither fix nor keep still between scans, so findings inside them only buried your own. A new **Excluded Elements** setting under **Settings → Scanning** takes extra CSS selectors, one per line, for anything else on the page you do not control: chat widgets, embedded players, testing overlays.

### Fixed
- Clicking a finding whose element could not be located in the Inspect preview used to box every element the rule could apply to: one nameless button reported, eighty-odd buttons highlighted; two misplaced list items, every list item on the page. Matching now finds the right element far more reliably, snippets cut off mid-tag included, and when the element genuinely is not in the preview (added by a script that does not run there, say), the report says it cannot highlight instead of highlighting everything.
- Browser-pass findings were described with axe's rule blurb ("Ensures `<dl>` elements are structured correctly"), which reads like a question about whether anything is wrong. They now carry the requirement that actually failed ("`<dl>` elements must only directly contain properly-ordered `<dt>` and `<dd>` groups…"), the same wording the overlay always used. Applies to newly scanned results.
- The frontend overlay's Highlight now scrolls to an element that is actually on screen when a finding matches several, and says so when everything it flashed sits inside a collapsed menu or panel.
- The site's own styles could bleed into the frontend overlay panel: a theme's heading font landing on the panel title, decorative flourishes attached to headings, that sort of thing. The panel now pins its own typography and blocks the page's decorative styles, so it looks the same on every site.
- On the Inspect page, clicking a finding about something the page lacks (no skip link, no meta description, no landmarks) would box unrelated elements in the preview, as if they were the problem. A missing skip link, for one, highlighted whatever ordinary in-page anchor links it could find. Those findings now show their message without highlighting anything, since there is no offending element to point at.
- The Inspect preview could highlight the wrong link when two links point at the same URL, a nav item and a "View all" button being the classic pair: the finding was about one, the box landed on the other. The box now lands on the one the finding is actually about.
- Findings about the document itself (a missing or empty page title, a missing language attribute) no longer try to highlight anything in the Inspect preview either: their reported element is the page as a whole, so the box would land on the whole page or on nothing visible.
- When a finding landed on an element with no attributes, every occurrence rendered as the same bare tag: a page with dozens of orphaned list items showed dozens of identical `<li>` chips, with no way to tell one from another. Each occurrence now shows a short preview of the element's text, so you can tell which is which.
- Not an issue and Confirm as failure could silently fail on findings whose snippet spans multiple lines: the ruling looked saved, then the question came straight back after the reload. Rulings on those findings now stick. If one bounced back on you before, click it once more after updating.
- Settings pages now render read-only on installs where administrative changes are disabled (a standard production lockdown), instead of refusing to open at all. The usual Craft read-only notice appears, every field greys out, save and token-generation controls are withheld, and both the plugin's own Settings link and the one under Settings → Plugins keep working on those installs.
- Two elements with an empty `id=""` were reported as a duplicate id. An empty id cannot be referenced by anything (labels, `aria-labelledby`, fragment links all need a value), so it collides with nothing; the check now ignores empty and whitespace-only ids. Genuine duplicates are still reported.
- Highlighting an element that sits inside a collapsed menu or panel looked like nothing happened: the box was applied, but out of sight until the menu was opened by hand. A notice now says where the element is hiding, and the highlight is waiting there when you open it.
- Show on page could look like it did nothing when the finding's content renders in more than one place, a hero title repeated on a listing card being the classic case: the box landed on whichever copy came first in the page's markup, possibly nowhere near where you were looking, or on a hidden one. All identical renderings are now boxed together, visible ones are preferred, and the preview scrolls to one that is actually on screen.
- Show on page for an image question could highlight every image sharing an upload path: the stored snippet cut the image URL short, so listing thumbnails all matched it. The right image is now identified, and rulings you have already made keep holding after the change.

## 1.0.10-beta.1 - 2026-08-21

### Fixed
- The Accessibility Audit link on **Settings → Plugins** gave a 404 instead of opening the plugin settings. The settings pages themselves were always fine, and reachable through the plugin's own sidebar, but the standard route in from the Settings page was broken. It now lands you on the settings like any other plugin. ([#1](https://github.com/john-henry/craft-accessibility-audit/issues/1))

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
