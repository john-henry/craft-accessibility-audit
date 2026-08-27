<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use craft\base\ElementInterface;

/**
 * Answers what a scan points at, whether that is an element or a bare URL.
 *
 * A scan used to be an element and nothing else, so every listing worked the
 * same way: read `elementId`, load the element, ask it for its label and its
 * URL. Scans of pages Craft routes without an element behind them, a search
 * results page or a filtered listing, have no element to ask, and the four
 * places that build table rows would each have had to learn that separately.
 * They ask here instead, so a URL scan is named and linked the same way
 * wherever it turns up.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
class ScanTarget
{
    // Public Methods
    // =========================================================================

    /**
     * Whether a scan row belongs to a URL rather than an element.
     *
     * @param array<string, mixed> $scan A scan row.
     * @return bool
     */
    public static function isUrl(array $scan): bool
    {
        return empty($scan['elementId']) && !empty($scan['url']);
    }

    /**
     * What to call the scanned page.
     *
     * A URL scan carries the page title captured at scan time, which is worth
     * more than the URL itself: "Search results" reads better than
     * "/search?q=craft". The URL stands in when there was no title to capture.
     *
     * @param array<string, mixed> $scan A scan row.
     * @param ElementInterface|null $element The element, when the scan has one.
     * @return string
     */
    public static function label(array $scan, ?ElementInterface $element = null): string
    {
        if (self::isUrl($scan)) {
            $title = trim((string)($scan['title'] ?? ''));

            return $title !== '' ? $title : self::_path((string)$scan['url']);
        }

        return ElementLabel::for($element, (int)($scan['elementId'] ?? 0));
    }

    /**
     * The address of the scanned page, for a link out to the site.
     *
     * @param array<string, mixed> $scan A scan row.
     * @param ElementInterface|null $element The element, when the scan has one.
     * @return string|null
     */
    public static function url(array $scan, ?ElementInterface $element = null): ?string
    {
        if (self::isUrl($scan)) {
            return (string)$scan['url'];
        }

        return $element?->getUrl();
    }

    /**
     * Query parameters that address this scan's page report.
     *
     * An element scan is still addressed by element, so links made before URL
     * scans existed keep working and the report keeps showing the latest scan
     * rather than a frozen one. A URL scan has no element to address, so it
     * goes by scan ID.
     *
     * @param array<string, mixed> $scan A scan row.
     * @param string|null $siteHandle The site handle to carry through.
     * @return array<string, mixed>
     */
    public static function reportParams(array $scan, ?string $siteHandle): array
    {
        $params = self::isUrl($scan)
            ? ['scanId' => (int)$scan['id']]
            : ['elementId' => (int)$scan['elementId']];

        if ($siteHandle !== null) {
            $params['site'] = $siteHandle;
        }

        return $params;
    }

    // Private Methods
    // =========================================================================

    /**
     * The path and query of a URL, so a table cell shows "/search?q=craft"
     * rather than repeating the host on every row.
     *
     * @param string $url An absolute URL.
     * @return string
     */
    private static function _path(string $url): string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $query = (string)parse_url($url, PHP_URL_QUERY);

        if ($path === '') {
            return $url;
        }

        return $query !== '' ? $path . '?' . $query : $path;
    }
}
