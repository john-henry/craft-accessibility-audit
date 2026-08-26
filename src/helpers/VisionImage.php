<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use Throwable;

/**
 * Prepares image assets for the Anthropic vision API.
 *
 * The API rejects images over 8000px on either edge with a validation error,
 * and downscales anything over the model's long-edge limit before the model
 * sees it. Both are handled by scaling oversized assets down locally first.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.1.1
 */
class VisionImage
{
    // Const Properties
    // =========================================================================

    /**
     * @var int The longest edge, in pixels, an image is scaled down to
     *          before it is sent. Matches the standard vision tier's own
     *          long-edge limit.
     */
    public const MAX_EDGE = 1568;

    // Public Methods
    // =========================================================================

    /**
     * A base64 image source holding a downscaled copy of the asset, or null
     * when the asset already fits and should be sent as-is. Failures log and
     * return null, leaving the caller to its normal source resolution.
     *
     * @param Asset $asset The image asset to prepare.
     * @return array{type:string,media_type:string,data:string}|null A base64
     *         source ready to send, or null to leave the asset alone.
     */
    public static function downscaledSource(Asset $asset): ?array
    {
        if (!self::needsDownscale($asset)) {
            return null;
        }

        $localCopy = null;
        $scaledPath = null;

        try {
            $images = Craft::$app->getImages();

            // A local temp copy, so cloud and local volumes behave alike.
            $localCopy = $asset->getCopyOfFile();

            // Decoding a print-resolution source can exceed the memory limit.
            if (!$images->checkMemoryForImage($localCopy)) {
                Craft::warning(
                    "A11y: not enough memory to downscale asset {$asset->id} for alt text.",
                    'accessibility-audit'
                );

                return null;
            }

            $scaledPath = Craft::$app->getPath()->getTempPath()
                . DIRECTORY_SEPARATOR . 'a11y-vision-' . $asset->id . '-' . self::MAX_EDGE . '.jpg';

            $image = $images->loadImage($localCopy);
            $image->scaleToFit(self::MAX_EDGE, self::MAX_EDGE);
            $image->saveAs($scaledPath);

            $data = file_get_contents($scaledPath);

            if ($data === false) {
                return null;
            }

            Craft::info(
                "A11y: downscaled asset {$asset->id} to fit " . self::MAX_EDGE . 'px for alt text.',
                'accessibility-audit'
            );

            return [
                'type' => 'base64',
                'media_type' => 'image/jpeg',
                'data' => base64_encode($data),
            ];
        } catch (Throwable $e) {
            Craft::warning(
                "A11y: downscale failed for asset {$asset->id}: " . $e->getMessage(),
                'accessibility-audit'
            );

            return null;
        } finally {
            foreach ([$localCopy, $scaledPath] as $path) {
                if ($path !== null && is_file($path)) {
                    FileHelper::unlink($path);
                }
            }
        }
    }

    /**
     * Whether the asset exceeds the long-edge limit on either side. An asset
     * with no recorded dimensions (an SVG, or one never indexed) is left
     * alone, there being nothing to measure.
     *
     * @param Asset $asset The image asset to measure.
     * @return bool True when the asset exceeds the long-edge limit.
     */
    public static function needsDownscale(Asset $asset): bool
    {
        $width = (int)$asset->getWidth();
        $height = (int)$asset->getHeight();

        if ($width === 0 || $height === 0) {
            return false;
        }

        return $width > self::MAX_EDGE || $height > self::MAX_EDGE;
    }
}
