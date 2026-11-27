<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use Craft;
use craft\db\Migration;
use craft\services\ProjectConfig;
use johnhenry\accessibilityaudit\AccessibilityAudit;

/**
 * Moves a site on the old Standard edition to Plus, its new name.
 *
 * Craft loads a plugin whose saved edition is no longer one it offers on the
 * lowest edition, so without this a Standard site would drop to Lite. The
 * edition lives in project config, so the change is made once where admin
 * changes are allowed and reaches other environments through the YAML.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class m261127_000000_rename_standard_edition extends Migration
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The edition handle the Plus edition had before 2.0.0.
     */
    public const OLD_HANDLE = 'standard';

    /**
     * @var string The schema version that first carries the new handle.
     */
    public const SCHEMA_VERSION = '2.0.2';

    /**
     * @var int The most history, in days, the Standard edition kept.
     */
    public const OLD_RETENTION_CAP = 30;

    // Public Methods
    // =========================================================================

    /**
     * The edition a site should be moved to, or null to leave it alone.
     *
     * @param mixed $savedEdition The edition in project config.
     * @param mixed $incomingSchema The schema version in the project config files.
     * @return string|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function editionToSet(mixed $savedEdition, mixed $incomingSchema): ?string
    {
        // The project config files already come from a site on the new handle.
        $incomingSchema = (string)$incomingSchema;
        if ($incomingSchema !== '' && version_compare($incomingSchema, self::SCHEMA_VERSION, '>=')) {
            return null;
        }

        return $savedEdition === self::OLD_HANDLE ? AccessibilityAudit::EDITION_PLUS : null;
    }

    /**
     * The history setting a site moved to Plus should get, or null to leave it.
     *
     * Standard capped history at 30 days and saved anything longer as 30, so a
     * stored 30 is that cap rather than a choice, and moves up to the Plus cap.
     * A shorter setting was picked on purpose and stays.
     *
     * @param mixed $retainDays The stored history setting, in days.
     * @return int|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function retainDaysToSet(mixed $retainDays): ?int
    {
        if (!is_numeric($retainDays) || (int)$retainDays !== self::OLD_RETENTION_CAP) {
            return null;
        }

        return AccessibilityAudit::PLUS_RETENTION_DAYS;
    }

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
        $projectConfig = Craft::$app->getProjectConfig();
        $path = ProjectConfig::PATH_PLUGINS . '.accessibility-audit';

        $edition = self::editionToSet(
            $projectConfig->get($path . '.edition'),
            $projectConfig->get($path . '.schemaVersion', true),
        );

        if ($edition === null) {
            return true;
        }

        $projectConfig->set($path . '.edition', $edition, 'Rename the Accessibility Audit Standard edition to Plus');

        $retainDays = self::retainDaysToSet($projectConfig->get($path . '.settings.retainDays'));

        if ($retainDays !== null) {
            $projectConfig->set($path . '.settings.retainDays', $retainDays, 'Raise the Accessibility Audit history to the Plus limit');
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always false: the old handle is gone from the plugin.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function safeDown(): bool
    {
        echo "m261127_000000_rename_standard_edition cannot be reverted.\n";

        return false;
    }
}
