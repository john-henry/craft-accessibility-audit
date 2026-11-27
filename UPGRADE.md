# Upgrade Notes

What changed under the bonnet, for anyone calling this plugin's services, listening to its events, or reading its tables directly. If you only install the plugin and use the control panel, the [changelog](CHANGELOG.md) is the one you want; nothing here affects you.

Versions are listed newest first.

## 2.0.0

### Before you update

Eight migrations run on update. The edition rename touches project config, so it runs once, on the environment that runs it first; the others pick the change up from the YAML.

| Migration | What it does |
|---|---|
| `m261104_000000_pdf_checks` | Adds `accessibilityaudit_pdf_checks`: one row per PDF asset, with whether it was checked, the reason where it wasn't, and the size and modified date it was checked at. The findings themselves go into `accessibilityaudit_asset_issues` under their `pdf-*` rule IDs |
| `m261106_000000_alt_drafts` | Adds `accessibilityaudit_alt_drafts`: one row per image, holding the three versions Claude wrote (`shortAlt`, `standardAlt`, `detailedAlt`), the image type, the review notes, a status, and the error where no draft could be written. Nothing in it is alt text until an editor approves it |
| `m261121_000000_announce_editions` | Pushes a What's New announcement to admins about the editions and PDF checks. A fresh install doesn't run it |
| `m261125_000000_issue_selector` | Adds a nullable `selector` column to `accessibilityaudit_issues`: the browser's own CSS selector for the element, where the headless pass found one. Existing rows stay null until the page is scanned again |
| `m261125_120000_site_snapshots` | Adds `accessibilityaudit_snapshots`: one row per site per day with its headline figures, kept for good so the Reports can show a trend after old scans are pruned |
| `m261125_130000_report_notes` | Adds `accessibilityaudit_report_notes`: the note a site's reports carry, one per site |
| `m261125_140000_report_sends` | Adds `accessibilityaudit_report_sends`: when each site's scheduled summary was last sent |
| `m261127_000000_rename_standard_edition` | Moves a site on the `standard` edition to `plus`. A history setting of 30 days, the most Standard allowed, goes up to 90 |

The plugin now has sixteen tables. The two asset tables key on `assetId` with a unique index and a cascading foreign key to `elements`; the three report tables key on `siteId` with a cascading foreign key to `sites`.

### New components

| Component | Class | For |
|---|---|---|
| `pdf` | `PdfChecker` | Reading one PDF and reporting what fails |
| `pdfAudit` | `PdfAuditService` | Queueing PDF checks, storing their results and listing them |
| `altDrafts` | `AltDrafts` | AI alt text drafts: queueing, listing, approving and discarding |
| `fixGuides` | `FixGuideService` | Building the Markdown fix guides for the site, a rule or a page |
| `snapshots` | `SnapshotService` | Recording each site's daily figures for the Reports |
| `scheduledReports` | `ScheduledReportService` | Sending the directors summary on a schedule, by email and optionally to Slack |

Each has a typed accessor on the plugin (`getPdf()`, `getPdfAudit()`, `getAltDrafts()`, `getFixGuides()`, `getSnapshots()`, `getScheduledReports()`).

### New controller actions

| Action | Method | Permission | Edition |
|---|---|---|---|
| `alt/queue-drafts` | POST | `run-scans` | Plus and Pro |
| `alt/approve-drafts` | POST, JSON | `run-scans` | Plus and Pro |
| `alt/discard-draft` | POST, JSON | `run-scans` | Plus and Pro |
| `audit/check-pdfs` | POST | `run-scans` | Plus and Pro, with Check PDF Files on |
| `audit/check-pdf` | POST | `run-scans` | Plus and Pro, with Check PDF Files on |
| `fix-guide/site`, `fix-guide/rule`, `fix-guide/page` | GET | `view-reports` | Plus and Pro |
| `audit/set-verdict-everywhere` | POST, JSON | `run-scans` | All |
| `reports/view`, `reports/csv` | GET | `view-reports` | Pro |
| `reports/save-note` | POST | `view-reports` and `run-scans` | Pro |

All are under `accessibility-audit/`. `audit/set-verdict-everywhere` refuses a ruling that would reach a page the user isn't allowed to view, and says how many it left alone.

Two console commands are new, both meant for a daily cron job: `accessibility-audit/audit/snapshot` records the day's figures, and `accessibility-audit/audit/send-reports` sends any scheduled summary that is due.

