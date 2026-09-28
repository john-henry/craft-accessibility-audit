<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use DOMXPath;
use johnhenry\accessibilityaudit\AccessibilityAudit;

/**
 * Removes the excluded elements (consent banners and other configured page
 * furniture) from a parsed scan DOM.
 *
 * Shared by ContentScanner and PotentialScanner so both PHP surfaces skip
 * exactly what the browser engines' axe exclude context skips — one scanner
 * honouring the exclusions while its sibling reports inside a consent banner
 * would be worse than neither doing so.
 *
 * Only the simple selector forms are translated to XPath here — `#id`,
 * `.class`, `tag`, `tag.class`, and `tag#id` — which covers every
 * consent-platform default. Anything fancier is skipped on the PHP surfaces
 * but still applies in the browser engines, which take full CSS.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
final class ExcludedElements
{
    // Constants
    // =========================================================================

    /**
     * @var string[] Elements that hold the page rather than sit in it. Removing
     * one takes the whole document with it and leaves the scan reporting a
     * blank page as a clean one, so a selector is never allowed to match them.
     * A tag on its own is refused when the selector is translated, but a class
     * or id selector reaches them too: `.no-js` and `.dark` live on `<html>` on
     * a great many sites.
     */
    private const _STRUCTURAL_TAGS = ['html', 'body', 'head'];

    // Public Methods
    // =========================================================================

    /**
     * Removes every element matching the resolved excluded selectors from the
     * document behind the given XPath handle.
     *
     * @param DOMXPath $xpath The scan document's XPath handle.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function removeFrom(DOMXPath $xpath): void
    {
        $settings = AccessibilityAudit::getInstance()->getSettings();

        foreach ($settings->resolvedExcludedSelectors() as $selector) {
            $expr = self::_selectorToXpath($selector);
            if ($expr === null) {
                continue;
            }

            foreach ($xpath->query($expr) ?: [] as $node) {
                if (in_array(strtolower($node->nodeName), self::_STRUCTURAL_TAGS, true)) {
                    continue;
                }

                $node->parentNode?->removeChild($node);
            }
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Translates a simple CSS selector to an XPath expression, or null when
     * the selector uses syntax this translator doesn't cover.
     *
     * Bare structural tags (`html`, `body`, `head`) are refused outright: a
     * stray line in the settings would otherwise delete the whole document
     * and silently blank the scan.
     *
     * @param string $selector A `#id`, `.class`, `tag`, `tag.class`, or `tag#id` selector.
     * @return string|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private static function _selectorToXpath(string $selector): ?string
    {
        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9-]*)?([#.])([a-zA-Z0-9_-]+)$|^([a-zA-Z][a-zA-Z0-9-]*)$/', $selector, $m)) {
            return null;
        }

        // Bare tag selector
        if (!empty($m[4])) {
            $tag = strtolower($m[4]);
            if (in_array($tag, self::_STRUCTURAL_TAGS, true)) {
                return null;
            }
            return '//' . $tag;
        }

        $tag = $m[1] !== '' ? strtolower($m[1]) : '*';

        if ($m[2] === '#') {
            return sprintf('//%s[@id="%s"]', $tag, $m[3]);
        }

        return sprintf(
            '//%s[contains(concat(" ", normalize-space(@class), " "), " %s ")]',
            $tag,
            $m[3],
        );
    }
}
