<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use craft\helpers\StringHelper;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes a VPAT report out as an OpenACR document.
 *
 * OpenACR is GSA's machine-readable format for an Accessibility Conformance
 * Report (https://github.com/GSA/openacr). The document names the VPAT 2.5 WCAG
 * 2.2 catalog, or the International Edition catalog when EN 301 549 is switched
 * on, and fills only the `web` component: a website is not electronic
 * documents, software or an authoring tool.
 *
 * Criteria are written as the plugin holds them. WCAG 2.2 made 4.1.1 Parsing
 * obsolete, so the plugin never asks about it, and the document leaves it out
 * rather than stating a level nobody chose. OpenACR's catalog check passes a
 * report that omits a catalog criterion; it fails one that adds a criterion the
 * catalog does not have.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.4.0
 */
class OpenAcr
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The catalog for a WCAG-only report.
     */
    public const CATALOG_WCAG = '2.5-edition-wcag-2.2-en';

    /**
     * @var string The catalog for a report that also states EN 301 549.
     */
    public const CATALOG_INTERNATIONAL = '2.5-edition-wcag-2.2-508-eu-en';

    /**
     * @var array<string, string> The plugin's conformance levels, keyed to the
     * OpenACR term each one is written as.
     */
    public const LEVELS = [
        'Supports' => 'supports',
        'Partially Supports' => 'partially-supports',
        'Does Not Support' => 'does-not-support',
        'Not Applicable' => 'not-applicable',
        'Not Evaluated' => 'not-evaluated',
    ];

    /**
     * @var string The level a criterion with no answer yet is written as.
     */
    public const LEVEL_UNANSWERED = 'not-evaluated';

    /**
     * @var string The OpenACR component a website's results belong to.
     */
    public const COMPONENT = 'web';

    /**
     * @var string[] The International Edition's chapters after the WCAG tables,
     * in catalog order. Only Chapter 9 (Web) applies to a website.
     */
    private const INTERNATIONAL_CHAPTERS = [
        'functional_performance_criteria',
        'hardware',
        'software',
        'support_documentation_and_services',
        'en_301_549_functional_performance',
        'en_301_549_generic_requirements',
        'en_301_549_ict_with_two-way_voice',
        'en_301_549_ict_with_video',
        'en_301_549_hardware',
        'en_301_549_web',
        'en_301_549_non-web-documents',
        'en_301_549_software',
        'en_301_549_documentation_and_support_services',
        'en_301_549_ict_providing_relay_or_emergency_service_access',
    ];

    /**
     * @var string The International Edition chapter covering web content.
     */
    private const EN_WEB_CHAPTER = 'en_301_549_web';

    // Public Methods
    // =========================================================================

    /**
     * Whether a report holds everything the OpenACR schema requires.
     *
     * The schema requires an email address for the report's author. The
     * product name is required too, but always has the site's name to fall
     * back on.
     *
     * @param array $report The report from VpatService::getFullReport().
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    public static function canExport(array $report): bool
    {
        return self::_text($report['meta']['contactEmail'] ?? '') !== '';
    }

    /**
     * Builds the OpenACR document for a report, as a nested array ready to be
     * written as YAML.
     *
     * Empty fields are left out rather than written as blank strings.
     *
     * @param array $report The report from VpatService::getFullReport().
     * @param string $siteName The site's name, used when no product name is set.
     * @return array
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    public static function document(array $report, string $siteName): array
    {
        $meta = $report['meta'] ?? [];
        $international = (bool)($report['en301549'] ?? false);
        $productName = self::productName($report, $siteName);

        $document = [
            'title' => Craft::t('accessibility-audit', '{name} Accessibility Conformance Report', [
                'name' => $productName,
            ]),
            'product' => self::_filled([
                'name' => $productName,
                'version' => self::_text($meta['productVersion'] ?? ''),
                'description' => self::_text($meta['productDescription'] ?? ''),
            ]),
            'author' => self::_filled([
                'name' => self::_text($meta['contactName'] ?? ''),
                'email' => self::_text($meta['contactEmail'] ?? ''),
                'phone' => self::_text($meta['contactPhone'] ?? ''),
            ]),
            'report_date' => self::_text($meta['reportDate'] ?? ''),
            'notes' => self::_notes($meta),
            'evaluation_methods_used' => self::_methods($meta),
            'legal_disclaimer' => self::_text($meta['legalDisclaimer'] ?? ''),
            'catalog' => $international ? self::CATALOG_INTERNATIONAL : self::CATALOG_WCAG,
            'chapters' => self::_chapters($report, $international),
        ];

        return self::_filled($document);
    }

    /**
     * The product name a report is written under: the one entered, or the
     * site's name when none was.
     *
     * @param array $report The report from VpatService::getFullReport().
     * @param string $siteName The site's name.
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    public static function productName(array $report, string $siteName): string
    {
        $name = self::_text($report['meta']['productName'] ?? '');

        if ($name !== '') {
            return $name;
        }

        return self::_text($siteName) ?: Craft::t('accessibility-audit', 'Website');
    }

    /**
     * Writes an OpenACR document as YAML.
     *
     * Remarks spanning several lines are written as literal blocks, so they
     * read as they were typed.
     *
     * @param array $document The document from [[document()]].
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    public static function toYaml(array $document): string
    {
        return Yaml::dump($document, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * The file name a document is downloaded as.
     *
     * @param string $productName The product name the report is written under.
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    public static function filename(string $productName): string
    {
        $slug = StringHelper::toKebabCase($productName);

        return ($slug !== '' ? $slug . '-' : '') . 'openacr.yaml';
    }

    // Private Methods
    // =========================================================================

    /**
     * The chapters of the document: both WCAG tables filled, Level AAA marked
     * out of scope, and on the International Edition every chapter but Web
     * marked out of scope too.
     *
     * @param array $report The report from VpatService::getFullReport().
     * @param bool $international Whether the International Edition catalog is used.
     * @return array<string, array>
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    private static function _chapters(array $report, bool $international): array
    {
        $chapters = [
            'success_criteria_level_a' => [
                'criteria' => self::_criteria($report['levelA'] ?? []),
            ],
            'success_criteria_level_aa' => [
                'criteria' => self::_criteria($report['levelAA'] ?? []),
            ],
            'success_criteria_level_aaa' => [
                'disabled' => true,
                'notes' => Craft::t('accessibility-audit', 'This report covers WCAG 2.2 Level A and AA.'),
            ],
        ];

        if (!$international) {
            return $chapters;
        }

        foreach (self::INTERNATIONAL_CHAPTERS as $id) {
            if ($id === self::EN_WEB_CHAPTER) {
                $chapters[$id] = ['notes' => self::_enWebNotes($report)];
                continue;
            }

            $chapters[$id] = [
                'disabled' => true,
                'notes' => Craft::t('accessibility-audit', 'Outside the scope of this report, which covers web content.'),
            ];
        }

        return $chapters;
    }

    /**
     * The criteria entries for one WCAG table.
     *
     * @param array<string, array> $rows The table's rows, keyed by criterion number.
     * @return array<int, array>
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    private static function _criteria(array $rows): array
    {
        $criteria = [];

        foreach ($rows as $number => $row) {
            $adherence = [
                'level' => self::LEVELS[$row['effectiveLevel'] ?? ''] ?? self::LEVEL_UNANSWERED,
            ];

            $remarks = self::_text($row['effectiveRemarks'] ?? '');
            if ($remarks !== '') {
                $adherence['notes'] = $remarks;
            }

            $criteria[] = [
                'num' => (string)$number,
                'components' => [
                    ['name' => self::COMPONENT, 'adherence' => $adherence],
                ],
            ];
        }

        return $criteria;
    }

    /**
     * The note for the International Edition's Web chapter, which carries no
     * criteria of its own: clause 9 adopts the WCAG criteria, so the WCAG
     * tables are the answer to it.
     *
     * @param array $report The report from VpatService::getFullReport().
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    private static function _enWebNotes(array $report): string
    {
        $uncovered = [];

        foreach (array_merge($report['levelA'] ?? [], $report['levelAA'] ?? []) as $number => $row) {
            if (($row['enClause'] ?? null) === null) {
                $uncovered[] = (string)$number;
            }
        }

        $version = (string)($report['en301549Version'] ?? '');
        $note = Craft::t('accessibility-audit', 'Clause 9 of EN 301 549 {version} adopts the WCAG Level A and AA success criteria for web content, so the Level A and AA tables are the results for this chapter.', [
            'version' => $version,
        ]);

        if ($uncovered === []) {
            return $note;
        }

        return $note . ' ' . Craft::t('accessibility-audit', 'EN 301 549 {version} adopts WCAG 2.1, so {criteria} have no clause in it and are assessed against WCAG 2.2 only.', [
            'version' => $version,
            'criteria' => implode(', ', $uncovered),
        ]);
    }

    /**
     * The report's notes, with the evaluation period and the pages covered
     * added, since OpenACR has no field of its own for either.
     *
     * @param array $meta The report's metadata.
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    private static function _notes(array $meta): string
    {
        $paragraphs = [];

        $notes = self::_text($meta['notes'] ?? '');
        if ($notes !== '') {
            $paragraphs[] = $notes;
        }

        $from = self::_text($meta['reportPeriodFrom'] ?? '');
        $to = self::_text($meta['reportPeriodTo'] ?? '');
        $period = match (true) {
            $from !== '' && $to !== '' => Craft::t('accessibility-audit', 'Evaluation period: {from} to {to}.', ['from' => $from, 'to' => $to]),
            $from !== '' => Craft::t('accessibility-audit', 'Evaluation period: from {from}.', ['from' => $from]),
            $to !== '' => Craft::t('accessibility-audit', 'Evaluation period: up to {to}.', ['to' => $to]),
            default => '',
        };
        if ($period !== '') {
            $paragraphs[] = $period;
        }

        $pages = array_values(array_filter(
            array_map(self::_text(...), (array)($meta['scopePages'] ?? [])),
            static fn(string $page): bool => $page !== '',
        ));
        if ($pages !== []) {
            $paragraphs[] = Craft::t('accessibility-audit', 'Scope of evaluation:') . "\n" . implode("\n", $pages);
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * The evaluation methods: the ones ticked, then the methodology written
     * out. With neither, the same automated-only statement the HTML export
     * makes, so the file never claims testing nobody recorded.
     *
     * @param array $meta The report's metadata.
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    private static function _methods(array $meta): string
    {
        $methods = array_values(array_filter(
            array_map(self::_text(...), (array)($meta['evalMethods'] ?? [])),
            static fn(string $method): bool => $method !== '',
        ));
        $methodology = self::_text($meta['evalMethodology'] ?? '');

        $paragraphs = [];
        if ($methods !== []) {
            $paragraphs[] = implode("\n", $methods);
        }
        if ($methodology !== '') {
            $paragraphs[] = $methodology;
        }

        if ($paragraphs === []) {
            return Craft::t('accessibility-audit', 'Automated scanning with axe-core and server-side HTML analysis.');
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * Drops the empty strings and empty arrays from a map.
     *
     * @param array $values
     * @return array
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    private static function _filled(array $values): array
    {
        return array_filter($values, static fn(mixed $value): bool => $value !== '' && $value !== []);
    }

    /**
     * A stored value as trimmed text.
     *
     * @param mixed $value
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.4.0
     */
    private static function _text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
