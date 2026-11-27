<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Adds the table holding each site's note for the directors summary.
 *
 * Kept apart from the organisation details, which the VPAT and statement
 * editors save as a whole: a note stored among them would be wiped by the
 * next save on either screen.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class m261125_130000_report_notes extends Migration
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
        $table = '{{%accessibilityaudit_report_notes}}';

        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'note' => $this->text(),
            'userId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['siteId'], true);
        $this->addForeignKey(null, $table, 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, $table, 'userId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

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
        $this->dropTableIfExists('{{%accessibilityaudit_report_notes}}');

        return true;
    }
}
