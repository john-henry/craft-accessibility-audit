<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

/**
 * Static registry mapping accessibility rule IDs to metadata used by the CP
 * reports: difficulty, responsibility, affected element type, and the user
 * abilities each rule impacts.
 *
 * @author JohnHenry <info@johnhenry.ie>
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
    private static array $rules = [
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
        'potential:identical-links' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Links',    'abilities' => ['cognition']],
        'potential:url-as-link-text' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Links',    'abilities' => ['cognition']],
        'potential:decorative-image' => ['difficulty' => 'beginner',     'responsibility' => 'development', 'elementType' => 'Images',   'abilities' => ['vision']],
        'potential:possible-heading' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Headings', 'abilities' => ['cognition']],
        'potential:table-layout' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Tables',   'abilities' => ['cognition']],
        'potential:video-audio-desc' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Media',    'abilities' => ['vision']],
        'potential:contrast-unmeasurable' => ['difficulty' => 'intermediate', 'responsibility' => 'design',      'elementType' => 'Text',     'abilities' => ['vision']],

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
    private static array $fallback = [
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
     */
    public static function get(string $ruleId): array
    {
        $baseId = str_starts_with($ruleId, 'axe:') ? substr($ruleId, 4) : $ruleId;
        return self::$rules[$baseId] ?? self::$fallback;
    }

    /**
     * Returns a human-readable label for a difficulty value.
     *
     * @param string $d The difficulty value.
     * @return string
     */
    public static function difficultyLabel(string $d): string
    {
        return match ($d) {
            'beginner' => 'Beginner',
            'intermediate' => 'Intermediate',
            'advanced' => 'Advanced',
            default => 'Unknown',
        };
    }

    /**
     * Returns a human-readable label for a responsibility value.
     *
     * @param string $r The responsibility value.
     * @return string
     */
    public static function responsibilityLabel(string $r): string
    {
        return match ($r) {
            'content' => 'Content writing',
            'design' => 'Visual design',
            'development' => 'Development',
            'technical' => 'Technical',
            default => 'Other',
        };
    }
}
