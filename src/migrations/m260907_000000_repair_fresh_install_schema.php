<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use Craft;
use craft\db\Migration;

/**
 * Brings a fresh install's schema up to what the upgrade path already had.
 *
 * The install migration was never updated alongside m260827_120000_add_url_scans,
 * so the URL columns and the relaxed element constraints only ever arrived by
 * upgrading. A site that installed the plugin outright got the older shape and
 * could not record a URL scan at all, and the earlier migration cannot correct
 * it because it is already marked as run.
 *
 * Everything here is checked before it is applied, so a site that upgraded its
 * way to the same schema passes straight through.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.3.0
 */
class m260907_000000_repair_fresh_install_schema extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $scans = '{{%accessibilityaudit_scans}}';
        $issues = '{{%accessibilityaudit_issues}}';

        // A no-op where the column is already nullable.
        $this->alterColumn($scans, 'elementId', $this->integer()->null());
        $this->alterColumn($scans, 'elementType', $this->string(255)->null());
        $this->alterColumn($issues, 'elementId', $this->integer()->null());
        $this->alterColumn($issues, 'elementType', $this->string(255)->null());

        if (!$this->db->columnExists($scans, 'url')) {
            $this->addColumn($scans, 'url', $this->string(2048)->null()->after('elementType'));
        }

        if (!$this->db->columnExists($scans, 'title')) {
            $this->addColumn($scans, 'title', $this->string(255)->null()->after('url'));
        }

        if (!$this->_indexExists($scans, ['siteId'])) {
            $this->createIndex(null, $scans, ['siteId']);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260907_000000_repair_fresh_install_schema cannot be reverted.\n";

        return false;
    }

    // Private Methods
    // =========================================================================

    /**
     * Says whether an index over exactly these columns is already on the table.
     *
     * Indexes are compared by the columns they cover rather than by name: the
     * ones being repaired were created with a generated name, which differs
     * between the install and the upgrade path.
     *
     * @param string $table The table to look at.
     * @param string[] $columns The columns the index should cover, in order.
     * @return bool
     */
    private function _indexExists(string $table, array $columns): bool
    {
        $schema = Craft::$app->getDb()->getSchema();
        $rawTable = $schema->getRawTableName($table);

        $schema->refreshTableSchema($rawTable);

        foreach ($schema->getTableIndexes($rawTable) as $index) {
            if ($index->columnNames === $columns) {
                return true;
            }
        }

        return false;
    }
}
