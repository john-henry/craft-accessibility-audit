<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\ElementLabel;
use johnhenry\accessibilityaudit\jobs\HeadlessScanJob;
use johnhenry\accessibilityaudit\jobs\ScanElementJob;
use johnhenry\accessibilityaudit\models\IssueModel;
use johnhenry\accessibilityaudit\models\SettingsModel;
use Throwable;
use yii\base\Component;
use yii\db\Exception;
use yii\db\Expression;

/**
 *
 * @property-read int $scannedElementCount
 * @property-read string[] $axeTags
 */
class AuditService extends Component
{
    /**
     * @var int The maximum number of distinct elements a Standard-edition
     * install may scan. Re-scanning an already-scanned element never counts
     * against this cap. This is an edition boundary, not a user setting.
     */
    public const STANDARD_SCAN_LIMIT = 100;

    /**
     * @var int The maximum scan-history retention, in days, on the Standard
     * edition. Longer or unlimited retention is a Pro feature. This is an
     * edition boundary, not a user setting.
     */
    public const STANDARD_RETENTION_CAP = 30;

    /**
     * @var string The desktop viewport bucket for browser-sourced findings.
     */
    public const VIEWPORT_DESKTOP = 'desktop';

    /**
     * @var string The mobile viewport bucket for browser-sourced findings.
     */
    public const VIEWPORT_MOBILE = 'mobile';

    /**
     * @var int Widest window (in CSS pixels) that still buckets as mobile.
     * Used to classify overlay scans, which report the admin's actual window
     * width rather than a fixed logical width.
     */
    public const MOBILE_MAX_WIDTH = 767;

    private const SEVERITY_WEIGHT = ['error' => 10, 'warning' => 4, 'notice' => 1];

    /**
     * @var array<string, string> Axe rules whose finding duplicates a PHP
     * scanner rule. When the PHP scanner has already flagged the equivalent
     * rule on a scan, the axe violation is skipped so the same problem isn't
     * counted (and score-penalised) twice. Only rules that genuinely detect
     * the same failure are mapped; axe-only rules (target-size, color-contrast,
     * landmark-unique, …) always store.
     */
    /**
     * @var string Rule id for a contrast node axe could not measure. Public
     * because the rule registry and the report UI both key off it, and a bare
     * literal in three places drifts silently.
     */
    public const RULE_POTENTIAL_CONTRAST = 'potential:contrast-unmeasurable';

    private const AXE_EQUIVALENT_PHP_RULES = [
        'image-alt' => 'img-alt',
        'heading-order' => 'heading-order',
        'empty-heading' => 'empty-heading',
        'link-name' => 'link-name',
        'button-name' => 'button-name',
        'label' => 'form-label',
        'select-name' => 'select-label',
        'html-has-lang' => 'html-lang',
        'document-title' => 'page-title',
        'bypass' => 'skip-link',
        'landmark-one-main' => 'landmark-main',
        'frame-title' => 'iframe-title',
        'video-caption' => 'video-captions',
        'no-autoplay-audio' => 'autoplay',
        'duplicate-id' => 'duplicate-id',
        'duplicate-id-active' => 'duplicate-id',
        'duplicate-id-aria' => 'duplicate-id',
        'aria-hidden-focus' => 'aria-hidden-focus',
        'region' => 'landmark-regions',
        'list' => 'list-structure',
        'listitem' => 'list-structure',
    ];

    // ─── Queue ───────────────────────────────────────────────────────────────

    public function queueScan(Element $element): void
    {
        Craft::$app->getQueue()->push(new ScanElementJob([
            'elementId' => $element->id,
            'elementType' => get_class($element),
            'siteId' => $element->siteId,
        ]));
    }

    // ─── axe-core configuration ──────────────────────────────────────────────

    /**
     * The axe-core tag list every browser engine scans with, derived from the
     * WCAG level and EN 301 549 settings. The single source for the frontend
     * overlay, the Inspect preview, and the headless scanner, so the three
     * engines can never quietly scan different rule sets.
     *
     * @return string[]
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getAxeTags(): array
    {
        $settings = AccessibilityAudit::getInstance()->getSettings();

        $tags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];

        if (strtoupper($settings->wcagLevel ?? 'AA') === 'AAA') {
            $tags[] = 'wcag2aaa';
        }

        if ((bool)($settings->en301549 ?? false)) {
            $tags[] = 'EN-301-549';
        }

        return $tags;
    }

    /**
     * The CSS selectors excluded from every scan surface, in axe's context
     * shape: one selector per entry, each wrapped in its own array. All three
     * browser engines (headless, Inspect preview, frontend overlay) read this
     * so an exclusion holds everywhere at once; the PHP scanner applies the
     * same list by removing matching nodes before its checks.
     *
     * @return string[][]
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getAxeExclude(): array
    {
        return array_map(
            static fn(string $selector): array => [$selector],
            AccessibilityAudit::getInstance()->getSettings()->resolvedExcludedSelectors(),
        );
    }

    // ─── Edition limits ──────────────────────────────────────────────────────

    /**
     * Returns the number of distinct elements that have been scanned across all
     * sites. Used to enforce the Standard-edition scan cap. Intentionally global
     * (not scoped to a single site) to stay consistent with this plugin's other
     * multi-site features.
     */
    public function getScannedElementCount(): int
    {
        return (int) (new Query())
            ->from('{{%accessibilityaudit_scans}}')
            ->count('DISTINCT [[elementId]]');
    }

    /**
     * Whether a scan may be created for the given element in the given site.
     *
     * Always allowed on the Pro edition, and always allowed when the element has
     * already been scanned in this site (re-scanning is never capped). Otherwise
     * the Standard-edition distinct-element cap applies.
     */
    public function canScanNewElement(int $elementId, int $siteId): bool
    {
        if (AccessibilityAudit::getInstance()->is(AccessibilityAudit::EDITION_PRO)) {
            return true;
        }

        $alreadyScanned = (new Query())
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->exists();

        if ($alreadyScanned) {
            return true;
        }

        return $this->getScannedElementCount() < self::STANDARD_SCAN_LIMIT;
    }

    // ─── URI exclusion ───────────────────────────────────────────────────────

    /**
     * Whether a URI is excluded from scanning by the settings' (or config
     * file's) excludedUriPatterns. Each row is a regular expression tested
     * against the URI, optionally scoped to a single site; an empty pattern
     * matches the homepage. The single gate every scan path consults, so a
     * page excluded here is skipped by the PHP scan, the browser pass, and the
     * axe/contrast overlay writes alike.
     *
     * @param string|null $uri The element URI ('__home__' or null is the homepage).
     * @param int $siteId The site the URI belongs to.
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function isUriExcluded(?string $uri, int $siteId): bool
    {
        $patterns = AccessibilityAudit::getInstance()->getSettings()->excludedUriPatterns ?? [];
        if (empty($patterns)) {
            return false;
        }

        // Normalise the URI to what the patterns are written against: no leading
        // slash, and the homepage as an empty string (Craft stores it as
        // '__home__'). Matching the homepage is then an empty pattern.
        $uri = ($uri === null || $uri === '__home__') ? '' : ltrim($uri, '/');

        foreach ($patterns as $row) {
            if (!($row['enabled'] ?? true)) {
                continue;
            }

            $rowSite = $row['siteId'] ?? '';
            if ($rowSite !== '' && $rowSite !== null && (int)$rowSite !== $siteId) {
                continue;
            }

            $pattern = trim((string)($row['uriPattern'] ?? ''));

            if ($pattern === '') {
                if ($uri === '') {
                    return true;
                }
                continue;
            }

            if ($this->_uriMatchesPattern($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tests a URI against one pattern as a regular expression.
     *
     * The `~` delimiter means slashes in the pattern need no escaping; a literal
     * `~` is escaped instead. A malformed pattern makes preg_match return false
     * (never 1), so it just doesn't match; the scoped error handler swallows the
     * warning PHP would otherwise emit for it, without reaching for `@`.
     *
     * @param string $pattern The URI pattern (a regular expression).
     * @param string $uri The normalised URI.
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _uriMatchesPattern(string $pattern, string $uri): bool
    {
        $delimited = '~' . str_replace('~', '\~', $pattern) . '~';
        set_error_handler(static fn(): bool => true);
        try {
            return preg_match($delimited, $uri) === 1;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Whether an element is excluded from scanning, by either its element type
     * (not in the configured scan set) or its URI (matching an excluded
     * pattern). Gates scan-on-save and single-element scans alike.
     *
     * @param Element $element The element.
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function isElementExcluded(Element $element): bool
    {
        $scanned = AccessibilityAudit::getInstance()->getSettings()->resolvedScannedElementTypes();
        if (!in_array($element::class, $scanned, true)) {
            return true;
        }

        return $this->isUriExcluded($element->uri, (int)$element->siteId);
    }

    /**
     * The scan-record ids whose page is currently excluded from scanning, so a
     * cleanup pass can remove pages that were scanned before their exclusion
     * (their stale score and issues otherwise linger in every report). The URI
     * is read from elements_sites, which the scans table doesn't carry itself.
     *
     * @return int[]
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getExcludedScanIds(): array
    {
        if (empty(AccessibilityAudit::getInstance()->getSettings()->excludedUriPatterns)) {
            return [];
        }

        $rows = (new Query())
            ->select(['s.id', 's.siteId', 'es.uri'])
            ->from(['s' => '{{%accessibilityaudit_scans}}'])
            ->leftJoin(
                ['es' => '{{%elements_sites}}'],
                '[[es.elementId]] = [[s.elementId]] AND [[es.siteId]] = [[s.siteId]]'
            )
            ->all();

        return $this->filterExcludedScanRows($rows);
    }

    /**
     * Filters scan rows down to the ids whose URI is excluded. Split out from
     * {@see self::getExcludedScanIds()} so the exclusion matching is testable
     * without seeding scans and their element URIs.
     *
     * @param array<int, array{id: int|string, siteId: int|string, uri: string|null}> $rows
     * @return int[]
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function filterExcludedScanRows(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($this->isUriExcluded($row['uri'] ?? null, (int)$row['siteId'])) {
                $ids[] = (int)$row['id'];
            }
        }

        return $ids;
    }

    /**
     * Removes every scan record (and its cascaded issues) for pages currently
     * excluded from scanning. The issue count is read before the delete, since
     * the rows are gone once the scans are, and returned for reporting.
     *
     * @return array{pages: int, issues: int} What was removed.
     * @throws Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function pruneExcludedPages(): array
    {
        $scanIds = $this->getExcludedScanIds();
        if (empty($scanIds)) {
            return ['pages' => 0, 'issues' => 0];
        }

        $issueCount = (int)(new Query())
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanIds])
            ->count();

        // issues.scanId is ON DELETE CASCADE, so deleting the scans clears their
        // issues in the same step.
        Craft::$app->getDb()->createCommand()
            ->delete('{{%accessibilityaudit_scans}}', ['id' => $scanIds])
            ->execute();

        return ['pages' => count($scanIds), 'issues' => $issueCount];
    }

    /**
     * Removes every scan record (and its cascaded issues) for the given element
     * type class names. Called when a type is newly excluded from scanning, so
     * its stale scores and issues drop out of reports at once rather than
     * lingering until the next sweep would have skipped them.
     *
     * @param class-string[] $elementTypes The element type class names to purge.
     * @return int The number of scan records removed.
     * @throws Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function pruneScansForElementTypes(array $elementTypes): int
    {
        if ($elementTypes === []) {
            return 0;
        }

        // issues.scanId is ON DELETE CASCADE, so deleting the scans clears their
        // issues in the same step.
        return Craft::$app->getDb()->createCommand()
            ->delete('{{%accessibilityaudit_scans}}', ['elementType' => $elementTypes])
            ->execute();
    }

    // ─── Scan element (any type) ─────────────────────────────────────────────

    /** @return array{scanId: int, score: int, issues: IssueModel[], limitReached?: bool} */
    /**
     * @param ElementInterface $element The element to scan.
     * @param bool $withHeadless Whether to queue the server-side browser pass
     * (when available). The Inspect page passes false: its preview runs the
     * browser checks itself, and two browser writers racing on one scan is
     * exactly what makes results flip-flop.
     * @throws Throwable
     */
    public function scanElement(ElementInterface $element, bool $withHeadless = true): array
    {
        assert($element instanceof Element);
        $url = $element->getUrl();
        if (!$url) {
            return ['scanId' => 0, 'score' => 100, 'issues' => []];
        }

        if ($this->isElementExcluded($element)) {
            return ['scanId' => 0, 'score' => 100, 'issues' => [], 'excluded' => true];
        }

        if (!$this->canScanNewElement($element->id, $element->siteId)) {
            return ['scanId' => 0, 'score' => 0, 'issues' => [], 'limitReached' => true];
        }

        $html = $this->fetchHtml($url);
        if ($html === null) {
            return ['scanId' => 0, 'score' => 100, 'issues' => []];
        }

        $result = $this->processHtml($html, $element->id, get_class($element), $element->siteId);

        // When server-side Chrome is configured (Pro), queue a full browser
        // pass for contrast, focus, and target-size findings. Queued, not
        // inline, so a synchronous sidebar scan stays fast.
        if ($withHeadless && ($result['scanId'] ?? 0) > 0 && AccessibilityAudit::getInstance()->headless->isAvailable()) {
            // Name the job after the page: a site-wide scan queues one of
            // these per page, and a wall of identical "Browser accessibility
            // checks" rows tells nobody what is actually being worked through.
            $pageLabel = ElementLabel::for($element, $element->id, $url);

            Craft::$app->getQueue()->push(new HeadlessScanJob([
                'scanId' => (int) $result['scanId'],
                'url' => $url,
                'description' => Craft::t('accessibility-audit', 'Browser accessibility checks: {page}', [
                    'page' => $pageLabel,
                ]),
            ]));
        }

        return $result;
    }

