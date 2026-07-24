<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\queue\BaseBatchedJob;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use Throwable;
use yii\queue\Queue;

/**
 * Scans every URL-bearing element in a site for accessibility issues, one
 * batch per job step.
 *
 * Replaces the previous "one queue job per element" approach: on a large site
 * that produced thousands of individual jobs, each with its own outbound fetch.
 * A single batched job processes {@see self::$batchSize} elements per step and
 * lets Craft's batch runner handle memory monitoring and progress reporting.
 *
 * @property-read Queue $queue
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class ScanElements extends BaseBatchedJob
{
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
        return new QueryBatcher(
            AccessibilityAudit::getInstance()->audit->getUrlElementsQuery($this->siteId)
        );
    }

    /**
     * @inheritdoc
     *
     * @param array{elementId: int|string, elementType: string} $item
     * @throws Throwable If the underlying scan fails irrecoverably.
     */
    protected function processItem(mixed $item): void
    {
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
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Scanning all pages for accessibility issues');
    }
}
