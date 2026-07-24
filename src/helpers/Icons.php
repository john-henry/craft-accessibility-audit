<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

/**
 * The plugin's shared icon markup.
 *
 * Glyphs come from Craft's own CP icon font (rendered via the data-icon
 * attribute), not custom SVGs, so they track the CP's look. CP-only: the
 * icon font is not loaded on the front end.
 *
 * Single source for icon markup that must render identically from Twig
 * (via craft.a11y.icon()) and from client-side JS (injected as
 * window.AccessibilityAudit.icons), so the two render paths cannot drift.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
final class Icons
{
    // Const Properties
    // =========================================================================

    /**
     * @var array<string, string> Icon handle to icon markup. All icons
     * stroke with currentColor and are aria-hidden: they always accompany
     * text or an accessible name supplied by the caller.
     */
    public const ICONS = [
        'pass' => '<span data-icon="check" aria-hidden="true"></span>',
        'fail' => '<span data-icon="remove" aria-hidden="true"></span>',
        'check' => '<span data-icon="check" aria-hidden="true"></span>',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Returns the SVG markup for an icon handle, or an empty string for an
     * unknown handle.
     *
     * @param string $name The icon handle.
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function get(string $name): string
    {
        return self::ICONS[$name] ?? '';
    }
}