### Editions

The plugin has three editions now, with the handles `lite`, `plus` and `pro`, in that order. The edition that was called Standard is now Plus, with the handle `plus` in place of `standard`, and a site on it moves across when its migrations run. `lite` is the lowest, so a fresh install starts there.

- `AccessibilityAudit::EDITION_LITE` is new. `isPlus()` is true on Plus and Pro; `isPro()` is unchanged.
- `scanLimit()` gives the active edition's page cap (25 on Lite, `null` where there is none) and `retentionCap()` its history cap in days (30 on Lite, 90 on Plus, `null` on Pro).
- `canGenerateAltText()` is true on Plus or Pro with an Anthropic API key set.
- `AuditService::STANDARD_SCAN_LIMIT` and `AuditService::STANDARD_RETENTION_CAP` are gone. Read the caps from `scanLimit()` and `retentionCap()` on the plugin instead.
- JSON refusals for a feature the edition lacks carry `editionRequired` (`plus` or `pro`). A Pro refusal still carries `proRequired: true` as well.
- `craft.a11y.isPlus()` and `craft.a11y.editionName()` are new Twig helpers.

### Removed

- `getAssets()->scanAsset()`. PDF results come from `getPdfAudit()`, and image alt text issues from the Assets listing methods.
- The `accessibility-audit/alt/save` action. The alt text editor posts to `accessibility-audit/alt/save-screen`.

### Queue jobs

- `CheckPdf` has a `force` flag. Without it, a job finds the file already checked as it is now and passes over it. `CheckPdf::ttr()` is the reservation the plugin pushes `CheckPdf` and `CheckPdfs` with; push them through `craft\helpers\Queue::push()` with it if you queue them yourself.
- `AltDrafts::draftAsset()` returns `skipped` where the image no longer needs a draft by the time Claude answers.

## 1.6.0

### Before you update

If the front-end overlay is on and the site sits behind a full-page cache (Blitz, Cloudflare and the like), clear the cache once after updating. Pages cached before the update don't carry the overlay's loader.

One migration runs on update:

| Migration | What it does |
|---|---|
| `m261005_000000_focus_walk_record` | Adds `focusVisibleChecked` and `focusObscuredChecked` booleans to `accessibilityaudit_scans`. They record whether the server-side browser pass finished a keyboard walk of the page, which the VPAT counts for 2.4.7 and 2.4.11. Existing rows are `false` until the page is scanned again |

### New rule IDs

Three potential-issue rules can turn up in `accessibilityaudit_issues.ruleId`. Each has a constant on `AuditService`.

