<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use craft\elements\Asset;
use craft\errors\ImageTransformException;
use craft\fs\Local;
use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use johnhenry\ipguard\IpRange;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Gets an image in front of the vision API, in the cheapest form it will take.
 *
 * Four tiers, in order: an oversized image is scaled down first, a public HTTPS
 * address is handed over as-is so the API fetches it itself, the file is read
 * off a local filesystem or streamed from a remote one, and a fetch is the last
 * resort. Every tier but the first falls through on failure rather than
 * throwing, so one unreadable image does not take the request with it.
 *
 * Both the on-demand draft and the queued one come through here. They used to
 * carry a copy each, which is how they drifted: one of them logged what it was
 * reading and the other did not, so a support question about an image that
 * would not describe had a different answer depending on which had asked.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class ImageSource
{
    // Const Properties
    // =========================================================================

    /**
     * @var int How long the last-resort fetch waits, in seconds.
     */
    private const FETCH_TIMEOUT = 15;

    /**
     * @var int The most the last-resort fetch will read, in bytes. The body is
     *      held whole and then base64-encoded, which adds a third again, so an
     *      unbounded read is an unbounded allocation. Matches
     *      {@see UrlSafety::MAX_BODY_BYTES}, and sits far above anything the
     *      vision API will accept anyway.
     */
    private const MAX_FETCH_BYTES = 10485760;

    // Public Methods
    // =========================================================================

    /**
     * The image content block for an asset, or null where there is nothing to
     * send.
     *
     * @param Asset $asset The asset to describe.
     * @param string $context What is asking, named in any log line this writes.
     * @return array<string, mixed>|null The content block source, or null when
     *         the asset is an unrenderable vector or could not be read at all.
     * @throws ImageTransformException
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function for(Asset $asset, string $context): ?array
    {
        // Oversized images are scaled down before the URL tier, which would
        // otherwise hand over the full-size original.
        $downscaled = VisionImage::downscaledSource($asset);

        if ($downscaled !== null) {
            return $downscaled;
        }

        // A vector that could not be rendered is never sent as-is: the API
        // takes raster formats only and would refuse it.
        if (VisionImage::isVector($asset)) {
            return null;
        }

        $url = $asset->getUrl();
        $mime = $asset->getMimeType() ?: 'image/jpeg';

        // Tier 1: public HTTPS address, fetched by the API itself.
        if ($url && !self::isLocalUrl($url)) {
            return ['type' => 'url', 'url' => $url];
        }

        // Tier 2a: local filesystem, resolve the alias path and read directly.
        $local = self::_readLocalFile($asset, $mime, $context);

        if ($local !== null) {
            return $local;
        }

        // Tier 2b: cloud or other filesystem, through the asset's own stream.
        try {
            $data = base64_encode($asset->getContents());

            if ($data) {
                return ['type' => 'base64', 'media_type' => $mime, 'data' => $data];
            }
        } catch (Throwable $e) {
            Craft::warning("A11y: {$context} stream read failed: " . $e->getMessage(), 'accessibility-audit');
        }

        // Tier 3: fetch it ourselves.
        return $url ? self::_fetch($url, $mime, $context) : null;
    }

    /**
     * Whether an address points at this install rather than somewhere the
     * vision API could reach, in which case the image is read locally and sent
     * inline.
     *
     * An address that cannot be parsed counts as local, so one whose host is
     * unreadable is never handed to the third-party API.
     *
     * @param string $url The image address.
     * @return bool True when the address is local or unreadable.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function isLocalUrl(string $url): bool
    {
        // The API only accepts public HTTPS addresses.
        if (!str_starts_with($url, 'https://')) {
            return true;
        }

        // parse_url answers false, not null, on an address it cannot read.
        $parsed = parse_url($url, PHP_URL_HOST);

        if ($parsed === false || $parsed === null) {
            return true;
        }

        // parse_url keeps the brackets on an IPv6 host ("[::1]"), so trim them
        // before matching the loopback address.
        $host = trim($parsed, '[]');

        // An address on a private network, or a single-label intranet name,
        // is nothing the API could reach either.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false && IpRange::isPrivate($host)) {
            return true;
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || !str_contains($host, '.')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.ddev.site')
            || str_ends_with($host, '.lndo.site');
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads the file straight off disk, where the asset lives on one.
     *
     * @param Asset $asset The asset to read.
     * @param string $mime The asset's media type.
     * @param string $context What is asking, named in any log line this writes.
     * @return array<string, mixed>|null The content block, or null where the
     *         asset is not on a local filesystem or the file is not there.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private static function _readLocalFile(Asset $asset, string $mime, string $context): ?array
    {
        $fs = $asset->getVolume()->getFs();

        if (!$fs instanceof Local) {
            return null;
        }

        try {
            $root = rtrim(Craft::getAlias($fs->path), '/\\');
            $filePath = $root . DIRECTORY_SEPARATOR . ltrim($asset->getPath(), '/\\');

            Craft::info("A11y: {$context} reading local file {$filePath}", 'accessibility-audit');

            if (file_exists($filePath)) {
                $contents = file_get_contents($filePath);

                if ($contents !== false) {
                    return ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($contents)];
                }

                Craft::warning("A11y: {$context} local file unreadable at {$filePath}", 'accessibility-audit');

                return null;
            }

            Craft::warning("A11y: {$context} local file not found at {$filePath}", 'accessibility-audit');
        } catch (Throwable $e) {
            Craft::warning("A11y: {$context} local file read failed: " . $e->getMessage(), 'accessibility-audit');
        }

        return null;
    }

    /**
     * Fetches the image over HTTP, the last resort when nothing else could read
     * it.
     *
     * Held to the same guard as every other fetch: only this install's own
     * origin or a public address, pinned to the address that was checked, and
     * no redirects. Certificate verification is only relaxed in devMode, where
     * DDEV, Lando and Valet serve their own certificates.
     *
     * @param string $url The image address.
     * @param string $mime The asset's media type.
     * @param string $context What is asking, named in any log line this writes.
     * @return array<string, mixed>|null The content block, or null where the
     *         fetch failed.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private static function _fetch(string $url, string $mime, string $context): ?array
    {
        $verifyTls = !Craft::$app->getConfig()->getGeneral()->devMode;
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        $explicitPort = isset($parts['port']) ? (int)$parts['port'] : null;

        try {
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new UnsafeUrlException('Only http and https URLs may be fetched.');
            }

            $addresses = UrlSafety::publicAddressesFor($host, $scheme, $explicitPort);
        } catch (UnsafeUrlException $e) {
            Craft::warning("A11y: {$context} image was not fetched: " . $e->getMessage(), 'accessibility-audit');

            return null;
        }

        $port = $explicitPort ?? ($scheme === 'http' ? 80 : 443);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => $addresses !== []
                ? [sprintf('%s:%d:%s', trim($host, '[]'), $port, implode(',', $addresses))]
                : [],
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,

            // Aborts mid-transfer rather than after the fact, so a response
            // with no Content-Length, or one lying about it, is still bounded.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn(
                mixed $handle,
                int $expected,
                int $received,
            ): int => $expected > self::MAX_FETCH_BYTES || $received > self::MAX_FETCH_BYTES ? 1 : 0,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body && $httpCode === 200) {
            return ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($body)];
        }

        Craft::warning(
            "A11y: {$context} fetch failed (HTTP {$httpCode}): {$curlErr}",
            'accessibility-audit',
        );

        return null;
    }
}
