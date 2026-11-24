<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\mcp;

use Craft;
use craft\base\ElementInterface;
use craft\errors\SiteNotFoundException;
use craft\helpers\ElementHelper;
use craft\mcp\attributes\RequiresPermission;
use craft\models\Site;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\FixPrompt;
use johnhenry\accessibilityaudit\helpers\InLanguage;
use johnhenry\accessibilityaudit\helpers\RateLimit;
use johnhenry\accessibilityaudit\helpers\RuleRows;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\RuleRegistry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Throwable;

/**
 * The plugin's tools for Craft's authenticated MCP server.
 *
 * An assistant can read what the scans found, work out what to fix and where,
 * and re-scan a page to check the fix. Each tool is gated twice: Craft leaves
 * a tool out of discovery for a user without its permissions or a token
 * without its scope, and each one checks the edition and the permission again
 * when called, because a stdio server keeps the tool list it started with.
 *
 * Built by the MCP container's autowiring, so nothing is injected: the plugin
 * and its services are read off the plugin instance inside each tool, which is
 * the one copy holding the site's settings.
 *
 * Everything said to the model is English. Rule texts are read in English,
 * and anything copied off a scanned page goes under the untrusted key.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class AuditTools
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[] The owners a rule can be filed under. Pinned to
     * RuleRegistry::responsibilities() by a test.
     */
    public const OWNERS = ['content', 'design', 'development', 'technical'];

    /**
     * @var string[] The levels the rule listing filters by. A, AA and AAA are
     * cumulative; best-practice is a rule with no WCAG criterion.
     */
    public const LEVELS = ['A', 'AA', 'AAA', 'best-practice'];

    /**
     * @var string[] The severities a finding can have.
     */
    public const SEVERITIES = ['error', 'warning', 'notice'];

    /**
     * @var string[] The orders the rule listing can be read in.
     */
    public const SORTS = ['severity', 'occurrences', 'points'];

    /**
     * @var int Most rules one page's issue listing carries.
     */
    public const MAX_PAGE_RULES = 100;

    /**
     * @var int Most re-scans one user may ask for in a window.
     */
    public const RESCAN_RATE_LIMIT = 30;

    /**
     * @var int The re-scan window, in seconds.
     */
    public const RESCAN_RATE_WINDOW = 600;

    /**
     * @var string Permission to read the reports.
     */
    private const VIEW_REPORTS = 'accessibility-audit:view-reports';

    /**
     * @var string Permission to run scans.
     */
    private const RUN_SCANS = 'accessibility-audit:run-scans';

    /**
     * @var string[] The WCAG levels, lowest first.
     */
    private const WCAG_LEVELS = ['A', 'AA', 'AAA'];

    // Public Methods
    // =========================================================================

    /**
     * The site's accessibility figures.
     *
     * @param string|null $site Site handle. Defaults to the primary site.
     * @return array<string, mixed>
     * @throws ToolCallException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    #[McpTool(
        name: 'accessibility_audit_summary',
        title: 'Accessibility summary',
        description: "The site's accessibility figures from its latest scans: scores by WCAG level, how much of the site is scanned, open issue counts, failing criteria, how many rules are open and how many of those look template-level. Start here.",
        annotations: new ToolAnnotations(title: 'Accessibility summary', readOnlyHint: true, openWorldHint: false),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission('accessPlugin-accessibility-audit')]
    #[RequiresPermission('accessibility-audit:view-reports')]
    public function summary(?string $site = null): array
    {
        return $this->_run(self::VIEW_REPORTS, function() use ($site): array {
            $plugin = AccessibilityAudit::getInstance();
            $target = $this->_site($site);
            $siteId = (int)$target->id;
            $audit = $plugin->getAudit();
            $settings = $plugin->getSettings();

            $summary = $audit->getSiteSummary($siteId);
            $coverage = $audit->getCoverage($siteId);
            // One entry per open rule, counted over the same rows the rule
            // listing reads.
            $pageCounts = $audit->getRulePageCounts($siteId);
            $pending = array_sum(array_map(
                static fn(array $row): int => (int)$row['occurrences'],
                $audit->getPotentialIssues($siteId),
            ));
            $scanned = (int)$summary['scannedCount'] > 0;

            return [
                'site' => ToolOutput::site($target),
                'edition' => $plugin->edition,
                'wcagLevelTarget' => strtoupper($settings->wcagLevel),
                'targetScore' => $settings->targetScore,
                'lastScanned' => ToolOutput::date($audit->getLatestScanDate($siteId)),
                'coverage' => [
                    'scanned' => (int)$coverage['scanned'],
                    'scannable' => (int)$coverage['scannable'],
                    'sweeping' => (bool)$coverage['sweeping'],
                ],
                'score' => $scanned ? [
                    'overall' => (int)$summary['avgScore'],
                    'A' => (int)$summary['avgScoreA'],
                    'AA' => (int)$summary['avgScoreAA'],
                    'AAA' => (int)$summary['avgScoreAAA'],
                ] : null,
                'openIssues' => [
                    'errors' => (int)$summary['errorCount'],
                    'warnings' => (int)$summary['warningCount'],
                    'notices' => (int)$summary['noticeCount'],
                    'pagesWithErrors' => (int)$summary['criticalPages'],
                ],
                'failingCriteria' => [
                    'A' => (int)$summary['failingCriteriaA'],
                    'AA' => (int)$summary['failingCriteriaAA'],
                    'AAA' => (int)$summary['failingCriteriaAAA'],
                ],
                'openRules' => count($pageCounts),
                'templateLevelRules' => count(array_filter($pageCounts, self::_isTemplateLevel(...))),
                'pendingReview' => $pending,
                'sites' => array_map(static fn(Site $s): string => (string)$s->handle, $plugin->allowedSites()),
            ];
        });
    }

    /**
     * The rules with open issues across a site.
     *
     * @param string|null $site Site handle. Defaults to the primary site.
     * @param string|null $owner Who usually fixes the rule: content (editors), design, development (templates and code) or technical (server and configuration).
     * @param string|null $level WCAG level, cumulative: AA returns A and AA rules. best-practice returns rules with no WCAG criterion.
     * @param string|null $severity Only rules found at this severity.
     * @param bool|null $templateLevel True for rules open on enough pages to most likely come from a shared template; false for the rest.
     * @param string|null $search Matched against the rule title and rule ID, ignoring case.
     * @param string $sort severity (worst first, then points to gain), occurrences, or points (score points to gain by fixing the rule).
     * @param int $limit Most rules to return.
     * @param int $offset Rules to skip. Pass nextOffset from the previous call.
     * @return array<string, mixed>
     * @throws ToolCallException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    #[McpTool(
        name: 'accessibility_audit_rules_list',
        title: 'List open accessibility rules',
        description: 'The accessibility rules with open issues across the site, one row per rule, with who usually fixes it, how often and on how many pages it was found, and whether it looks template-level. Filter, sort and page through them; fetch one with accessibility_audit_rule_get.',
        annotations: new ToolAnnotations(title: 'List open accessibility rules', readOnlyHint: true, openWorldHint: false),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission('accessPlugin-accessibility-audit')]
    #[RequiresPermission('accessibility-audit:view-reports')]
    public function rulesList(
        ?string $site = null,
        #[Schema(enum: [...self::OWNERS, null])]
        ?string $owner = null,
        #[Schema(enum: [...self::LEVELS, null])]
        ?string $level = null,
        #[Schema(enum: [...self::SEVERITIES, null])]
        ?string $severity = null,
        ?bool $templateLevel = null,
        #[Schema(maxLength: 100)]
        ?string $search = null,
        #[Schema(enum: self::SORTS)]
        string $sort = 'severity',
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 25,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        return $this->_run(self::VIEW_REPORTS, function() use ($site, $owner, $level, $severity, $templateLevel, $search, $sort, $limit, $offset): array {
            self::_oneOf('owner', $owner, self::OWNERS);
            self::_oneOf('level', $level, self::LEVELS);
            self::_oneOf('severity', $severity, self::SEVERITIES);
            self::_oneOf('sort', $sort, self::SORTS);
            self::_range('limit', $limit, 1, 100);
            self::_range('offset', $offset, 0, PHP_INT_MAX);

            $target = $this->_site($site);
            $siteId = (int)$target->id;
            $audit = AccessibilityAudit::getInstance()->getAudit();
            $pageCounts = $audit->getRulePageCounts($siteId);

            // Level and severity are properties of a finding, so they filter
            // the rows before a rule's rows are folded into one.
            $rows = array_filter(
                $audit->getIssuesByImpact($siteId, PHP_INT_MAX),
                static fn(array $row): bool => ($level === null || self::_matchesLevel($row, $level))
                    && ($severity === null || $row['severity'] === $severity),
            );

            $rules = [];

            foreach (RuleRows::foldByRule(array_values($rows)) as $row) {
                $ruleId = (string)$row['ruleId'];
                $pages = $pageCounts[$ruleId] ?? 0;
                $row['pages'] = $pages;
                $row['templateLevel'] = self::_isTemplateLevel($pages);

                if ($owner !== null && $row['responsibility'] !== $owner) {
                    continue;
                }

                if ($templateLevel !== null && $row['templateLevel'] !== $templateLevel) {
                    continue;
                }

                $rules[] = $row;
            }

            $rules = RuleRows::sort(
                RuleRows::filter($rules, (string)$search),
                match ($sort) {
                    'occurrences' => 'count',
                    'points' => 'points',
                    default => null,
                },
                false,
            );

            $total = count($rules);
            $slice = array_slice($rules, $offset, $limit);
            $next = $offset + count($slice);

            return [
                'site' => ToolOutput::site($target),
                'total' => $total,
                'count' => count($slice),
                'limit' => $limit,
                'offset' => $offset,
                'nextOffset' => $next < $total ? $next : null,
                'rules' => array_map(static fn(array $row): array => [
                    'ruleId' => (string)$row['ruleId'],
                    'title' => (string)$row['title'],
                    'wcagCriterion' => ToolOutput::orNull($row['wcagCriterion'] ?? null),
                    'wcagLevel' => ToolOutput::orNull($row['wcagLevel'] ?? null),
                    'severity' => (string)$row['severity'],
                    'owner' => (string)$row['responsibility'],
                    'difficulty' => (string)$row['difficulty'],
                    'occurrences' => (int)$row['occurrences'],
                    'pages' => (int)$row['pages'],
                    'templateLevel' => (bool)$row['templateLevel'],
                    'pointsToGain' => (float)$row['pointsToGain'],
                ], $slice),
            ];
        });
    }

    /**
     * One rule across a site: what it checks, how to fix it, examples and the
     * pages it is open on.
     *
     * @param string $ruleId The rule ID, as accessibility_audit_rules_list or accessibility_audit_page_issues returns it.
     * @param string|null $site Site handle. Defaults to the primary site.
     * @param int $pagesLimit Most affected pages to return.
     * @param int $pagesOffset Affected pages to skip. Pass pages.nextOffset from the previous call unchanged.
     * @param int $examplesLimit Most example occurrences to return, taken from the pages returned.
     * @return array<string, mixed>
     * @throws ToolCallException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    #[McpTool(
        name: 'accessibility_audit_rule_get',
        title: 'Get an accessibility rule',
        description: 'One accessibility rule across the site: what it checks, how to fix it, example markup it was found in, and the pages it is open on with links to edit each. A rule with nothing left open returns open: false, which is how to confirm a fix took.',
        annotations: new ToolAnnotations(title: 'Get an accessibility rule', readOnlyHint: true, openWorldHint: false),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission('accessPlugin-accessibility-audit')]
    #[RequiresPermission('accessibility-audit:view-reports')]
    public function ruleGet(
        #[Schema(minLength: 1, maxLength: 100)]
        string $ruleId,
        ?string $site = null,
        #[Schema(minimum: 1, maximum: 25)]
        int $pagesLimit = 10,
        #[Schema(minimum: 0)]
        int $pagesOffset = 0,
        #[Schema(minimum: 0, maximum: 20)]
        int $examplesLimit = 5,
    ): array {
        return $this->_run(self::VIEW_REPORTS, function() use ($ruleId, $site, $pagesLimit, $pagesOffset, $examplesLimit): array {
            $ruleId = trim($ruleId);

            if ($ruleId === '' || mb_strlen($ruleId) > 100) {
                throw new ToolCallException('Pass a rule ID of 1 to 100 characters.');
            }

            self::_range('pagesLimit', $pagesLimit, 1, 25);
            self::_range('pagesOffset', $pagesOffset, 0, PHP_INT_MAX);
            self::_range('examplesLimit', $examplesLimit, 0, 20);

            if ($pagesOffset % $pagesLimit !== 0) {
                throw new ToolCallException('pagesOffset must be a multiple of pagesLimit. Pass pages.nextOffset back unchanged, with the same pagesLimit.');
            }

            $target = $this->_site($site);
            $siteId = (int)$target->id;
            $plugin = AccessibilityAudit::getInstance();
            $audit = $plugin->getAudit();
            $summary = $audit->getIssueRuleSummary($ruleId, $siteId);
            $pageCount = (int)($summary['pageCount'] ?? 0);
            $meta = RuleRegistry::get($ruleId);
            $title = RuleRegistry::title($ruleId);
            $description = RuleRegistry::description($ruleId);

            $listing = $summary !== []
                ? $audit->getPagesForRule($ruleId, $siteId, intdiv($pagesOffset, $pagesLimit) + 1, $pagesLimit)
                : ['total' => 0, 'entries' => []];
            $entries = $listing['entries'];
            $byScan = $examplesLimit > 0
                ? $audit->getRuleOccurrencesForScans($ruleId, array_column($entries, 'id'), $examplesLimit)
                : [];

            $pages = [];
            $urls = [];

            foreach ($entries as $entry) {
                $scanId = (int)$entry['id'];
                $page = ToolOutput::page($entry, $entry['element'], $target);
                $urls[$scanId] = $page['url'];
                $pages[] = $page + [
                    'score' => (int)$entry['score'],
                    'occurrences' => (int)$entry['occurrences'],
                ];
            }

            $next = $pagesOffset + count($pages);

            return ToolOutput::withNotice([
                'site' => ToolOutput::site($target),
                'open' => $summary !== [],
                'rule' => [
                    'ruleId' => $ruleId,
                    'title' => $title,
                    'description' => $description,
                    'howToFix' => RuleRegistry::fix($ruleId),
                    'helpUrl' => ToolOutput::orNull($summary['helpUrl'] ?? null),
                    'wcagCriterion' => ToolOutput::orNull($summary['wcagCriterion'] ?? null),
                    'wcagLevel' => ToolOutput::orNull($summary['wcagLevel'] ?? null),
                    'severity' => ToolOutput::orNull($summary['severity'] ?? null),
                    'owner' => $meta['responsibility'],
                    'difficulty' => $meta['difficulty'],
                    'abilities' => $meta['abilities'],
                    'relatedPluginRule' => RuleRegistry::pluginRuleFor($ruleId),
                    'ignored' => in_array($ruleId, $plugin->getSettings()->ignoreRules, true),
                    'occurrences' => (int)($summary['occurrences'] ?? 0),
                    'pages' => $pageCount,
                    'templateLevel' => self::_isTemplateLevel($pageCount),
                    'firstDetected' => ToolOutput::date($summary['firstDetected'] ?? null),
                    'pointsToGain' => (float)($summary['pointsToGain'] ?? 0),
                ],
                'examples' => self::_examples($byScan, $urls, $examplesLimit, $title, $description),
                'pages' => [
                    'total' => (int)$listing['total'],
                    'count' => count($pages),
                    'limit' => $pagesLimit,
                    'offset' => $pagesOffset,
                    'nextOffset' => $next < (int)$listing['total'] ? $next : null,
                    'items' => $pages,
                ],
            ]);
        });
    }

    /**
     * The open issues on one scanned page.
     *
     * @param int|null $elementId The ID of the entry, category or other element whose page to read. A draft ID reads the element it belongs to. Pass this or url, not both.
     * @param string|null $url The page's URL, absolute or a path on the site such as /about. Pass this or elementId, not both.
     * @param string|null $site Site handle. Defaults to the primary site.
     * @param int $examplesPerRule Most example occurrences to return per rule.
     * @return array<string, mixed>
     * @throws ToolCallException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    #[McpTool(
        name: 'accessibility_audit_page_issues',
        title: 'Get a page\'s accessibility issues',
        description: "The open accessibility issues on one page from its latest scan, one row per rule, with how to fix each and example markup. Name the page by elementId or url. Call it after accessibility_audit_page_rescan to check a fix.",
        annotations: new ToolAnnotations(title: 'Get a page\'s accessibility issues', readOnlyHint: true, openWorldHint: false),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission('accessPlugin-accessibility-audit')]
    #[RequiresPermission('accessibility-audit:view-reports')]
    public function pageIssues(
        #[Schema(minimum: 1)]
        ?int $elementId = null,
        #[Schema(maxLength: 2048)]
        ?string $url = null,
        ?string $site = null,
        #[Schema(minimum: 0, maximum: 10)]
        int $examplesPerRule = 3,
    ): array {
        return $this->_run(self::VIEW_REPORTS, function() use ($elementId, $url, $site, $examplesPerRule): array {
            self::_range('examplesPerRule', $examplesPerRule, 0, 10);
            self::_checkTarget($elementId, $url);

            $target = $this->_site($site);
            $plugin = AccessibilityAudit::getInstance();
            $audit = $plugin->getAudit();
            $page = PageResolver::resolve($elementId, $url, $target);
            $scan = PageResolver::latestScan($page, $target);

            if ($scan === null) {
                throw new ToolCallException('This page has not been scanned. Call accessibility_audit_page_rescan to scan it.');
            }

            $scanId = (int)$scan['id'];
            $pageCounts = $audit->getRulePageCounts((int)$target->id);
            $rows = RuleRows::foldByRule($audit->getIssuesGroupedByScan($scanId));
            $rules = [];

            foreach (array_slice($rows, 0, self::MAX_PAGE_RULES) as $row) {
                $ruleId = (string)$row['ruleId'];
                $title = (string)$row['title'];
                $description = RuleRegistry::description($ruleId);
                $occurrences = $examplesPerRule > 0
                    ? $audit->getRuleOccurrencesForScans($ruleId, [$scanId], $examplesPerRule)
                    : [];

                $rules[] = [
                    'ruleId' => $ruleId,
                    'title' => $title,
                    'severity' => (string)$row['severity'],
                    'wcagCriterion' => ToolOutput::orNull($row['wcagCriterion'] ?? null),
                    'wcagLevel' => ToolOutput::orNull($row['wcagLevel'] ?? null),
                    'owner' => (string)$row['responsibility'],
                    'occurrences' => (int)$row['occurrences'],
                    'templateLevel' => self::_isTemplateLevel($pageCounts[$ruleId] ?? 0),
                    'howToFix' => RuleRegistry::fix($ruleId),
                    'helpUrl' => ToolOutput::orNull($row['helpUrl'] ?? null),
                    'examples' => self::_examples($occurrences, [], $examplesPerRule, $title, $description),
                ];
            }

            return ToolOutput::withNotice([
                'site' => ToolOutput::site($target),
                'page' => ToolOutput::page($scan, $page['element'], $target) + [
                    'score' => ToolOutput::score($scan),
                    'counts' => ToolOutput::counts($scan),
                ],
                'browserChecksAvailable' => $plugin->getHeadless()->isAvailable(),
                'pendingReview' => count($audit->getPendingPotentialForScan($scanId)),
                'rules' => $rules,
                'truncated' => count($rows) > self::MAX_PAGE_RULES,
            ]);
        });
    }

    /**
     * Re-scans one page.
     *
     * @param int|null $elementId The ID of the entry, category or other element whose page to scan. A draft ID scans the element it belongs to. Pass this or url, not both.
     * @param string|null $url The page's URL, absolute or a path on the site such as /about. A URL with no element behind it must be one the site already scans. Pass this or elementId, not both.
     * @param string|null $site Site handle. Defaults to the primary site.
     * @return array<string, mixed>
     * @throws ToolCallException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    #[McpTool(
        name: 'accessibility_audit_page_rescan',
        title: 'Re-scan a page for accessibility issues',
        description: "Fetches one page of the site and scans it again, replacing its results. Name the page by elementId or url. Browser checks (contrast, focus, target size) are queued and land when the site's queue runs, so a result with final: false will change.",
        annotations: new ToolAnnotations(
            title: 'Re-scan a page for accessibility issues',
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission('accessPlugin-accessibility-audit')]
    #[RequiresPermission('accessibility-audit:run-scans')]
    public function pageRescan(
        #[Schema(minimum: 1)]
        ?int $elementId = null,
        #[Schema(maxLength: 2048)]
        ?string $url = null,
        ?string $site = null,
    ): array {
        return $this->_run(self::RUN_SCANS, function() use ($elementId, $url, $site): array {
            self::_checkTarget($elementId, $url);

            $target = $this->_site($site);
            $siteId = (int)$target->id;

            if (RateLimit::exceeded('mcp-rescan', self::RESCAN_RATE_LIMIT, self::RESCAN_RATE_WINDOW)) {
                throw new ToolCallException('Re-scan limit reached: ' . self::RESCAN_RATE_LIMIT . ' re-scans per user every ' . intdiv(self::RESCAN_RATE_WINDOW, 60) . ' minutes. Wait a few minutes and try again.');
            }

            $plugin = AccessibilityAudit::getInstance();
            $audit = $plugin->getAudit();
            $page = PageResolver::resolve($elementId, $url, $target);
            $element = $page['element'];

            $result = $element !== null
                ? $this->_scanElement($audit, $element, $target)
                : $this->_scanUrl($audit, (string)$page['url'], $target);

            $scan = $audit->getScan((int)$result['scanId'], $siteId);

            if ($scan === null) {
                throw new ToolCallException('The page was scanned but its result could not be read back. Call accessibility_audit_page_issues to read it.');
            }

            $queued = $plugin->getHeadless()->isAvailable();

            return ToolOutput::withNotice([
                'site' => ToolOutput::site($target),
                'page' => ToolOutput::page($scan, $element, $target),
                'score' => ToolOutput::score($scan),
                'counts' => ToolOutput::counts($scan),
                'browserChecks' => $queued ? 'queued' : 'unavailable',
                'final' => !$queued,
                'next' => $queued
                    ? 'Browser checks for contrast, focus and target size are queued and update this page when the queue runs. Call accessibility_audit_page_issues now for the server-side findings, and again later for the full picture.'
                    : 'These results are final. Call accessibility_audit_page_issues to see what is still open on the page.',
            ]);
        });
    }

    // Private Methods
    // =========================================================================

    /**
     * Runs a tool body after the edition and permission checks, in English.
     *
     * @template T
     * @param string $permission The plugin permission the tool needs.
     * @param callable(): T $body The tool body.
     * @return T What the body returns.
     * @throws ToolCallException Where the edition or the user's permissions
     *         do not allow the tool, or the body refuses the call.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _run(string $permission, callable $body): mixed
    {
        $plugin = AccessibilityAudit::getInstance();

        if (!$plugin->isStandard()) {
            throw new ToolCallException("Accessibility Audit's MCP tools need the Standard or Pro edition. This site runs Lite.");
        }

        $user = Craft::$app->getUser();

        foreach (['accessCp', 'accessPlugin-accessibility-audit', $permission] as $required) {
            if (!$user->checkPermission($required)) {
                throw new ToolCallException("This tool needs the \"{$required}\" permission, which the signed-in user does not have.");
            }
        }

        try {
            return InLanguage::run('en', $body);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (Throwable $e) {
            // The message stays in the log: it can carry paths and SQL that
            // have no business reaching the client.
            Craft::error(sprintf('MCP tool failed: %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()), 'accessibility-audit');
            throw new ToolCallException('The tool failed on the server. The error is in the Accessibility Audit log.');
        }
    }

    /**
     * The site a call is scoped to.
     *
     * Refused rather than retargeted: a handle the edition or the user cannot
     * reach is an error naming the ones they can, never a quiet fall back to
     * the primary site.
     *
     * @param string|null $handle The site handle, or null for the primary site.
     * @return Site
     * @throws ToolCallException Where the site is unknown or not allowed.
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _site(?string $handle): Site
    {
        $plugin = AccessibilityAudit::getInstance();
        $sites = Craft::$app->getSites();
        $allowed = $plugin->allowedSites();
        $handles = implode(', ', array_map(static fn(Site $s): string => (string)$s->handle, $allowed));
        $handle = $handle !== null ? trim($handle) : '';

        if ($allowed === []) {
            throw new ToolCallException('The signed-in user cannot edit any site, so there are no reports to read.');
        }

        if ($handle === '') {
            $primary = $sites->getPrimarySite();

            foreach ($allowed as $site) {
                if ((int)$site->id === (int)$primary->id) {
                    return $site;
                }
            }

            throw new ToolCallException("Pass a site handle. The signed-in user can use: {$handles}.");
        }

        foreach ($allowed as $site) {
            if ($site->handle === $handle) {
                return $site;
            }
        }

        if (!$plugin->isPro() && $sites->getSiteByHandle($handle) !== null) {
            throw new ToolCallException("Sites other than the primary site need the Pro edition. Use: {$handles}.");
        }

        throw new ToolCallException("Unknown or unavailable site \"{$handle}\". Use one of: {$handles}.");
    }

    /**
     * Scans an element's page, the way the control panel's Re-scan does.
     *
     * @param AuditService $audit The audit service.
     * @param ElementInterface $element The element, resolved to its canonical.
     * @param Site $site The site.
     * @return array<string, mixed> The scan result, with a scan ID.
     * @throws ToolCallException Where the element cannot be scanned.
     * @throws Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _scanElement(AuditService $audit, ElementInterface $element, Site $site): array
    {
        // Scanning stores what is on the page for anyone with the reports to
        // read, so it is limited to elements this user can view. One answer
        // for both, so a refusal does not say the element exists.
        if (!Craft::$app->getElements()->canView($element)) {
            throw new ToolCallException("No element with ID {$element->id} was found on the {$site->handle} site.");
        }

        if (ElementHelper::isDraftOrRevision($element)) {
            throw new ToolCallException("Element {$element->id} is an unpublished draft. Only live pages can be scanned.");
        }

        return self::_scanned($audit->scanElement($element), "Element {$element->id} has no URL on the {$site->handle} site, so there is no page to scan.");
    }

    /**
     * Scans a page with no element behind it, once it is known to the site.
     *
     * @param AuditService $audit The audit service.
     * @param string $url The absolute URL.
     * @param Site $site The site.
     * @return array<string, mixed> The scan result, with a scan ID.
     * @throws ToolCallException Where the URL is not one the site scans, or
     *         the scan did not store a result.
     * @throws Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _scanUrl(AuditService $audit, string $url, Site $site): array
    {
        // The fetch happens server-side, so an address taken on trust would
        // make the site a proxy for reaching whatever the server can reach.
        if (!$audit->isKnownScanUrl($url, (int)$site->id)) {
            throw new ToolCallException('That URL is not one this site scans. Only pages with an element, URLs listed under Settings > Scanning, and URLs already scanned for this site can be re-scanned.');
        }

        return self::_scanned($audit->scanUrl($url, (int)$site->id), 'The page could not be scanned.');
    }

    /**
     * A scan result checked for a stored scan.
     *
     * @param array<string, mixed> $result What the scan returned.
     * @param string $fallback What to say where nothing was stored and the
     *                         scan gave no reason.
     * @return array<string, mixed> The result.
     * @throws ToolCallException Where nothing was stored.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _scanned(array $result, string $fallback): array
    {
        if (!empty($result['excluded'])) {
            throw new ToolCallException('This page is excluded from scanning in the plugin settings (Settings > Scanning).');
        }

        if (!empty($result['limitReached'])) {
            throw new ToolCallException("This edition's page limit has been reached, so a page not scanned before cannot be scanned.");
        }

        if (!empty($result['error'])) {
            throw new ToolCallException((string)$result['error']);
        }

        if ((int)($result['scanId'] ?? 0) === 0) {
            throw new ToolCallException($fallback);
        }

        return $result;
    }

    /**
     * Examples of a rule, a round at a time across the pages and with repeats
     * dropped.
     *
     * A rule from a shared template finds the same markup on every page, and
     * one copy of it says all there is to say.
     *
     * @param array<int, array<int, array{message: string, markup: string, selector: string}>> $byScan
     *        Occurrences keyed by scan ID.
     * @param array<int, string|null> $urls Each scan's page URL, keyed by scan
     *        ID, where the examples come from more than one page.
     * @param int $limit Most examples to return.
     * @param string $title The rule's title, in English.
     * @param string|null $description The rule's description, in English.
     * @return array<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _examples(array $byScan, array $urls, int $limit, string $title, ?string $description): array
    {
        $examples = [];

        for ($round = 0; $round < $limit && count($examples) < $limit; $round++) {
            foreach ($byScan as $scanId => $occurrences) {
                $occurrence = $occurrences[$round] ?? null;

                if ($occurrence === null) {
                    continue;
                }

                $key = $occurrence['markup'] . '|' . $occurrence['selector'];

                if (isset($examples[$key])) {
                    continue;
                }

                $example = $urls !== [] ? ['pageUrl' => $urls[$scanId] ?? null] : [];
                $example[ToolOutput::UNTRUSTED_KEY] = ToolOutput::untrusted([
                    'markup' => FixPrompt::snippet($occurrence['markup']),
                    'selector' => $occurrence['selector'],
                    'finding' => RuleRegistry::exampleFinding($occurrence['message'], $title, $description),
                ]);
                $examples[$key] = $example;
            }
        }

        return array_slice(array_values($examples), 0, $limit);
    }

    /**
     * Refuses both or neither of the two ways of naming a page.
     *
     * @param int|null $elementId The element ID passed.
     * @param string|null $url The URL passed.
     * @return void
     * @throws ToolCallException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _checkTarget(?int $elementId, ?string $url): void
    {
        if ($elementId !== null && $elementId < 1) {
            throw new ToolCallException('elementId must be 1 or more.');
        }

        if ($url !== null && mb_strlen($url) > 2048) {
            throw new ToolCallException('url must be 2048 characters or fewer.');
        }

        if (($elementId === null) === ($url === null || trim($url) === '')) {
            throw new ToolCallException('Pass exactly one of elementId or url.');
        }
    }

    /**
     * Refuses a value outside a fixed list.
     *
     * The server checks arguments against the schema before a tool runs; this
     * holds the same line for a call that does not go through it.
     *
     * @param string $name The parameter's name.
     * @param string|null $value The value, or null where it was not passed.
     * @param string[] $allowed The values allowed.
     * @return void
     * @throws ToolCallException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _oneOf(string $name, ?string $value, array $allowed): void
    {
        if ($value !== null && !in_array($value, $allowed, true)) {
            throw new ToolCallException("{$name} must be one of: " . implode(', ', $allowed) . '.');
        }
    }

    /**
     * Refuses a number outside a range.
     *
     * @param string $name The parameter's name.
     * @param int $value The value.
     * @param int $min The smallest allowed.
     * @param int $max The largest allowed.
     * @return void
     * @throws ToolCallException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _range(string $name, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new ToolCallException($max === PHP_INT_MAX
                ? "{$name} must be {$min} or more."
                : "{$name} must be between {$min} and {$max}.");
        }
    }

    /**
     * Whether a rule's row sits at or under a WCAG level, or is best practice.
     *
     * @param array<string, mixed> $row A rule row.
     * @param string $level One of self::LEVELS.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _matchesLevel(array $row, string $level): bool
    {
        $criterion = trim((string)($row['wcagCriterion'] ?? ''));

        if ($level === 'best-practice') {
            return $criterion === '';
        }

        $levels = array_slice(self::WCAG_LEVELS, 0, (int)array_search($level, self::WCAG_LEVELS, true) + 1);

        return $criterion !== '' && in_array((string)($row['wcagLevel'] ?? ''), $levels, true);
    }

    /**
     * Whether a rule is open on enough pages to read as template-level.
     *
     * @param int $pages Pages the rule is open on.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _isTemplateLevel(int $pages): bool
    {
        return $pages >= AuditService::TEMPLATE_PAGE_THRESHOLD;
    }
}
