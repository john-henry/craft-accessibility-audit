<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use DOMElement;
use DOMXPath;

/**
 * Where a link sits, in the terms 2.4.4 is written in.
 *
 * Link Purpose (In Context) is satisfied when something around the link tells
 * you where it goes: the landmark it is in, the heading above it, the sentence
 * it sits in. So two links reading "Services" are only a failure when nothing
 * separates them. In different named landmarks they pass at AA, and are still
 * a problem in a screen reader's links list, which strips all of that away and
 * shows two identical entries.
 *
 * A rule that cannot see context cannot tell those apart, and reporting both at
 * the same weight is what teaches people to dismiss the queue unread.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
class LinkContext
{
    // Const Properties
    // =========================================================================

    /**
     * @var array<string, string> Landmark elements, and the role each carries
     *      implicitly. header and footer only count at page level, which is
     *      checked separately.
     */
    private const LANDMARK_TAGS = [
        'nav' => 'navigation',
        'main' => 'main',
        'aside' => 'complementary',
        'form' => 'form',
        'header' => 'banner',
        'footer' => 'contentinfo',
        'section' => 'region',
    ];

    /**
     * @var string[] Explicit roles that make any element a landmark.
     */
    private const LANDMARK_ROLES = [
        'navigation', 'main', 'complementary', 'banner', 'contentinfo',
        'form', 'search', 'region',
    ];

    /**
     * @var string[] Ancestors that demote header and footer out of page level.
     */
    private const SECTIONING_TAGS = ['article', 'aside', 'main', 'nav', 'section'];

    // Public Methods
    // =========================================================================

    /**
     * The landmark and heading a link sits under.
     *
     * @param DOMElement $link The link.
     * @param DOMXPath $xpath The document.
     * @return array{landmark: ?DOMElement, tag: string, name: string, heading: string, label: string}
     *         `label` is for display, e.g. "nav[Documentation]" or "nav[unnamed]".
     */
    public static function for(DOMElement $link, DOMXPath $xpath): array
    {
        $landmark = self::landmarkFor($link);
        $tag = $landmark !== null ? strtolower($landmark->nodeName) : '';
        $name = $landmark !== null ? self::nameOf($landmark, $xpath) : '';
        $heading = self::headingFor($link, $landmark, $xpath);

        return [
            'landmark' => $landmark,
            'tag' => $tag,
            'name' => $name,
            'heading' => $heading,
            'label' => self::label($tag, $name, $heading),
        ];
    }

    /**
     * Whether a link is hidden in a way a server-side scan can see.
     *
     * Responsive markup often ships a desktop rail and a mobile drawer, both in
     * the DOM, with CSS deciding which exists for a reader. Two links that can
     * never be reached at the same time are not a duplicate anyone experiences.
     *
     * Only the attribute-level cases are visible from here: `hidden`,
     * `aria-hidden`, and an inline display:none. Hiding by class, which is what
     * a utility framework does, needs the browser pass to see, so a pair hidden
     * that way is still reported. Worth knowing when reading a finding.
     *
     * @param DOMElement $link The link to test.
     * @return bool
     */
    public static function isHidden(DOMElement $link): bool
    {
        for ($node = $link; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->hasAttribute('hidden')) {
                return true;
            }

            if (strtolower($node->getAttribute('aria-hidden')) === 'true') {
                return true;
            }

            $style = strtolower(preg_replace('/\s+/', '', $node->getAttribute('style')) ?? '');

            if (str_contains($style, 'display:none')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The nearest ancestor landmark, or null when the link is in none.
     *
     * @param DOMElement $link The link.
     * @return DOMElement|null
     */
    public static function landmarkFor(DOMElement $link): ?DOMElement
    {
        for ($node = $link->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $tag = strtolower($node->nodeName);
            $role = strtolower(trim($node->getAttribute('role')));

            if ($role !== '') {
                if (in_array($role, self::LANDMARK_ROLES, true)) {
                    return $node;
                }

                // An explicit role that is not a landmark overrides the tag's
                // implicit one, so this element is not a landmark either.
                continue;
            }

            if (!isset(self::LANDMARK_TAGS[$tag])) {
                continue;
            }

            // A form or section is only a landmark once it has a name.
            if (in_array($tag, ['form', 'section'], true) && !self::hasNameAttribute($node)) {
                continue;
            }

            // header and footer are landmarks only at page level.
            if (in_array($tag, ['header', 'footer'], true) && self::insideSectioning($node)) {
                continue;
            }

            return $node;
        }

        return null;
    }

    /**
     * A landmark's accessible name, or an empty string when it has none.
     *
     * @param DOMElement $landmark The landmark element.
     * @param DOMXPath $xpath The document, for resolving aria-labelledby.
     * @return string
     */
    public static function nameOf(DOMElement $landmark, DOMXPath $xpath): string
    {
        $label = trim($landmark->getAttribute('aria-label'));

        if ($label !== '') {
            return $label;
        }

        $labelledBy = trim($landmark->getAttribute('aria-labelledby'));

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
                    $parts[] = AccessibleName::fromContent($ref);
                }
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

    // Private Methods
    // =========================================================================

    /**
     * The nearest heading before the link, kept inside the landmark so a
     * heading from an earlier section cannot be borrowed as context.
     *
     * @param DOMElement $link The link.
     * @param DOMElement|null $landmark The landmark it sits in, if any.
     * @param DOMXPath $xpath The document.
     * @return string The heading text, or an empty string.
     */
    private static function headingFor(DOMElement $link, ?DOMElement $landmark, DOMXPath $xpath): string
    {
        $headings = $xpath->query(
            'preceding::*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]',
            $link,
        );

        if ($headings === false || $headings->length === 0) {
            return '';
        }

        // Document order, so the last one is the nearest above the link.
        for ($i = $headings->length - 1; $i >= 0; $i--) {
            $heading = $headings->item($i);

            if (!$heading instanceof DOMElement) {
                continue;
            }

            if ($landmark !== null && !self::isWithin($heading, $landmark)) {
                // Everything earlier is further away and equally outside.
                return '';
            }

            return trim(preg_replace('/\s+/', ' ', $heading->textContent) ?? '');
        }

        return '';
    }

    /**
     * Whether a node is inside a given ancestor.
     */
    private static function isWithin(DOMElement $node, DOMElement $ancestor): bool
    {
        for ($cur = $node->parentNode; $cur instanceof DOMElement; $cur = $cur->parentNode) {
            if ($cur === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an element carries a naming attribute at all.
     */
    private static function hasNameAttribute(DOMElement $el): bool
    {
        return trim($el->getAttribute('aria-label')) !== ''
            || trim($el->getAttribute('aria-labelledby')) !== '';
    }

    /**
     * Whether a header or footer sits inside sectioning content, which takes
     * its landmark role away.
     */
    private static function insideSectioning(DOMElement $el): bool
    {
        for ($node = $el->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (in_array(strtolower($node->nodeName), self::SECTIONING_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A short label for a context, for printing beside a URL.
     *
     * An unnamed landmark is shown as such rather than hidden, because that is
     * usually the actual defect: naming it is what would separate the links.
     */
    private static function label(string $tag, string $name, string $heading): string
    {
        if ($tag === '') {
            return $heading !== '' ? 'under "' . $heading . '"' : 'no landmark';
        }

        if ($name !== '') {
            return $tag . '[' . $name . ']';
        }

        // main is normally unnamed by design, so its heading says more.
        if ($heading !== '') {
            return $tag . '[unnamed, under "' . $heading . '"]';
        }

        return $tag . '[unnamed]';
    }
}
