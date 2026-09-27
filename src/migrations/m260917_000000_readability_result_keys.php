<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;

/**
 * Keeps one readability result per element and site, and ties results to
 * their element and site so they go when those do.
 *
 * Rows whose element or site no longer exists are removed, and where an
 * element and site have more than one row the most recently analysed is kept,
 * before the unique index and foreign keys are added.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class m260917_000000_readability_result_keys extends Migration
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The readability results table.
     */
    private const TABLE = '{{%accessibilityaudit_readability}}';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->delete(self::TABLE, [
            'and',
            ['not', ['elementId' => null]],
            ['not in', 'elementId', (new Query())->select('id')->from('{{%elements}}')],
        ]);
        $this->delete(self::TABLE, [
            'and',
            ['not', ['siteId' => null]],
            ['not in', 'siteId', (new Query())->select('id')->from('{{%sites}}')],
        ]);

        $duplicates = (new Query())
            ->select(['elementId', 'siteId'])
            ->from(self::TABLE)
            ->where(['not', ['elementId' => null]])
            ->groupBy(['elementId', 'siteId'])
            ->having('COUNT(*) > 1')
            ->all($this->db);

        foreach ($duplicates as $pair) {
            $keep = (new Query())
                ->select('id')
                ->from(self::TABLE)
                ->where(['elementId' => $pair['elementId'], 'siteId' => $pair['siteId']])
                ->orderBy(['dateAnalysed' => SORT_DESC, 'id' => SORT_DESC])
                ->scalar($this->db);

            $this->delete(self::TABLE, [
                'and',
                ['elementId' => $pair['elementId'], 'siteId' => $pair['siteId']],
                ['not', ['id' => $keep]],
            ]);
        }

        if ($index = Db::findIndex(self::TABLE, ['elementId', 'siteId'], false, $this->db)) {
            $this->dropIndex($index, self::TABLE);
        }

        if (!Db::findIndex(self::TABLE, ['elementId', 'siteId'], true, $this->db)) {
            $this->createIndex(null, self::TABLE, ['elementId', 'siteId'], true);
        }

        if (!Db::findForeignKey(self::TABLE, 'elementId', $this->db)) {
            $this->addForeignKey(null, self::TABLE, 'elementId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
        }

        if (!Db::findForeignKey(self::TABLE, 'siteId', $this->db)) {
            $this->addForeignKey(null, self::TABLE, 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260917_000000_readability_result_keys cannot be reverted.\n";

        return false;
    }
}
