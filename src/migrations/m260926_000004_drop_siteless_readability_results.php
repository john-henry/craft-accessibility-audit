<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use Craft;
use craft\db\Migration;
use yii\db\Exception;

/**
 * Deletes readability results that belong to neither an element nor a site.
 *
 * Those are pages analysed by URL that weren't entries: other websites, and
 * this install's own pages built from templates. None of them shows in the
 * Readability table, which lists one site's results. An own page listed under
 * Additional URLs is scored again, against its site, by Analyse every page.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class m260926_000004_drop_siteless_readability_results extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     * @throws Exception if the delete fails.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function safeUp(): bool
    {
        self::apply();

        return true;
    }

    /**
     * Deletes the results with no element and no site.
     *
     * @return int How many were deleted.
     * @throws Exception if the delete fails.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function apply(): int
    {
        return Craft::$app->getDb()->createCommand()
            ->delete('{{%accessibilityaudit_readability}}', ['elementId' => null, 'siteId' => null])
            ->execute();
    }

    /**
     * @inheritdoc
     *
     * @return bool Always true: the deleted results can't be put back.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function safeDown(): bool
    {
        return true;
    }
}
