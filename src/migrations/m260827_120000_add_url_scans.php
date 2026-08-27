<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Lets a scan belong to a URL rather than an element.
 *
 * Pages Craft routes without backing them with an element, a search results
 * page or a filtered listing, have no row in elements_sites and so were never
 * reachable. Holding the URL on the scan is what lets them be.
 *
 * The foreign key on elementId stays: a null is simply not checked, so real
 * elements still cascade on delete, and every query that joins elements keeps
 * excluding URL scans without being touched.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260827_120000_add_url_scans extends Migration
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

        $this->alterColumn($scans, 'elementId', $this->integer()->null());
        $this->alterColumn($scans, 'elementType', $this->string(255)->null());
        $this->alterColumn($issues, 'elementId', $this->integer()->null());
        $this->alterColumn($issues, 'elementType', $this->string(255)->null());

        if (!$this->db->columnExists($scans, 'url')) {
            $this->addColumn($scans, 'url', $this->string(2048)->null()->after('elementType'));
        }

        // Captured at scan time: a URL scan has no element to borrow a title
        // from, and "/search/results?q=craft" alone tells a reader little.
        if (!$this->db->columnExists($scans, 'title')) {
            $this->addColumn($scans, 'title', $this->string(255)->null()->after('url'));
        }

        // Prefix-indexed: the column is longer than an index key can be, and
        // lookups are on the whole URL for one site.
        $this->createIndex(null, $scans, ['siteId']);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260827_120000_add_url_scans cannot be reverted.\n";

        return false;
    }
}
