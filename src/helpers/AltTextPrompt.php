<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use craft\elements\Asset;
use johnhenry\accessibilityaudit\models\SettingsModel;

/**
 * Builds the prompt behind AI alt text.
 *
 * Both generation paths (the Generate button and the queued job) come through
 * here, so the two cannot describe the same image differently.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.1.1
 */
class AltTextPrompt
{
    // Const Properties
    // =========================================================================

    /**
     * @var int The character ceiling given to the model. Alt text past roughly
     *          this length stops being a summary and starts being the content.
     */
    public const MAX_LENGTH = 125;

    // Public Methods
    // =========================================================================

    /**
     * The full prompt for one asset.
     *
     * Built outermost-first: the site's own context, then what is known about
     * this asset, then the instruction itself. Everything before the
     * instruction is framed as background so the model describes the picture
     * rather than reciting the notes.
     *
     * @param Asset $asset The image to describe.
     * @param SettingsModel $settings The plugin settings.
     * @return string The prompt to send with the image.
     */
    public static function build(Asset $asset, SettingsModel $settings): string
    {
        $parts = [];

        $siteContext = trim((string)($settings->altTextContext ?? ''));
        if ($siteContext !== '') {
            $parts[] = 'Site context: ' . $siteContext;
        }

        $parts[] = "Context for the image (do not repeat it verbatim):\n" . self::assetContext($asset);
        $parts[] = self::instruction($settings);

        return implode("\n\n", $parts);
    }

    /**
     * What is known about the asset besides its pixels.
     *
     * The filename and title often carry the subject or the intent that the
     * image alone does not make plain, and a filename is frequently the only
     * clue that something is a screenshot rather than a photograph.
     *
     * @param Asset $asset The image to describe.
     * @return string The context block.
     */
    public static function assetContext(Asset $asset): string
    {
        $context = 'Filename: ' . $asset->filename;

        $title = trim((string)$asset->title);
        if ($title !== '') {
            $context .= "\nTitle: " . $title;
        }

        return $context;
    }

    /**
     * The instruction itself.
     *
     * The screenshot rule earns its place: a screenshot is a picture of an
     * interface, and interfaces are usually full of other pictures. Left to
     * itself the model describes whichever layer fills the frame, so a
     * screenshot demonstrating a button gets alt text about the stock photo
     * sitting behind it. That reads fluently and tells the reader nothing
     * about what they are being shown.
     *
     * @param SettingsModel $settings The plugin settings.
     * @return string The instruction block.
     */
    public static function instruction(SettingsModel $settings): string
    {
        $language = trim((string)($settings->altTextLanguage ?? 'English')) ?: 'English';

        return 'Write concise, descriptive alt text for this image. '
            . 'If it is a screenshot of a user interface, describe the interface and what it is showing: '
            . 'name the screen, the controls and the state on display, and ignore any photographs, artwork '
            . 'or sample content that merely happens to appear inside it. '
            . 'Return only the alt text: no quotes, no explanation, no trailing period. '
            . 'Maximum ' . self::MAX_LENGTH . ' characters. '
            . 'Respond in ' . $language . '.';
    }
}
