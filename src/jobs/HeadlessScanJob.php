<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\db\Query;
use craft\queue\BaseJob;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\HeadlessScanner;
use yii\db\Exception;

/**
 * Runs server-side axe-core browser passes against one page and stores the
 * findings on its scan record.
 *
 * The page is rendered once per viewport bucket (desktop and mobile) on a
 * single shared browser, each pass stored into its own bucket, so mobile-only
 * failures (target size,
 * breakpoint-dependent contrast and landmarks) are caught without an admin
 * ever visiting the page on a phone. Queued after a successful PHP scan when
 * headless Chrome is configured (Pro). Results feed
 * AuditService::storeAxeIssues, so the cross-engine dedup and score
 * recalculation apply exactly as they do for the frontend overlay.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class HeadlessScanJob extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The scan record to attach the browser findings to.
     */
    public int $scanId = 0;

    /**
     * @var string The absolute URL of the page to scan.
     */
    public string $url = '';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws Exception
     * @throws \Exception
     */
    public function execute($queue): void
    {
        if ($this->scanId === 0 || $this->url === '') {
            return;
        }

        $plugin = AccessibilityAudit::getInstance();

        // Settings (or the edition) may have changed between queueing and
        // running; skip quietly rather than fail the job.
        if (!$plugin->headless->isAvailable()) {
            return;
        }

        // The scan may have been pruned or superseded since queueing.
        $scanExists = (new Query())
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['id' => $this->scanId])
            ->exists();

        if (!$scanExists) {
            return;
        }

        // One pass per viewport bucket on a single shared browser (launching
        // Chrome per viewport doubles the cost of large scans), each pass
        // replacing only its own bucket. A failed pass (null, already logged)
        // skips just that viewport: the PHP scan results and the other
        // viewport's findings stand on their own, and carried-forward overlay
        // data is untouched.
        $results = $plugin->headless->scanUrlViewports($this->url, array_keys(HeadlessScanner::VIEWPORTS));

        foreach ($results as $viewport => $findings) {
            if ($findings === null) {
                continue;
            }

            $plugin->audit->storeAxeIssues(
                $this->scanId,
                $findings['violations'],
                $viewport,
                $findings['incomplete'],
            );
            Craft::info("Headless axe scan ({$viewport}) stored for scan {$this->scanId}", 'accessibility-audit');
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Browser accessibility checks');
    }
}
