<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\mcp;

use Craft;
use craft\base\ElementInterface;
use craft\models\Site;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use Mcp\Exception\ToolCallException;

/**
 * Works out which page an MCP tool call is about.
 *
 * A page is named by element ID or by URL, never both. A URL on the site
 * that belongs to an element is that element's page, so it reads the same
 * scans the element does. Anything else is a page with no element behind it,
 * addressed by its URL the way the scanner stores it.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class PageResolver
{
    // Public Methods
    // =========================================================================

    /**
     * The page a call names.
     *
     * A draft or revision ID resolves to the element it belongs to, which is
     * the one with a page of its own.
     *
     * @param int|null $elementId The element ID passed, if any.
     * @param string|null $url The URL passed, if any.
     * @param Site $site The site the call is scoped to.
     * @return array{element: ElementInterface|null, url: string|null} The
     *         element behind the page, or the absolute URL of a page with none.
     * @throws ToolCallException Where both or neither are passed, the element
     *         does not exist on the site, or the URL cannot be read.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function resolve(?int $elementId, ?string $url, Site $site): array
    {
        $url = $url !== null ? trim($url) : null;

        if (($elementId === null) === ($url === null || $url === '')) {
            throw new ToolCallException('Pass exactly one of elementId or url.');
        }

        if ($elementId !== null) {
            $element = Craft::$app->getElements()->getElementById($elementId, null, $site->id);

            if ($element === null) {
                throw new ToolCallException("No element with ID {$elementId} was found on the {$site->handle} site.");
            }

            return ['element' => $element->getCanonical(), 'url' => null];
        }

        $absolute = AccessibilityAudit::getInstance()->getAudit()->absoluteUrl((string)$url, (int)$site->id);

        if ($absolute === null) {
            throw new ToolCallException('That URL could not be read. Pass an absolute URL, or a path on the site such as /about.');
        }

        $element = self::_elementForUrl($absolute, $site);

        return $element !== null
            ? ['element' => $element, 'url' => null]
            : ['element' => null, 'url' => $absolute];
    }

    /**
     * The latest scan of a resolved page.
     *
     * @param array{element: ElementInterface|null, url: string|null} $target
     *        The page, from resolve().
     * @param Site $site The site the call is scoped to.
     * @return array<string, mixed>|null The scan row, or null where the page
     *         has never been scanned.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function latestScan(array $target, Site $site): ?array
    {
        $audit = AccessibilityAudit::getInstance()->getAudit();

        if ($target['element'] !== null) {
            return $audit->getLatestScan((int)$target['element']->id, (int)$site->id);
        }

        return $audit->getLatestUrlScan((string)$target['url'], (int)$site->id);
    }

    // Private Methods
    // =========================================================================

    /**
     * The live element whose URI a URL on the site carries.
     *
     * A URL with a query string is never an element's: Craft routes elements
     * by path alone, so the query makes it a page of its own.
     *
     * @param string $url The absolute URL.
     * @param Site $site The site to match against.
     * @return ElementInterface|null The element, or null where the URL is not
     *         under the site's base URL or no element owns the path.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _elementForUrl(string $url, Site $site): ?ElementInterface
    {
        $base = rtrim((string)$site->getBaseUrl(), '/');
        $url = (string)strtok($url, '#');

        if ($base === '' || str_contains($url, '?')) {
            return null;
        }

        if (strcasecmp(rtrim($url, '/'), $base) !== 0 && stripos($url, $base . '/') !== 0) {
            return null;
        }

        $uri = trim(rawurldecode(substr($url, strlen($base))), '/');

        // An empty URI is the homepage, which Craft looks up as __home__.
        return Craft::$app->getElements()->getElementByUri($uri, (int)$site->id, true);
    }
}
