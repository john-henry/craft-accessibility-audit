<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use Imagick;
use ImagickPixel;
use Throwable;

/**
 * Prepares image assets for the Anthropic vision API.
 *
 * The API takes JPEG, PNG, GIF and WebP only, rejects images over 8000px on
 * either edge, and downscales anything over the model's long-edge limit before
 * the model sees it. So an SVG is rasterised and an oversized raster is scaled
 * down before either is sent.
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

    /**
     * @var int The square the SVG is rasterised into. Icons and logos carry
     *          little detail, so the long-edge limit is more than enough.
     */
    public const SVG_RASTER_SIZE = 1024;

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
        if (self::isVector($asset)) {
            return self::rasterisedSource($asset);
        }

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
     * Whether the asset is a vector the API will not accept.
     *
     * @param Asset $asset The asset to check.
     * @return bool True for SVG.
     */
    public static function isVector(Asset $asset): bool
    {
        return strtolower((string)$asset->getExtension()) === 'svg';
    }

    /**
     * Whether this server can render a vector faithfully enough to describe.
     *
     * Answering "is SVG supported?" is not enough. ImageMagick ships its own
     * SVG renderer that draws fills and silently drops stroked paths, so an
     * icon made of strokes comes out as an empty box, and the model then
     * describes with total confidence something that was never drawn. Wrong
     * alt text is worse than none, so the capability is tested rather than
     * assumed: a stroke-only probe is rendered and the result checked for ink.
     *
     * @return bool True when strokes survive the render.
     */
    public static function canRenderVectors(): bool
    {
        static $capable = null;

        if ($capable !== null) {
            return $capable;
        }

        if (!extension_loaded('imagick') || empty(Imagick::queryFormats('SVG'))) {
            return $capable = false;
        }

        $probe = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" width="40" height="40">'
            . '<path d="M6 20 L16 30 L34 9" stroke="#000000" stroke-width="6" fill="none"/></svg>';

        try {
            $imagick = new Imagick();
            $imagick->setBackgroundColor(new ImagickPixel('white'));
            $imagick->readImageBlob($probe);
            $imagick->setImageFormat('png');

            // Any ink at all means strokes made it through. A renderer that
            // drops them leaves the probe uniformly white.
            $capable = $imagick->getImageColors() > 1;
            $imagick->clear();
        } catch (Throwable $e) {
            $capable = false;
        }

        return $capable;
    }

    /**
     * A base64 PNG of a vector asset, or null when it cannot be rendered.
     *
     * The API takes raster formats only, so without this an SVG is sent as-is
     * and refused, and the reader is told the request was rejected rather than
     * that the format was never going to work.
     *
     * @param Asset $asset The vector asset to render.
     * @return array{type:string,media_type:string,data:string}|null A base64
     *         PNG source, or null when Imagick cannot render vectors here.
     */
    public static function rasterisedSource(Asset $asset): ?array
    {
        if (!self::canRenderVectors()) {
            Craft::warning(
                "A11y: cannot render asset {$asset->id} for alt text, no usable SVG renderer.",
                'accessibility-audit',
            );

            return null;
        }

        try {
            $svg = $asset->getContents();

            if ($svg === '') {
                return null;
            }

            // Measured first, so the render resolution can be set to fill the
            // target square. A vector has no fixed size, and an icon declared
            // at 24px rendered at 24px gives the model almost nothing to read.
            $probe = new Imagick();
            $probe->readImageBlob($svg);
            $longest = max($probe->getImageWidth(), $probe->getImageHeight());
            $probe->clear();

            $imagick = new Imagick();

            if ($longest > 0) {
                $scale = self::SVG_RASTER_SIZE / $longest;
                $imagick->setResolution((int)round(96 * $scale), (int)round(96 * $scale));
            }

            // Artwork drawn for a dark background is invisible on a white one
            // and the other way about, so the background is set before the
            // render and flattened onto afterwards, never removed from under it.
            $imagick->setBackgroundColor(new ImagickPixel('white'));
            $imagick->readImageBlob($svg);
            $imagick->setImageFormat('png');

            $flat = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $flat->setImageBackgroundColor(new ImagickPixel('white'));
            $flat->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);

            if ($flat->getImageWidth() > self::SVG_RASTER_SIZE
                || $flat->getImageHeight() > self::SVG_RASTER_SIZE) {
                $flat->thumbnailImage(self::SVG_RASTER_SIZE, self::SVG_RASTER_SIZE, true);
            }

            $png = $flat->getImageBlob();
            $flat->clear();
            $imagick->clear();

            Craft::info(
                "A11y: rendered vector asset {$asset->id} to PNG for alt text.",
                'accessibility-audit',
            );

            return [
                'type' => 'base64',
                'media_type' => 'image/png',
                'data' => base64_encode($png),
            ];
        } catch (Throwable $e) {
            Craft::warning(
                "A11y: could not render vector asset {$asset->id}: " . $e->getMessage(),
                'accessibility-audit',
            );

            return null;
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
