<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use craft\base\ElementInterface;

/**
 * Works out what to call a scanned page.
 *
 * Three places need this and each got it slightly wrong on its own: reading
 * `title` straight off the element leaves a Single showing as "Element #1343",
 * and casting the element to a string gives "Entry 1343", neither of which
 * names anything a person would recognise. Craft's own `getUiLabel()` falls
 * back to the slug, and the homepage (which Craft marks with the `__home__`
 * URI, and which usually has no title at all) is named outright rather than
 * left as whatever its slug happens to be.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class ElementLabel
{
    // Public Methods
    // =========================================================================

    /**
     * A human-readable name for an element.
     *
     * @param ElementInterface|null $element The element, or null when it could
     *                                       not be loaded.
     * @param int|null $elementId The element ID, used for the last-resort label
     *                            when the element itself is unavailable.
     * @param string $fallback A final fallback, such as the page URL, preferred
     *                         over "Element #id" when it is given.
     * @return string
     */
    public static function for(?ElementInterface $element, ?int $elementId = null, string $fallback = ''): string
    {
        if ($element !== null) {
            // The homepage is the common case with no title at all, and
            // "__home__" is not a thing to show anybody.
            if ($element->uri === '__home__' && (string)($element->title ?? '') === '') {
                return Craft::t('accessibility-audit', 'Home page');
            }

            $label = trim((string) $element->getUiLabel());

            if ($label !== '') {
                return $label;
            }
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return Craft::t('accessibility-audit', 'Element #') . ($elementId ?? $element?->id ?? '');
    }
}
