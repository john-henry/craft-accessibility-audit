<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Gives the VPAT's stored answers room for the remarks written into them.
 *
 * `overrides` holds every criterion's conformance level and its remark, and a
 * remark is a paragraph a person writes for a procurement team. Fifty-odd
 * criteria answered properly run past the 64KB a `text` column holds, and the
 * revisions table already uses `mediumText` for the snapshot of the same
 * answers: the source column was simply left at the default.
 *
 * Overflowing it fails the save outright where the database runs strict, and
 * truncates the JSON where it does not, which leaves the report unreadable and
 * the answers gone. The statement's own stored fields get the same treatment
 * for the same reason: its excluded-content entries are written prose too.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class m260919_000000_widen_vpat_overrides extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->alterColumn('{{%accessibilityaudit_vpat}}', 'overrides', $this->mediumText()->null());
        $this->alterColumn('{{%accessibilityaudit_vpat}}', 'meta', $this->mediumText()->null());
        $this->alterColumn('{{%accessibilityaudit_statement}}', 'exclusions', $this->mediumText()->null());
        $this->alterColumn('{{%accessibilityaudit_statement}}', 'meta', $this->mediumText()->null());

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Narrowing these back would cut whatever no longer fits, and what
        // would be cut is the wording somebody wrote.
        echo "m260919_000000_widen_vpat_overrides cannot be reverted.\n";

        return false;
    }
}
