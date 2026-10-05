<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Records on each scan which keyboard focus questions its browser pass
 * actually measured.
 *
 * Both start false on existing scans: nothing on them says whether a walk ran,
 * so they are claimed for a page only once the browser pass walks it again.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.6.0
 */
class m261005_000000_focus_walk_record extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    public function safeUp(): bool
    {
        $table = '{{%accessibilityaudit_scans}}';

        if (!$this->db->columnExists($table, 'focusVisibleChecked')) {
            $this->addColumn($table, 'focusVisibleChecked', $this->boolean()->notNull()->defaultValue(false)->after('noticeCount'));
        }

        if (!$this->db->columnExists($table, 'focusObscuredChecked')) {
            $this->addColumn($table, 'focusObscuredChecked', $this->boolean()->notNull()->defaultValue(false)->after('focusVisibleChecked'));
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    public function safeDown(): bool
    {
        $table = '{{%accessibilityaudit_scans}}';

        if ($this->db->columnExists($table, 'focusObscuredChecked')) {
            $this->dropColumn($table, 'focusObscuredChecked');
        }

        if ($this->db->columnExists($table, 'focusVisibleChecked')) {
            $this->dropColumn($table, 'focusVisibleChecked');
        }

        return true;
    }
}
