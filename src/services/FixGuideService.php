<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\base\ElementInterface;
use craft\errors\SiteNotFoundException;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\FixGuideMarkdown;
use johnhenry\accessibilityaudit\helpers\InLanguage;
use johnhenry\accessibilityaudit\helpers\RuleRows;
use johnhenry\accessibilityaudit\helpers\ScanTarget;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Gathers the fix guides: Markdown files a team downloads to work through what
 * the scans found, for the whole site, for one rule, or for one page.
 *
 * Built here and handed back as a download. Nothing is sent anywhere: the AI
 * prompts in a guide are for a person to paste into a tool of their choosing.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class FixGuideService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string A guide to everything open on the site.
     */
    public const SCOPE_SITE = 'site';

    /**
     * @var string A guide to one rule.
     */
    public const SCOPE_RULE = 'rule';

    /**
     * @var string A guide to one page.
     */
    public const SCOPE_PAGE = 'page';

    /**
     * @var int Example occurrences shown for each rule in the site guide.
     */
    public const SITE_MAX_EXAMPLES = 5;

    /**
     * @var int Pages listed for each rule in the site guide.
     */
    public const SITE_MAX_PAGES = 25;

    /**
     * @var int Example occurrences shown in a rule or page guide.
     */
    public const FOCUSED_MAX_EXAMPLES = 10;

    /**
     * @var int Pages listed in a rule guide.
     */
    public const FOCUSED_MAX_PAGES = 100;

    // Public Methods
    // =========================================================================

    /**
     * The guide to every open issue on a site.
     *
     * @param int $siteId The site to read.
     * @return string The guide, as Markdown.
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function siteGuide(int $siteId): string
    {
        $audit = AccessibilityAudit::getInstance()->getAudit();
        $templatePages = $this->_templatePages($siteId);
        $rules = [];

        foreach (self::_byRule(RuleRows::sort($audit->getIssuesByImpact($siteId, PHP_INT_MAX), null, false)) as $ruleId => $row) {
            $rule = $this->_siteRule($ruleId, $row, $siteId, $templatePages, self::SITE_MAX_PAGES, self::SITE_MAX_EXAMPLES);

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return FixGuideMarkdown::render($this->_guide($siteId, self::SCOPE_SITE, $rules, self::SITE_MAX_PAGES, self::SITE_MAX_EXAMPLES));
    }

    /**
     * The guide to one rule across a site.
     *
     * @param string $ruleId The rule.
     * @param int $siteId The site to read.
     * @return string The guide, as Markdown.
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function ruleGuide(string $ruleId, int $siteId): string
    {
        $rule = $this->_siteRule(
            $ruleId,
            null,
            $siteId,
            $this->_templatePages($siteId),
            self::FOCUSED_MAX_PAGES,
            self::FOCUSED_MAX_EXAMPLES,
        );

        return FixGuideMarkdown::render($this->_guide(
            $siteId,
            self::SCOPE_RULE,
            $rule !== null ? [$rule] : [],
            self::FOCUSED_MAX_PAGES,
            self::FOCUSED_MAX_EXAMPLES,
            RuleRegistry::title($ruleId),
        ));
    }

    /**
     * The guide to one scanned page.
     *
     * @param array<string, mixed> $scan The page's latest scan, from findPageScan().
     * @param ElementInterface|null $element The element the page belongs to, if any.
     * @param int $siteId The site the scan belongs to.
     * @return string The guide, as Markdown.
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function pageGuide(array $scan, ?ElementInterface $element, int $siteId): string
    {
        $audit = AccessibilityAudit::getInstance()->getAudit();
        $scanId = (int)$scan['id'];
        $templatePages = $this->_templatePages($siteId);
        $page = $this->_page($scan, $element, 0);
        $rows = $audit->getIssuesGroupedByScan($scanId);

        foreach ($rows as &$row) {
            // Ranked by occurrences within each severity: the points to gain
            // follow from occurrences alone once the severity is fixed.
            $row['pointsToGain'] = (float)$row['occurrences'];
        }
        unset($row);

        $rules = [];

        foreach (self::_byRule(RuleRows::sort($rows, null, false)) as $ruleId => $row) {
            $occurrences = (int)$row['occurrences'];
            $examples = $audit->getRuleOccurrencesForScans($ruleId, [$scanId], self::FOCUSED_MAX_EXAMPLES)[$scanId] ?? [];

            $rules[] = $this->_rule(
                ruleId: $ruleId,
                row: $row,
                occurrences: $occurrences,
                pageCount: 1,
                templatePages: $templatePages[$ruleId] ?? null,
                pages: [['occurrences' => $occurrences, 'found' => $examples[0]['markup'] ?? ''] + $page],
                examples: array_map(static fn(array $o): array => $o + ['pageUrl' => $page['url']], $examples),
            );
        }

        return FixGuideMarkdown::render($this->_guide(
            $siteId,
            self::SCOPE_PAGE,
            $rules,
            1,
            self::FOCUSED_MAX_EXAMPLES,
            $page['label'],
            $page['url'],
        ));
    }

    /**
     * The latest scan of a page, addressed the way its page report is.
     *
     * An element is read by its latest scan. A page with no element behind it
     * is addressed by scan ID, scoped to the site so an ID from another site
     * finds nothing, and then followed to the newest scan of the same address.
     *
     * @param int $elementId The element, or 0.
     * @param int $scanId The scan, or 0, where there is no element.
     * @param int $siteId The site to read.
     * @return array<string, mixed>|null The scan, or null where there is none.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function findPageScan(int $elementId, int $scanId, int $siteId): ?array
    {
        $audit = AccessibilityAudit::getInstance()->getAudit();

        if ($elementId !== 0) {
            return $audit->getLatestScan($elementId, $siteId);
        }

        if ($scanId === 0) {
            return null;
        }

        $scan = $audit->getScan($scanId, $siteId);

        if ($scan === null) {
            return null;
        }

        if (!empty($scan['elementId'])) {
            return $audit->getLatestScan((int)$scan['elementId'], $siteId);
        }

        return $audit->getLatestUrlScan((string)$scan['url'], $siteId) ?? $scan;
    }

    /**
     * The filename a guide is offered under.
     *
     * @param int $siteId The site the guide is for.
     * @param string $scope One of the SCOPE_* constants.
     * @param string $name The rule ID or page name the guide is about, for the
     *                     rule and page scopes.
     * @return string Such as `accessibility-fix-guide-default-rule-img-alt-2026-11-23.md`.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function filename(int $siteId, string $scope, string $name = ''): string
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $parts = ['accessibility-fix-guide', self::slug((string)$site?->handle, 'site'), $scope];

        if ($scope !== self::SCOPE_SITE) {
            $parts[] = self::slug($name, $scope);
        }

        $parts[] = DateTimeHelper::now()->format('Y-m-d');

        return implode('-', $parts) . '.md';
    }

    /**
     * Text made safe for a filename.
     *
     * @param string $text The text.
     * @param string $fallback What to use where nothing is left.
     * @return string Lowercase ASCII letters and digits joined by hyphens, at
     *                most 60 characters.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function slug(string $text, string $fallback): string
    {
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', StringHelper::toAscii($text)), '-'));
        $slug = rtrim(substr($slug, 0, 60), '-');

        return $slug !== '' ? $slug : $fallback;
    }

    // Private Methods
    // =========================================================================

    /**
     * One rule across a site, with its pages and examples.
     *
     * @param string $ruleId The rule.
     * @param array<string, mixed>|null $row The rule's row from the issue
     *        listing, for its severity and criterion, or null to read them from
     *        the rule's summary.
     * @param int $siteId The site to read.
     * @param array<string, int> $templatePages Pages each template-level rule
     *        is on, keyed by rule ID.
     * @param int $maxPages Most pages to list.
     * @param int $maxExamples Most examples to show.
     * @return array<string, mixed>|null The rule, or null where nothing is open.
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _siteRule(string $ruleId, ?array $row, int $siteId, array $templatePages, int $maxPages, int $maxExamples): ?array
    {
        $audit = AccessibilityAudit::getInstance()->getAudit();
        $summary = $audit->getIssueRuleSummary($ruleId, $siteId);

        if ($summary === []) {
            return null;
        }

        $entries = $audit->getPagesForRule($ruleId, $siteId, 1, $maxPages)['entries'];
        $byScan = $audit->getRuleOccurrencesForScans($ruleId, array_column($entries, 'id'), $maxExamples);
        $pages = [];

        foreach ($entries as $entry) {
            $page = $this->_page($entry, $entry['element'], (int)$entry['occurrences']);
            $page['found'] = $byScan[(int)$entry['id']][0]['markup'] ?? '';
            $pages[(int)$entry['id']] = $page;
        }

        // Taken a round at a time across the pages, so the examples come from
        // as many pages as they can, with repeats dropped: a rule from a shared
        // template finds the same markup on every page, and one copy of it says
        // all there is to say.
        $examples = [];

        for ($round = 0; $round < $maxExamples && count($examples) < $maxExamples; $round++) {
            foreach ($pages as $scanId => $page) {
                $occurrence = $byScan[$scanId][$round] ?? null;

                if ($occurrence !== null) {
                    $examples[$occurrence['markup'] . '|' . $occurrence['selector']] ??= $occurrence + ['pageUrl' => $page['url']];
                }
            }
        }

        return $this->_rule(
            ruleId: $ruleId,
            row: ($row ?? []) + $summary,
            occurrences: (int)$summary['occurrences'],
            pageCount: (int)$summary['pageCount'],
            templatePages: $templatePages[$ruleId] ?? null,
            pages: array_values($pages),
            examples: array_slice(array_values($examples), 0, $maxExamples),
        );
    }

    /**
     * A rule as the guide lays it out.
     *
     * The rule's words are read twice: in the reader's language for the guide,
     * and in English for the prompt.
     *
     * @param string $ruleId The rule.
     * @param array<string, mixed> $row Carries the rule's `severity`,
     *        `wcagCriterion`, `wcagLevel` and `helpUrl`.
     * @param int $occurrences How many times the rule was found in scope.
     * @param int $pageCount How many pages it was found on in scope.
     * @param int|null $templatePages How many pages it is on across the site,
     *        where that is enough to come from a shared template.
     * @param array<int, array<string, mixed>> $pages The pages to list.
     * @param array<int, array{message: string, markup: string, selector: string, pageUrl?: string|null}> $examples
     *        The occurrences to show.
     * @return array<string, mixed>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _rule(string $ruleId, array $row, int $occurrences, int $pageCount, ?int $templatePages, array $pages, array $examples): array
    {
        $helpUrl = (string)($row['helpUrl'] ?? '');
        $english = InLanguage::run('en', static fn(): array => [
            'title' => RuleRegistry::title($ruleId),
            'description' => RuleRegistry::description($ruleId),
            'fix' => RuleRegistry::fix($ruleId),
        ]);

        foreach ($examples as &$example) {
            $example['finding'] = RuleRegistry::exampleFinding($example['message'], $english['title'], $english['description']);
        }
        unset($example);

        return [
            'ruleId' => $ruleId,
            'title' => RuleRegistry::title($ruleId),
            'description' => RuleRegistry::description($ruleId),
            'fix' => RuleRegistry::fix($ruleId),
            'helpUrl' => $helpUrl,
            'severity' => (string)($row['severity'] ?? ''),
            'wcagCriterion' => $row['wcagCriterion'] ?? null,
            'wcagLevel' => $row['wcagLevel'] ?? null,
            'responsibility' => RuleRegistry::get($ruleId)['responsibility'],
            'occurrences' => $occurrences,
            'pageCount' => $pageCount,
            'templateLevel' => $templatePages !== null,
            'templatePages' => $templatePages,
            'pages' => $pages,
            'examples' => $examples,
            'prompt' => $english + [
                'ruleId' => $ruleId,
                'helpUrl' => $helpUrl,
                'wcagCriterion' => $row['wcagCriterion'] ?? null,
                'wcagLevel' => $row['wcagLevel'] ?? null,
                'occurrences' => $occurrences,
                'pageCount' => $pageCount,
                'templatePages' => $templatePages,
            ],
        ];
    }

    /**
     * A page as the guide lists it.
     *
     * @param array<string, mixed> $scan The page's scan row.
     * @param ElementInterface|null $element The element behind it, if any.
     * @param int $occurrences How many times the rule was found on it.
     * @return array{label: string, url: string|null, editUrl: string|null, occurrences: int, found?: string}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _page(array $scan, ?ElementInterface $element, int $occurrences): array
    {
        return [
            'label' => ScanTarget::label($scan, $element),
            'url' => ScanTarget::url($scan, $element),
            'editUrl' => $element?->getCpEditUrl(),
            'occurrences' => $occurrences,
        ];
    }

    /**
     * The guide's frame: the site, the date, the scope and the caps.
     *
     * @param int $siteId The site.
     * @param string $scope One of the SCOPE_* constants.
     * @param array<int, array<string, mixed>> $rules The rules, in order.
     * @param int $maxPages Most pages to list per rule.
     * @param int $maxExamples Most examples to show per rule.
     * @param string|null $scopeLabel The rule's or page's name.
     * @param string|null $scopeUrl The page's address.
     * @return array<string, mixed>
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _guide(int $siteId, string $scope, array $rules, int $maxPages, int $maxExamples, ?string $scopeLabel = null, ?string $scopeUrl = null): array
    {
        $site = Craft::$app->getSites()->getSiteById($siteId) ?? Craft::$app->getSites()->getPrimarySite();

        return [
            'siteName' => $site->getName(),
            'siteUrl' => $site->getBaseUrl(),
            'generated' => Craft::$app->getFormatter()->asDatetime(DateTimeHelper::now(), 'medium'),
            'scope' => $scope,
            'scopeLabel' => $scopeLabel,
            'scopeUrl' => $scopeUrl,
            'maxPages' => $maxPages,
            'maxExamples' => $maxExamples,
            'rules' => $rules,
        ];
    }

    /**
     * How many pages each template-level rule is on.
     *
     * @param int $siteId The site to read.
     * @return array<string, int> Keyed by rule ID.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _templatePages(int $siteId): array
    {
        $pages = [];

        foreach (AccessibilityAudit::getInstance()->getAudit()->getTemplateIssues($siteId) as $row) {
            $ruleId = (string)$row['ruleId'];
            $pages[$ruleId] = max($pages[$ruleId] ?? 0, (int)$row['pageCount']);
        }

        return $pages;
    }

    /**
     * Rule rows folded to one per rule, in the order given.
     *
     * The issue listings split a rule by criterion and severity. A guide covers
     * each rule once, under the first of its rows, with the occurrences of all
     * of them.
     *
     * @param array<int, array<string, mixed>> $rows The rows, in order.
     * @return array<string, array<string, mixed>> Keyed by rule ID.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _byRule(array $rows): array
    {
        $rules = [];

        foreach ($rows as $row) {
            $ruleId = (string)$row['ruleId'];

            if (isset($rules[$ruleId])) {
                $rules[$ruleId]['occurrences'] = (int)$rules[$ruleId]['occurrences'] + (int)$row['occurrences'];
                continue;
            }

            $rules[$ruleId] = $row;
        }

        return $rules;
    }
}
