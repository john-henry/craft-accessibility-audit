<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;

/**
 * Static registry of accessibility statement profiles.
 *
 * A statement says the same things everywhere: what standard you are claiming,
 * how close you are, what still falls short, how somebody complains. What
 * changes by jurisdiction is which of those sections are legally compulsory and
 * what the law is called. A profile carries only that difference, so the
 * statement itself stays one document rather than three.
 *
 * Deliberately absent: any list of national enforcement bodies. There are
 * dozens, they get renamed and merged, and shipping them would mean every
 * rename is a plugin release. The profile marks the field required and the
 * editor supplies the body; that stays correct without maintenance.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class StatementProfiles
{
    // Const Properties
    // =========================================================================

    /**
     * @var string No statutory statement requirement: the W3C WAI structure,
     * which suits the US, Australia and most of the world.
     */
    public const PROFILE_GENERIC = 'generic';

    /**
     * @var string EU Web Accessibility Directive (2016/2102), whose model
     * statement is prescribed by Commission Implementing Decision (EU) 2018/1523.
     */
    public const PROFILE_EU = 'eu';

    /**
     * @var string UK Public Sector Bodies (Websites and Mobile Applications)
     * Accessibility Regulations 2018.
     */
    public const PROFILE_UK = 'uk';

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, array{
     *     label: string,
     *     legislation: string,
     *     standard: string,
     *     requiresEnforcement: bool,
     *     requiresExclusionCategories: bool,
     *     requiresPreparationMethod: bool,
     *     minimumLevel: string,
     * }> Profile definitions, keyed by profile handle.
     *
     * requiresEnforcement:         the statement must name a body to complain to.
     * requiresExclusionCategories: shortfalls must be split into non-compliance,
     *                              disproportionate burden, and out of scope,
     *                              rather than listed as one lot.
     * requiresPreparationMethod:   the statement must say how it was assessed.
     * minimumLevel:                the WCAG level the legislation demands; a
     *                              target below this cannot satisfy it.
     */
    private static array $profiles = [
        self::PROFILE_GENERIC => [
            'label' => 'General (W3C WAI)',
            'legislation' => '',
            'standard' => 'WCAG 2.2',
            'requiresEnforcement' => false,
            'requiresExclusionCategories' => false,
            'requiresPreparationMethod' => false,
            'minimumLevel' => 'AA',
        ],
        self::PROFILE_EU => [
            'label' => 'European Union (Web Accessibility Directive)',
            'legislation' => 'Directive (EU) 2016/2102',
            'standard' => 'EN 301 549 (WCAG 2.1 AA)',
            'requiresEnforcement' => true,
            'requiresExclusionCategories' => true,
            'requiresPreparationMethod' => true,
            'minimumLevel' => 'AA',
        ],
        self::PROFILE_UK => [
            'label' => 'United Kingdom (Public Sector Bodies Regulations 2018)',
            'legislation' => 'The Public Sector Bodies (Websites and Mobile Applications) (No. 2) Accessibility Regulations 2018',
            'standard' => 'WCAG 2.2 AA',
            'requiresEnforcement' => true,
            'requiresExclusionCategories' => true,
            'requiresPreparationMethod' => true,
            'minimumLevel' => 'AA',
        ],
    ];

    // Public Methods
    // =========================================================================

    /**
     * Returns the definition for a profile, falling back to the generic one so
     * an unrecognised stored value degrades to the least demanding structure
     * rather than throwing on a page the editor needs to fix it from.
     *
     * @param string $profile The profile handle.
     * @return array<string, mixed> The profile definition.
     */
    public static function get(string $profile): array
    {
        return self::$profiles[$profile] ?? self::$profiles[self::PROFILE_GENERIC];
    }

    /**
     * All profile handles.
     *
     * @return string[]
     */
    public static function handles(): array
    {
        return array_keys(self::$profiles);
    }

    /**
     * Profiles as select options for the editor.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::$profiles as $handle => $definition) {
            $options[] = [
                'value' => $handle,
                'label' => Craft::t('accessibility-audit', $definition['label']),
            ];
        }

        return $options;
    }

    /**
     * Whether a profile demands that shortfalls be split into the three
     * categories the EU model statement requires.
     *
     * @param string $profile The profile handle.
     * @return bool
     */
    public static function requiresExclusionCategories(string $profile): bool
    {
        return (bool) self::get($profile)['requiresExclusionCategories'];
    }

    /**
     * Whether the target WCAG level is enough for the profile's legislation.
     *
     * Level A satisfies nothing that mandates a statement: both the EU and UK
     * regimes require AA. Saying so plainly beats letting somebody publish a
     * statement claiming a standard their target cannot reach.
     *
     * @param string $profile The profile handle.
     * @param string $targetLevel The configured target WCAG level (A/AA/AAA).
     * @return bool
     */
    public static function targetLevelSatisfies(string $profile, string $targetLevel): bool
    {
        $rank = ['A' => 1, 'AA' => 2, 'AAA' => 3];
        $minimum = (string) self::get($profile)['minimumLevel'];

        return ($rank[$targetLevel] ?? 0) >= ($rank[$minimum] ?? 0);
    }
}
