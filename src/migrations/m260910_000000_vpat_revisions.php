<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Keeps a snapshot of the VPAT answers each time a revision is recorded.
 *
 * A conformance report is a claim about a moving website, and read on its own
 * it says nothing about whether the position is improving or slipping. Holding
 * the previous answers lets a reissued report state what changed since the last
 * one, per criterion.
 *
 * The whole answer map is stored rather than a diff. Diffs computed at write
 * time are only as good as the code that wrote them, and a snapshot can be
 * re-read against any future definition of what counts as a change.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.3.0
 */
class m260910_000000_vpat_revisions extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $table = '{{%accessibilityaudit_vpat_revisions}}';

        if (!$this->db->tableExists($table)) {
            $this->createTable($table, [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'snapshot' => $this->mediumText()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, $table, ['siteId', 'dateCreated']);

            $this->addForeignKey(
                null,
                $table,
                'siteId',
                '{{%sites}}',
                'id',
                'CASCADE',
                null,
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%accessibilityaudit_vpat_revisions}}');

        return true;
    }
}
