<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\queue\BaseBatchedJob;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use Throwable;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\queue\Queue;

/**
 * Sweeps every image asset in the library for alt-text issues and stores the
 * findings, one batch per job step.
 *
 * The stored results make the asset audit scale: the Assets page and the
 * dashboard read indexed counts instead of loading elements, so a
 * 65,000-image library costs the same to summarise as a 65-image one. The
 * per-asset checks are pure PHP (no HTTP, no rendering), so even huge
 * libraries sweep in minutes through the queue's memory-safe batches.
 *
 * @property-read Queue $queue
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class AuditAssets extends BaseBatchedJob
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Stats are refreshed after every batch (cheap indexed counts), so the
     * final batch naturally leaves the completion timestamp, and a sweep that
     * dies mid-way still reports how far it got.
     * @throws InvalidConfigException
     * @throws Exception
     * @throws \Exception
     */
    public function execute($queue): void
    {
        parent::execute($queue);

        AccessibilityAudit::getInstance()->getAssets()->updateStoredStats(
            (int) $this->_imageQuery()->count()
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    protected function loadData(): Batchable
    {
        // Oldest first by id, so an image uploaded during the sweep lands at
        // the end rather than shifting every page after it by one.
        return new QueryBatcher($this->_imageQuery()->orderBy(['elements.id' => SORT_ASC]));
    }

    /**
     * @inheritdoc
     *
     * @param Asset $item
     * @throws Throwable If the audit row sync fails irrecoverably.
     */
    protected function processItem(mixed $item): void
    {
        // One asset that will not read is logged and passed over rather than
        // ending the sweep, the same way the page sweep treats a bad page.
        try {
            AccessibilityAudit::getInstance()->getAssets()->syncAssetAudit($item);
        } catch (Throwable $e) {
            $id = (int) $item->id;
            Craft::warning("A11y: asset sweep skipped asset {$id}: " . $e->getMessage(), 'accessibility-audit');
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Auditing asset library alt text');
    }

    // Private Methods
    // =========================================================================

    /**
     * The image-asset query the sweep runs over, with any excluded volumes
     * filtered out at the query level so the batched loop never even receives
     * an excluded image, and the stored total counts only what was audited.
     *
     * @return AssetQuery<int, Asset>
     * @throws InvalidConfigException
     * @since 1.0.0
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    private function _imageQuery(): AssetQuery
    {
        return AccessibilityAudit::getInstance()->getAssets()->imageQuery();
    }
}
