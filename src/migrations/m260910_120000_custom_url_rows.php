<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use Craft;
use craft\db\Migration;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\SettingsModel;
use Throwable;

/**
 * Rewrites the Additional URLs setting from one string into a row per URL.
 *
 * The setting was a newline-separated list and is now an editable table, so
 * each URL can be turned off or scoped to a single site. A line commented out
 * with a `#` becomes an unticked row.
 *
 * Nothing depends on this running. {@see SettingsModel::setAttributes()}
 * converts a string on the way in, so a site whose settings are still stored
 * the old way behaves identically; this only settles the stored shape so the
 * next person to read the project config sees what the control panel shows.
 * That is why an install with admin changes turned off is left alone rather
 * than failing: it cannot write project config at all.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.3.0
 */
class m260910_120000_custom_url_rows extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $stored = Craft::$app->getProjectConfig()
            ->get('plugins.accessibility-audit.settings.customUrls');

        if (!is_string($stored)) {
            return true;
        }

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            Craft::info(
                'A11y: leaving the Additional URLs setting as it is, because admin changes are off. '
                . 'It is read as rows either way.',
                'accessibility-audit',
            );

            return true;
        }

        $plugin = AccessibilityAudit::getInstance();

        if ($plugin === null) {
            return true;
        }

        $settings = $plugin->getSettings();
        $settings->customUrls = SettingsModel::customUrlRows($stored);

        try {
            Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
        } catch (Throwable $e) {
            // The stored string still reads correctly, so a site that cannot
            // take the write is no worse off for it.
            Craft::warning(
                'A11y: could not rewrite the Additional URLs setting: ' . $e->getMessage(),
                'accessibility-audit',
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260910_120000_custom_url_rows cannot be reverted.\n";

        return false;
    }
}
