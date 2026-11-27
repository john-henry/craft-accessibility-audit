<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Adds the table recording when each site's scheduled summary last went out,
 * so a summary is sent once per week or month however many times the check
 * runs.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class m261125_140000_report_sends extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function safeUp(): bool
    {
        $table = '{{%accessibilityaudit_report_sends}}';

        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'lastSent' => $this->date()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['siteId'], true);
        $this->addForeignKey(null, $table, 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%accessibilityaudit_report_sends}}');

        return true;
    }
}
