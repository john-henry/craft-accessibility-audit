<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use DOMElement;
use DOMXPath;

/**
 * Works out what a screen reader announces for an element.
 *
 * Every rule that judges a link or a control has to judge the name the user
 * actually hears, and that is rarely the text you can see. `aria-labelledby`
 * wins, then `aria-label`, then the element's own subtree, then `title`; alt
 * text and SVG titles count towards the subtree, and an `aria-hidden` branch
 * counts for nothing.
 *
 * It is shared rather than kept on one scanner because every rule that judges
 * a link needs it and each would otherwise reach for `textContent`. Judged on
 * visible text, two links reading "Visit Website" with distinct `aria-label`s
 * look like the same link twice, and a link warning about a new tab in a
 * visually hidden span looks like it gives no warning. Both readings flag
 * markup that is already correct, which is worse than silence: it teaches the
 * reader to dismiss the question without reading it.
 *
 * This is the computation from the accessible name spec reduced to what can be
 * had from static HTML. It does not resolve CSS-generated content or anything
 * that depends on layout.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
class AccessibleName
{
    // Public Methods
    // =========================================================================

    /**
     * The announced name for an element.
     *
     * @param DOMElement $el The element to name.
     * @param DOMXPath $xpath The document, for resolving aria-labelledby.
     * @return string The name, empty when the element has none.
     */
    public static function for(DOMElement $el, DOMXPath $xpath): string
    {
        $labelledBy = trim($el->getAttribute('aria-labelledby'));

        if ($labelledBy !== '') {
            $parts = [];

            foreach (preg_split('/\s+/', $labelledBy) ?: [] as $id) {
                // A double quote would break out of the XPath literal below.
                if ($id === '' || str_contains($id, '"')) {
                    continue;
                }

                foreach ($xpath->query('//*[@id="' . $id . '"]') as $ref) {
                    if ($ref instanceof DOMElement) {
                        $parts[] = self::fromContent($ref);
                    }
                }
            }

            $name = trim(implode(' ', array_filter($parts)));

            if ($name !== '') {
                return $name;
            }
        }

        $ariaLabel = trim($el->getAttribute('aria-label'));

        if ($ariaLabel !== '') {
            return $ariaLabel;
        }

        $content = self::fromContent($el);

        return $content !== '' ? $content : trim($el->getAttribute('title'));
    }

    /**
     * The name an element contributes from its own subtree: text, image alt
     * text and SVG titles. Subtrees hidden from assistive tech are skipped.
     *
     * @param DOMElement $el The element whose subtree should be read.
     * @return string The collapsed subtree name, empty if there is nothing.
     */
    public static function fromContent(DOMElement $el): string
    {
        if (strtolower($el->getAttribute('aria-hidden')) === 'true') {
            return '';
        }

        $parts = [];

        foreach ($el->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                $parts[] = $child->textContent;
                continue;
            }

            $tag = strtolower($child->nodeName);

            if ($tag === 'img' || $tag === 'area') {
                $parts[] = $child->getAttribute('alt');
                continue;
            }

            if ($tag === 'svg') {
                foreach ($child->getElementsByTagName('title') as $svgTitle) {
                    $parts[] = $svgTitle->textContent;
                }
                continue;
            }

            $parts[] = self::fromContent($child);
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }
}
