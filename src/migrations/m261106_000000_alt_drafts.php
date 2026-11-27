<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Adds the table that holds AI alt text drafts waiting for review.
 *
 * One row per image: the three versions Claude wrote, what it took the image
 * to be, and its notes on what to check, or the reason no draft could be
 * written. Nothing in it is alt text until an editor approves it.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class m261106_000000_alt_drafts extends Migration
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
        $table = '{{%accessibilityaudit_alt_drafts}}';

        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'shortAlt' => $this->text(),
            'standardAlt' => $this->text(),
            'detailedAlt' => $this->text(),
            'imageType' => $this->string(30),
            'reviewReasons' => $this->text(),
            'status' => $this->string(20)->notNull(),
            'error' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['assetId'], true);
        $this->createIndex(null, $table, ['status']);
        $this->addForeignKey(null, $table, 'assetId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');

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
        $this->dropTableIfExists('{{%accessibilityaudit_alt_drafts}}');

        return true;
    }
}
