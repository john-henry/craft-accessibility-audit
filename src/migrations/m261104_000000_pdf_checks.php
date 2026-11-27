<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Adds the table that records each PDF check.
 *
 * One row per PDF asset, holding whether the file was checked, why not where
 * it was not, and the size and date of the file it was checked as, so a
 * replaced file is checked again and an unchanged one is not. The findings go
 * into the existing asset issues table under their rule ids.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class m261104_000000_pdf_checks extends Migration
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
        $table = '{{%accessibilityaudit_pdf_checks}}';

        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'fileSize' => $this->bigInteger(),
            'fileModified' => $this->dateTime(),
            'status' => $this->string(20)->notNull(),
            'reason' => $this->string(30),
            'ruleSetVersion' => $this->smallInteger()->notNull()->defaultValue(0),
            'hasForm' => $this->boolean()->notNull()->defaultValue(false),
            'meta' => $this->mediumText(),
            'dateChecked' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['assetId'], true);
        $this->createIndex(null, $table, ['status', 'reason']);
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
        $this->dropTableIfExists('{{%accessibilityaudit_pdf_checks}}');

        return true;
    }
}
