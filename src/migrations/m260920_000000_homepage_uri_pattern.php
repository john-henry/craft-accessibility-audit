<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\ProjectConfig as ProjectConfigHelper;

/**
 * Rewrites a blank excluded-URI pattern as `^$`.
 *
 * A blank pattern used to mean the homepage. Nothing else about the row said
 * so, and a row added and left unfilled looked exactly like one somebody meant,
 * so the homepage could be dropped from every scan by a slip nobody could see
 * afterwards. The homepage is now written as an expression like any other
 * exclusion, and a blank pattern matches nothing.
 *
 * Rows that carried the old meaning are rewritten so they keep excluding the
 * homepage. A blank row that was switched off excluded nothing under either
 * reading and is dropped.
 *
 * An install whose patterns come from `config/accessibility-audit.php` is not
 * reached from here: that file is the source and has to be edited by hand.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class m260920_000000_homepage_uri_pattern extends Migration
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The expression that matches the homepage, whose URI is
     * normalised to an empty string before any pattern is tested against it.
     */
    public const HOMEPAGE_PATTERN = '^$';

    /**
     * @var string Where the patterns live in project config.
     */
    private const PATH = 'plugins.accessibility-audit.settings.excludedUriPatterns';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Always true; nothing here stops the upgrade.
     * @throws \yii\base\Exception if project config can't be updated.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $schemaVersion = $projectConfig->get('plugins.accessibility-audit.schemaVersion', true);

        // Another environment already ran this and its YAML carries the result.
        if ($schemaVersion !== null && version_compare($schemaVersion, '1.3.0', '>=')) {
            return true;
        }

        self::apply();

        return true;
    }

    /**
     * Rewrites the stored patterns in project config.
     *
     * The upgrade's work, apart from the check on whether another environment
     * already did it, so it can be run on its own.
     *
     * @return void
     * @throws \yii\base\Exception if project config can't be updated.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function apply(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $stored = $projectConfig->get(self::PATH);

        if (!is_array($stored) || $stored === []) {
            return;
        }

        // Plugin settings are stored packed; the rows only have their keys once
        // unpacked.
        $rows = ProjectConfigHelper::unpackAssociativeArrays($stored);
        $rewritten = self::rewrite($rows);

        if ($rewritten === $rows) {
            return;
        }

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            Craft::warning(
                'A11y: an excluded URI pattern is blank and admin changes are off, so it cannot be '
                . 'rewritten here. A blank pattern no longer matches the homepage; write it as '
                . self::HOMEPAGE_PATTERN . ' in your project config.',
                'accessibility-audit',
            );

            return;
        }

        // Only this one key is written, so neither the settings model's
        // validation nor values from config/accessibility-audit.php get a say.
        $projectConfig->set(
            self::PATH,
            ProjectConfigHelper::packAssociativeArrays($rewritten),
            'Write blank Accessibility Audit URI patterns as ^$',
        );
    }

    /**
     * Rewrites a stored pattern set, turning a blank pattern that was switched
     * on into the homepage expression and dropping one that was switched off.
     *
     * Split out from {@see self::safeUp()} so the rewrite is testable without
     * writing project config.
     *
     * @param array<int, mixed> $rows The stored rows.
     * @return array<int, array<string, mixed>> The rewritten rows.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function rewrite(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (trim((string)($row['uriPattern'] ?? '')) !== '') {
                $out[] = $row;
                continue;
            }

            if (!($row['enabled'] ?? true)) {
                continue;
            }

            $row['uriPattern'] = self::HOMEPAGE_PATTERN;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always false; the rewrite isn't reverted.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function safeDown(): bool
    {
        echo "m260920_000000_homepage_uri_pattern cannot be reverted.\n";

        return false;
    }
}
