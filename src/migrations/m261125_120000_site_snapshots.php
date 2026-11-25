<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Adds the table that records each site's headline figures once a day.
 *
 * Scans are pruned after the retention window and the Overview chart averages
 * whichever pages ran on a day, so neither can say what the site scored on a
 * given date. One row per site per day can, and is small enough to keep for good.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class m261125_120000_site_snapshots extends Migration
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
        $table = '{{%accessibilityaudit_snapshots}}';

        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'date' => $this->date()->notNull(),
            'avgScore' => $this->smallInteger()->unsigned()->notNull(),
            'avgScoreA' => $this->smallInteger()->unsigned()->notNull(),
            'avgScoreAA' => $this->smallInteger()->unsigned()->notNull(),
            'avgScoreAAA' => $this->smallInteger()->unsigned()->notNull(),
            'scannedCount' => $this->integer()->unsigned()->notNull(),
            'scannableCount' => $this->integer()->unsigned()->notNull(),
            'errorCount' => $this->integer()->unsigned()->notNull(),
            'warningCount' => $this->integer()->unsigned()->notNull(),
            'noticeCount' => $this->integer()->unsigned()->notNull(),
            'criticalPages' => $this->integer()->unsigned()->notNull(),
            'failingCriteriaAA' => $this->smallInteger()->unsigned()->notNull(),
            'targetScore' => $this->smallInteger()->unsigned()->notNull(),
            'pagesBelowTarget' => $this->integer()->unsigned()->null(),
            'pendingReview' => $this->integer()->unsigned()->notNull(),
            'sweepRunning' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, $table, ['siteId', 'date'], true);
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
        $this->dropTableIfExists('{{%accessibilityaudit_snapshots}}');

        return true;
    }
}
