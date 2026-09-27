<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use Craft;
use craft\db\Migration;
use yii\base\Exception;

/**
 * Keeps the Site Target Score switched off on installs that never set one.
 *
 * The default moved from 0 (no target) to 90. An install that never saved the
 * settings has no stored value, so without this it would pick up the new
 * default on upgrade and its CI builds would start failing.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class m260926_000002_keep_target_score_off extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     * @throws Exception if project config can't be updated.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $schemaVersion = $projectConfig->get('plugins.accessibility-audit.schemaVersion', true);

        if ($schemaVersion !== null && version_compare($schemaVersion, '1.3.0', '>=')) {
            return true;
        }

        self::apply();

        return true;
    }

    /**
     * Pins the target score at 0 where none is stored.
     *
     * @return void
     * @throws Exception if project config can't be updated.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function apply(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        if ($projectConfig->get('plugins.accessibility-audit.settings.targetScore') !== null) {
            return;
        }

        $projectConfig->set(
            'plugins.accessibility-audit.settings.targetScore',
            0,
            'Keep the Accessibility Audit target score off',
        );
    }

    /**
     * @inheritdoc
     *
     * @return bool Always false; the change isn't reverted.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function safeDown(): bool
    {
        echo "m260926_000002_keep_target_score_off cannot be reverted.\n";

        return false;
    }
}
