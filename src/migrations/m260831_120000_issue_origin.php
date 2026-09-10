<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Records where an issue's markup came from: a component library, or written by
 * hand on the page.
 *
 * Left null on every row that already exists rather than guessed at. An old
 * scan was taken before anything knew to look, and filling those in with
 * "authored" would be inventing a fact. They stay unknown until the page is
 * scanned again.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.3.0
 */
class m260831_120000_issue_origin extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $table = '{{%accessibilityaudit_issues}}';

        if (!$this->db->columnExists($table, 'origin')) {
            $this->addColumn($table, 'origin', $this->string(50)->null()->after('source'));
            $this->createIndex(null, $table, ['origin'], false);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $table = '{{%accessibilityaudit_issues}}';

        if ($this->db->columnExists($table, 'origin')) {
            $this->dropColumn($table, 'origin');
        }

        return true;
    }
}