    // ─── Discover all URL-bearing elements for a site ────────────────────────

    /**
     * Returns elementId + elementType rows for every live, non-draft,
     * non-revision element that has a public URI in the given site.
     * This covers entries, categories, Commerce products, and any other
     * element type that registers URI routes.
     *
     * @return array<array-key, array{elementId: int, elementType: string}>
     */
    public function getUrlElements(int $siteId): array
    {
        return $this->getUrlElementsQuery($siteId)->all();
    }

    /**
     * Returns the (unexecuted) query behind {@see self::getUrlElements()}.
     *
     * Exposed so a batched queue job can wrap it in a QueryBatcher and process
     * the result set in memory-safe chunks rather than loading every row at once.
     */
    public function getUrlElementsQuery(int $siteId): Query
    {
        return (new Query())
            ->select(['es.elementId', 'e.type as elementType'])
            ->from(['es' => '{{%elements_sites}}'])
            ->innerJoin(['e' => '{{%elements}}'], 'e.id = es.elementId')
            ->where([
                'es.siteId' => $siteId,
                'es.enabled' => true,
                'e.enabled' => true,
                'e.draftId' => null,
                'e.revisionId' => null,
                'e.dateDeleted' => null,
            ])
            ->andWhere(['not', ['es.uri' => null]])
            ->andWhere(['<>', 'es.uri', ''])
            // Cover only the configured element types. The resolved list never
            // includes Asset (handled by the alt-text audit), so this replaces
            // the old blanket Asset exclusion. An empty list scans nothing.
            ->andWhere(['e.type' => AccessibilityAudit::getInstance()->getSettings()->resolvedScannedElementTypes()])
            // Deterministic order is load-bearing for the batched queue job:
            // QueryBatcher pages with LIMIT/OFFSET, and without a total order
            // the database may return rows in a different order per page,
            // silently skipping some elements and scanning others twice.
            ->orderBy(['es.elementId' => SORT_ASC]);
    }

    /** @return array{scanId: int, score: int, issues: IssueModel[], limitReached?: bool} */
    public function scanHtml(string $html, int $elementId, string $elementType, int $siteId): array
    {
        return $this->processHtml($html, $elementId, $elementType, $siteId);
    }

    // ─── axe-core results ────────────────────────────────────────────────────

    /**
     * Find or create a scan record for an element so axe results can be stored
     * even when no PHP scan has been run yet. Returns an existing scan if one
     * exists within the last 24 hours, otherwise inserts a fresh record.
     *
     * Returns 0 without inserting when the Standard-edition scan cap has been
     * reached and this element has never been scanned before, so the frontend
     * axe-core overlay can't bypass the cap.
     *
     * @throws \Exception
     */
    public function ensureScan(int $elementId, string $elementType, int $siteId): int
    {
        // Block the overlay from writing a scan for an excluded element type.
        // The type is posted with the results, so no element load is needed;
        // an empty type is left to the checks below rather than assumed out.
        if (
            $elementType !== '' &&
            !in_array($elementType, AccessibilityAudit::getInstance()->getSettings()->resolvedScannedElementTypes(), true)
        ) {
            return 0;
        }

        // Block the axe/contrast overlay from writing a scan for an excluded
        // page. The element is only loaded when patterns exist, to avoid an
        // extra query on the common (none) path.
        if (!empty(AccessibilityAudit::getInstance()->getSettings()->excludedUriPatterns)) {
            $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
            if ($element instanceof Element && $this->isUriExcluded($element->uri, $siteId)) {
                return 0;
            }
        }

        $existing = (new Query())
            ->select(['id'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->andWhere(['>=', 'dateScanned', Db::prepareDateForDb((new DateTime())->modify('-24 hours'))])
            ->orderBy(['dateScanned' => SORT_DESC])
            ->scalar();

        if ($existing) {
            return (int) $existing;
        }

        if (!$this->canScanNewElement($elementId, $siteId)) {
            return 0;
        }

        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
            'elementId' => $elementId,
            'elementType' => $elementType ?: Element::class,
            'siteId' => $siteId,
            'score' => 100,
            'scoreA' => 100,
            'scoreAA' => 100,
            'scoreAAA' => 100,
            'errorCount' => 0,
            'warningCount' => 0,
            'noticeCount' => 0,
            'dateScanned' => Db::prepareDateForDb(new DateTime()),
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');
    }

    /**
     * Resolves the viewport bucket for a browser scan run at the given window
     * width. Overlay scans report the admin's actual window width; fixed-width
     * engines (headless, Inspect preview) pass their bucket explicitly instead.
     *
     * @param int $width The window width in CSS pixels.
     * @return string One of the VIEWPORT_* constants.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function viewportForWidth(int $width): string
    {
        if ($width > 0 && $width <= self::MOBILE_MAX_WIDTH) {
            return self::VIEWPORT_MOBILE;
        }

        return self::VIEWPORT_DESKTOP;
    }

    /**
     * Stores axe-core violations against a scan, replacing only the same
     * viewport's previous axe results. Buckets are unioned rather than
     * last-writer-wins: a desktop pass never wipes mobile findings (and vice
     * versa), so scores stay stable across browser runs from different window
     * widths.
     *
     * @throws Exception
     * @throws \Exception
     */
    public function storeAxeIssues(
        int $scanId,
        array $axeViolations,
        string $viewport = self::VIEWPORT_DESKTOP,
        array $axeIncomplete = [],
    ): void {
        $scan = (new Query())
            ->select(['elementId', 'elementType', 'siteId'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['id' => $scanId])
            ->one();

        if (!$scan) {
            return;
        }

        // Remove stale axe results for this viewport only: each run replaces
        // its own bucket, the other viewport's findings stand. The desktop
        // bucket also owns untagged (null-viewport) rows, since those predate
        // viewport tagging and came from desktop-width passes.
        Craft::$app->getDb()->createCommand()
            ->delete('{{%accessibilityaudit_issues}}', [
                'scanId' => $scanId,
                'source' => 'axe',
                'viewport' => $this->_viewportBucketCondition($viewport),
            ])
            ->execute();

        // Rules the PHP scanner already flagged on this scan: axe violations
        // that duplicate one of them are skipped, so the same problem isn't
        // counted and score-penalised twice by two engines.
        $phpRules = (new Query())
            ->select(['ruleId'])
            ->distinct()
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId, 'source' => 'php', 'isResolved' => false])
            ->column();

        foreach ($axeViolations as $violation) {
            $wcag = $this->extractWcagFromAxe($violation);
            $axeId = $violation['id'] ?? 'unknown';

            $equivalent = self::AXE_EQUIVALENT_PHP_RULES[$axeId] ?? null;
            if ($equivalent !== null && in_array($equivalent, $phpRules, true)) {
                continue;
            }
            $ruleId = $this->_axeRuleId($axeId);
            $nodes = $violation['nodes'] ?? [];

            // color-contrast: store every node with per-occurrence colour data
            if ($axeId === 'color-contrast' && !empty($nodes)) {
                foreach ($nodes as $node) {
                    $colorData = $node['any'][0]['data'] ?? [];
                    $ratio = $colorData['contrastRatio'] ?? null;
                    $fg = $colorData['fgColor'] ?? null;
                    $bg = $colorData['bgColor'] ?? null;
                    $expected = $colorData['expectedContrastRatio'] ?? null;

                    $message = ($ratio !== null && $fg && $bg)
                        ? sprintf('Contrast %s:1 (need %s) · Text %s on %s', round((float)$ratio, 2), $expected ?? '4.5:1', $fg, $bg)
                        : ($violation['description'] ?? 'Insufficient colour contrast');

                    $target = $node['target'][0] ?? null;
                    $context = Json::encode([
                        'html' => mb_substr($node['html'] ?? '', 0, 300),
                        'fg' => $fg,
                        'bg' => $bg,
                        'ratio' => $ratio,
                        'expected' => $expected,
                        // axe's own target selector for the node, so the report
                        // can highlight the exact element.
                        'selector' => is_string($target) ? mb_substr($target, 0, 300) : '',
                    ]);

                    $this->insertIssue($scanId, $scan['elementId'], $scan['elementType'], $scan['siteId'], IssueModel::make(
                        ruleId: $ruleId,
                        severity: $this->axeImpactToSeverity($violation['impact'] ?? 'serious'),
                        message: $message,
                        wcagCriterion: $wcag['criterion'] ?? null,
                        wcagLevel: $wcag['level'] ?? null,
                        context: $context,
                        helpUrl: $violation['helpUrl'] ?? null,
                        source: 'axe',
                        viewport: $viewport,
                    ));
                }
                continue;
            }

            // All other axe violations: one issue per violation (first-node context)
            $this->insertIssue($scanId, $scan['elementId'], $scan['elementType'], $scan['siteId'], IssueModel::make(
                ruleId: $ruleId,
                severity: $this->axeImpactToSeverity($violation['impact'] ?? 'moderate'),
                // help before description: axe's `help` states the failed
                // requirement ("<dl> elements must only directly contain…"),
                // while `description` documents what the rule checks
                // ("Ensures <dl> elements are structured correctly"), which
                // reads as a question, not a finding. The overlay makes the
                // same choice when it renders live results.
                message: $violation['help'] ?? $violation['description'] ?? 'Accessibility issue detected by axe-core.',
                wcagCriterion: $wcag['criterion'] ?? null,
                wcagLevel: $wcag['level'] ?? null,
                context: !empty($nodes[0]['html']) ? mb_substr($nodes[0]['html'], 0, 200) : null,
                helpUrl: $violation['helpUrl'] ?? null,
                source: 'axe',
                viewport: $viewport,
            ));
        }

        $this->_storeContrastNeedsReview($scanId, $scan, $axeIncomplete, $viewport);

        $this->recalculateScanScore($scanId);
    }

    // ─── Queries ─────────────────────────────────────────────────────────────

    public function getLatestScan(int $elementId, int $siteId): ?array
    {
        return (new Query())
            ->select(['id', 'score', 'scoreA', 'scoreAA', 'scoreAAA', 'errorCount', 'warningCount', 'noticeCount', 'dateScanned'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->orderBy(['dateScanned' => SORT_DESC])
            ->one() ?: null;
    }

    /**
     * Returns one scan's summary row by ID. Used after storing client-side
     * results so the overlay can repaint with the recalculated score.
     *
     * @param int $scanId The scan ID.
     * @return array|null
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getScanSummary(int $scanId): ?array
    {
        return (new Query())
            ->select(['id', 'score', 'errorCount', 'warningCount', 'noticeCount', 'dateScanned'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['id' => $scanId])
            ->one() ?: null;
    }

    /**
     * Returns the site a scan belongs to, or null when the scan does not exist.
     *
     * The scan owns the site its findings are written against, so a request
     * that supplies a raw `scanId` must be authorised against this site rather
     * than a separately posted one: the write (and read) always targets the
     * scan's own site.
     *
     * @param int $scanId The scan ID.
     * @return int|null The scan's site ID, or null when the scan is not found.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.1
     */
    public function getScanSiteId(int $scanId): ?int
    {
        $siteId = (new Query())
            ->select(['siteId'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['id' => $scanId])
            ->scalar();

        return $siteId !== false ? (int) $siteId : null;
    }

    public function getIssues(int $scanId): array
    {
        return (new Query())
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId])
            // Feeds the entry sidebar, the field, and craft.a11y. A dismissed
            // issue is excluded since the author has already answered it.
            //
            // The null branch is load-bearing: `NOT (verdict = 'dismissed')` is
            // NULL rather than true for an unreviewed row, so on its own it
            // would drop every issue that has no ruling.
            ->andWhere(['or',
                ['verdict' => null],
                ['not', ['verdict' => VerdictService::VERDICT_DISMISSED]],
            ])
            ->orderBy(['severity' => SORT_ASC])
            ->all();
    }

    /**
     * Returns definite issues for a scan grouped by rule, enriched with RuleRegistry metadata.
     * Sorted: errors first, then warnings, then notices; within each group by occurrence count desc.
     */
    public function getIssuesGroupedByScan(int $scanId): array
    {
        $rows = (new Query())
            ->select([
                'ruleId',
                'wcagCriterion',
                'wcagLevel',
                'severity',
                'MIN(message) as message',
                'MIN(helpUrl) as helpUrl',
                'MIN(context) as context',
                'COUNT(*) as occurrences',
            ])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId, 'isResolved' => false])
            ->andWhere($this->definiteCondition())
            ->groupBy(['ruleId', 'wcagCriterion', 'wcagLevel', 'severity'])
            ->all();

        foreach ($rows as &$row) {
            $meta = RuleRegistry::get($row['ruleId']);
            $row['difficulty'] = $meta['difficulty'];
            $row['responsibility'] = $meta['responsibility'];
            $row['elementType'] = $meta['elementType'];
        }
        unset($row);

        $order = ['error' => 0, 'warning' => 1, 'notice' => 2];
        usort($rows, static function(array $a, array $b) use ($order): int {
            $ao = $order[$a['severity']] ?? 3;
            $bo = $order[$b['severity']] ?? 3;
            if ($ao !== $bo) {
                return $ao - $bo;
            }
            return (int)$b['occurrences'] - (int)$a['occurrences'];
        });

        return $rows;
    }

