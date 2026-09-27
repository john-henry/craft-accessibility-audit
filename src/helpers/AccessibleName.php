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
 * @author John Henry Donovan <info@johnhenry.ie>
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function for(DOMElement $el, DOMXPath $xpath): string
    {
        $name = self::fromLabelledBy($el, $xpath);

        if ($name !== '') {
            return $name;
        }

        $ariaLabel = trim($el->getAttribute('aria-label'));

        if ($ariaLabel !== '') {
            return $ariaLabel;
        }

        $content = self::fromContent($el);

        return $content !== '' ? $content : trim($el->getAttribute('title'));
    }

    /**
     * The name built from the elements an `aria-labelledby` points at, in the
     * order it lists them.
     *
     * Shared rather than written out at each call site, because the ids come
     * from the page and are interpolated into an XPath literal: one copy of
     * that escaping is one place to get it right.
     *
     * A referenced element contributes even when it carries `aria-hidden`.
     * Pointing at something by id is a deliberate act, and screen readers
     * announce it, so treating it as hidden reports a named control as
     * unnamed. Hidden elements *within* that subtree are still skipped, which
     * is what the accessible name computation asks for.
     *
     * @param DOMElement $el The element carrying the attribute.
     * @param DOMXPath $xpath The document, for resolving the references.
     * @return string The name, empty when there is no attribute or nothing it
     *         points at has any content.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function fromLabelledBy(DOMElement $el, DOMXPath $xpath): string
    {
        $labelledBy = trim($el->getAttribute('aria-labelledby'));

        if ($labelledBy === '') {
            return '';
        }

        $parts = [];

        foreach (preg_split('/\s+/', $labelledBy) ?: [] as $id) {
            // A double quote would break out of the XPath literal below.
            if ($id === '' || str_contains($id, '"')) {
                continue;
            }

            foreach ($xpath->query('//*[@id="' . $id . '"]') as $ref) {
                if ($ref instanceof DOMElement) {
                    $parts[] = self::fromContent($ref, true);
                }
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * The name an element contributes from its own subtree: text, image alt
     * text and SVG titles. Subtrees hidden from assistive tech are skipped.
     *
     * @param DOMElement $el The element whose subtree should be read.
     * @param bool $referenced Whether an `aria-labelledby` points straight at
     *                         this element, which makes it count even when it
     *                         is hidden. Never passed on to its children.
     * @return string The collapsed subtree name, empty if there is nothing.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function fromContent(DOMElement $el, bool $referenced = false): string
    {
        if (!$referenced && strtolower($el->getAttribute('aria-hidden')) === 'true') {
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
