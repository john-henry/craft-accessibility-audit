<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;

/**
 * Enumerates the element types the content scanner can cover.
 *
 * A "scannable" type is any registered element type that exposes public URIs
 * (see {@see ElementInterface::hasUris()}), minus Assets, which are binary
 * files handled by the alt-text audit rather than page scanning. The "native"
 * subset is everything under the `craft\` namespace: Craft core and first-party
 * plugins such as Commerce, as opposed to a third-party plugin's custom
 * element, which stays opt-in.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class ScannableElementTypes
{
    // Public Methods
    // =========================================================================

    /**
     * Every scannable element type as class name => display label, ordered by
     * label for display in the settings checklist.
     *
     * @return array<class-string, string>
     */
    public static function all(): array
    {
        $types = [];

        /** @var class-string<ElementInterface> $type */
        foreach (Craft::$app->getElements()->getAllElementTypes() as $type) {
            if ($type === Asset::class || !$type::hasUris()) {
                continue;
            }

            $types[$type] = $type::displayName();
        }

        asort($types);

        return $types;
    }

    /**
     * The class names of the native (Craft-core and first-party) scannable
     * types: the default scan set used when nothing has been configured.
     *
     * @return class-string[]
     */
    public static function native(): array
    {
        return array_values(array_filter(
            array_keys(self::all()),
            static fn(string $type): bool => str_starts_with($type, 'craft\\'),
        ));
    }
}