| Rule | Criterion | Context |
|---|---|---|
| `potential:focus-outline-removed` | 2.4.7 | JSON: `html` (the element's markup) and `rule` (the stylesheet selector that removes its outline) |
| `potential:focus-not-visible` | 2.4.7 | Markup, like the other potential rules |
| `potential:focus-obscured` | 2.4.11 | JSON: `html` and `cover` (a CSS path to the element covering it), or plain markup when the cover can't be named |

`AuditService::contextMarkup()` hands back the markup for any stored context, JSON or not. Use it rather than decoding contexts yourself. The CSV export uses it now, so its Context column shows markup for contrast and focus findings where it used to show raw JSON.

### Stricter endpoints

If you post to these from your own code, check the calls:

- `audit/store-axe-results` and `audit/store-contrast-results` need an `Accept: application/json` header, and refuse a page the user isn't allowed to view. The element type stored with a new scan is the element's real class, whatever `elementType` is posted.
- `audit/set-verdict` and `audit/set-verdicts-bulk` refuse a page the user isn't allowed to view.

### Front-end overlay

With the overlay turned on, every site page gets a small loader script at the end of the body, the same for every visitor. Page templates still need Craft's `endBody()` call, as they always did.

- New action: `accessibility-audit/frontend-overlay/config`. A GET, open to guests, who are told the overlay is off. Admins get the overlay's config.
- New cookie: `a11yOverlay` (`OverlayService::MARKER_COOKIE`), set for admins when they sign in or open the control panel, and cleared when they sign out. Add it to your cookie-consent listing if you keep one.
- `OverlayService::canShowFrontendOverlay()` is the one check both the rendered overlay and the loader go through.
- The loader and the decoupled loader both set `window.__accessibilityAuditOverlayClaim` before booting, and stay out if it's already set.

### Signatures that widened

```php
// Rules to skip, by ID. Existing calls need no change.
ContentScanner::scan(string $html, array $ignoreRules = []): array
PotentialScanner::scan(string $html, array $ignoreRules = []): array
```

### Behaviour worth knowing about

- `OrganisationMetaModel` gains `organisationName`, shared by the VPAT and the accessibility statement.
- VPAT criterion 2.4.11 is now Focus Not Obscured (Minimum). Saved answers stay keyed on `2.4.11`, but they were written against the old wording, so have a look at them.

## 1.5.0

### Before you update

Three things in your own code or config may need changing before this goes out:

- Anything that checks the plugin's permissions by handle. They are kebab-case now; see [Breaking: permission handles](#breaking-permission-handles).
- In `config/accessibility-audit.php`, rows in `excludedUriPatterns` and `additionalUrls` take `siteUid` instead of `siteId`.
- In the same file, a blank `pattern` no longer means the homepage and matches nothing. Write `^$` instead.

A row in the config file that still has `siteId` is read, so nothing breaks straight away, but the ID means a different site on any environment where the IDs differ, which is why it moved.

Ten migrations run on update. The three that touch project config run once, on the environment that runs them first; the others pick the change up from the YAML.

| Migration | What it does |
|---|---|
| `m260917_000000_readability_result_keys` | Keeps one readability result per element and site, removes rows whose element or site is gone, and adds a unique index and foreign keys |
| `m260919_000000_widen_vpat_overrides` | Widens the VPAT's stored answers to `mediumText` |
| `m260919_120000_drop_temp_upload_audits` | Deletes audit rows recorded against Craft's temporary uploads |
| `m260919_140000_widen_organisation_meta` | Widens the shared organisation details column |
| `m260920_000000_homepage_uri_pattern` | Rewrites a blank excluded URI pattern in project config as `^$` |
| `m260926_000000_kebab_case_permissions` | Renames the permissions, keeping every user and user group grant |
| `m260926_000001_settings_site_uids` | Switches excluded URI patterns and additional URLs in project config from site ID to site UID |
| `m260926_000002_keep_target_score_off` | Writes `targetScore: 0` on installs that never saved the settings, so they don't pick up the new default of 90 |
| `m260926_000003_readability_sentence_counts` | Adds nullable `hardSentences` and `veryHardSentences` columns to `accessibilityaudit_readability`. Existing rows stay null until the page is analysed again |
| `m260926_000004_drop_siteless_readability_results` | Deletes readability results with neither an element nor a site: pages analysed by URL that weren't entries. None of them showed in the Readability table. An entry whose only result was one of these shows as not analysed until it's saved or analysed again |

The first rescan after updating will move scores. axe-core goes from 4.9.1 to 4.13.0, and trashed, disabled and archived pages drop out of the score, the listings and the CI check.

### Breaking: permission handles

| Old | New |
|---|---|
| `accessibility-audit:viewReports` | `accessibility-audit:view-reports` |
| `accessibility-audit:runScans` | `accessibility-audit:run-scans` |
| `accessibility-audit:manageVpat` | `accessibility-audit:manage-vpat` |
| `accessibility-audit:manageStatement` | `accessibility-audit:manage-statement` |

Grants carry over. Code that calls `$user->can('accessibility-audit:viewReports')` or `requirePermission()` with the old handle gets `false` or a 403 for everyone except admins, who hold every permission, so it's easy to miss in testing.

### Removed

| Removed | Instead |
|---|---|
| `ReportService::getSummaryReport()` | `AuditService::getSiteSummary()`, which it wrapped |
| `AuditService::markResolvedIssues()` | Private now. Resolved issues are marked as part of storing a scan |
| `RuleRegistry::difficultyLabel()` | The difficulty is still in the rule metadata from `RuleRegistry::get()`; label it yourself |
| `UrlSafety::guzzleRedirectConfig()` | `UrlSafety::fetch()`, or `UrlSafety::pinnedRequestOptions()` for a single request of your own |

### Readability is English only

`ReadabilityService::supportsSite($siteId)` is true when the site's language starts with `en`. On any other site, `analyseElement()`, `analyseElementText()` and `previewElementText()` return `['error' => …]` instead of scores, and `analyseHtml()` does the same for a page whose `<html lang>` isn't English. The message is `unsupportedMessage()`. Check for the `error` key before reading scores.

The `RecordReadability` job takes an optional `siteId`. Without it, the element loads on any site, as before.

### The public statement renders in English

`StatementService::render()` switches the app language to English while it renders on a non-English site, and puts it back afterwards. The rendered markup carries a `lang` attribute, so a statement dropped into a German page is announced correctly. This lasts until the statement's translations have had a native speaker's review.

### Statement exclusions need a reason

`StatementExclusionModel` fails validation when a disproportionate burden has content but no reason. If you save statement data through the service, supply the reason or check `getErrors()`.

### Signatures that widened

These accept more than they did; existing calls need no change:

```php
// Any element the scanner covers, not only entries.
craft.a11y.scan(element)
craft.a11y.issues(element)

// The own-site exemption now needs the exact scheme, host and port.
// Pass them when you aren't checking https on 443.
UrlSafety::assertHostIsPublic(string $host, string $scheme = 'https', ?int $port = null): void
UrlSafety::publicAddressesFor(string $host, string $scheme = 'https', ?int $port = null): array
```

### Readability results

- Analysis results carry `hardSentences` and `veryHardSentences`, counted at the site's Readability Target the same way the preview counts them, and stored with the result.
- The `AnalyseReadability` job, behind Analyse every page, also scores the Additional URLs from Settings that are on the site and aren't entries.
- `ReadabilityService::getResultsPaged()` now defaults to `'worst'`: failing pages first, highest grade at the top. Pass `'dateAnalysed'` for the old order.
- The `readability/analyse` action refuses a URL that isn't on one of the install's own sites. `ReadabilityService::siteForUrl()` is the check it uses: same scheme, host and port, and at or under the site's base path.

### New settings

`SettingsModel` gains `readabilityTarget` (`accessible`, `default` or `technical`) and `readabilityPreviewTarget` (bool, default `true`). `targetScore` now defaults to `SettingsModel::RECOMMENDED_TARGET_SCORE` (90) on new installs.

Saving settings no longer copies values from `config/accessibility-audit.php` into project config, so a key you set in the config file stays out of the YAML.

## 1.2.0

### Before you update

Scan history older than your **Retain Scan Results** setting is deleted the first time Craft runs garbage collection after this update. That setting never deleted anything before, so there is probably more history sitting there than it allows, and the default is 90 days. Raise it, or set it to 0 to keep everything (Pro), before you update if that history matters to you.

Seven migrations run on update. Two of them delete rows, and three re-key existing rulings:

| Migration | What it does |
|---|---|
| `m260827_000000_clear_unstyled_contrast_findings` | Deletes contrast findings recorded against `#0000EE` text, which came from pages measured before their stylesheet had loaded |
| `m260827_120000_add_url_scans` | Makes `elementId` and `elementType` nullable on scans and issues, and adds `url` and `title` to scans |
| `m260827_140000_url_verdicts` | Makes `elementId` nullable on verdicts, adds `url` and `targetHash`, backfills `targetHash`, and moves the unique index onto it |
| `m260827_160000_clear_decorative_contrast_findings` | Deletes contrast findings recorded against `aria-hidden="true"` markup |
| `m260830_090000_restable_verdict_keys` | Moves existing rulings onto the id-independent key, recovering the original markup from the scan history. Merges a stale row into a newer answer for the same question rather than colliding with it |
| `m260830_100000_collapse_contrast_context_keys` | Shortens stored contrast occurrences to their opening tag and moves any ruling made against the longer form onto that key. Where both forms were ruled on, the later answer is kept |
| `m260830_110000_strip_own_marks_from_contexts` | Removes the plugin's own `data-accessibility-audit-*` marks from stored occurrences and moves rulings made against a marked form onto the clean key |

Both deletions are of findings that were wrong, and both recalculate the scores that were affected. Anything still failing comes back on the next scan.

### Breaking: VerdictService

A verdict used to belong to an element. It now belongs to a *target*, which is either an element or a URL, because pages scanned by URL have no element to key one to. `targetHash` is the single non-null column both reduce to, and the unique index that enforces one ruling per question is built on it.

If you call these, update the calls:

```php
// Renamed. The map is now keyed "targetHash|ruleId|contextHash",
// where it used to be "elementId|ruleId|contextHash".
- $verdicts->metaForElements([$elementId], $siteId);
+ $verdicts->metaForTargets([$verdicts->targetHash($elementId)], $siteId);

// Second argument is now a target hash, not an element id.
- $verdicts->lookupMeta($map, $elementId, $ruleId, $context);
+ $verdicts->lookupMeta($map, $verdicts->targetHash($elementId), $ruleId, $context);
```

These stay source-compatible, with a widened first target argument and a new trailing `$url`:

```php
setVerdict(int $siteId, ?int $elementId, string $ruleId, ?string $context, ?string $verdict, ?string $note = null, ?string $url = null): void
applyToIssues(int $siteId, ?int $elementId, string $ruleId, string $hash, ?string $verdict, ?string $url = null): void
mapForElement(?int $elementId, int $siteId, ?string $url = null): array
```

Pass `null` for `$elementId` and a URL for `$url` to rule on a page scanned by URL. Existing element calls need no change.

### Breaking: ReadabilityController permissions

`readability/analyse` and `readability/analyse-entry` now require `accessibility-audit:run-scans` rather than `accessibility-audit:view-reports`, and both are explicitly POST-only. Analysing fetches a page from your server and can call the Anthropic API, so it spends your outbound requests and your API budget, which is the scanning permission's business rather than the reading one's.

If you have editors who analyse pages, grant them **Run scans**. Reading the stored results is unchanged.

### Nullable columns

`elementId` and `elementType` are nullable on `accessibilityaudit_scans` and `accessibilityaudit_issues`, and `elementId` is nullable on `accessibilityaudit_verdicts`. A row with a null `elementId` belongs to a URL, held in the `url` column on the scan.

Any query of your own that joins these tables to `elements` will silently drop URL scans. If you want them, use a left join, and group on `scanId` rather than `elementId` when you are counting pages: every URL scan shares a null `elementId`, so grouping on it folds them into one and `COUNT(DISTINCT elementId)` skips them altogether.

### New rule IDs

Three rules can now appear in `accessibilityaudit_issues.ruleId`, all with the same JSON context shape as `color-contrast`:

- `contrast-hover`
- `contrast-focus`
- `contrast-selection`

Plus `block-in-paragraph` and `symbol-only-content`, which store raw markup as their context like the other PHP rules.

`symbol-only-content` varies its criterion by element: `2.4.4` on a link, whose name does not say where it goes, and `1.1.1` on a cell or button, where a glyph is sitting in the place of text. Anything switching on rule IDs to pick a criterion needs to read it off the issue rather than assume one per rule.

If you have anything switching on rule IDs, or a hard-coded list of them, add these. `RuleRegistry::get()` has metadata for all four.

### potential:identical-links now varies its severity and criterion

The rule id is unchanged and the stored `context` string is unchanged byte for byte, so existing dismissals and history stay valid. What changed is the finding it produces:

- `severity` is `warning` for a 2.4.4 failure and `notice` for the AAA advisory, where it used to always be `notice`.
- `wcagCriterion` is `2.4.4` (level A) for a failure and `2.4.9` (level AAA) for the advisory, where it used to always be `2.4.4`.

If you have anything grouping potential issues on the assumption that all of them are notices, or reading `wcagCriterion` from this rule as a constant, that assumption no longer holds. Nothing reaches the score until a finding is confirmed either way.

### VerdictService::setVerdict() and applyToIssues() now return the scans needing a score

Both used to return `void` and recalculate every affected scan's score themselves. They still do by default, so existing calls need no change, but both now return `int[]` of the scans whose scores are out of date, and both take a trailing `bool $deferScoring = false`.

Pass `deferScoring: true` when ruling on many occurrences at once and recalculate the collected scan ids yourself at the end. Every occurrence in a group shares one scan, so the default path recomputes the same number once per ruling.

```php
$needScoring = [];

foreach ($items as $item) {
    $needScoring = array_merge($needScoring, $verdicts->setVerdict(
        $siteId, $elementId, $item['ruleId'], $item['context'], 'dismissed',
        null, null, deferScoring: true,
    ));
}

foreach (array_unique($needScoring) as $scanId) {
    $plugin->audit->recalculateScoreForScan($scanId);
}
```

### UrlSafety::fetch() reports where it landed

The third parameter is new and optional, so existing calls are unaffected. Pass a variable by reference to get the URL the last hop ended on:

```php
$landed = $url;
$response = UrlSafety::fetch($url, $clientConfig, $landed);
```

A redirect landing on a host this install does not serve is skipped instead of scanned, with the reason naming the host. Comparison is on the host alone, so http to https, trailing slashes and path moves are unaffected.

A host counts as yours when it is at or below one already known to be yours: the address the scan asked for, or any site's base URL. So `site.com` to `shop.site.com` is allowed, as is a hop to another site in the install. The only hop upwards allowed is dropping a leading `www.`.

No public suffix list is involved. Nothing is derived from the host string; the only question is whether the landed host sits under a host already trusted. So `example.co.uk` to `somebody-else.co.uk` is refused without anyone having to decide whether `co.uk` is a domain or a suffix.

`AuditService::scanUrl()` now files a scan under `$landed` rather than the URL it was asked for. A redirect therefore produces one page in the listing instead of two, and the returned `url` may differ from the one you passed. Existing scans stored under redirecting addresses are left alone; they fall away as those pages are scanned again.

### Missing pages are skipped deliberately

The scan fetcher sets `http_errors => false` and checks the status itself. Anything outside 200-299 is skipped with a reason naming the status, where a 4xx previously threw and was reported with the same message as an unreachable host.

`scanElement()` returns `score: 0` and an `error` key when the page could not be read. It previously returned `score: 100`, which read as a clean page rather than as one nobody looked at. Nothing was stored in either case, so no history changes, but anything reading `score` off that return should check `error` first.

### Occurrence identity no longer includes element ids

`VerdictService::stableContextHash()` is what a ruling is keyed on now. It hashes the context with every id, and every attribute referencing one (`for`, `aria-describedby`, `headers`, and the rest), taken out, and with the id-built CSS path dropped from a contrast finding's JSON. Identity comes from the tag, its classes, the field `name`, and the text.

This is for pages whose ids change per render. Formie mints a fresh token into every id each time a form is drawn, so the same field arrived as a new occurrence on every scan and rulings against it were orphaned.

`contextHash()` is unchanged and still public. Lookups try the stable hash first, then the raw hash, then the older short-snippet form, so nothing already dismissed comes back. If you compare hashes yourself, use `stableContextHash()`.

No attempt is made to detect a generated id, and none should be added: "kqjyvj" and "submit" are both six lowercase letters, and a false positive collapses two separate occurrences into one, so a ruling on either would silently answer both.

### Contrast occurrences are keyed on the opening tag

`AuditService::openingTagOf()` reduces a markup snippet to its opening tag, and the three places a contrast occurrence is stored now pass through it.

The engines report an element's markup in full when it is short and as the bare opening tag once it passes their own length limit. Which side of that limit an element falls on depends on how much of the page has rendered when the check runs, so one element could be stored two ways and counted as two occurrences.

`openingTagOf()` also strips the plugin's own `data-accessibility-audit-*` attributes. The report stamps those onto elements in its preview to highlight them, and the browser pass reads that same preview, so without this an element that had been highlighted was recorded as a different element.

If you read `accessibilityaudit_issues.context` for a contrast rule, expect the opening tag rather than the whole element. Several elements sharing one opening tag on a page now collapse to a single occurrence, which is deliberate: they are the same question with the same answer.

### New helpers

Public and reusable, if any of it saves you writing your own:

| Class | For |
|---|---|
| `helpers\AccessibleName` | The name a screen reader announces for an element (`aria-labelledby`, then `aria-label`, then the subtree, then `title`) |
| `helpers\LinkContext` | The landmark and heading a link sits under, and whether it is hidden from a reader |
| `helpers\ScanTarget` | Naming, linking and addressing a scan, whether it points at an element or a URL |
| `helpers\OccurrenceCluster` | Grouping repeated occurrences of one finding by tag and class |
| `helpers\UrlSafety::fetch()` | An outbound fetch that connects only to addresses it validated, following redirects one checked hop at a time |
| `helpers\VisionImage` | Preparing an image for a vision API, including deciding whether this server can render a given SVG faithfully |

### Behaviour worth knowing about

- `UrlSafety::fetch()` follows redirects itself and passes `allow_redirects => false` to the client. If you were relying on `UrlSafety::guzzleRedirectConfig()`, it still exists and still re-validates each hop, but it does not pin addresses. Prefer `fetch()`.
- `AuditService::getScannedElementCount()` no longer counts pages the site has deleted. Craft soft-deletes, so those rows survive; they were being counted against the Standard edition's page limit.
- Scan pruning now runs during Craft's garbage collection. It previously only ran from the console command.
