<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Adds a selector column to the issues table.
 *
 * Holds the browser engine's own CSS selector for the element an issue is
 * about, so the page report can find the exact element where the stored
 * markup is a bare tag shared by several. Kept out of `context` because
 * rulings are keyed on a hash of that, and changing it would orphan them.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class m261125_000000_issue_selector extends Migration
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
        $table = '{{%accessibilityaudit_issues}}';

        if ($this->db->columnExists($table, 'selector')) {
            return true;
        }

        $this->addColumn($table, 'selector', $this->string(300)->null());

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
        $table = '{{%accessibilityaudit_issues}}';

        if ($this->db->columnExists($table, 'selector')) {
            $this->dropColumn($table, 'selector');
        }

        return true;
    }
}
