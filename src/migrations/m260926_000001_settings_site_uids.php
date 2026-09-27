<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use yii\base\Exception;

/**
 * Switches the site on each excluded URI pattern and additional URL from a
 * site ID to a site UID, so a row means the same site in every environment.
 *
 * The settings live in project config, so this runs once, on the environment
 * that first runs it; other environments pick it up from the YAML.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class m260926_000001_settings_site_uids extends Migration
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[] The settings whose rows carry a site.
     */
    private const KEYS = ['excludedUriPatterns', 'customUrls'];

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
     * Converts the stored rows in project config.
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

        foreach (self::KEYS as $key) {
            $path = "plugins.accessibility-audit.settings.$key";
            $stored = $projectConfig->get($path);

            if (!is_array($stored) || $stored === []) {
                continue;
            }

            $rows = ProjectConfigHelper::unpackAssociativeArrays($stored);
            $converted = self::convert($rows);

            if ($converted !== $rows) {
                $projectConfig->set(
                    $path,
                    ProjectConfigHelper::packAssociativeArrays($converted),
                    'Store Accessibility Audit row sites by UID',
                );
            }
        }
    }

    /**
     * Rewrites rows carrying a numeric `siteId` to carry that site's UID.
     *
     * An empty or unknown site ID becomes an empty UID, which is every site.
     *
     * @param array<int, mixed> $rows The stored rows.
     * @return array<int, mixed> The converted rows.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function convert(array $rows): array
    {
        $sites = Craft::$app->getSites();

        foreach ($rows as $i => $row) {
            if (!is_array($row) || !array_key_exists('siteId', $row)) {
                continue;
            }

            $siteId = (int)$row['siteId'];
            $row['siteUid'] = $row['siteUid'] ?? ($siteId ? ($sites->getSiteById($siteId, true)->uid ?? '') : '');
            unset($row['siteId']);
            $rows[$i] = $row;
        }

        return $rows;
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
        echo "m260926_000001_settings_site_uids cannot be reverted.\n";

        return false;
    }
}
