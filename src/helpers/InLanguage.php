<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;

/**
 * Runs a piece of rendering in a given language, dates included.
 *
 * Switching `Craft::$app->language` alone changes what `|t` returns, but the
 * formatter Craft has already built keeps its locale, so dates and numbers
 * would stay in the reader's language. Both are switched and both are put back.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class InLanguage
{
    // Public Methods
    // =========================================================================

    /**
     * Runs the callback with the app language and formatter locale set.
     *
     * @template T
     * @param string $language The language to render in.
     * @param callable(): T $callback The rendering.
     * @return T What the callback returns.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function run(string $language, callable $callback): mixed
    {
        $formatter = Craft::$app->getFormatter();
        $originalLanguage = Craft::$app->language;
        $originalLocale = $formatter->locale;

        Craft::$app->language = $language;
        $formatter->locale = $language;

        try {
            return $callback();
        } finally {
            Craft::$app->language = $originalLanguage;
            $formatter->locale = $originalLocale;
        }
    }
}