    /**
     * Returns every individual occurrence (row) for a specific rule in a scan.
     * Used by the page-report sidebar to list each affected element.
     *
     * @return array<array-key, array{id: int, message: string, context: string|null, severity: string, viewport: string|null}>
     */
    public function getOccurrencesForRule(int $scanId, string $ruleId): array
    {
        return (new Query())
            ->select(['id', 'message', 'context', 'severity', 'viewport'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId, 'ruleId' => $ruleId, 'isResolved' => false])
            // Same filter as the grouped list this expands, so the occurrence
            // count matches the rows shown (excludes dismissed/unreviewed ones).
            ->andWhere($this->definiteCondition())
            ->orderBy(['viewport' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
    }

    public function getElementIssues(int $elementId, int $siteId): array
    {
        $scan = $this->getLatestScan($elementId, $siteId);
        return $scan ? $this->getIssues($scan['id']) : [];
    }

    // ─── Site-wide summary ───────────────────────────────────────────────────

    public function getSiteSummary(int $siteId): array
    {
        $latestIds = $this->getLatestScanIds($siteId);

        if (empty($latestIds)) {
            return [
                'scannedCount' => 0,
                'avgScore' => 0,
                'avgScoreA' => 0,
                'avgScoreAA' => 0,
                'avgScoreAAA' => 0,
                'errorCount' => 0,
                'warningCount' => 0,
                'noticeCount' => 0,
                'criticalPages' => 0,
                'failingCriteriaA' => 0,
                'failingCriteriaAA' => 0,
                'failingCriteriaAAA' => 0,
            ];
        }

        $scans = (new Query())
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['id' => $latestIds])
            ->all();

        $count = count($scans);
        $avg = fn($col) => (int) round(array_sum(array_column($scans, $col)) / $count);

        // Distinct WCAG success criteria with at least one definite, unresolved
        // failure, bucketed by the criterion's own level. No "passing" count:
        // most criteria aren't machine-testable, so only failures are known.
        $failByLevel = ['A' => 0, 'AA' => 0, 'AAA' => 0];
        $failingRows = (new Query())
            ->select(['wcagLevel', 'COUNT(DISTINCT wcagCriterion) as n'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $latestIds, 'isResolved' => false])
            ->andWhere($this->definiteCondition())
            ->andWhere(['not', ['wcagCriterion' => null]])
            ->andWhere(['wcagLevel' => ['A', 'AA', 'AAA']])
            ->groupBy(['wcagLevel'])
            ->all();
        foreach ($failingRows as $row) {
            $failByLevel[$row['wcagLevel']] = (int)$row['n'];
        }

        return [
            'scannedCount' => $count,
            'avgScore' => $avg('score'),
            'avgScoreA' => $avg('scoreA'),
            'avgScoreAA' => $avg('scoreAA'),
            'avgScoreAAA' => $avg('scoreAAA'),
            'errorCount' => (int) array_sum(array_column($scans, 'errorCount')),
            'warningCount' => (int) array_sum(array_column($scans, 'warningCount')),
            'noticeCount' => (int) array_sum(array_column($scans, 'noticeCount')),
            'criticalPages' => count(array_filter($scans, fn($s) => (int) $s['errorCount'] > 0)),
            // Cumulative, like the level scores: AA conformance also requires A,
            // AAA requires A and AA. A criterion has one level, so summing the
            // buckets never double-counts.
            'failingCriteriaA' => $failByLevel['A'],
            'failingCriteriaAA' => $failByLevel['A'] + $failByLevel['AA'],
            'failingCriteriaAAA' => $failByLevel['A'] + $failByLevel['AA'] + $failByLevel['AAA'],
        ];
    }

    // ─── Issues by rule / impact ─────────────────────────────────────────────

    /**
     * The rule IDs on the plugin's ignore list. Ignored rules are muted, not
     * fixed, so the rule-level Issues and Resolved queries filter them out even
     * for issues stored before the rule was ignored (a re-scan clears the rest).
     *
     * @return string[] The ignored rule IDs, empty when none are set.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _ignoredRuleIds(): array
    {
        return AccessibilityAudit::getInstance()->getSettings()->ignoreRules ?? [];
    }

    /** Returns issues grouped by rule, sorted by impact (occurrences × severity weight). */
    public function getIssuesByImpact(int $siteId, int $limit = 20): array
    {
        $latestIds = $this->getLatestScanIds($siteId);
        if (empty($latestIds)) {
            return [];
        }

        $query = (new Query())
            ->select(['ruleId', 'wcagCriterion', 'wcagLevel', 'severity', 'COUNT(*) as occurrences'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $latestIds, 'isResolved' => false])
            ->andWhere($this->definiteCondition());
        $ignored = $this->_ignoredRuleIds();
        if (!empty($ignored)) {
            $query->andWhere(['not in', 'ruleId', $ignored]);
        }
        $rows = $query
            ->groupBy(['ruleId', 'wcagCriterion', 'wcagLevel', 'severity'])
            ->all();

        foreach ($rows as &$row) {
            $weight = self::SEVERITY_WEIGHT[$row['severity']] ?? 1;
            $row['impactScore'] = (int) $row['occurrences'] * $weight;
            $row['pointsToGain'] = round(min(2, $row['impactScore'] / 500), 2);

            $meta = RuleRegistry::get($row['ruleId']);
            $row['difficulty'] = $meta['difficulty'];
            $row['responsibility'] = $meta['responsibility'];
            $row['elementType'] = $meta['elementType'];
            $row['abilities'] = $meta['abilities'];
        }
        unset($row);

        usort($rows, static fn($a, $b) => $b['impactScore'] <=> $a['impactScore']);

        return array_slice($rows, 0, $limit);
    }

    /** @deprecated Use getIssuesByImpact() */
    public function getIssuesByRule(int $siteId, int $limit = 20): array
    {
        return $this->getIssuesByImpact($siteId, $limit);
    }

    // ─── Template issues ─────────────────────────────────────────────────────

    /** Rules that appear on $threshold or more pages: indicates a template-level problem. */
    public function getTemplateIssues(int $siteId, int $threshold = 5): array
    {
        $latestIds = $this->getLatestScanIds($siteId);
        if (empty($latestIds)) {
            return [];
        }

        $query = (new Query())
            ->select(['ruleId', 'wcagCriterion', 'wcagLevel', 'severity', 'COUNT(DISTINCT elementId) as pageCount', 'COUNT(*) as occurrences'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $latestIds, 'isResolved' => false])
            ->andWhere($this->definiteCondition());
        $ignored = $this->_ignoredRuleIds();
        if (!empty($ignored)) {
            $query->andWhere(['not in', 'ruleId', $ignored]);
        }

        return $query
            ->groupBy(['ruleId', 'wcagCriterion', 'wcagLevel', 'severity'])
            ->having(['>=', 'COUNT(DISTINCT elementId)', $threshold])
            ->orderBy(['pageCount' => SORT_DESC])
            ->all();
    }

    // ─── Resolved issues ─────────────────────────────────────────────────────

    /** Issues from the previous scan that no longer appear in the latest scan. */
    public function getResolvedIssues(int $siteId, int $limit = 20): array
    {
        // Get the two most recent scan IDs per element
        $db = Craft::$app->getDb();

        $query = (new Query())
            ->select(['i.ruleId', 'i.wcagCriterion', 'i.severity', 'MAX(i.dateResolved) as dateResolved', 'COUNT(*) as count'])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->where(['s.siteId' => $siteId, 'i.isResolved' => true])
            // A potential issue that stops appearing isn't a fix unless it was
            // first confirmed as a failure. Dismissing one as "not an issue"
            // resolves nothing, since nothing was ever established as wrong.
            ->andWhere($this->definiteCondition('i'));
        $ignored = $this->_ignoredRuleIds();
        if (!empty($ignored)) {
            $query->andWhere(['not in', 'i.ruleId', $ignored]);
        }

        return $query
            ->groupBy(['i.ruleId', 'i.wcagCriterion', 'i.severity'])
            ->orderBy(['MAX(i.dateResolved)' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Returns resolved issues grouped by rule for the Issues page "Resolved"
     * tab, enriched (difficulty / responsibility / element type) and scored the
     * same way as getIssuesByImpact, plus the points recovered by resolving them
     * and the most recent resolution date. Newest resolutions first. Excludes
     * potential (manual-review) rules, which are never auto-resolved.
     *
     * @param int $siteId The site to report resolved issues for.
     * @param int $limit The maximum number of rules to return.
     * @return array<int, array<string, mixed>>
     * @throws Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getResolvedIssuesByImpact(int $siteId, int $limit = 500): array
    {
        $query = (new Query())
            ->select([
                'i.ruleId',
                'i.wcagCriterion',
                'i.wcagLevel',
                'i.severity',
                'COUNT(*) as occurrences',
                'MAX(i.dateResolved) as dateResolved',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->where(['s.siteId' => $siteId, 'i.isResolved' => true])
            ->andWhere($this->definiteCondition('i'));
        $ignored = $this->_ignoredRuleIds();
        if (!empty($ignored)) {
            $query->andWhere(['not in', 'i.ruleId', $ignored]);
        }
        $rows = $query
            ->groupBy(['i.ruleId', 'i.wcagCriterion', 'i.wcagLevel', 'i.severity'])
            ->all();

        foreach ($rows as &$row) {
            $weight = self::SEVERITY_WEIGHT[$row['severity']] ?? 1;
            $impactScore = (int)$row['occurrences'] * $weight;
            $row['pointsGained'] = round(min(2, $impactScore / 500), 2);

            $meta = RuleRegistry::get($row['ruleId']);
            $row['difficulty'] = $meta['difficulty'];
            $row['responsibility'] = $meta['responsibility'];
            $row['elementType'] = $meta['elementType'];
            $row['abilities'] = $meta['abilities'];
        }
        unset($row);

        usort($rows, static fn($a, $b) => strcmp((string)$b['dateResolved'], (string)$a['dateResolved']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * Returns the daily count of issues resolved over the last $sinceDays for the
     * site, oldest day first, so the Resolved tab can chart remediation momentum
     * as a cumulative line. Excludes potential (manual-review) rules, which are
     * never auto-resolved.
     *
     * @param int $siteId The site to report on.
     * @param int $sinceDays The size of the trailing window in days.
     * @return array<int, array{day: string, resolved: int}>
     * @throws Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getResolvedTrend(int $siteId, int $sinceDays = 90): array
    {
        $since = Db::prepareDateForDb((new DateTime())->modify("-{$sinceDays} days"));

        $query = (new Query())
            ->select([
                new Expression('DATE([[i.dateResolved]]) as day'),
                'COUNT(*) as resolved',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->where(['s.siteId' => $siteId, 'i.isResolved' => true])
            ->andWhere($this->definiteCondition('i'))
            ->andWhere(['>=', 'i.dateResolved', $since]);
        $ignored = $this->_ignoredRuleIds();
        if (!empty($ignored)) {
            $query->andWhere(['not in', 'i.ruleId', $ignored]);
        }
        $rows = $query
            ->groupBy([new Expression('DATE([[i.dateResolved]])')])
            ->orderBy(new Expression('DATE([[i.dateResolved]]) ASC'))
            ->all();

        return array_map(static fn(array $r): array => [
            'day' => (string)$r['day'],
            'resolved' => (int)$r['resolved'],
        ], $rows);
    }

    // ─── Per-element resolved issues ─────────────────────────────────────────

    /** Resolved issues for a specific element: for the page report view. */
    public function getResolvedIssuesForElement(int $elementId, int $siteId, int $limit = 30): array
    {
        return (new Query())
            ->select([
                'i.ruleId',
                'i.wcagCriterion',
                'i.severity',
                'MAX(i.dateResolved) as dateResolved',
                'COUNT(*) as count',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->where(['i.elementId' => $elementId, 's.siteId' => $siteId, 'i.isResolved' => true])
            // Same rule as every other resolved listing: see getResolvedIssues().
            ->andWhere($this->definiteCondition('i'))
            ->groupBy(['i.ruleId', 'i.wcagCriterion', 'i.severity'])
            ->orderBy(['MAX(i.dateResolved)' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    // ─── Issue detail ────────────────────────────────────────────────────────

    /**
     * Returns aggregate data for a single rule across the site's latest scans.
     * Returns an empty array when the rule has no active issues.
     */
    public function getIssueRuleSummary(string $ruleId, int $siteId): array
    {
        $latestIds = $this->getLatestScanIds($siteId);
        if (empty($latestIds)) {
            return [];
        }

        $row = (new Query())
            ->select([
                'MIN(i.message) as message',
                'MIN(i.helpUrl) as helpUrl',
                'MIN(i.wcagCriterion) as wcagCriterion',
                'MIN(i.wcagLevel) as wcagLevel',
                'MIN(i.severity) as severity',
                'COUNT(DISTINCT i.elementId) as pageCount',
                'COUNT(*) as occurrences',
                'MIN(i.firstDetected) as firstDetected',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->where(['i.scanId' => $latestIds, 'i.ruleId' => $ruleId, 'i.isResolved' => false])
            ->one();

        if (!$row || !(int)$row['occurrences']) {
            return [];
        }

        $weight = self::SEVERITY_WEIGHT[$row['severity'] ?? 'notice'] ?? 1;
        $row['pointsToGain'] = round(min(2, (int)$row['occurrences'] * $weight / 500), 2);

        return $row;
    }

    /**
     * Returns a paginated list of elements that have active issues for $ruleId,
     * with occurrence counts and the loaded Craft element when available.
     * Pagination and the optional title search are pushed into SQL so this stays
     * cheap on sites with tens of thousands of pages.
     *
     * @param string $ruleId The rule to list affected pages for.
     * @param int $siteId The site to scope to.
     * @param int $page Current 1-based page number.
     * @param int $perPage Items per page.
     * @param string $search Optional case-insensitive page-title filter.
     * @param string $orderBy Column to sort on: title, score, firstDetected, dateScanned, or occurrences (default).
     * @param int $orderDir SORT_ASC or SORT_DESC.
     * @return array{total: int, entries: array<array-key, array{elementId: int, occurrences: int, firstDetected: ?string, context: ?string, score: int, dateScanned: ?string, element: ?Element}>}
     * @throws \yii\base\Exception
     */
    public function getPagesForRule(string $ruleId, int $siteId, int $page = 1, int $perPage = 25, string $search = '', string $orderBy = 'occurrences', int $orderDir = SORT_DESC): array
    {
        $latestIds = $this->getLatestScanIds($siteId);
        if (empty($latestIds)) {
            return ['total' => 0, 'entries' => []];
        }

        $search = trim($search);

        $orderColumn = match ($orderBy) {
            'title' => 'es.title',
            'score' => 's.score',
            'firstDetected' => 'firstDetected',
            'dateScanned' => 's.dateScanned',
            default => 'occurrences',
        };
        $needsTitleJoin = $search !== '' || $orderColumn === 'es.title';

        // The page title lives in Craft's elements_sites table, not the plugin's
        // issue tables, so it's joined only when it's searched or sorted on.
        // Applied to both queries so pagination stays correct.
        $applyTitle = static function(Query $query) use ($needsTitleJoin, $search, $siteId): void {
            if (!$needsTitleJoin) {
                return;
            }
            $query->innerJoin(
                ['es' => '{{%elements_sites}}'],
                ['and', '[[es.elementId]] = [[i.elementId]]', ['es.siteId' => $siteId]],
            );
            if ($search !== '') {
                $query->andWhere(['like', 'es.title', $search]);
            }
        };

        $totalQuery = (new Query())
            ->select(['COUNT(DISTINCT i.elementId)'])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->where(['i.scanId' => $latestIds, 'i.ruleId' => $ruleId, 'i.isResolved' => false]);
        $applyTitle($totalQuery);
        $total = (int)$totalQuery->scalar();

        // es.title joins the GROUP BY when sorting on it; it's 1:1 with the
        // element, so the grouping is unchanged.
        $groupBy = ['i.elementId', 's.score', 's.dateScanned'];
        if ($needsTitleJoin) {
            $groupBy[] = 'es.title';
        }

        $rowsQuery = (new Query())
            ->select([
                'i.elementId',
                'COUNT(*) as occurrences',
                'MIN(i.firstDetected) as firstDetected',
                'MIN(i.context) as context',
                's.score',
                's.dateScanned',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->where(['i.scanId' => $latestIds, 'i.ruleId' => $ruleId, 'i.isResolved' => false])
            ->groupBy($groupBy)
            ->orderBy([$orderColumn => $orderDir])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage);
        $applyTitle($rowsQuery);
        $rows = $rowsQuery->all();

        $elementIds = array_column($rows, 'elementId');
        $elements = $this->loadElementsByIds($elementIds, $siteId);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'elementId' => (int)$row['elementId'],
                'occurrences' => (int)$row['occurrences'],
                'firstDetected' => $row['firstDetected'],
                'context' => $row['context'],
                'score' => (int)$row['score'],
                'dateScanned' => $row['dateScanned'],
                'element' => $elements[(int)$row['elementId']] ?? null,
            ];
        }

        return ['total' => $total, 'entries' => $result];
    }

    /**
     * Returns every affected page for $ruleId as flat export rows (title, uri,
     * score, count, dates) straight from the join, without hydrating Craft
     * elements, so a CSV export of the whole set stays cheap on very large sites.
     *
     * @param string $ruleId The rule to export affected pages for.
     * @param int $siteId The site to scope to.
     * @return array<int, array{elementId: int, title: ?string, uri: ?string, occurrences: int, score: int, firstDetected: ?string, dateScanned: ?string}>
     */
    public function getPagesForRuleExport(string $ruleId, int $siteId): array
    {
        $latestIds = $this->getLatestScanIds($siteId);
        if (empty($latestIds)) {
            return [];
        }

        return (new Query())
            ->select([
                'i.elementId',
                'es.title',
                'es.uri',
                'COUNT(*) as occurrences',
                'MIN(i.firstDetected) as firstDetected',
                's.score',
                's.dateScanned',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->leftJoin(
                ['es' => '{{%elements_sites}}'],
                ['and', '[[es.elementId]] = [[i.elementId]]', ['es.siteId' => $siteId]],
            )
            ->where(['i.scanId' => $latestIds, 'i.ruleId' => $ruleId, 'i.isResolved' => false])
            ->groupBy(['i.elementId', 'es.title', 'es.uri', 's.score', 's.dateScanned'])
            ->orderBy(['occurrences' => SORT_DESC])
            ->all();
    }

    /**
     * Returns the daily trend for $ruleId over the last $sinceDays: occurrences
     * and affected pages per day. Each day counts from the latest scan of each
     * element that day, so same-day re-scans don't inflate the totals. Naturally
     * bounded by the scan-retention window, since older scans are pruned.
     *
     * @param string $ruleId The rule to trend.
     * @param int $siteId The site to scope to.
     * @param int $sinceDays How many days back to include.
     * @return array<int, array{day: string, occurrences: int, pages: int}>
     */
    public function getRuleTrend(string $ruleId, int $siteId, int $sinceDays = 90): array
    {
        $cutoff = Db::prepareDateForDb((new DateTime())->modify(sprintf('-%d days', max(1, $sinceDays))));
        $day = new Expression('DATE([[s.dateScanned]])');

        // One scan per element per day (the latest, i.e. highest id), so multiple
        // same-day re-scans of a page don't multiply that day's counts.
        $latestPerDay = (new Query())
            ->select('MAX([[id]])')
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'dateScanned', $cutoff])
            ->groupBy(['elementId', new Expression('DATE([[dateScanned]])')]);

        $rows = (new Query())
            ->select([
                'day' => $day,
                'occurrences' => 'COUNT(*)',
                'pages' => 'COUNT(DISTINCT [[i.elementId]])',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], '[[s.id]] = [[i.scanId]]')
            ->where(['i.ruleId' => $ruleId, 'i.scanId' => $latestPerDay])
            ->groupBy([$day])
            ->orderBy(['day' => SORT_ASC])
            ->all();

        return array_map(static fn(array $row): array => [
            'day' => (string)$row['day'],
            'occurrences' => (int)$row['occurrences'],
            'pages' => (int)$row['pages'],
        ], $rows);
    }

    // ─── Potential issues ────────────────────────────────────────────────────

    /** Potential issues grouped by rule, sorted by page count. */
    public function getPotentialIssues(int $siteId): array
    {
        $latestIds = $this->getLatestScanIds($siteId);
        if (empty($latestIds)) {
            return [];
        }

        $rows = (new Query())
            ->select(['ruleId', 'wcagCriterion', 'wcagLevel', 'severity',
                      'COUNT(DISTINCT elementId) as pageCount', 'COUNT(*) as occurrences', ])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $latestIds])
            ->andWhere($this->pendingPotentialCondition())
            ->groupBy(['ruleId', 'wcagCriterion', 'wcagLevel', 'severity'])
            ->orderBy(['pageCount' => SORT_DESC, 'occurrences' => SORT_DESC])
            ->all();

        foreach ($rows as &$row) {
            $meta = RuleRegistry::get($row['ruleId']);
            $row['difficulty'] = $meta['difficulty'];
            $row['responsibility'] = $meta['responsibility'];
            $row['elementType'] = $meta['elementType'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Pages that have at least one potential issue. Pagination, the optional
     * title search, and sorting all run in SQL so it holds up on large sites.
     *
     * @param int $siteId The site to scope to.
     * @param int $page Current 1-based page number.
     * @param int $perPage Items per page.
     * @param string $search Optional case-insensitive page-title filter.
     * @param string $orderBy title, issues, dateScanned, or occurrences (default).
     * @param int $orderDir SORT_ASC or SORT_DESC.
     * @return array{total: int, entries: array<array-key, array{row: array<string, mixed>, entry: ?ElementInterface, element: ?ElementInterface}>}
     * @throws \yii\base\Exception
     */
    public function getPagesWithPotentialIssues(int $siteId, int $page = 1, int $perPage = 50, string $search = '', string $orderBy = 'occurrences', int $orderDir = SORT_DESC): array
    {
        $latestIds = $this->getLatestScanIds($siteId);
        if (empty($latestIds)) {
            return ['total' => 0, 'entries' => []];
        }

        $search = trim($search);
        $orderColumn = match ($orderBy) {
            'title' => 'es.title',
            'issues' => 'issueCount',
            'dateScanned' => 'dateScanned',
            default => 'occurrences',
        };
        $needsTitleJoin = $search !== '' || $orderColumn === 'es.title';

        // The page title lives in elements_sites, joined only when searched or
        // sorted on. Applied to both queries so pagination stays correct.
        $applyTitle = static function(Query $query) use ($needsTitleJoin, $search, $siteId): void {
            if (!$needsTitleJoin) {
                return;
            }
            $query->innerJoin(
                ['es' => '{{%elements_sites}}'],
                ['and', '[[es.elementId]] = [[s.elementId]]', ['es.siteId' => $siteId]],
            );
            if ($search !== '') {
                $query->andWhere(['like', 'es.title', $search]);
            }
        };

        $totalQuery = (new Query())
            ->select(['COUNT(DISTINCT s.elementId)'])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->where(['i.scanId' => $latestIds])
            ->andWhere($this->pendingPotentialCondition('i'));
        $applyTitle($totalQuery);
        $total = (int)$totalQuery->scalar();

        $groupBy = ['s.elementId'];
        if ($needsTitleJoin) {
            $groupBy[] = 'es.title';
        }

        $rowsQuery = (new Query())
            ->select(['s.elementId', 'COUNT(DISTINCT i.ruleId) as issueCount', 'COUNT(*) as occurrences', 'MAX(s.dateScanned) as dateScanned'])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->where(['i.scanId' => $latestIds])
            ->andWhere($this->pendingPotentialCondition('i'))
            ->groupBy($groupBy)
            ->orderBy([$orderColumn => $orderDir])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage);
        $applyTitle($rowsQuery);
        $rows = $rowsQuery->all();

        $elements = $this->loadElementsByIds(array_column($rows, 'elementId'), $siteId);

        $result = [];
        foreach ($rows as $row) {
            $el = $elements[$row['elementId']] ?? null;
            $result[] = [
                'row' => $row,
                'entry' => $el,
                'element' => $el,
            ];
        }

        return ['total' => $total, 'entries' => $result];
    }

    /**
     * Every page with a potential issue as flat export rows (title, uri, issue
     * count, occurrences, last scanned), without hydrating Craft elements, so a
     * CSV export of the whole set stays cheap on very large sites.
     *
     * @param int $siteId The site to scope to.
     * @return array<int, array{elementId: int, title: ?string, uri: ?string, issueCount: int, occurrences: int, dateScanned: ?string}>
     */
    public function getPotentialPagesExport(int $siteId): array
    {
        $latestIds = $this->getLatestScanIds($siteId);
        if (empty($latestIds)) {
            return [];
        }

        return (new Query())
            ->select([
                's.elementId',
                'es.title',
                'es.uri',
                'COUNT(DISTINCT i.ruleId) as issueCount',
                'COUNT(*) as occurrences',
                'MAX(s.dateScanned) as dateScanned',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->leftJoin(
                ['es' => '{{%elements_sites}}'],
                ['and', '[[es.elementId]] = [[s.elementId]]', ['es.siteId' => $siteId]],
            )
            ->where(['i.scanId' => $latestIds])
            ->andWhere($this->pendingPotentialCondition('i'))
            ->groupBy(['s.elementId', 'es.title', 'es.uri'])
            ->orderBy(['occurrences' => SORT_DESC])
            ->all();
    }

    /**
     * Mark issues as resolved when they no longer appear in a fresh scan.
     *
     * @throws Exception
     */
    public function markResolvedIssues(int $elementId, int $siteId, array $currentRuleIds, int $previousScanId): void
    {
        $previousRuleIds = (new Query())
            ->select(['ruleId'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $previousScanId, 'isResolved' => false])
            ->column();

        // A rule that's now on the ignore list vanishes from scans, but it was
        // muted, not fixed, so it must not be flipped to "resolved". Exclude the
        // ignored rules so they're left untouched (the display queries hide them).
        $ignoreRules = AccessibilityAudit::getInstance()->getSettings()->ignoreRules ?? [];
        $resolvedRules = array_diff($previousRuleIds, $currentRuleIds, $ignoreRules);

        if (!empty($resolvedRules)) {
            Craft::$app->getDb()->createCommand()->update(
                '{{%accessibilityaudit_issues}}',
                [
                    'isResolved' => true,
                    'dateResolved' => Db::prepareDateForDb(new DateTime()),
                    'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                ],
                ['scanId' => $previousScanId, 'ruleId' => $resolvedRules]
            )->execute();
        }
    }

    // ─── Score history ───────────────────────────────────────────────────────

    /** Returns the last $limit scan scores for charting. */
    public function getScoreHistory(int $siteId, int $limit = 30): array
    {
        // Average score per day across all elements
        return (new Query())
            ->select([
                'DATE(dateScanned) as day',
                'ROUND(AVG(score), 1) as avgScore',
                'ROUND(AVG(scoreA), 1) as avgScoreA',
                'ROUND(AVG(scoreAA), 1) as avgScoreAA',
            ])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['siteId' => $siteId])
            ->groupBy(['DATE(dateScanned)'])
            ->orderBy(['day' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    // ─── Paginated entry list ────────────────────────────────────────────────

    /**
     * Returns a paginated list of scanned elements for the CP table.
     *
     * @throws \yii\base\Exception
     */
    public function getScannedElements(int $siteId, int $page = 1, int $perPage = 50, string $search = '', string $orderBy = 'score', int $orderDir = SORT_ASC): array
    {
        $latestIds = $this->getLatestScanIds($siteId);
        if (empty($latestIds)) {
            return ['total' => 0, 'entries' => []];
        }

        $search = trim($search);

        // The combined error/warning/notice sum can't be an array key, so it's
        // passed to orderBy() as an Expression; the plain columns stay a
        // [column => dir] map. es.title only exists once the join below runs.
        if ($orderBy === 'issues') {
            $orderExpr = new Expression('([[s.errorCount]] + [[s.warningCount]] + [[s.noticeCount]]) ' . ($orderDir === SORT_DESC ? 'DESC' : 'ASC'));
        } else {
            $orderColumn = match ($orderBy) {
                'title' => 'es.title',
                'dateScanned' => 's.dateScanned',
                default => 's.score',
            };
            $orderExpr = [$orderColumn => $orderDir];
        }
        $needsTitleJoin = $search !== '' || $orderBy === 'title';

        // The page title lives in Craft's elements_sites table, not the scans
        // table, so it's joined only when it's searched or sorted on. Applied to
        // both queries so pagination totals stay correct.
        $applyTitle = static function(Query $query) use ($needsTitleJoin, $search, $siteId): void {
            if (!$needsTitleJoin) {
                return;
            }
            $query->innerJoin(
                ['es' => '{{%elements_sites}}'],
                ['and', '[[es.elementId]] = [[s.elementId]]', ['es.siteId' => $siteId]],
            );
            if ($search !== '') {
                $query->andWhere(['like', 'es.title', $search]);
            }
        };

        $totalQuery = (new Query())
            ->select(['COUNT(*)'])
            ->from(['s' => '{{%accessibilityaudit_scans}}'])
            ->where(['s.id' => $latestIds]);
        $applyTitle($totalQuery);
        $total = (int)$totalQuery->scalar();

        $scansQuery = (new Query())
            ->select(['s.id', 's.elementId', 's.elementType', 's.score', 's.scoreA', 's.scoreAA', 's.errorCount', 's.warningCount', 's.noticeCount', 's.dateScanned'])
            ->from(['s' => '{{%accessibilityaudit_scans}}'])
            ->where(['s.id' => $latestIds])
            ->orderBy($orderExpr)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage);
        $applyTitle($scansQuery);
        $scans = $scansQuery->all();

        // Build typeMap from already-loaded scan data to avoid an extra query
        $typeMap = array_column($scans, 'elementType', 'elementId');
        $elements = $this->loadElementsByIds(array_column($scans, 'elementId'), $siteId, $typeMap);

        $result = [];
        foreach ($scans as $scan) {
            $el = $elements[$scan['elementId']] ?? null;
            $result[] = [
                'scan' => $scan,
                'entry' => $el,
                'element' => $el,
            ];
        }

        return ['total' => $total, 'entries' => $result];
    }

    /**
     * Returns every scanned page for a site as flat export rows (title, uri,
     * score, severity counts, last scanned) straight from the scans table,
     * without hydrating Craft elements, so a CSV export of the whole set stays
     * cheap on very large sites.
     *
     * @param int $siteId The site to export scanned pages for.
     * @return array<int, array{elementId: int, title: ?string, uri: ?string, score: int, errorCount: int, warningCount: int, noticeCount: int, dateScanned: ?string}>
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getScannedElementsExport(int $siteId): array
    {
        $latestIds = $this->getLatestScanIds($siteId);
        if (empty($latestIds)) {
            return [];
        }

        return (new Query())
            ->select([
                's.elementId',
                'es.title',
                'es.uri',
                's.score',
                's.errorCount',
                's.warningCount',
                's.noticeCount',
                's.dateScanned',
            ])
            ->from(['s' => '{{%accessibilityaudit_scans}}'])
            ->leftJoin(
                ['es' => '{{%elements_sites}}'],
                ['and', '[[es.elementId]] = [[s.elementId]]', ['es.siteId' => $siteId]],
            )
            ->where(['s.id' => $latestIds])
            ->orderBy(['s.score' => SORT_ASC])
            ->all();
    }

    /**
     * @deprecated Use getScannedElements()
     * @throws \yii\base\Exception
     */
    public function getScannedEntries(int $siteId, int $page = 1, int $perPage = 50): array
    {
        return $this->getScannedElements($siteId, $page, $perPage);
    }

    // ─── Prune ───────────────────────────────────────────────────────────────

    /**
     * Deletes scan results (and their issues) older than $days, honouring the
     * plugin's three-way `resolvedRetention` setting for resolved issues:
     *
     * - RESOLVED_RETENTION_WITH_SCANS (default): a straight delete of old
     *   {{%accessibilityaudit_scans}} rows; the scanId foreign key cascades to remove every
     *   issue attached to them, resolved or not.
     * - RESOLVED_RETENTION_KEEP_DAYS: resolved issues are kept on their own
     *   dateResolved clock instead of the scan's dateScanned, so an old scan
     *   still hosting a kept resolved issue survives past its own cutoff.
     * - RESOLVED_RETENTION_FOREVER: resolved issues are never pruned, and any
     *   scan hosting one is kept as long as it does. This mode requires the Pro
     *   edition; on Standard it is treated as RESOLVED_RETENTION_KEEP_DAYS.
     *
     * In the latter two modes a scan is only removed once nothing (resolved or
     * unresolved) still references it.
     *
     * Retention beyond the Standard cap (and $days <= 0, "never prune") is a Pro
     * feature; on Standard $days is clamped to self::STANDARD_RETENTION_CAP.
     *
     * @param int $days The age in days beyond which scan results are pruned.
     * @return int The number of {{%accessibilityaudit_scans}} rows deleted.
     * @throws Exception
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function pruneScanResults(int $days): int
    {
        $db = Craft::$app->getDb();
        $plugin = AccessibilityAudit::getInstance();

        // Retention beyond the Standard cap (and "never", i.e. <= 0) is a Pro
        // feature. Enforce it here, not just on save, so no caller can exceed it
        // on a Standard site (including one downgraded from Pro).
        if (!$plugin->isPro() && ($days <= 0 || $days > self::STANDARD_RETENTION_CAP)) {
            $days = self::STANDARD_RETENTION_CAP;
        }

        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-{$days} days"));
        $retention = $plugin->getSettings()->resolvedRetention;

        // Keeping resolved issues forever is likewise a Pro feature. Enforce it
        // here too, so a site downgraded from Pro with `forever` still stored
        // can't retain them unbounded: fall back to keepDays.
        if ($retention === SettingsModel::RESOLVED_RETENTION_FOREVER && !$plugin->isPro()) {
            $retention = SettingsModel::RESOLVED_RETENTION_KEEP_DAYS;
        }

        // Resolved issues follow their scans: cascade-delete removes them too.
        if ($retention === SettingsModel::RESOLVED_RETENTION_WITH_SCANS) {
            return $db->createCommand()
                ->delete('{{%accessibilityaudit_scans}}', ['<', 'dateScanned', $cutoff])
                ->execute();
        }

        $oldScanIds = (new Query())
            ->select(['id'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['<', 'dateScanned', $cutoff])
            ->column();

        if (empty($oldScanIds)) {
            return 0;
        }

        // Unresolved issues on old scans are pruned as normal.
        $db->createCommand()
            ->delete('{{%accessibilityaudit_issues}}', [
                'and',
                ['scanId' => $oldScanIds],
                ['isResolved' => false],
            ])
            ->execute();

        // keepDays: resolved issues are pruned on their own dateResolved clock,
        // independent of which scan they are attached to. forever: kept for good.
        if ($retention === SettingsModel::RESOLVED_RETENTION_KEEP_DAYS) {
            $db->createCommand()
                ->delete('{{%accessibilityaudit_issues}}', [
                    'and',
                    ['isResolved' => true],
                    ['<', 'dateResolved', $cutoff],
                ])
                ->execute();
        }

        // Only remove an old scan once nothing still references it.
        $stillReferencedScanIds = (new Query())
            ->select(['scanId'])
            ->distinct()
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $oldScanIds])
            ->column();

        $deletableScanIds = array_diff($oldScanIds, $stillReferencedScanIds);

        if (empty($deletableScanIds)) {
            return 0;
        }

        return $db->createCommand()
            ->delete('{{%accessibilityaudit_scans}}', ['id' => $deletableScanIds])
            ->execute();
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * The `viewport` condition value for replacing one bucket's rows.
     *
     * The desktop bucket also matches null: rows stored before viewport
     * tagging existed are desktop-era findings, and if no bucket claimed
     * them they would be carried forward (and double-counted) forever.
     *
     * @param string $viewport The bucket being written.
     * @return string|array{string, null}
     */
    private function _viewportBucketCondition(string $viewport): string|array
    {
        if ($viewport === self::VIEWPORT_DESKTOP) {
            return [$viewport, null];
        }

        return $viewport;
    }

    /**
     * @throws Throwable
     */
    private function processHtml(string $html, int $elementId, string $elementType, int $siteId): array
    {
        if (!$this->canScanNewElement($elementId, $siteId)) {
            return ['scanId' => 0, 'score' => 0, 'issues' => [], 'limitReached' => true];
        }

        $settings = AccessibilityAudit::getInstance()->getSettings();
        $ignoreRules = $settings->ignoreRules ?? [];

        $definiteIssues = AccessibilityAudit::getInstance()->content->scan($html, $ignoreRules);
        $potentialIssues = AccessibilityAudit::getInstance()->potential->scan($html);

        // Snapshot the element's previous scan before this one is recorded, so
        // notifications can compare the two. Captured outside the transaction.
        $previousSnapshot = $this->_notificationSnapshot($elementId, $siteId);

        $scanId = $this->createScan($elementId, $elementType, $siteId, $definiteIssues, $potentialIssues);
        $score = $this->calculateScore($definiteIssues);

        // Carried-forward client-side findings (axe, contrast) can lower the
        // stored score below the PHP-only calculation; report the stored value
        // so callers never show a number the CP disagrees with.
        if ($scanId > 0) {
            $summary = $this->getScanSummary($scanId);
            if ($summary !== null) {
                $score = (int) $summary['score'];
            }
        }

        // Fire notifications after the scan has committed, so a notification
        // failure can never roll back a recorded scan.
        $this->_dispatchNotifications($elementId, $elementType, $siteId, $score, $definiteIssues, $previousSnapshot);

        return ['scanId' => $scanId, 'score' => $score, 'issues' => $definiteIssues];
    }

    /**
     * Builds the {score, errorRuleIds} snapshot the notification service compares
     * against, from the element's most recent scan. Returns null when the element
     * has never been scanned before.
     *
     * @return array{score: int, errorRuleIds: string[]}|null
     */
    private function _notificationSnapshot(int $elementId, int $siteId): ?array
    {
        $scan = (new Query())
            ->select(['id', 'score'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->orderBy(['dateScanned' => SORT_DESC])
            ->one();

        if (!$scan) {
            return null;
        }

        $errorRuleIds = (new Query())
            ->select(['ruleId'])
            ->distinct()
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => (int) $scan['id'], 'severity' => 'error', 'isResolved' => false])
            ->column();

        return [
            'score' => (int) $scan['score'],
            'errorRuleIds' => array_values(array_map('strval', $errorRuleIds)),
        ];
    }

    /**
     * Hands the new scan and the previous snapshot to the notification service.
     *
     * @param IssueModel[] $definiteIssues
     * @param array{score: int, errorRuleIds: string[]}|null $previousSnapshot
     * @throws \yii\base\Exception
     */
    private function _dispatchNotifications(
        int $elementId,
        string $elementType,
        int $siteId,
        int $score,
        array $definiteIssues,
        ?array $previousSnapshot,
    ): void {
        $errorRuleIds = [];
        foreach ($definiteIssues as $issue) {
            if ($issue->severity === 'error') {
                $errorRuleIds[$issue->ruleId] = true;
            }
        }

        $label = $this->_notificationLabel($elementId, $elementType, $siteId);

        AccessibilityAudit::getInstance()->notifications->evaluateScan(
            [
                'score' => $score,
                'errorRuleIds' => array_keys($errorRuleIds),
                'label' => $label,
                // Deep link straight to the page's Inspect report, so the
                // person triaging the alert lands on the findings in one click.
                'reportUrl' => UrlHelper::cpUrl('accessibility-audit/page-report', [
                    'elementId' => $elementId,
                    'siteId' => $siteId,
                ]),
            ],
            $previousSnapshot
        );
    }

    /**
     * Resolves a human-readable label for the scanned element, falling back to a
     * generic string when the element can't be loaded.
     *
     * @throws \yii\base\Exception
     */
    private function _notificationLabel(int $elementId, string $elementType, int $siteId): string
    {
        $elements = $this->loadElementsByIds([$elementId], $siteId, [$elementId => $elementType]);
        $element = $elements[$elementId] ?? null;

        if ($element === null) {
            return Craft::t('accessibility-audit', 'a page');
        }

        $url = $element->getUrl();

        return $url ? sprintf('%s (%s)', (string) $element, $url) : (string) $element;
    }

    /**
     * Filters a scan's issues down to the configured target WCAG level, dropping
     * any whose level is above it (for example AAA issues when the target is AA),
     * so the stored counts, score, and issue rows never reflect criteria the site
     * is not aiming for. Best-practice issues (no WCAG level) are always kept, and
     * an unrecognised level is treated as Level A so it is never dropped. This
     * mirrors the axe scanner, which only requests tags up to the target level.
     *
     * @param IssueModel[] $issues The issues found during a scan.
     * @return IssueModel[] The issues at or below the target WCAG level.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _filterIssuesToTargetLevel(array $issues): array
    {
        $rank = ['A' => 1, 'AA' => 2, 'AAA' => 3];
        $target = $rank[strtoupper(AccessibilityAudit::getInstance()->getSettings()->wcagLevel ?? 'AA')] ?? 2;

        return array_values(array_filter($issues, static function(IssueModel $issue) use ($rank, $target): bool {
            if ($issue->wcagLevel === null) {
                return true;
            }
            return ($rank[strtoupper($issue->wcagLevel)] ?? 1) <= $target;
        }));
    }

    /**
     * Creates a scan row and all its issue rows atomically.
     *
     * The scan insert, the per-issue insert loop, and the resolved-issue update
     * run inside a single transaction: a mid-loop failure rolls the whole batch
     * back rather than leaving a partial issue set and a miscalculated score.
     *
     * @throws Throwable
     */
    private function createScan(int $elementId, string $elementType, int $siteId, array $issues, array $potentialIssues = []): int
    {
        // Drop any issues above the target WCAG level (e.g. AAA when targeting
        // AA) before anything is counted or stored. Mirrors the axe scanner,
        // which only requests tags up to that level.
        $issues = $this->_filterIssuesToTargetLevel($issues);

        $db = Craft::$app->getDb();

        return $db->transaction(function() use ($db, $elementId, $elementType, $siteId, $issues, $potentialIssues): int {
            $previousScan = (new Query())
                ->select(['id'])
                ->from('{{%accessibilityaudit_scans}}')
                ->where(['elementId' => $elementId, 'siteId' => $siteId])
                ->orderBy(['dateScanned' => SORT_DESC])
                ->one();

            $errorCount = count(array_filter($issues, fn($i) => $i->severity === 'error'));
            $warningCount = count(array_filter($issues, fn($i) => $i->severity === 'warning'));
            $noticeCount = count(array_filter($issues, fn($i) => $i->severity === 'notice'));

            $scores = $this->calculateScoreByLevel($issues);

            $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
                'elementId' => $elementId,
                'elementType' => $elementType,
                'siteId' => $siteId,
                'score' => $scores['overall'],
                'scoreA' => $scores['A'],
                'scoreAA' => $scores['AA'],
                'scoreAAA' => $scores['AAA'],
                'errorCount' => $errorCount,
                'warningCount' => $warningCount,
                'noticeCount' => $noticeCount,
                'dateScanned' => Db::prepareDateForDb(new DateTime()),
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();

            $scanId = (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');
            $currentRules = [];

            // Rulings the author has already made on this element, carried
            // forward onto the re-scan's fresh rows. Fetched once rather than
            // per issue.
            $verdicts = AccessibilityAudit::getInstance()->verdicts;
            $verdictMap = $verdicts->mapForElement($elementId, $siteId);

            foreach ($issues as $issue) {
                $firstDetected = $this->resolveFirstDetected($elementId, $siteId, $issue->ruleId);
                $this->insertIssue($scanId, $elementId, $elementType, $siteId, $issue, $firstDetected,
                    $verdicts->lookup($verdictMap, $issue->ruleId, $issue->context));
                $currentRules[] = $issue->ruleId;
            }

            foreach ($potentialIssues as $issue) {
                $firstDetected = $this->resolveFirstDetected($elementId, $siteId, $issue->ruleId);
                $this->insertIssue($scanId, $elementId, $elementType, $siteId, $issue, $firstDetected,
                    $verdicts->lookup($verdictMap, $issue->ruleId, $issue->context));
                $currentRules[] = $issue->ruleId;
            }

            if ($previousScan) {
                // The PHP scanner can't see what the client-side engines found
                // (axe, contrast), so carry those findings onto the fresh scan.
                // They stay until the next overlay run replaces them.
                $carriedRules = $this->_carryForwardClientIssues((int) $previousScan['id'], $scanId, $currentRules);
                if (!empty($carriedRules)) {
                    $currentRules = array_merge($currentRules, $carriedRules);
                    $this->recalculateScanScore($scanId);
                }

                $this->markResolvedIssues($elementId, $siteId, $currentRules, (int) $previousScan['id']);
            }

            return $scanId;
        });
    }

    /**
     * Copies the previous scan's unresolved client-side findings (axe and
     * contrast sources) onto a fresh PHP scan, skipping any axe finding whose
     * equivalent rule the new PHP scan has just flagged itself (the reverse of
     * the store-time dedup).
     *
     * @param int $previousScanId The scan being superseded.
     * @param int $newScanId The freshly created scan.
     * @param string[] $phpRuleIds Rule IDs the new PHP scan found.
     * @return string[] The carried-forward rule IDs.
     * @throws Exception
     * @throws \Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _carryForwardClientIssues(int $previousScanId, int $newScanId, array $phpRuleIds): array
    {
        $rows = (new Query())
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $previousScanId, 'source' => ['axe', 'contrast'], 'isResolved' => false])
            ->all();

        $carried = [];
        foreach ($rows as $row) {
            $axeId = str_starts_with($row['ruleId'], 'axe:') ? substr($row['ruleId'], 4) : $row['ruleId'];
            $equivalent = self::AXE_EQUIVALENT_PHP_RULES[$axeId] ?? null;
            if ($row['source'] === 'axe' && $equivalent !== null && in_array($equivalent, $phpRuleIds, true)) {
                continue;
            }

            Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_issues}}', [
                'scanId' => $newScanId,
                'elementId' => $row['elementId'],
                'elementType' => $row['elementType'],
                'siteId' => $row['siteId'],
                'ruleId' => $row['ruleId'],
                'wcagCriterion' => $row['wcagCriterion'],
                'wcagLevel' => $row['wcagLevel'],
                'severity' => $row['severity'],
                'message' => $row['message'],
                'context' => $row['context'],
                'helpUrl' => $row['helpUrl'],
                'source' => $row['source'],
                'viewport' => $row['viewport'],
                'firstDetected' => $row['firstDetected'],
                'isResolved' => false,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();

            $carried[] = $row['ruleId'];
        }

        return array_unique($carried);
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    private function insertIssue(
        int $scanId,
        int $elementId,
        string $elementType,
        int $siteId,
        IssueModel $issue,
        ?DateTime $firstDetected = null,
        ?string $verdict = null,
    ): void {
        Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_issues}}', [
            'scanId' => $scanId,
            'elementId' => $elementId,
            'elementType' => $elementType,
            'siteId' => $siteId,
            'ruleId' => $issue->ruleId,
            'wcagCriterion' => $issue->wcagCriterion,
            'wcagLevel' => $issue->wcagLevel,
            'severity' => $issue->severity,
            'message' => $this->scrubUtf8($issue->message),
            'context' => $this->scrubUtf8($issue->context),
            'helpUrl' => $issue->helpUrl,
            'source' => $issue->source,
            'viewport' => $issue->viewport,
            'firstDetected' => Db::prepareDateForDb($firstDetected ?? new DateTime()),
            'isResolved' => false,
            'verdict' => $verdict,
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    /**
     * Returns the value as valid UTF-8, replacing any invalid byte sequence.
     *
     * Issue text is sliced out of arbitrary page content, and strict-mode
     * MySQL rejects a row containing an invalid sequence, which aborts the
     * whole scan transaction. Scrubbing at this final chokepoint means no
     * upstream truncation slip can ever fail a scan again.
     *
     * @param string|null $value The message or context text.
     * @return string|null
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function scrubUtf8(?string $value): ?string
    {
        if ($value === null || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /** Look up when this rule was first seen on this element: preserves the original date across re-scans.
     *
     * @throws \Exception
     */
    private function resolveFirstDetected(int $elementId, int $siteId, string $ruleId): ?DateTime
    {
        $existing = (new Query())
            ->select(['MIN(firstDetected) as first'])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->where(['i.elementId' => $elementId, 's.siteId' => $siteId, 'i.ruleId' => $ruleId])
            ->scalar();

        if ($existing) {
            return new DateTime($existing);
        }

        return null;
    }

    /**
     * The potential issues on a scan that still need an answer, one row per
     * occurrence so each can be ruled on separately.
     *
     * Ungrouped on purpose, unlike the definite issues: the question is about a
     * specific bit of markup ("is *this* image decorative?"), so collapsing
     * occurrences would ask one question and apply the answer to several.
     *
     * @param int $scanId The scan.
     * @return array<array-key, array{id: int, ruleId: string, message: string, context: string|null, wcagCriterion: string|null, wcagLevel: string|null}>
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getPendingPotentialForScan(int $scanId): array
    {
        return (new Query())
            ->select(['id', 'ruleId', 'message', 'context', 'wcagCriterion', 'wcagLevel'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId, 'isResolved' => false])
            ->andWhere($this->pendingPotentialCondition())
            ->orderBy(['ruleId' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
    }

    /**
     * Potential issues dismissed as "not an issue" across the whole site.
     *
     * The per-page report can already restore a dismissal, but only on the page
     * it was made. This answers the question that view cannot: what has been
     * waved through, everywhere? Somebody inheriting a site, or re-checking a
     * batch after a rule turned out to be over-firing, has no other way to find
     * out.
     *
     * Reads the latest scan per element, so it lists dismissals that still
     * apply to what is on the page now. A dismissal whose markup has since
     * changed is not listed, because there is no longer a finding to restore;
     * the verdict itself survives in the verdicts table and re-applies if the
     * same markup comes back.
     *
     * @param int $siteId The site to read.
     * @param int $page 1-indexed page number.
     * @param int $perPage Rows per page.
     * @param string $search Matches the rule ID or the stored context.
     * @param string $orderBy A whitelisted column to sort on.
     * @param int $orderDir SORT_ASC or SORT_DESC.
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function getDismissedPotential(
        int $siteId,
        int $page = 1,
        int $perPage = 100,
        string $search = '',
        string $orderBy = 'ruleId',
        int $orderDir = SORT_ASC,
    ): array {
        $latestIds = $this->getLatestScanIds($siteId);

        if (empty($latestIds)) {
            return ['rows' => [], 'total' => 0];
        }

        // Whitelisted: the sort column arrives from a query parameter.
        $sortable = ['ruleId', 'elementId'];
        $column = in_array($orderBy, $sortable, true) ? $orderBy : 'ruleId';

        $query = (new Query())
            ->select(['id', 'ruleId', 'message', 'context', 'elementId', 'elementType'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where([
                'scanId' => $latestIds,
                'isResolved' => false,
                'verdict' => VerdictService::VERDICT_DISMISSED,
            ])
            ->andWhere(['like', 'ruleId', 'potential:%', false]);

        if ($search !== '') {
            $query->andWhere([
                'or',
                ['like', 'ruleId', $search],
                ['like', 'context', $search],
            ]);
        }

        $total = (int) (clone $query)->count();

        $rows = $query
            ->orderBy([$column => $orderDir, 'id' => SORT_ASC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The potential issues on a scan the author has already dismissed, so a
     * wrong call can be found and undone rather than being lost.
     *
     * @param int $scanId The scan.
     * @return array<array-key, array{id: int, ruleId: string, message: string, context: string|null}>
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDismissedPotentialForScan(int $scanId): array
    {
        return (new Query())
            ->select(['id', 'ruleId', 'message', 'context'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where([
                'scanId' => $scanId,
                'isResolved' => false,
                'verdict' => VerdictService::VERDICT_DISMISSED,
            ])
            ->andWhere(['like', 'ruleId', 'potential:%', false])
            ->orderBy(['ruleId' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
    }

    /**
     * The condition for "counts as a real failure": anything the scanner is
     * sure about, plus any potential issue the author has confirmed.
     *
     * Expressed once and reused by every scoring and listing query, so
     * confirming a potential issue promotes it everywhere at once rather than
     * in whichever query happened to be updated.
     *
     * @param string $alias The table alias in use, or '' when unaliased.
     * @return array A Yii query condition.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function definiteCondition(string $alias = ''): array
    {
        $rule = $alias !== '' ? "$alias.ruleId" : 'ruleId';
        $verdict = $alias !== '' ? "$alias.verdict" : 'verdict';

        return ['or',
            ['not like', $rule, 'potential:%', false],
            [$verdict => VerdictService::VERDICT_CONFIRMED],
        ];
    }

    /**
     * The condition for "still awaiting the author's answer": a potential issue
     * with no ruling yet. Dismissed and confirmed ones have both been dealt
     * with, so neither belongs in the review queue.
     *
     * @param string $alias The table alias in use, or '' when unaliased.
     * @return array A Yii query condition.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function pendingPotentialCondition(string $alias = ''): array
    {
        $rule = $alias !== '' ? "$alias.ruleId" : 'ruleId';
        $verdict = $alias !== '' ? "$alias.verdict" : 'verdict';

        return ['and',
            ['like', $rule, 'potential:%', false],
            [$verdict => null],
        ];
    }

    /**
     * Recalculates a scan's scores from its stored issues. The public way in
     * for callers outside a scan, such as a verdict changing what counts.
     *
     * @param int $scanId The scan to redo.
     * @throws Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function recalculateScoreForScan(int $scanId): void
    {
        $this->recalculateScanScore($scanId);
    }

    private function calculateScore(array $issues): int
    {
        if (empty($issues)) {
            return 100;
        }
        $penalty = array_sum(array_map(
            static fn($i) => self::SEVERITY_WEIGHT[$i->severity] ?? 1,
            $issues
        ));
        return max(0, 100 - $penalty);
    }

    private function calculateScoreByLevel(array $issues): array
    {
        $byLevel = ['A' => [], 'AA' => [], 'AAA' => []];

        foreach ($issues as $issue) {
            $level = $issue->wcagLevel ?? '';
            // An issue with no WCAG level (a best-practice rule, or an axe rule
            // with no mapped criterion) is a real finding for the overall score
            // but not a conformance failure, so it must not move a level score.
            // Anything unrecognised is skipped rather than creating a bucket
            // that nothing below reads.
            if (!array_key_exists($level, $byLevel)) {
                continue;
            }
            $byLevel[$level][] = $issue;
        }

        // Cumulative: AA conformance also requires A, AAA requires A and AA.
        return [
            'A' => $this->calculateScore($byLevel['A']),
            'AA' => $this->calculateScore(array_merge($byLevel['A'], $byLevel['AA'])),
            'AAA' => $this->calculateScore(array_merge($byLevel['A'], $byLevel['AA'], $byLevel['AAA'])),
            'overall' => $this->calculateScore($issues),
        ];
    }

    /**
     * @throws Exception
     */
    private function recalculateScanScore(int $scanId): void
    {
        // Definite, unresolved issues only: potential issues never affect the
        // score, and resolved rows are history, matching calculateScore()'s
        // basis so a recalculated score is comparable to a fresh PHP scan's.
        $counts = (new Query())
            ->select([
                "SUM(CASE WHEN severity='error' THEN 1 ELSE 0 END) as errors",
                "SUM(CASE WHEN severity='warning' THEN 1 ELSE 0 END) as warnings",
                "SUM(CASE WHEN severity='notice' THEN 1 ELSE 0 END) as notices",
            ])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId, 'isResolved' => false])
            ->andWhere($this->definiteCondition())
            ->one();

        $w = self::SEVERITY_WEIGHT;
        $errors = (int) ($counts['errors'] ?? 0);
        $warnings = (int) ($counts['warnings'] ?? 0);
        $notices = (int) ($counts['notices'] ?? 0);
        $score = max(0, 100 - ($errors * $w['error']) - ($warnings * $w['warning']) - ($notices * $w['notice']));

        // Per-level scores are recalculated here too, since axe/contrast
        // findings land after the PHP scanner writes the initial levels and
        // must be reflected in them. Only real WCAG levels are counted: an
        // issue with no level (best practice, unmapped axe rule) counts
        // toward the overall score but not conformance.
        $penaltyExpr = "SUM(CASE severity"
            . " WHEN 'error' THEN {$w['error']}"
            . " WHEN 'warning' THEN {$w['warning']}"
            . " ELSE {$w['notice']} END)";

        $levelRows = (new Query())
            ->select(['wcagLevel', $penaltyExpr . ' as penalty'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId, 'isResolved' => false])
            ->andWhere($this->definiteCondition())
            ->andWhere(['wcagLevel' => ['A', 'AA', 'AAA']])
            ->groupBy(['wcagLevel'])
            ->all();

        $penalty = ['A' => 0, 'AA' => 0, 'AAA' => 0];
        foreach ($levelRows as $row) {
            $penalty[$row['wcagLevel']] = (int) $row['penalty'];
        }

        // Cumulative: AA conformance also requires A, AAA requires A and AA.
        $scoreA = max(0, 100 - $penalty['A']);
        $scoreAA = max(0, 100 - $penalty['A'] - $penalty['AA']);
        $scoreAAA = max(0, 100 - $penalty['A'] - $penalty['AA'] - $penalty['AAA']);

        Craft::$app->getDb()->createCommand()->update('{{%accessibilityaudit_scans}}', [
            'score' => $score,
            'scoreA' => $scoreA,
            'scoreAA' => $scoreAA,
            'scoreAAA' => $scoreAAA,
            'errorCount' => $errors,
            'warningCount' => $warnings,
            'noticeCount' => $notices,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $scanId])->execute();
    }

    /**
     * Load elements of any type by ID.
     * Pass $typeMap ([elementId => elementType]) when already available to skip
     * the extra query; otherwise it is resolved from the scans table.
     *
     * @throws \yii\base\Exception
     */
    private function loadElementsByIds(array $elementIds, int $siteId, array $typeMap = []): array
    {
        if (empty($elementIds)) {
            return [];
        }

        if (empty($typeMap)) {
            // pairs() returns [firstColumn => secondColumn]: exactly [elementId => elementType]
            $typeMap = (new Query())
                ->select(['elementId', 'elementType'])
                ->from('{{%accessibilityaudit_scans}}')
                ->where(['elementId' => $elementIds, 'siteId' => $siteId])
                ->pairs();
        }

        $byType = [];
        foreach ($elementIds as $id) {
            $type = $typeMap[$id] ?? null;
            if ($type) {
                $byType[$type][] = $id;
            }
        }

        $elements = [];
        foreach ($byType as $type => $ids) {
            if (!is_string($type)) {
                continue;
            }
            try {
                $found = $type::find()->id($ids)->siteId($siteId)->status(null)->indexBy('id')->all();
                foreach ($found as $id => $el) {
                    $elements[$id] = $el;
                }
            } catch (Throwable) {
                // Element type not installed or query not supported
            }
        }

        return $elements;
    }

    private function getLatestScanIds(int $siteId): array
    {
        return (new Query())
            ->select(['MAX(id)'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['siteId' => $siteId])
            ->groupBy(['elementId'])
            ->column();
    }

    /**
     * Fetches the rendered HTML for a scan target.
     *
     * URLs reach this method from element scans ($element->getUrl()), i.e. the
     * site's own known entries, so they are not attacker-controlled the way the
     * readability URL fetcher is. TLS verification is only disabled in dev /
     * ephemeral environments (DDEV self-signed certs); production verifies certs.
     */
    private function fetchHtml(string $url): ?string
    {
        try {
            $clientConfig = [
                'timeout' => 15,
                'connect_timeout' => 5,
                'verify' => self::_verifyTls(),
            ];

            // The scanner always identifies itself so hosts can allow-list
            // scan traffic in WAF/firewall rules: the branded default carries
            // the token by default, or the admin's own override if set.
            $clientConfig['headers'] = [
                'User-Agent' => AccessibilityAudit::getInstance()->getSettings()->getFetchUserAgent(),
            ];

            $client = Craft::createGuzzleClient($clientConfig);
            $response = $client->get($url);
            return (string) $response->getBody();
        } catch (Throwable $e) {
            Craft::warning("Accessibility scan failed to fetch $url: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Whether outbound Guzzle requests should verify TLS certificates.
     *
     * Returns false only in dev-mode or ephemeral (Cloud/CI) environments so
     * self-signed certs (DDEV/Lando/Valet) work locally; production verifies.
     */
    private static function _verifyTls(): bool
    {
        return !App::isEphemeral() && !Craft::$app->getConfig()->getGeneral()->devMode;
    }

    /**
     * Store colour-contrast occurrences captured client-side from the page-report iframe.
     * Replaces only the same viewport's previous client-side contrast results
     * for this scan, so desktop and mobile buckets union instead of
     * overwriting each other.
     *
     * @param array<array-key, array{fg: string, bg: string, ratio: float|null, expected: string|null, html: string|null, selector?: string|null}> $occurrences
     * @throws Exception
     * @throws \Exception
     */
    public function storeContrastIssues(int $scanId, array $occurrences, string $viewport = self::VIEWPORT_DESKTOP): int
    {
        $scan = (new Query())
            ->select(['elementId', 'elementType', 'siteId'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['id' => $scanId])
            ->one();

        if (!$scan) {
            return 0;
        }

        // Replace previous client-side contrast results for this viewport only.
        // As with axe results, the desktop bucket also sweeps untagged legacy rows.
        Craft::$app->getDb()->createCommand()
            ->delete('{{%accessibilityaudit_issues}}', [
                'scanId' => $scanId,
                'source' => 'contrast',
                'viewport' => $this->_viewportBucketCondition($viewport),
            ])
            ->execute();

        $count = 0;
        foreach ($occurrences as $occ) {
            $fg = trim((string)$occ['fg']);
            $bg = trim((string)$occ['bg']);
            $ratio = isset($occ['ratio']) ? round((float)$occ['ratio'], 2) : null;
            $expected = trim((string)($occ['expected'] ?? '4.5:1'));
            $html = mb_substr(trim((string)($occ['html'] ?? '')), 0, 300);

            if ($fg === '' || $bg === '') {
                continue;
            }

            $message = $ratio !== null
                ? sprintf('Contrast %s:1 (need %s) · Text %s on %s', $ratio, $expected, $fg, $bg)
                : sprintf('Insufficient colour contrast · Text %s on %s', $fg, $bg);

            $context = Json::encode([
                'html' => $html,
                'fg' => $fg,
                'bg' => $bg,
                'ratio' => $ratio,
                'expected' => $expected,
                // The failing element's CSS path, computed in the browser where
                // the DOM is live, so the report can highlight the exact element.
                'selector' => mb_substr(trim((string)($occ['selector'] ?? '')), 0, 300),
            ]);

            $this->insertIssue($scanId, $scan['elementId'], $scan['elementType'], $scan['siteId'], IssueModel::make(
                ruleId: 'color-contrast',
                severity: 'error',
                message: $message,
                wcagCriterion: '1.4.3',
                wcagLevel: 'AA',
                context: $context,
                helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum',
                source: 'contrast',
                viewport: $viewport,
            ));
            $count++;
        }

        if ($count > 0) {
            $this->recalculateScanScore($scanId);
        }

        return $count;
    }

    /** Map an axe rule ID to our internal ruleId (some get a first-class ID, rest get axe: prefix). */
    /**
     * Stores axe's undecided contrast results as needs-review items.
     *
     * axe returns a node as "incomplete" when it can compute neither a pass nor
     * a failure, which for contrast means it couldn't resolve what is actually
     * behind the text: an overlapping element, a background image, a gradient.
     * A person can settle those by looking, so they go in as `potential:` rows,
     * sitting in the needs-review bucket and staying out of the score until
     * someone confirms one.
     *
     * Only contrast is taken from the incomplete set. axe returns incomplete
     * liberally across other rules, and most of those aren't answerable by
     * looking at the page.
     *
     * @param int $scanId The scan being written to.
     * @param array $scan The scan's element/site row.
     * @param array $axeIncomplete axe's incomplete results, in the violation payload shape.
     * @param string $viewport The viewport bucket these results belong to.
     * @return void
     */
    private function _storeContrastNeedsReview(int $scanId, array $scan, array $axeIncomplete, string $viewport): void
    {
        foreach ($axeIncomplete as $result) {
            if (($result['id'] ?? '') !== 'color-contrast') {
                continue;
            }

            $wcag = $this->extractWcagFromAxe($result);

            foreach ($result['nodes'] ?? [] as $node) {
                $html = mb_substr($node['html'] ?? '', 0, 300);

                if ($html === '') {
                    continue;
                }

                $this->insertIssue($scanId, $scan['elementId'], $scan['elementType'], $scan['siteId'], IssueModel::make(
                    ruleId: self::RULE_POTENTIAL_CONTRAST,
                    severity: 'notice',
                    message: $this->_contrastNeedsReviewMessage($node['any'][0]['data'] ?? []),
                    wcagCriterion: $wcag['criterion'] ?? '1.4.3',
                    wcagLevel: $wcag['level'] ?? 'AA',
                    // Bare markup, like every other potential issue. The report
                    // prints the context as-is, matches the element by it for
                    // "Show on page", and keys the verdict to its hash, so all
                    // three depend on this staying markup.
                    context: $html,
                    helpUrl: $result['helpUrl'] ?? null,
                    source: 'axe',
                    viewport: $viewport,
                ));
            }
        }
    }

    /**
     * Builds the question shown against an undecided contrast node, phrased by
     * whichever reason axe gave for not being able to measure it.
     *
     * @param array $data The node's contrast check data.
     * @return string
     */
    private function _contrastNeedsReviewMessage(array $data): string
    {
        $size = isset($data['fontSize']) ? " The text is {$data['fontSize']}." : '';
        $needs = $data['expectedContrastRatio'] ?? '4.5:1';

        $reason = match ($data['messageKey'] ?? '') {
            'bgOverlap' => 'another element sits over it',
            'bgImage' => 'it sits on a background image',
            'bgGradient' => 'it sits on a gradient',
            'imgNode' => 'it sits on an image',
            'pseudoContent' => 'a CSS pseudo-element covers it',
            default => 'the background could not be worked out',
        };

        return "Does this text have enough contrast? It could not be measured automatically because {$reason}."
            . " Check it against what is actually behind it, which needs {$needs}.{$size}";
    }

    private function _axeRuleId(string $axeId): string
    {
        static $firstClass = ['color-contrast', 'color-contrast-enhanced'];
        return in_array($axeId, $firstClass, true) ? $axeId : 'axe:' . $axeId;
    }

    private function axeImpactToSeverity(string $impact): string
    {
        return match ($impact) {
            'critical', 'serious' => 'error',
            'moderate' => 'warning',
            default => 'notice',
        };
    }

    /**
     * Pulls the WCAG criterion and level out of an axe violation's tags.
     *
     * Axe encodes the criterion by concatenating its digits (wcag258 → 2.5.8,
     * wcag1410 → 1.4.10): principle and guideline are always one digit each,
     * the criterion number takes the rest. The level rides on the separate
     * version tags (wcag2a / wcag21aa / wcag22aa / wcag2aaa).
     *
     * @param array $violation One axe violation.
     * @return array{criterion: ?string, level: ?string}
     */
    private function extractWcagFromAxe(array $violation): array
    {
        $criterion = null;
        $level = null;

        foreach ($violation['tags'] ?? [] as $tag) {
            if ($criterion === null && preg_match('/^wcag(\d)(\d)(\d{1,2})$/', $tag, $m)) {
                $criterion = $m[1] . '.' . $m[2] . '.' . $m[3];
            }
            if ($level === null && preg_match('/^wcag\d+(a{1,3})$/', $tag, $m)) {
                $level = strtoupper($m[1]);
            }
        }

        return ['criterion' => $criterion, 'level' => $level];
    }
}
