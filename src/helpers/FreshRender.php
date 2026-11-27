<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use craft\web\Request as WebRequest;

/**
 * Asks for a page as Craft renders it now, past any copy a full-page cache
 * is holding.
 *
 * Used by the scan that follows an entry save. That scan is queued alongside
 * the cache's own refresh, and when it runs first a cached copy still shows
 * the issues the save fixed. Every other scan audits what a visitor gets,
 * cached or not.
 *
 * The query parameter does the work: Blitz leaves URLs with a query string
 * alone by default, and most CDNs key their cache on it. The request headers
 * reach proxies that honour them, such as Varnish. A site that strips unknown
 * parameters, or a CDN set to ignore the query string, still serves its copy.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class FreshRender
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The query parameter that marks a request for a fresh render.
     */
    public const PARAM = 'a11y-fresh';

    /**
     * @var array<string, string> Request headers asking any cache on the way
     *      to revalidate with the origin.
     */
    public const HEADERS = [
        'Cache-Control' => 'no-cache',
        'Pragma' => 'no-cache',
    ];

    // Public Methods
    // =========================================================================

    /**
     * The address with the fresh-render parameter added.
     *
     * The value is new on every call, so no cache can have stored a response
     * under it. An existing query string and fragment are kept, and an older
     * value of the parameter is replaced rather than repeated.
     *
     * @param string $url The page's address.
     * @return string The address to request.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function url(string $url): string
    {
        [$base, $pairs, $fragment] = self::_split($url);

        $pairs[] = self::PARAM . '=' . bin2hex(random_bytes(6));

        return $base . '?' . implode('&', $pairs) . $fragment;
    }

    /**
     * The address with the fresh-render parameter taken out, for storing and
     * comparing as the page's own.
     *
     * @param string $url An address that may carry the parameter.
     * @return string The address without it.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function strip(string $url): string
    {
        [$base, $pairs, $fragment] = self::_split($url);

        return $base . ($pairs === [] ? '' : '?' . implode('&', $pairs)) . $fragment;
    }

    /**
     * Whether the current request asked for a fresh render.
     *
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function isRequested(): bool
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest) {
            return false;
        }

        return $request->getQueryParam(self::PARAM) !== null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Splits an address into the part before its query, the query's pairs
     * without the fresh-render parameter, and its fragment.
     *
     * @param string $url The address.
     * @return array{0: string, 1: string[], 2: string}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _split(string $url): array
    {
        $fragment = '';
        $hash = strpos($url, '#');

        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $query = '';
        $mark = strpos($url, '?');

        if ($mark !== false) {
            $query = substr($url, $mark + 1);
            $url = substr($url, 0, $mark);
        }

        $pairs = array_values(array_filter(
            explode('&', $query),
            static fn(string $pair): bool => $pair !== '' && explode('=', $pair, 2)[0] !== self::PARAM,
        ));

        return [$url, $pairs, $fragment];
    }
}
