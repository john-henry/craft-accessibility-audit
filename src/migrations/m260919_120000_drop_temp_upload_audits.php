<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * Clears stored audit rows for Craft's temporary uploads.
 *
 * A temporary upload belongs to no volume, so no volume exclusion ever reached
 * it and the sweep audited every part-built file on its way to a volume. They
 * filled the Images screen with "photo.jpg / Temporary Uploads" rows, all of
 * them reported as missing alt text, most with thumbnails already gone because
 * the file had since been cleared.
 *
 * The sweep leaves them alone now. This clears what it recorded before, which
 * nothing else would: the rows are not orphaned, so the orphan prune keeps
 * them.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class m260919_120000_drop_temp_upload_audits extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $tempAssetIds = (new Query())
            ->select(['id'])
            ->from(['{{%assets}}'])
            ->where(['volumeId' => null]);

        // asset_stats holds one site-wide row and carries no assetId. Its
        // totalImages is recounted by the next sweep.
        foreach (['asset_issues', 'asset_flags'] as $table) {
            $name = "{{%accessibilityaudit_{$table}}}";

            if (!$this->db->tableExists($name)) {
                continue;
            }

            $this->delete($name, ['assetId' => $tempAssetIds]);
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * Deleted rows describe files that were never the audit's business. There
     * is nothing to put back, and the next sweep will not recreate them.
     */
    public function safeDown(): bool
    {
        echo "m260919_120000_drop_temp_upload_audits cannot be reverted.\n";

        return false;
    }
}
