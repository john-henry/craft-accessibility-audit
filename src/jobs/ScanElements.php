<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\base\Batchable;
use craft\queue\BaseBatchedJob;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\ScanTargets;
use johnhenry\accessibilityaudit\services\AuditService;
use Throwable;
use yii\queue\Queue;

/**
 * Scans every page in a site for accessibility issues, one batch per job step.
 *
 * A page is either a URL-bearing element or one of the Additional URLs listed
 * under Settings, and {@see ScanTargets} hands both over as a single run.
 *
 * Replaces the previous "one queue job per element" approach: on a large site
 * that produced thousands of individual jobs, each with its own outbound fetch.
 * A single batched job processes {@see self::$batchSize} pages per step and
 * lets Craft's batch runner handle memory monitoring and progress reporting.
 *
 * @property-read Queue $queue
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class ScanElements extends BaseBatchedJob
{
    // Constants
    // =========================================================================

    /**
     * How long the "a sweep is running" flag survives without the job clearing
     * it. A sweep that dies mid-run, a failed job or a worker restarted under
     * it, never reaches after(), and a flag with no expiry would leave the
     * Overview saying a scan is running for good.
     */
    private const SWEEP_TTL = 21600;

    // Public Properties
    // =========================================================================

    /**
     * @var int The site ID whose elements should be scanned.
     */
    public int $siteId = 0;

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        $plugin = AccessibilityAudit::getInstance();

        return new ScanTargets(
            $plugin->audit->getUrlElementsQuery($this->siteId),
            $plugin->getSettings()->resolvedCustomUrls($this->siteId),
        );
    }

    /**
     * @inheritdoc
     *
     * @param array{elementId: int|string, elementType: string}|string $item An
     * element row, or a configured URL.
     * @throws Throwable If the underlying scan fails irrecoverably.
     */
    protected function processItem(mixed $item): void
    {
        if (is_string($item)) {
            AccessibilityAudit::getInstance()->audit->scanUrl($item, $this->siteId);

            return;
        }

        $element = Craft::$app->getElements()->getElementById(
            (int) $item['elementId'],
            $item['elementType'] ?: null,
            $this->siteId,
        );

        if (!$element || !$element->getUrl()) {
            return;
        }

        AccessibilityAudit::getInstance()->audit->scanElement($element);
    }

    /**
     * @inheritdoc
     *
     * Marks the sweep as under way so the Overview can say its figures are
     * still moving. A finished sweep leaves pages unscanned by design, so the
     * page count cannot be read as progress.
     */
    protected function before(): void
    {
        Craft::$app->getCache()->set(AuditService::sweepKey($this->siteId), true, self::SWEEP_TTL);
    }

    /**
     * @inheritdoc
     */
    protected function after(): void
    {
        Craft::$app->getCache()->delete(AuditService::sweepKey($this->siteId));
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Scanning all pages for accessibility issues');
    }
}
