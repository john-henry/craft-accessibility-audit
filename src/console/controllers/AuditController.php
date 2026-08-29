<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\elements\Asset;
use craft\errors\SiteNotFoundException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\jobs\AuditAssets;
use johnhenry\accessibilityaudit\services\AuditService;
use Throwable;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;
use yii\db\Exception;
use yii\helpers\BaseConsole;

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
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class AuditController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The default action.
     */
    public $defaultAction = 'scan-all';

    /**
     * @var string|null Site handle to restrict scans to.
     */
    public ?string $site = null;

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
            'scan-all' => ['site'],
            'scan-element' => ['elementId', 'site'],
            'scan-url' => ['url', 'site'],
            'prune' => ['days'],
            'report' => ['site'],
            default => [],
        });
    }

    /**
     * Scan all live, public elements across entries, categories, Commerce products, etc.
     *
     * @return int
     * @throws SiteNotFoundException
     */
    public function actionScanAll(): int
    {
        $siteId = $this->resolveSiteId();
        $audit = AccessibilityAudit::getInstance()->audit;
        $urlRows = $audit->getUrlElements($siteId);

        // Configured URLs are scanned even when nothing else is, so a site
        // that routes everything through templates is not turned away here.
        if (empty($urlRows) && empty(AccessibilityAudit::getInstance()->getSettings()->resolvedCustomUrls())) {
            $this->stdout("No URL-bearing elements found for this site.\n", BaseConsole::FG_YELLOW);
            return ExitCode::OK;
        }

        $total = count($urlRows);
        $errors = 0;

        // Group by type for a summary line
        $typeCounts = array_count_values(array_column($urlRows, 'elementType'));
        $summary = [];
        foreach ($typeCounts as $type => $count) {
            $summary[] = $count . '× ' . class_basename($type);
        }
        $this->stdout("Scanning {$total} elements (" . implode(', ', $summary) . ")...\n", BaseConsole::FG_GREEN);

        foreach ($urlRows as $i => $row) {
            $element = Craft::$app->getElements()->getElementById(
                (int) $row['elementId'],
                $row['elementType'] ?: null,
                $siteId
            );

            if (!$element || !$element->getUrl()) {
                continue;
            }

            $label = class_basename(get_class($element)) . ': ' . ($element->title ?? "#{$element->id}");
            $this->stdout(sprintf("  [%d/%d] %s ... ", $i + 1, $total, $label));

            try {
                $result = $audit->scanElement($element);

                // A page that could not be read has no score, and printing one
                // for it reads as a verdict on a page nobody opened.
                if (($result['error'] ?? null) !== null) {
                    $this->stdout('skipped: ' . $result['error'] . PHP_EOL, BaseConsole::FG_YELLOW);
                    continue;
                }

                $score = $result['score'];
                $colour = $score >= 80 ? BaseConsole::FG_GREEN : ($score >= 50 ? BaseConsole::FG_YELLOW : BaseConsole::FG_RED);
                $this->stdout("score: {$score} (issues: " . count($result['issues']) . ")\n", $colour);
            } catch (Throwable $e) {
                $this->stdout("FAILED: " . $e->getMessage() . "\n", BaseConsole::FG_RED);
                $errors++;
            }
        }

        // Configured URLs last: pages Craft routes without an element behind
        // them, which the sweep above has no way of finding.
        $customUrls = AccessibilityAudit::getInstance()->getSettings()->resolvedCustomUrls();

        if (!empty($customUrls)) {
            $count = count($customUrls);
            $this->stdout("\nScanning {$count} additional URL(s)...\n", BaseConsole::FG_GREEN);

            foreach ($customUrls as $i => $customUrl) {
                $this->stdout(sprintf("  [%d/%d] %s ... ", $i + 1, $count, $customUrl));

                try {
                    $result = $audit->scanUrl($customUrl, $siteId);

                    if ($result['scanId'] === 0) {
                        $this->stdout('SKIPPED: ' . ($result['error'] ?? 'scan limit reached') . "\n", BaseConsole::FG_YELLOW);
                        continue;
                    }

                    $score = (int)$result['score'];
                    $scoreColour = $score >= 80 ? BaseConsole::FG_GREEN : ($score >= 50 ? BaseConsole::FG_YELLOW : BaseConsole::FG_RED);
                    $this->stdout("score: {$score}\n", $scoreColour);
                    $total++;
                } catch (Throwable $e) {
                    $this->stdout('FAILED: ' . $e->getMessage() . "\n", BaseConsole::FG_RED);
                    $errors++;
                }
            }
        }

        $colour = $errors > 0 ? BaseConsole::FG_RED : BaseConsole::FG_GREEN;
        $this->stdout("\nDone. {$total} scanned, {$errors} error(s).\n", $colour);

        // A non-zero exit when any scan failed, so a pipeline running this
        // can actually tell. Scans that succeed with poor scores still exit
        // 0: score gating is the CI endpoint's job, not this command's.
        return $errors > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Scan a single element by ID (works for entries, products, categories, etc.).
     *
     * @return int
     * @throws SiteNotFoundException
     */
    public function actionScanElement(): int
    {
        if (!$this->elementId) {
            $this->stderr("--element-id is required.\n", BaseConsole::FG_RED);
            return ExitCode::USAGE;
        }

        $siteId = $this->resolveSiteId();
        $element = Craft::$app->getElements()->getElementById($this->elementId, null, $siteId);

        if (!$element) {
            $this->stderr("Element #{$this->elementId} not found.\n", BaseConsole::FG_RED);
            return ExitCode::DATAERR;
        }

        $label = class_basename(get_class($element)) . ': ' . ($element->title ?? "#{$element->id}");
        $this->stdout("Scanning \"{$label}\"...\n");

        $result = AccessibilityAudit::getInstance()->audit->scanElement($element);

        $this->stdout("Score: {$result['score']}/100\n");
        $this->stdout("Issues found: " . count($result['issues']) . "\n");

        foreach ($result['issues'] as $issue) {
            $colour = match ($issue->severity) {
                'error' => BaseConsole::FG_RED,
                'warning' => BaseConsole::FG_YELLOW,
                default => BaseConsole::FG_GREY,
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
     */
    public function actionScanUrl(): int
    {
        if (!$this->url) {
            $this->stderr("--url is required.
", BaseConsole::FG_RED);
            return ExitCode::USAGE;
        }

        $siteId = $this->resolveSiteId();
        $this->stdout("Scanning {$this->url}...
");

        $result = AccessibilityAudit::getInstance()->audit->scanUrl($this->url, $siteId);

        if (!empty($result['error'])) {
            $this->stderr($result['error'] . "
", BaseConsole::FG_RED);
            return ExitCode::DATAERR;
        }

        if (!empty($result['limitReached'])) {
            $this->stderr("Scan limit reached for this edition.
", BaseConsole::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Score: {$result['score']}/100
");
        $this->stdout("Scan ID: {$result['scanId']}
");

        return ExitCode::OK;
    }

    /**
     * Queue a batched sweep of every image asset's alt text.
     *
     * @return int
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function actionScanAssets(): int
    {
        $query = Asset::find()->kind(Asset::KIND_IMAGE);
        $excludedIds = AccessibilityAudit::getInstance()->getAssets()->excludedVolumeIds();
        if (!empty($excludedIds)) {
            $query->andWhere(['not', ['volumeId' => $excludedIds]]);
        }
        $total = (int) $query->count();

        $pruned = AccessibilityAudit::getInstance()->getAssets()->pruneOrphanedAssetIssues();

        Craft::$app->getQueue()->push(new AuditAssets());

        if ($pruned > 0) {
            $this->stdout("Cleared {$pruned} orphaned audit row(s) from deleted images.\n");
        }
        $this->stdout("Asset sweep queued: {$total} image(s) will be audited as the queue runs.\n", BaseConsole::FG_GREEN);
        $this->stdout("Run the queue with: php craft queue/run\n");

        return ExitCode::OK;
    }

    /**
     * Prune old scan results.
     *
     * @return int
     * @throws Exception
     */
    public function actionPrune(): int
    {
        $plugin = AccessibilityAudit::getInstance();

        $days = $this->days;
        if (!$plugin->isPro() && ($days <= 0 || $days > AuditService::STANDARD_RETENTION_CAP)) {
            $this->stdout(
                "Standard edition keeps at most " . AuditService::STANDARD_RETENTION_CAP . " days of history; pruning to that.\n",
                BaseConsole::FG_YELLOW
            );
            $days = AuditService::STANDARD_RETENTION_CAP;
        }

        $this->stdout("Pruning scan results older than {$days} days...\n");
        $deleted = $plugin->audit->pruneScanResults($days);
        $this->stdout("Deleted {$deleted} scan(s).\n", BaseConsole::FG_GREEN);
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
     */
    public function actionPruneExcluded(): int
    {
        $plugin = AccessibilityAudit::getInstance();

        if (empty($plugin->getSettings()->excludedUriPatterns)) {
            $this->stdout("No excluded URI patterns are configured. Nothing to prune.\n", BaseConsole::FG_YELLOW);
            return ExitCode::OK;
        }

        $scanIds = $plugin->audit->getExcludedScanIds();
        if (empty($scanIds)) {
            $this->stdout("No scanned pages match the exclusion list. Nothing to remove.\n", BaseConsole::FG_GREEN);
            return ExitCode::OK;
        }

        $issueCount = (int)(new Query())
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanIds])
            ->count();

        $pages = count($scanIds);
        $this->stdout(
            "Found {$pages} excluded page(s) with {$issueCount} issue(s) still in reports.\n",
            BaseConsole::FG_YELLOW
        );

        if (!$this->confirmRemoval()) {
            $this->stdout("Cancelled. Nothing removed.\n");
            return ExitCode::OK;
        }

        $result = $plugin->audit->pruneExcludedPages();
        $this->stdout(
            "Removed {$result['pages']} page(s) and {$result['issues']} issue(s).\n",
            BaseConsole::FG_GREEN
        );
        return ExitCode::OK;
    }

    /**
     * Confirmation prompt for the excluded-pages removal, honouring
     * non-interactive runs (--interactive=0), where it returns true.
     *
     * @return bool
     */
    private function confirmRemoval(): bool
    {
        if (!$this->interactive) {
            return true;
        }
        return $this->confirm('Remove them?', true);
    }

    /**
     * Print a site-wide accessibility report.
     *
     * @return int
     * @throws SiteNotFoundException
     */
    public function actionReport(): int
    {
        $siteId = $this->resolveSiteId();
        $summary = AccessibilityAudit::getInstance()->audit->getSiteSummary($siteId);

        $this->stdout("\n=== Accessibility Report ===\n", BaseConsole::BOLD);
        $this->stdout("Pages scanned:  {$summary['scannedCount']}\n");
        $this->stdout("Average score:  {$summary['avgScore']}/100\n");
        $this->stdout("Total errors:   {$summary['errorCount']}\n", BaseConsole::FG_RED);
        $this->stdout("Total warnings: {$summary['warningCount']}\n", BaseConsole::FG_YELLOW);
        $this->stdout("Total notices:  {$summary['noticeCount']}\n");
        $this->stdout("Critical pages: {$summary['criticalPages']} (pages with at least one error)\n\n");

        $byRule = AccessibilityAudit::getInstance()->audit->getIssuesByImpact($siteId, 15);
        if (!empty($byRule)) {
            $this->stdout("Top issues:\n", BaseConsole::BOLD);
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
     * Resolves the site ID from the --site option, falling back to the primary site.
     *
     * @return int
     * @throws SiteNotFoundException
     */
    private function resolveSiteId(): int
    {
        // Multi-site is a Pro feature: --site is only honoured on Pro, so
        // Standard always operates on the primary site.
        if ($this->site) {
            if (!AccessibilityAudit::getInstance()->isPro()) {
                $this->stderr(
                    "Multi-site is a Pro feature; --site is ignored on the Standard edition.\n",
                    BaseConsole::FG_YELLOW
                );
            } else {
                $site = Craft::$app->getSites()->getSiteByHandle($this->site);
                if ($site) {
                    return $site->id;
                }
            }
        }
        return Craft::$app->getSites()->getPrimarySite()->id;
    }
}
