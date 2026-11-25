<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use Craft;
use craft\db\Migration;
use craft\i18n\Translation;

/**
 * Tells admins about the three editions, PDF checks and reports under What's New.
 *
 * The heading and body are prepared for lazy translation, so each admin reads
 * them in their own control panel language. A fresh install never runs this,
 * so only sites updating from an earlier version get the announcement.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class m261121_000000_announce_editions extends Migration
{
    // Const Properties
    // =========================================================================

    /**
     * @var string Where the announcement sends admins for the full list of changes.
     */
    private const RELEASE_NOTES_URL = 'https://github.com/john-henry/craft-accessibility-audit/blob/craft-5/CHANGELOG.md';

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
        Craft::$app->getAnnouncements()->push(
            heading: Translation::prep('accessibility-audit', 'Editions, PDF checks and reports'),
            body: Translation::prep(
                'accessibility-audit',
                'Accessibility Audit now comes in Lite, Standard and Pro, and this site keeps the edition it is on. Standard and Pro check the PDFs in your asset library, draft alt text for images missing it, and give you fix guides to hand to a developer. Pro adds a Reports screen, with a directors summary you can email every week or month. [Read the release notes]({url}).',
                ['url' => self::RELEASE_NOTES_URL],
            ),
            pluginHandle: 'accessibility-audit',
            adminsOnly: true,
        );

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always true. A pushed announcement is left in place.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function safeDown(): bool
    {
        return true;
    }
}
