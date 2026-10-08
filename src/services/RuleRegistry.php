<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;

/**
 * Static registry mapping accessibility rule IDs to metadata used by the CP
 * reports: difficulty, responsibility, affected element type, and the user
 * abilities each rule impacts.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class RuleRegistry
{
    // Private Properties
    // =========================================================================

    // difficulty:     beginner | intermediate | advanced
    // responsibility: content | design | development | technical
    // elementType:    human-readable category
    // abilities:      array of vision | cognition | motor | hearing

    /**
     * @var array<string, array{difficulty: string, responsibility: string, elementType: string, abilities: string[]}> Rule metadata, keyed by rule ID.
     */
    private static array $_rules = [
        'img-alt' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Images',         'abilities' => ['vision']],
        'img-alt-filename' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Images',         'abilities' => ['vision']],
        'heading-order' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Headings',       'abilities' => ['cognition']],
        'empty-heading' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Headings',       'abilities' => ['cognition']],
        'multiple-h1' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Headings',       'abilities' => ['cognition']],
        'link-name' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Links',          'abilities' => ['vision', 'cognition']],
        'link-generic' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Links',          'abilities' => ['cognition']],
        'link-new-window' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Links',          'abilities' => ['cognition']],
        'button-name' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Buttons',        'abilities' => ['vision', 'cognition']],
        'form-label' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['vision', 'cognition']],
        'select-label' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['vision', 'cognition']],
        'table-header' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Tables',         'abilities' => ['cognition']],
        'html-lang' => ['difficulty' => 'beginner',     'responsibility' => 'technical',   'elementType' => 'Page',           'abilities' => ['vision', 'cognition']],
        'page-title' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Page',           'abilities' => ['cognition']],
        'skip-link' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Navigation',     'abilities' => ['motor']],
        'landmark-main' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Page structure', 'abilities' => ['cognition']],
        'landmark-regions' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Page structure', 'abilities' => ['cognition']],
        'iframe-title' => ['difficulty' => 'beginner',     'responsibility' => 'development', 'elementType' => 'Iframes',        'abilities' => ['vision', 'cognition']],
        'video-captions' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Media',          'abilities' => ['hearing']],
        'autoplay' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Media',          'abilities' => ['cognition', 'motor']],
        'duplicate-id' => ['difficulty' => 'advanced',     'responsibility' => 'technical',   'elementType' => 'Page',           'abilities' => ['cognition']],
        'meta-description' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Page',           'abilities' => ['cognition']],
        'aria-tab-name' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Interactive',    'abilities' => ['vision', 'cognition']],
        'summary-name' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Interactive',    'abilities' => ['vision', 'cognition']],
        'aria-hidden-focus' => ['difficulty' => 'advanced',     'responsibility' => 'development', 'elementType' => 'Interactive',    'abilities' => ['vision']],
        'list-structure' => ['difficulty' => 'beginner',     'responsibility' => 'development', 'elementType' => 'Lists',          'abilities' => ['cognition']],
        'contrast-hover' => ['difficulty' => 'beginner',     'responsibility' => 'design',      'elementType' => 'Text',           'abilities' => ['vision']],
        'contrast-focus' => ['difficulty' => 'beginner',     'responsibility' => 'design',      'elementType' => 'Text',           'abilities' => ['vision']],
        'contrast-selection' => ['difficulty' => 'beginner',     'responsibility' => 'design',      'elementType' => 'Text',           'abilities' => ['vision']],
        'unescaped-markup-in-code' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Page structure', 'abilities' => ['vision', 'cognition']],
        'block-in-paragraph' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Page structure', 'abilities' => ['cognition']],
        'input-type' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['motor']],

        // Potential issue rules
        'potential:short-alt' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Images',   'abilities' => ['vision']],
        'potential:long-alt' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Images',   'abilities' => ['vision', 'cognition']],
        'potential:identical-links' => [
            'difficulty' => 'beginner',
            'responsibility' => 'content',
            'elementType' => 'Links',
            'abilities' => ['cognition'],
            'description' => 'Two or more links on a page read the same but go to different places. Someone '
                . 'moving through a list of links in a screen reader sees the same words twice with nothing '
                . 'to choose between them. Whether it fails WCAG 2.4.4 at level A or is a 2.4.9 improvement '
                . 'at AAA depends on what surrounds each link, so every occurrence is judged on its own and '
                . 'says which it is.',
        ],
        'symbol-only-content' => [
            'difficulty' => 'beginner',
            'responsibility' => 'development',
            'elementType' => 'Text',
            'abilities' => ['vision', 'cognition'],
            'description' => 'A cell, link or button whose whole announced name is a symbol: a tick, a '
                . 'cross, an arrow, or a dash standing in for "not applicable". The shape carries the '
                . 'meaning and the character does not, so a screen reader announces "check mark" or, '
                . 'depending on how the reader has symbol verbosity set, nothing at all. The fix is '
                . 'visually hidden text saying what the symbol means, with the symbol itself marked '
                . 'aria-hidden.',
        ],
        'potential:url-as-link-text' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Links',    'abilities' => ['cognition']],
        'potential:decorative-image' => ['difficulty' => 'beginner',     'responsibility' => 'development', 'elementType' => 'Images',   'abilities' => ['vision']],
        'potential:possible-heading' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Headings', 'abilities' => ['cognition']],
        'potential:table-layout' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Tables',   'abilities' => ['cognition']],
        'potential:video-audio-desc' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Media',    'abilities' => ['vision']],
        'potential:contrast-unmeasurable' => ['difficulty' => 'intermediate', 'responsibility' => 'design',      'elementType' => 'Text',     'abilities' => ['vision']],
        'potential:focus-outline-removed' => [
            'difficulty' => 'beginner',
            'responsibility' => 'design',
            'elementType' => 'Interactive',
            'abilities' => ['vision', 'motor'],
            'description' => 'A rule in the stylesheet turns off the outline browsers draw around whatever has '
                . 'keyboard focus, and nothing else in the stylesheet draws an indicator in its place. Someone '
                . 'using the keyboard moves through the page without being able to see where they are. Read '
                . 'from the stylesheet, so a script that adds its own focus styling is not seen: tab through '
                . 'the page to check before answering.',
        ],
        'potential:focus-not-visible' => [
            'difficulty' => 'intermediate',
            'responsibility' => 'design',
            'elementType' => 'Interactive',
            'abilities' => ['vision', 'motor'],
            'description' => 'The browser pass moved keyboard focus onto this control and nothing changed: '
                . 'no outline, shadow, border, background, colour or underline, on the control or on the '
                . 'elements around it. A change too faint to see still passes this check, so it asks rather '
                . 'than fails. Tab to the control in a browser and look.',
        ],
        'potential:focus-obscured' => [
            'difficulty' => 'intermediate',
            'responsibility' => 'development',
            'elementType' => 'Page structure',
            'abilities' => ['vision', 'motor'],
            'description' => 'A fixed or sticky element, usually a header, a footer bar or a chat button, '
                . 'completely covered controls as keyboard focus reached them, so someone using the keyboard '
                . 'cannot see where they are. Reported once per covering element, since that is where the fix '
                . 'goes: scroll-padding on the page, or a smaller element. Consent banners on the excluded '
                . 'list are hidden during the check.',
        ],

        // axe-core rules (common ones)
        'color-contrast' => ['difficulty' => 'intermediate', 'responsibility' => 'design',      'elementType' => 'Text',           'abilities' => ['vision']],
        'image-alt' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Images',         'abilities' => ['vision']],
        'label' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['vision', 'cognition']],
        'html-has-lang' => ['difficulty' => 'beginner',     'responsibility' => 'technical',   'elementType' => 'Page',           'abilities' => ['vision', 'cognition']],
        'document-title' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Page',           'abilities' => ['cognition']],
        'frame-title' => ['difficulty' => 'beginner',     'responsibility' => 'development', 'elementType' => 'Iframes',        'abilities' => ['vision', 'cognition']],
        'duplicate-id-active' => ['difficulty' => 'advanced',     'responsibility' => 'technical',   'elementType' => 'Page',           'abilities' => ['cognition']],
        'region' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Page structure', 'abilities' => ['cognition']],
        'landmark-one-main' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Page structure', 'abilities' => ['cognition']],
        'bypass' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Navigation',     'abilities' => ['motor']],
        'video-caption' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Media',          'abilities' => ['hearing']],
        'td-headers-attr' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Tables',         'abilities' => ['cognition']],
        'th-has-data-cells' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Tables',         'abilities' => ['cognition']],
        'select-name' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['vision', 'cognition']],
        'input-button-name' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['vision', 'cognition']],
    ];

    /**
     * @var array{difficulty: string, responsibility: string, elementType: string, abilities: string[]} Fallback metadata for unknown rules.
     */
    private static array $_fallback = [
        'difficulty' => 'intermediate',
        'responsibility' => 'technical',
        'elementType' => 'Other',
        'abilities' => ['cognition'],
    ];

    // Public Methods
    // =========================================================================

    /**
     * Returns the metadata for a rule ID, falling back to a generic default.
     *
     * @param string $ruleId The rule identifier, optionally prefixed with `axe:`.
     * @return array{difficulty: string, responsibility: string, elementType: string, abilities: string[]}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function get(string $ruleId): array
    {
        $baseId = str_starts_with($ruleId, 'axe:') ? substr($ruleId, 4) : $ruleId;
        return self::$_rules[$baseId] ?? self::$_fallback;
    }

    /**
     * Who a rule is for, in words, translated.
     *
     * @param string $r The responsibility value.
     * @return string The label, or a general one where the value is unknown.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function responsibilityLabel(string $r): string
    {
        return self::responsibilityLabels()[$r]
            ?? Craft::t('accessibility-audit', 'Other');
    }

    /**
     * Every responsibility, with the label to print and the class modifier the
     * badge is styled by.
     *
     * The one source for these. The same badge is built server-side by the Twig
     * macro and client-side by the table renderers, so the map is handed to the
     * JavaScript rather than written out again there. It had been written out
     * again: the client copy said "Content" where the server said "Content
     * writing", and never went through the translation file at all.
     *
     * @return array<string, array{modifier: string, label: string}> Keyed by
     *         responsibility value.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function responsibilities(): array
    {
        return [
            'content' => ['modifier' => 'content', 'label' => Craft::t('accessibility-audit', 'Content writing')],
            'design' => ['modifier' => 'design', 'label' => Craft::t('accessibility-audit', 'Visual design')],
            'development' => ['modifier' => 'dev', 'label' => Craft::t('accessibility-audit', 'Development')],
            'technical' => ['modifier' => 'technical', 'label' => Craft::t('accessibility-audit', 'Technical')],
        ];
    }

    /**
     * Every responsibility label, keyed by value.
     *
     * @return array<string, string> The labels, keyed by responsibility value.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function responsibilityLabels(): array
    {
        return array_map(
            static fn(array $r): string => $r['label'],
            self::responsibilities(),
        );
    }
}
