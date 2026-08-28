# Upgrade Notes

What changed under the bonnet, for anyone calling this plugin's services, listening to its events, or reading its tables directly. If you only install the plugin and use the control panel, the [changelog](CHANGELOG.md) is the one you want; nothing here affects you.

Versions are listed newest first.

## 1.2.0

### Before you update

Scan history older than your **Retain Scan Results** setting is deleted the first time Craft runs garbage collection after this update. That setting never deleted anything before, so there is probably more history sitting there than it allows, and the default is 90 days. Raise it, or set it to 0 to keep everything (Pro), before you update if that history matters to you.

Four migrations run on update. Two of them delete rows:

| Migration | What it does |
|---|---|
| `m260827_000000_clear_unstyled_contrast_findings` | Deletes contrast findings recorded against `#0000EE` text, which came from pages measured before their stylesheet had loaded |
| `m260827_120000_add_url_scans` | Makes `elementId` and `elementType` nullable on scans and issues, and adds `url` and `title` to scans |
| `m260827_140000_url_verdicts` | Makes `elementId` nullable on verdicts, adds `url` and `targetHash`, backfills `targetHash`, and moves the unique index onto it |
| `m260827_160000_clear_decorative_contrast_findings` | Deletes contrast findings recorded against `aria-hidden="true"` markup |

Both deletions are of findings that were wrong, and both recalculate the scores that were affected. Anything genuinely failing comes back on the next scan.

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

`readability/analyse` and `readability/analyse-entry` now require `accessibility-audit:runScans` rather than `accessibility-audit:viewReports`, and both are explicitly POST-only. Analysing fetches a page from your server and can call the Anthropic API, so it spends your outbound requests and your API budget, which is the scanning permission's business rather than the reading one's.

If you have editors who analyse pages, grant them **Run scans**. Reading the stored results is unchanged.

### Nullable columns

`elementId` and `elementType` are nullable on `accessibilityaudit_scans` and `accessibilityaudit_issues`, and `elementId` is nullable on `accessibilityaudit_verdicts`. A row with a null `elementId` belongs to a URL, held in the `url` column on the scan.

Any query of your own that joins these tables to `elements` will silently drop URL scans. If you want them, use a left join, and group on `scanId` rather than `elementId` when you are counting pages: every URL scan shares a null `elementId`, so grouping on it folds them into one and `COUNT(DISTINCT elementId)` skips them altogether.

### New rule IDs

Three rules can now appear in `accessibilityaudit_issues.ruleId`, all with the same JSON context shape as `color-contrast`:

- `contrast-hover`
- `contrast-focus`
- `contrast-selection`

Plus `block-in-paragraph`, which stores raw markup as its context like the other PHP rules.

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
