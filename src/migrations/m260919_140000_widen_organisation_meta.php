<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Widens the shared organisation metadata column to hold what is written into
 * it.
 *
 * The column carries one JSON document holding the details every compliance
 * document shares: the product, the contact, the evaluation methodology, and
 * two open-ended lists, the methods used and the pages the evaluation covered.
 * Neither list is capped in length, and a scope list naming the pages of a
 * whole site runs past what a `text` column holds.
 *
 * Overflowing fails the save where the database runs strict and truncates the
 * JSON where it does not. Truncated JSON cannot be read back, so the product
 * name, the contact address and the methodology disappear from the VPAT and
 * from the published accessibility statement at once.
 *
 * The VPAT and statement tables were widened for the same reason in
 * m260919_000000_widen_vpat_overrides. This one was missed at the time.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class m260919_140000_widen_organisation_meta extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->alterColumn('{{%accessibilityaudit_organisation}}', 'meta', $this->mediumText()->null());

        return true;
    }

    /**
     * @inheritdoc
     *
     * Narrowing the column again would truncate anything written since, so the
     * data would be destroyed by the rollback rather than by the overflow.
     */
    public function safeDown(): bool
    {
        echo "m260919_140000_widen_organisation_meta cannot be reverted.\n";

        return false;
    }
}
