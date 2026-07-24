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
 * @author JohnHenry <info@johnhenry.ie>
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
     */
    protected function loadData(): Batchable
    {
        return new QueryBatcher($this->_imageQuery());
    }

    /**
     * @inheritdoc
     *
     * @param Asset $item
     * @throws Throwable If the audit row sync fails irrecoverably.
     */
    protected function processItem(mixed $item): void
    {
        AccessibilityAudit::getInstance()->getAssets()->syncAssetAudit($item);
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
     * @return AssetQuery
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _imageQuery(): AssetQuery
    {
        $query = Asset::find()->kind(Asset::KIND_IMAGE);
        $excludedIds = AccessibilityAudit::getInstance()->getAssets()->excludedVolumeIds();
        if (!empty($excludedIds)) {
            $query->andWhere(['not', ['volumeId' => $excludedIds]]);
        }

        return $query;
    }
}
