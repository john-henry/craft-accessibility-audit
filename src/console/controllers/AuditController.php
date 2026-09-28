<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\console\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\console\Controller;
use craft\db\Query;
use craft\elements\Asset;
use craft\errors\SiteNotFoundException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\base\ConsoleSiteTrait;
use johnhenry\accessibilityaudit\helpers\ElementLabel;
use johnhenry\accessibilityaudit\jobs\AuditAssets;
use johnhenry\accessibilityaudit\services\AuditService;
use Throwable;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;
use yii\db\Exception;
use yii\helpers\Console;

/**
 * Accessibility audit console commands.
 *
 * Usage:
 *   php craft accessibility-audit/audit/scan-all
 *   php craft accessibility-audit/audit/scan-element --element-id=42
 *   php craft accessibility-audit/audit/scan-assets
 *   php craft accessibility-audit/audit/prune --days=90
 *   php craft accessibility-audit/audit/prune-excluded
 *   php craft accessibility-audit/audit/report
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class AuditController extends Controller
{
    // Traits
    // =========================================================================

    use ConsoleSiteTrait;

    // Constants
    // =========================================================================

    /**
     * @var int How many element rows are read from the database at a time. The
     *      scan of each one is far slower than the read, so a larger page buys
     *      nothing and only holds more rows.
     */
    private const BATCH_SIZE = 100;

    // Public Properties
    // =========================================================================

    /**
     * @var string The default action.
     */
    public $defaultAction = 'scan-all';

    /**
     * @var int|null Element ID for single-element scan.
     */
    public ?int $elementId = null;

    /**
     * @var int Days to retain results when pruning.
     */
    public int $days = 90;

    /**
     * @var string|null A single URL to scan, for pages with no element behind
     *                  them.
     */
    public ?string $url = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'scan-all', 'report' => ['site'],
            'scan-element' => ['elementId', 'site'],
            'scan-url' => ['url', 'site'],
            'prune' => ['days'],
            default => [],
        });
    }

    /**
     * Scan all live, public elements across entries, categories, Commerce products, etc.
     *
     * @return int
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionScanAll(): int
    {
        $siteId = $this->_resolveSiteId();

        if ($siteId === null) {
            return ExitCode::USAGE;
        }
        $audit = AccessibilityAudit::getInstance()->getAudit();

        // The rows are paged rather than read in one go: a site with tens of
        // thousands of pages is exactly the site this command is left to run
        // on overnight, and it should not hold the lot in memory to do it.
        $query = $audit->getUrlElementsQuery($siteId);
        $total = (int)$query->count();

        // Configured URLs are scanned even when nothing else is, so a site
        // that routes everything through templates is not turned away here.
        if ($total === 0 && empty(AccessibilityAudit::getInstance()->getSettings()->resolvedCustomUrls($siteId))) {
            $this->stdout("No URL-bearing elements found for this site.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $errors = 0;
        $scanned = 0;

        if ($total > 0) {
            $summary = [];

            foreach ($this->_typeCounts($query) as $type => $count) {
                $summary[] = $count . '× ' . class_basename($type);
            }

            $this->stdout("Scanning {$total} elements (" . implode(', ', $summary) . ")...\n", Console::FG_GREEN);
        }

        $i = 0;

        foreach ($query->each(self::BATCH_SIZE) as $row) {
            $i++;
            // Resolving the element is inside the catch along with the scan:
            // getUrl() fires events a third-party handler can throw from, and
            // this command runs unattended, where one bad page must not take
            // the remaining thousands with it.
            try {
                /** @var class-string<ElementInterface>|null $elementType */
                $elementType = $row['elementType'] ?: null;

                $element = Craft::$app->getElements()->getElementById(
                    (int) $row['elementId'],
                    $elementType,
                    $siteId
                );

                if (!$element || !$element->getUrl()) {
                    continue;
                }

                $label = class_basename(get_class($element)) . ': ' . ElementLabel::for($element);
                $this->stdout(sprintf("  [%d/%d] %s ... ", $i, $total, $label));

                $result = $audit->scanElement($element);

                // A page that could not be read has no score, and printing one
                // for it reads as a verdict on a page nobody opened.
                if (($result['error'] ?? null) !== null) {
                    $this->stdout('skipped: ' . $result['error'] . PHP_EOL, Console::FG_YELLOW);
                    continue;
                }

                $score = $result['score'];
                $colour = $this->_scoreColour((int)$score);
                $this->stdout("score: {$score} (issues: " . count($result['issues']) . ")\n", $colour);
                $scanned++;
            } catch (Throwable $e) {
                $this->stdout("FAILED: " . $e->getMessage() . "\n", Console::FG_RED);
                $errors++;
            }
        }

        // Configured URLs last: pages Craft routes without an element behind
        // them, which the sweep above has no way of finding.
        $customUrls = AccessibilityAudit::getInstance()->getSettings()->resolvedCustomUrls($siteId);

        if (!empty($customUrls)) {
            $count = count($customUrls);
            $this->stdout("\nScanning {$count} additional URL(s)...\n", Console::FG_GREEN);

            foreach ($customUrls as $i => $customUrl) {
                $this->stdout(sprintf("  [%d/%d] %s ... ", $i + 1, $count, $customUrl));

                try {
                    $result = $audit->scanUrl($customUrl, $siteId);

                    if ($result['scanId'] === 0) {
                        $this->stdout('SKIPPED: ' . ($result['error'] ?? 'scan limit reached') . "\n", Console::FG_YELLOW);
                        continue;
                    }

                    $score = (int)$result['score'];
                    $scoreColour = $this->_scoreColour($score);
                    $this->stdout("score: {$score}\n", $scoreColour);
                    $scanned++;
                } catch (Throwable $e) {
                    $this->stdout('FAILED: ' . $e->getMessage() . "\n", Console::FG_RED);
                    $errors++;
                }
            }
        }

        $colour = $errors > 0 ? Console::FG_RED : Console::FG_GREEN;
        $this->stdout("\nDone. {$scanned} scanned, {$errors} error(s).\n", $colour);

        // A non-zero exit when any scan failed, so a pipeline running this
        // can actually tell. Scans that succeed with poor scores still exit
        // 0: score gating is the CI endpoint's job, not this command's.
        return $errors > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Scan a single element by ID (works for entries, products, categories, etc.).
     *
     * @return int
     * @throws SiteNotFoundException|Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionScanElement(): int
    {
        if (!$this->elementId) {
            $this->stderr("--element-id is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $siteId = $this->_resolveSiteId();

        if ($siteId === null) {
            return ExitCode::USAGE;
        }
        $element = Craft::$app->getElements()->getElementById($this->elementId, null, $siteId);

        if (!$element) {
            $this->stderr("Element #{$this->elementId} not found.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $label = class_basename(get_class($element)) . ': ' . ElementLabel::for($element);
        $this->stdout("Scanning \"{$label}\"...\n");

        $result = AccessibilityAudit::getInstance()->getAudit()->scanElement($element);

        // A scan that never ran still comes back with a score, because the
        // caller is expected to read why it stopped. Printing the number on its
        // own reports a page nobody opened as a clean one.
        if ($result['scanId'] === 0) {
            if (!empty($result['error'])) {
                $this->stderr($result['error'] . "\n", Console::FG_RED);

                return ExitCode::UNSPECIFIED_ERROR;
            }

            if (!empty($result['excluded'])) {
                $this->stdout(
                    "Not scanned: this page is on the excluded list.\n",
                    Console::FG_YELLOW
                );

                return ExitCode::OK;
            }

            if (!empty($result['limitReached'])) {
                $this->stderr("Scan limit reached for this edition.\n", Console::FG_RED);

                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stderr("This element has no page to scan.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout("Score: {$result['score']}/100\n");
        $this->stdout("Issues found: " . count($result['issues']) . "\n");

        foreach ($result['issues'] as $issue) {
            $colour = match ($issue->severity) {
                'error' => Console::FG_RED,
                'warning' => Console::FG_YELLOW,
                default => Console::FG_GREY,
            };
            $wcag = $issue->wcagCriterion ? " [WCAG {$issue->wcagCriterion}]" : '';
            $this->stdout("  [{$issue->severity}]{$wcag} {$issue->message}\n", $colour);
        }

        return ExitCode::OK;
    }

    /**
     * Scan a single URL that has no element behind it, such as a search results
     * or filtered listing page.
     *
     * @return int
     * @throws SiteNotFoundException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function actionScanUrl(): int
    {
        if (!$this->url) {
            $this->stderr("--url is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $siteId = $this->_resolveSiteId();

        if ($siteId === null) {
            return ExitCode::USAGE;
        }
        $this->stdout("Scanning {$this->url}...\n");

        $result = AccessibilityAudit::getInstance()->getAudit()->scanUrl($this->url, $siteId);

        if (!empty($result['error'])) {
            $this->stderr($result['error'] . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if (!empty($result['limitReached'])) {
            $this->stderr("Scan limit reached for this edition.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Score: {$result['score']}/100\n");
        $this->stdout("Scan ID: {$result['scanId']}\n");

        return ExitCode::OK;
    }

    /**
     * Queue a batched sweep of every image asset's alt text.
     *
     * @return int
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionScanAssets(): int
    {
        $total = (int) AccessibilityAudit::getInstance()->getAssets()->imageQuery()->count();

        $pruned = AccessibilityAudit::getInstance()->getAssets()->pruneOrphanedAssetIssues();

        Craft::$app->getQueue()->push(new AuditAssets());

        if ($pruned > 0) {
            $this->stdout("Cleared {$pruned} orphaned audit row(s) from deleted images.\n");
        }
        $this->stdout("Asset sweep queued: {$total} image(s) will be audited as the queue runs.\n", Console::FG_GREEN);
        $this->stdout("Run the queue with: php craft queue/run\n");

        return ExitCode::OK;
    }

    /**
     * Prune old scan results.
     *
     * @return int
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPrune(): int
    {
        $plugin = AccessibilityAudit::getInstance();

        $days = $this->days;

        // Zero means "keep history for good" everywhere else the setting is
        // read, including the garbage-collection prune, so it means that here
        // too. Read as a cutoff instead it puts the boundary at this moment and
        // takes every scan ever recorded with it.
        if ($days <= 0 && $plugin->isPro()) {
            $this->stdout(
                "A retention of 0 keeps history for good. Nothing pruned.\n",
                Console::FG_YELLOW
            );

            return ExitCode::OK;
        }

        if (!$plugin->isPro() && ($days <= 0 || $days > AuditService::STANDARD_RETENTION_CAP)) {
            $this->stdout(
                "Standard edition keeps at most " . AuditService::STANDARD_RETENTION_CAP . " days of history; pruning to that.\n",
                Console::FG_YELLOW
            );
            $days = AuditService::STANDARD_RETENTION_CAP;
        }

        $this->stdout("Pruning scan results older than {$days} days...\n");
        $deleted = $plugin->getAudit()->pruneScanResults($days);
        $this->stdout("Deleted {$deleted} scan(s).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Remove scan data for pages that are now excluded from scanning.
     *
     * Adding an exclusion only stops future scans; a page scanned before its
     * exclusion keeps its stale score and issues in every report. This clears
     * those, reporting the count first and asking to confirm (use --interactive=0
     * to skip the prompt in a script).
     *
     * @return int
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPruneExcluded(): int
    {
        $plugin = AccessibilityAudit::getInstance();

        if (empty($plugin->getSettings()->excludedUriPatterns)) {
            $this->stdout("No excluded URI patterns are configured. Nothing to prune.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $scanIds = $plugin->getAudit()->getExcludedScanIds();
        if (empty($scanIds)) {
            $this->stdout("No scanned pages match the exclusion list. Nothing to remove.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $issueCount = (int)(new Query())
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanIds])
            ->count();

        $pages = count($scanIds);
        $this->stdout(
            "Found {$pages} excluded page(s) with {$issueCount} issue(s) still in reports.\n",
            Console::FG_YELLOW
        );

        if (!$this->_confirmRemoval()) {
            $this->stdout("Cancelled. Nothing removed.\n");
            return ExitCode::OK;
        }

        $result = $plugin->getAudit()->pruneExcludedPages();
        $this->stdout(
            "Removed {$result['pages']} page(s) and {$result['issues']} issue(s).\n",
            Console::FG_GREEN
        );
        return ExitCode::OK;
    }

    /**
     * Print a site-wide accessibility report.
     *
     * @return int
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionReport(): int
    {
        $siteId = $this->_resolveSiteId();

        if ($siteId === null) {
            return ExitCode::USAGE;
        }
        $summary = AccessibilityAudit::getInstance()->getAudit()->getSiteSummary($siteId);

        $this->stdout("\n=== Accessibility Report ===\n", Console::BOLD);
        $this->stdout("Pages scanned:  {$summary['scannedCount']}\n");
        $this->stdout("Average score:  {$summary['avgScore']}/100\n");
        $this->stdout("Total errors:   {$summary['errorCount']}\n", Console::FG_RED);
        $this->stdout("Total warnings: {$summary['warningCount']}\n", Console::FG_YELLOW);
        $this->stdout("Total notices:  {$summary['noticeCount']}\n");
        $this->stdout("Critical pages: {$summary['criticalPages']} (pages with at least one error)\n\n");

        $byOrigin = AccessibilityAudit::getInstance()->getAudit()->getIssuesByOrigin($siteId);

        if ($byOrigin !== [] && array_keys($byOrigin) !== ['unknown']) {
            $this->stdout("Where the markup came from:\n", Console::BOLD);

            foreach ($byOrigin as $origin => $count) {
                $label = match ($origin) {
                    'authored' => 'written by hand',
                    'unknown' => 'not recorded (scanned before this was tracked)',
                    default => 'component: ' . $origin,
                };

                $this->stdout("  {$count}× {$label}\n");
            }

            $this->stdout("\n");
        }

        $byRule = AccessibilityAudit::getInstance()->getAudit()->getIssuesByImpact($siteId, 15);
        if (!empty($byRule)) {
            $this->stdout("Top issues:\n", Console::BOLD);
            foreach ($byRule as $row) {
                $wcag = $row['wcagCriterion'] ? " (WCAG {$row['wcagCriterion']})" : '';
                $this->stdout("  {$row['occurrences']}× [{$row['severity']}] {$row['ruleId']}{$wcag}\n");
            }
        }

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * How many pages of each element type the sweep covers, for the summary
     * line, counted by the database rather than by reading every row.
     *
     * @param Query<int, array<string, mixed>> $query The sweep's element query.
     * @return array<string, int> Counts keyed by element class name.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _typeCounts(Query $query): array
    {
        $rows = (clone $query)
            ->select(['e.type', 'total' => 'COUNT(*)'])
            // The sweep's ordering is by element, which a grouped count cannot
            // carry and does not need.
            ->orderBy([])
            ->groupBy(['e.type'])
            ->all();

        return array_map('intval', array_column($rows, 'total', 'type'));
    }

    /**
     * The console colour a score is reported in, on the same thresholds the CP
     * score badge uses so a scan reads the same in both places.
     *
     * @param int $score The score out of 100.
     * @return int A Console foreground colour constant.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _scoreColour(int $score): int
    {
        return match (true) {
            $score >= 80 => Console::FG_GREEN,
            $score >= 50 => Console::FG_YELLOW,
            default => Console::FG_RED,
        };
    }
}
