<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\helpers\AdminTable;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\assets\PageReportAsset;
use johnhenry\accessibilityaudit\assets\StatementAsset;
use johnhenry\accessibilityaudit\assets\UtilitiesAsset;
use johnhenry\accessibilityaudit\assets\VpatAsset;
use johnhenry\accessibilityaudit\helpers\AffectedExample;
use johnhenry\accessibilityaudit\helpers\Csv;
use johnhenry\accessibilityaudit\helpers\ElementLabel;
use johnhenry\accessibilityaudit\helpers\OccurrenceCluster;
use johnhenry\accessibilityaudit\helpers\ScanTarget;
use johnhenry\accessibilityaudit\models\StatementExclusionModel;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\RuleRegistry;
use johnhenry\accessibilityaudit\services\StatementProfiles;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Renders the Accessibility Audit CP dashboard, issue reports, and detail pages.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class DashboardController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Rows per page for the issue and page listings.
     *
     * One number, used for both the server-rendered first page and the
     * per_page fallback the table endpoints answer with. These were 10, 25 and
     * 50 depending on which you hit, and a client asking for one size against a
     * server assuming another draws a page count that does not match its rows.
     */
    private const TABLE_PER_PAGE = 100;

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws \yii\base\Exception
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $sites = $plugin->allowedSites();

        $summary = $plugin->audit->getSiteSummary($siteId);

        // Compute totals from the whole site, not the top-10 slice, so the
        // severity-band totals and subhead match the Issues page.
        $byImpactAll = $plugin->audit->getIssuesByImpact($siteId, PHP_INT_MAX);
        $byImpact = array_slice($byImpactAll, 0, 10);
        $templateIssues = $plugin->audit->getTemplateIssues($siteId);
        $resolvedIssues = $plugin->audit->getResolvedIssues($siteId, 10);
        $scoreHistory = array_reverse($plugin->audit->getScoreHistory($siteId, 30));
        $settings = $plugin->getSettings();

        $isPro = $plugin->isPro();

        // Standard's scan cap counts distinct elements across the whole install
        // (all sites), matching Craft's plugin licensing. Only show the "across
        // all sites" wording on multi-site installs.
        $isMultiSite = count($sites) > 1;

        return $this->renderTemplate('accessibility-audit/index', [
            'summary' => $summary,
            'byImpact' => $byImpact,
            'byImpactAll' => $byImpactAll,
            'templateIssues' => $templateIssues,
            'resolvedIssues' => $resolvedIssues,
            'scoreHistory' => $scoreHistory,
            'siteId' => $siteId,
            'siteHandle' => $siteHandle,
            'sites' => $sites,
            'targetScore' => $settings->targetScore,
            'wcagLevel' => strtoupper((string)($settings->wcagLevel ?? 'AA')),
            // Drives the EN 301 549 note under the conformance scores. Derived
            // from the WCAG level, not axe tags: clause 9 of the standard restates
            // WCAG 2.1 AA, so the level is the reliable signal for every issue.
            'en301549' => (bool)($settings->en301549 ?? false),
            'assetStats' => $plugin->getAssets()->getStoredAssetStats(),
            'isPro' => $isPro,
            'isMultiSite' => $isMultiSite,
            'scannedElementCount' => $isPro ? 0 : $plugin->audit->getScannedElementCount(),
            'scanLimit' => AuditService::STANDARD_SCAN_LIMIT,
        ]);
    }

    /**
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function actionIssues(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $activeTab = $this->request->getQueryParam('tab', 'issues');
        $page = max(1, (int) ($this->request->getQueryParam('page') ?? 1));
        $perPage = self::TABLE_PER_PAGE;

        $result = $plugin->audit->getScannedElements($siteId, $page, $perPage);
        // The Issues tab is the full list, so no rule cap: a silently
        // truncated "full list" would contradict the dashboard.
        $byRule = $plugin->audit->getIssuesByImpact($siteId, PHP_INT_MAX);
        $resolvedByRule = $plugin->audit->getResolvedIssuesByImpact($siteId);
        $sites = $plugin->allowedSites();
        $totalPages = (int) ceil($result['total'] / $perPage);

        // The remediation-over-time chart is only rendered on the Resolved tab,
        // so its data is fetched only then (the range persists via ?range=).
        $resolvedTrendRange = max(1, min(365, (int) ($this->request->getQueryParam('range') ?? 90)));
        $resolvedTrend = $activeTab === 'resolved'
            ? $plugin->audit->getResolvedTrend($siteId, $resolvedTrendRange)
            : [];

        return $this->renderTemplate('accessibility-audit/issues', [
            'result' => $result,
            'byRule' => $byRule,
            'resolvedByRule' => $resolvedByRule,
            'resolvedTrend' => $resolvedTrend,
            'resolvedTrendRange' => $resolvedTrendRange,
            'siteId' => $siteId,
            'siteHandle' => $siteHandle,
            'sites' => $sites,
            'page' => $page,
            'perPage' => $perPage,
            'activeTab' => in_array($activeTab, ['issues', 'pages', 'resolved'], true) ? $activeTab : 'issues',
            'pageInfo' => $this->_pageInfo('accessibility-audit/issues', $page, $perPage, (int) $result['total'], $totalPages, ['site' => $siteHandle, 'tab' => 'pages']),
        ]);
    }

    /**
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     */
    public function actionPageReport(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $plugin = AccessibilityAudit::getInstance();
        $elementId = (int)($this->request->getQueryParam('elementId') ?? 0);
        $scanId = (int)($this->request->getQueryParam('scanId') ?? 0);
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;

        if ($elementId === 0 && $scanId === 0) {
            return $this->redirect(UrlHelper::cpUrl('accessibility-audit/issues'));
        }

        $audit = $plugin->audit;

        // A page with no element behind it is addressed by scan ID, and the
        // scan is loaded scoped to the requested site so an ID from another
        // site is a miss rather than a way across the fence. Its URL is then
        // what the report follows, so a rescan lands on the newest scan of the
        // same page rather than on the one the link was made from.
        if ($elementId === 0) {
            $scan = $audit->getScan($scanId, $siteId);

            if ($scan === null) {
                return $this->redirect(UrlHelper::cpUrl('accessibility-audit/issues'));
            }

            if (!empty($scan['elementId'])) {
                return $this->redirect(UrlHelper::cpUrl('accessibility-audit/page-report', [
                    'elementId' => (int)$scan['elementId'],
                    'site' => $siteHandle,
                ]));
            }

            $scan = $audit->getLatestUrlScan((string)$scan['url'], $siteId) ?? $scan;
        } else {
            $scan = $audit->getLatestScan($elementId, $siteId);
        }

        $scanUrl = $scan !== null ? (string)($scan['url'] ?? '') : '';
        $issues = $scan ? $audit->getIssuesGroupedByScan((int)$scan['id']) : [];
        $resolved = $scanUrl !== ''
            ? $audit->getResolvedIssuesForUrl($scanUrl, $siteId)
            : $audit->getResolvedIssuesForElement($elementId, $siteId);

        // Potential issues awaiting the author's answer, shown here (not just on
        // the Potential page) because the page markup is what settles the answer.
        // Grouped by rule so each question is asked once, with its occurrences
        // listed underneath by context.
        $potential = [];
        $dismissed = [];
        if ($scan) {
            // The page is measured at both widths, and a question can come
            // from one of them only: a decoration that clears the text column
            // on a wide screen sits behind it on a narrow one. Those arrive as
            // two rows differing by viewport alone, so they are folded into one
            // question carrying both, and a reader looking at the desktop
            // preview is told when what they are being asked about is not there.
            $seen = [];

            foreach ($audit->getPendingPotentialForScan((int)$scan['id']) as $row) {
                $ruleId = $row['ruleId'];
                $potential[$ruleId] ??= [
                    'ruleId' => $ruleId,
                    'question' => $this->_potentialQuestion($ruleId),
                    'occurrences' => [],
                ];

                $key = (string)($row['context'] ?? '') . '|' . $row['message'];
                $at = $seen[$ruleId][$key] ?? null;

                if ($at !== null) {
                    $potential[$ruleId]['occurrences'][$at]['viewports'][] = (string)($row['viewport'] ?? '');
                    $potential[$ruleId]['occurrences'][$at]['viewportLabel'] = $this->_viewportLabel(
                        $potential[$ruleId]['occurrences'][$at]['viewports'],
                    );
                    continue;
                }

                $row['viewports'] = [(string)($row['viewport'] ?? '')];
                $row['viewportLabel'] = $this->_viewportLabel($row['viewports']);
                $seen[$ruleId][$key] = count($potential[$ruleId]['occurrences']);
                $potential[$ruleId]['occurrences'][] = $row;
            }

            // Repeated markup is gathered into one thing to answer. A rule
            // that fires per element on a repeated component otherwise asks
            // the same question dozens of times over contexts that read
            // identically, which is a queue nobody works through.
            foreach ($potential as $ruleId => $group) {
                $potential[$ruleId]['clusters'] = OccurrenceCluster::group($group['occurrences']);
            }

            foreach ($audit->getDismissedPotentialForScan((int)$scan['id']) as $row) {
                $row['question'] = $this->_potentialQuestion($row['ruleId']);
                $dismissed[] = $row;
            }
        }
        $potential = array_values($potential);

        $element = $elementId !== 0
            ? Craft::$app->getElements()->getElementById($elementId, null, $siteId)
            : null;
        $sites = $plugin->allowedSites();

        $pageLabel = $scan !== null
            ? ScanTarget::label($scan, $element)
            : ElementLabel::for($element, $elementId);

        $view = Craft::$app->getView();
        $view->registerAssetBundle(PageReportAsset::class);

        // Everything page-report.js needs from the server. Merged onto the
        // plugin-wide window.AccessibilityAudit (see AccessibilityAudit.php) and
        // registered at POS_HEAD so it exists before the bundle's JS runs.
        $view->registerJs(
            'window.AccessibilityAudit=Object.assign(window.AccessibilityAudit||{},{pageReport:' .
            Json::encode([
                'scanId' => (int)($scan['id'] ?? 0),
                'elementId' => $elementId,
                'pageUrl' => $scanUrl,
                'siteId' => $siteId,
                'elementType' => $element !== null ? get_class($element) : '',
                'axeUrl' => Craft::$app->getAssetManager()->getPublishedUrl(
                    '@johnhenry/accessibilityaudit/resources',
                    true,
                    'axe/axe.min.js',
                ),
                'storeAxeUrl' => UrlHelper::actionUrl('accessibility-audit/audit/store-axe-results'),
                // Compared against a freshly computed axe pass to decide whether
                // the stored counts are stale; -1 stands for "never scanned".
                'current' => [
                    (int)($scan['score'] ?? -1),
                    (int)($scan['errorCount'] ?? -1),
                    (int)($scan['warningCount'] ?? -1),
                    (int)($scan['noticeCount'] ?? -1),
                ],
            ]) . '});',
            View::POS_HEAD,
        );

        return $this->renderTemplate('accessibility-audit/page-report', [
            'element' => $element,
            'elementId' => $elementId,
            'pageUrl' => $scan !== null ? ScanTarget::url($scan, $element) : $element?->getUrl(),
            'pageLabel' => $pageLabel,
            'scan' => $scan,
            'issues' => $issues,
            'resolved' => $resolved,
            'potential' => $potential,
            'dismissed' => $dismissed,
            'potentialCount' => array_sum(array_map(static fn(array $g): int => count($g['occurrences']), $potential)),
            'canRunScans' => Craft::$app->getUser()->checkPermission('accessibility-audit:runScans'),
            'siteId' => $siteId,
            'siteHandle' => $siteHandle,
            'sites' => $sites,
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     */
    public function actionPageRuleOccurrences(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $scanId = (int)($this->request->getQueryParam('scanId') ?? 0);
        $ruleId = trim((string)($this->request->getQueryParam('ruleId') ?? ''));

        if ($scanId === 0 || $ruleId === '') {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Invalid parameters.')]);
        }

        if (($refusal = $this->_requireAllowedScanSite($scanId)) !== null) {
            return $refusal;
        }

        $occurrences = AccessibilityAudit::getInstance()->audit->getOccurrencesForRule($scanId, $ruleId);

        return $this->asJson(['success' => true, 'occurrences' => $occurrences]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     */
    public function actionPageIssues(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $scanId = (int)($this->request->getQueryParam('scanId') ?? 0);
        if ($scanId === 0) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Invalid scan ID.')]);
        }

        if (($refusal = $this->_requireAllowedScanSite($scanId)) !== null) {
            return $refusal;
        }

        $issues = AccessibilityAudit::getInstance()->audit->getIssuesGroupedByScan($scanId);

        return $this->asJson(['success' => true, 'issues' => $issues]);
    }

    /**
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws \yii\base\Exception
     */
    public function actionIssueDetail(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $plugin = AccessibilityAudit::getInstance();
        $ruleId = trim((string)($this->request->getQueryParam('ruleId') ?? ''));
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $page = max(1, (int)($this->request->getQueryParam('page') ?? 1));
        $perPage = self::TABLE_PER_PAGE;

        if ($ruleId === '') {
            return $this->redirect(UrlHelper::cpUrl('accessibility-audit/issues'));
        }

        $audit = $plugin->audit;
        $detail = $audit->getIssueRuleSummary($ruleId, $siteId);
        $pages = $audit->getPagesForRule($ruleId, $siteId, $page, $perPage);
        $meta = RuleRegistry::get($ruleId);
        $sites = $plugin->allowedSites();
        $totalPages = (int) ceil($pages['total'] / $perPage);

        // Initial state for the "Progress with this issue" trend chart. The
        // range toggle re-fetches from the rule-trend action; both default to
        // 90 days so the active button and the first-paint data line up.
        $trendRange = max(1, min(365, (int) ($this->request->getQueryParam('range') ?? 90)));
        $trend = $audit->getRuleTrend($ruleId, $siteId, $trendRange);

        return $this->renderTemplate('accessibility-audit/issue-detail', [
            'ruleId' => $ruleId,
            'meta' => $meta,
            'detail' => $detail,
            'pages' => $pages,
            'pageCount' => (int) $pages['total'],
            'siteId' => $siteId,
            'siteHandle' => $siteHandle,
            'sites' => $sites,
            'trend' => $trend,
            'trendRange' => $trendRange,
            'page' => $page,
            'perPage' => $perPage,
            'pageInfo' => $this->_pageInfo('accessibility-audit/issues/detail', $page, $perPage, (int) $pages['total'], $totalPages, ['ruleId' => $ruleId, 'site' => $siteHandle]),
        ]);
    }

    /**
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws \yii\base\Exception
     */
    public function actionPotential(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $activeTab = $this->request->getQueryParam('tab', 'issues');
        $page = max(1, (int) ($this->request->getQueryParam('page') ?? 1));
        $perPage = self::TABLE_PER_PAGE;

        $audit = $plugin->audit;
        $potentialRules = $audit->getPotentialIssues($siteId);
        $pages = $audit->getPagesWithPotentialIssues($siteId, $page, $perPage);
        $sites = $plugin->allowedSites();
        $totalPages = (int) ceil($pages['total'] / $perPage);

        return $this->renderTemplate('accessibility-audit/potential', [
            'potentialRules' => $potentialRules,
            'pages' => $pages,
            'siteId' => $siteId,
            'siteHandle' => $siteHandle,
            'sites' => $sites,
            'page' => $page,
            'perPage' => $perPage,
            'activeTab' => in_array($activeTab, ['issues', 'pages', 'dismissed'], true) ? $activeTab : 'issues',
            // Count only, for the tab label. The rows come from the table
            // endpoint like every other paginated listing.
            'dismissedTotal' => $plugin->audit->getDismissedPotential($siteId, 1, 1)['total'],
            'canRunScans' => Craft::$app->getUser()->checkPermission('accessibility-audit:runScans'),
            'pageInfo' => $this->_pageInfo('accessibility-audit/potential', $page, $perPage, (int) $pages['total'], $totalPages, ['site' => $siteHandle, 'tab' => 'pages']),
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     */
    public function actionUtilities(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        // utilities.js needs nothing from the server, so there's no config to
        // inject alongside it.
        Craft::$app->getView()->registerAssetBundle(UtilitiesAsset::class);

        // The contrast analyser reports per WCAG level, so it needs the target
        // to know which rows to leave out (AAA when the site targets AA).
        $settings = AccessibilityAudit::getInstance()->getSettings();

        // Which tool tab to render: an allow-list, defaulting to the first, so a
        // junk ?tab= can't reach the template.
        $tab = trim((string) ($this->request->getQueryParam('tab') ?? ''));
        if (!in_array($tab, ['contrast', 'simulator', 'grid'], true)) {
            $tab = 'contrast';
        }

        return $this->renderTemplate('accessibility-audit/utilities', [
            'wcagLevel' => strtoupper((string)($settings->wcagLevel ?? 'AA')),
            'activeTab' => $tab,
        ]);
    }

    /**
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws Exception
     * @throws \yii\base\Exception
     * @throws \Exception
     */
    public function actionVpat(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $sites = $plugin->allowedSites();

        // VPAT is Pro-only, but the page is reachable from the CP nav, so render
        // an upsell state on Standard instead of throwing. The report is only
        // built when it will actually be shown.
        $isPro = $plugin->isPro();
        $report = $isPro ? $plugin->vpat->getFullReport($siteId) : null;

        // AI remark drafting is only offered when an Anthropic API key is
        // configured; without one the button would just error.
        $settings = $plugin->getSettings();
        $canDraftRemarks = $isPro && trim(App::parseEnv($settings->anthropicApiKey ?? '')) !== '';

        // Pages the scanner has actually covered, offered to the editor's
        // "Fill from scanned pages" button so the Scope section starts from
        // evidence rather than memory.
        $scopeSuggestions = [];
        if ($isPro) {
            $scanned = $plugin->audit->getScannedElements($siteId, 1, 200);
            foreach ($scanned['entries'] as $row) {
                $element = $row['element'] ?? null;
                if ($element === null) {
                    continue;
                }
                $url = $element->getUrl();
                $scopeSuggestions[] = (string) $element . ($url ? " ({$url})" : '');
            }
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(VpatAsset::class);

        // Everything vpat.js needs from the server. The save message is
        // translated here rather than via Craft.t() in the JS: the plugin never
        // calls registerTranslations(), so Craft.t() would fall back to English.
        $view->registerJs(
            'window.AccessibilityAudit=Object.assign(window.AccessibilityAudit||{},{vpat:' .
            Json::encode([
                'siteId' => $siteId,
                'saveMetaUrl' => UrlHelper::actionUrl('accessibility-audit/vpat/save-meta'),
                'saveCriterionUrl' => UrlHelper::actionUrl('accessibility-audit/vpat/save-criterion'),
                'draftRemarkUrl' => UrlHelper::actionUrl('accessibility-audit/vpat/draft-remark'),
                'scopeSuggestions' => $scopeSuggestions,
                'savedMessage' => Craft::t('accessibility-audit', 'Product information saved.'),
            ]) . '});',
            View::POS_HEAD,
        );

        return $this->renderTemplate('accessibility-audit/vpat', [
            'isPro' => $isPro,
            'report' => $report,
            // Both this and the statement are derived from scan data, so the
            // date they were derived from belongs on screen beside them.
            'lastScanned' => $plugin->getAudit()->getLatestScanDate($siteId),
            'siteId' => $siteId,
            'siteHandle' => $siteHandle,
            'sites' => $sites,
            'canDraftRemarks' => $canDraftRemarks,
            'scopeSuggestions' => $scopeSuggestions,
        ]);
    }

    /**
     * Renders the accessibility statement editor.
     *
     * Viewing is gated on viewReports like every other report page; editing is
     * gated separately inside StatementController, so a reader can see what the
     * organisation has published without being able to change it.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws Exception
     * @throws \Exception
     */
    public function actionStatement(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $sites = $plugin->allowedSites();

        // Available on every edition: in the EU and UK a statement is legally
        // required, so it is not something to hold back for an upgrade.
        $statement = $plugin->statement->getFullStatement($siteId);
        $derivation = $plugin->statement->deriveComplianceStatus($siteId);
        $suggestions = $plugin->statement->deriveSuggestions($siteId);

        // Turn the bare criterion numbers into a readable checklist so a
        // reviewer can see which criteria still need a human eye, not just the
        // count. Unknown numbers are filtered out defensively.
        $unconfirmedDetails = array_values(array_filter(array_map(
            static fn(string $number): ?array => $plugin->vpat->criterionMeta($number),
            $derivation['unconfirmedCriteria'],
        )));

        $view = Craft::$app->getView();
        $view->registerAssetBundle(StatementAsset::class);

        $view->registerJs(
            'window.AccessibilityAudit=Object.assign(window.AccessibilityAudit||{},{statement:' .
            Json::encode([
                'siteId' => $siteId,
                'saveMetaUrl' => UrlHelper::actionUrl('accessibility-audit/statement/save-meta'),
                'saveExclusionsUrl' => UrlHelper::actionUrl('accessibility-audit/statement/save-exclusions'),
                'suggestionsUrl' => UrlHelper::actionUrl('accessibility-audit/statement/suggestions'),
                'savedMessage' => Craft::t('accessibility-audit', 'Statement saved.'),
                'refusedMessage' => Craft::t('accessibility-audit', 'Saved, but the full compliance claim was not applied: criteria remain failing or unconfirmed.'),
                'categories' => StatementExclusionModel::categories(),
            ]) . '});',
            View::POS_HEAD,
        );

        return $this->renderTemplate('accessibility-audit/statement', [
            'statement' => $statement,
            'derivation' => $derivation,
            'lastScanned' => $plugin->getAudit()->getLatestScanDate($siteId),
            'unconfirmedDetails' => $unconfirmedDetails,
            'suggestions' => $suggestions,
            // Criterion names, so an entry can label itself in the editor. The
            // label is chrome: the published statement is built from the
            // fields, and a criterion name is developer wording that has no
            // business in a document written for the public.
            'criteriaNames' => array_map(
                static fn(array $criterion): string => (string)($criterion['name'] ?? ''),
                $plugin->vpat->getCriteria(),
            ),
            // Placeholders, not values: shown greyed and never saved, so a
            // published statement can only ever carry what somebody wrote.
            'affectedExamples' => array_merge(
                array_map(
                    static fn(string $number): string => AffectedExample::for($number),
                    array_combine(
                        array_keys($plugin->vpat->getCriteria()),
                        array_keys($plugin->vpat->getCriteria()),
                    ),
                ),
                ['*' => AffectedExample::for('')],
            ),
            'profiles' => StatementProfiles::options(),
            'siteId' => $siteId,
            'siteHandle' => $siteHandle,
            'sites' => $sites,
            'canUseVpat' => $plugin->isPro(),
        ]);
    }

    /**
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws \yii\base\Exception
     */
    public function actionAssets(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();
        $page = max(1, (int) ($this->request->getQueryParam('page') ?? 1));
        $perPage = 100;

        // Volume filter: an unknown handle simply yields no matches, so it's
        // validated against the real volume list here rather than trusting the
        // query param, and dropped back to "all" when it doesn't resolve.
        // Excluded volumes are dropped from the list so you can't narrow to a
        // volume the audit ignores, which would only ever show nothing.
        $excludedVolumes = $settings->excludedVolumes ?? [];
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        if (!empty($excludedVolumes)) {
            $volumes = array_values(array_filter(
                $volumes,
                static fn($v) => !in_array($v->uid, $excludedVolumes, true),
            ));
        }
        $volume = trim((string) ($this->request->getQueryParam('volume') ?? ''));
        if ($volume !== '' && !ArrayHelper::firstWhere($volumes, 'handle', $volume)) {
            $volume = '';
        }

        $search = trim((string) ($this->request->getQueryParam('search') ?? ''));

        // Issue-type filter acts on the whole library server-side, so a chip's
        // count matches the rows it shows. Validated against the known asset
        // rules so a junk param can't reach the query.
        $filter = trim((string) ($this->request->getQueryParam('filter') ?? ''));
        if (!in_array($filter, ['asset-alt-missing', 'asset-alt-filename', 'asset-alt-short', 'pdf-accessibility', 'decorative'], true)) {
            $filter = '';
        }

        // Paginate: scanning every image on every render OOMs/times out on
        // large media libraries. The decorative filter lists images currently
        // marked decorative (from the flags table) instead of the issues list,
        // so a marked image can be reviewed and switched back off; every other
        // filter runs the issue scan.
        $paged = $filter === 'decorative'
            ? $plugin->assets->listDecorativePaged($page, $perPage, $volume ?: null, $search ?: null)
            : $plugin->assets->scanImagesPaged($page, $perPage, $volume ?: null, $search ?: null, $filter ?: null);
        $stats = $plugin->assets->getSiteAssetStats();

        // Preserve the volume filter, filename search, and issue-type filter
        // across pagination links.
        $extraParams = array_filter([
            'volume' => $volume,
            'search' => $search,
            'filter' => $filter,
        ], static fn(string $value): bool => $value !== '');

        return $this->renderTemplate('accessibility-audit/assets', [
            'assetIssues' => $paged['results'],
            'stats' => $stats,
            'storedStats' => $plugin->getAssets()->getStoredAssetStats(),
            'canRunScans' => Craft::$app->getUser()->checkPermission('accessibility-audit:runScans'),
            'page' => $paged['page'],
            'perPage' => $paged['perPage'],
            'total' => $paged['total'],
            'totalPages' => $paged['totalPages'],
            'pageInfo' => $this->_pageInfo('accessibility-audit/assets', $paged['page'], $paged['perPage'], $paged['total'], $paged['totalPages'], $extraParams),
            'hasApiKey' => !empty(trim(App::parseEnv($settings->anthropicApiKey ?? ''))),
            'volumes' => $volumes,
            'currentVolume' => $volume,
            'currentSearch' => $search,
            'activeFilter' => $filter,
            'decorativeCount' => $plugin->assets->getDecorativeCount($volume ?: null, $search ?: null),
        ]);
    }

    // ─── VueAdminTable endpoints ─────────────────────────────────────────────
    // These are AJAX endpoints, not navigable pages: their URLs are rebuilt on
    // every table load and never bookmarked, so they read an integer `siteId`
    // param via resolveSiteId() rather than the `site` handle.

    /**
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     */
    public function actionScannedPagesTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:viewReports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE));
        $search = trim((string)($this->request->getParam('search') ?? ''));

        $sortField = match ($this->request->getParam('sort.0.field')) {
            'page' => 'title',
            'issues' => 'issues',
            'lastScanned' => 'dateScanned',
            default => 'score',
        };
        $sortDir = $this->request->getParam('sort.0.direction') === 'desc' ? SORT_DESC : SORT_ASC;

        $result = AccessibilityAudit::getInstance()->audit->getScannedElements($siteId, $page, $limit, $search, $sortField, $sortDir);

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, (int)$result['total'], $limit),
            'data' => $this->_scannedPagesTableData($result['entries'], $siteId),
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     */
    public function actionScannedPagesExport(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));

        $site = Craft::$app->getSites()->getSiteById($siteId);
        $baseUrl = $site !== null ? rtrim((string)$site->getBaseUrl(), '/') : '';
        $rows = AccessibilityAudit::getInstance()->audit->getScannedElementsExport($siteId);

        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fputcsv($handle, ['Page', 'URL', 'Score', 'Errors', 'Warnings', 'Notices', 'Last scanned']);

        foreach ($rows as $row) {
            $uri = (string)($row['uri'] ?? '');
            $url = ($uri === '' || $uri === '__home__') ? $baseUrl : $baseUrl . '/' . $uri;
            $lastScanned = $row['dateScanned'] ? DateTimeHelper::toDateTime($row['dateScanned']) : false;

            fputcsv($handle, [
                Csv::guard((string)($row['title'] ?? ('Element #' . $row['elementId']))),
                Csv::guard($url),
                (int)$row['score'],
                (int)$row['errorCount'],
                (int)$row['warningCount'],
                (int)$row['noticeCount'],
                $lastScanned !== false ? $lastScanned->format('Y-m-d') : '',
            ]);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="accessibility-scanned-pages.csv"');
        $response->content = $csv;

        return $response;
    }

    /**
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws SiteNotFoundException
     * @throws \Exception
     */
    public function actionResolvedTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:viewReports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE));
        $sortDir = $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC;

        $rows = AccessibilityAudit::getInstance()->audit->getResolvedIssuesByImpact($siteId);

        // The list is bounded (one row per rule) and its points/owner columns are
        // derived after the query, so it is sorted and paged in PHP here.
        $key = match ($this->request->getParam('sort.0.field')) {
            'rule' => static fn(array $r): string => (string)$r['ruleId'],
            'occurrences' => static fn(array $r): int => (int)$r['occurrences'],
            'points' => static fn(array $r): float => (float)$r['pointsGained'],
            default => static fn(array $r): string => (string)($r['dateResolved'] ?? ''),
        };
        usort($rows, static function(array $a, array $b) use ($key, $sortDir): int {
            $cmp = $key($a) <=> $key($b);
            return $sortDir === SORT_ASC ? $cmp : -$cmp;
        });

        $total = count($rows);
        $paged = array_slice($rows, ($page - 1) * $limit, $limit);

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $this->_resolvedTableData($paged, $siteId),
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws SiteNotFoundException
     */
    public function actionResolvedTrend(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:viewReports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $range = max(1, min(365, (int)$this->request->getParam('range', 90)));

        return $this->asJson([
            'trend' => AccessibilityAudit::getInstance()->audit->getResolvedTrend($siteId, $range),
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     */
    public function actionRuleTrend(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:viewReports');

        $ruleId = trim((string)($this->request->getParam('ruleId') ?? ''));
        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $range = max(1, (int)$this->request->getParam('range', 90));

        return $this->asJson([
            'trend' => AccessibilityAudit::getInstance()->audit->getRuleTrend($ruleId, $siteId, $range),
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     */
    public function actionPotentialTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:viewReports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE));

        $sortField = match ($this->request->getParam('sort.0.field')) {
            'count' => 'occurrences',
            default => 'pageCount',
        };
        $asc = $this->request->getParam('sort.0.direction') === 'asc';

        $rows = AccessibilityAudit::getInstance()->audit->getPotentialIssues($siteId);
        usort($rows, static fn(array $a, array $b): int => $asc
            ? ((int)$a[$sortField] <=> (int)$b[$sortField])
            : ((int)$b[$sortField] <=> (int)$a[$sortField]));

        $total = count($rows);

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $this->_potentialTableData(array_slice($rows, ($page - 1) * $limit, $limit), $siteId),
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     */
    public function actionPotentialPagesTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:viewReports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE));
        $search = trim((string)($this->request->getParam('search') ?? ''));

        $sortField = match ($this->request->getParam('sort.0.field')) {
            'page' => 'title',
            'issues' => 'issues',
            'lastScanned' => 'dateScanned',
            default => 'occurrences',
        };
        $sortDir = $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC;

        $result = AccessibilityAudit::getInstance()->audit->getPagesWithPotentialIssues($siteId, $page, $limit, $search, $sortField, $sortDir);

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, (int)$result['total'], $limit),
            'data' => $this->_potentialPagesTableData($result['entries'], $siteId),
        ]);
    }

    /**
     * Table data for the Potential issues → Dismissed tab.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     */
    public function actionDismissedTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:viewReports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE));
        $search = trim((string)($this->request->getParam('search') ?? ''));

        $sortField = match ($this->request->getParam('sort.0.field')) {
            'page' => 'elementId',
            default => 'ruleId',
        };
        $sortDir = $this->request->getParam('sort.0.direction') === 'desc' ? SORT_DESC : SORT_ASC;

        $result = $plugin->audit->getDismissedPotential($siteId, $page, $limit, $search, $sortField, $sortDir);

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $result['total'], $limit),
            'data' => $this->_dismissedTableData($result['rows'], $siteId),
        ]);
    }

    /**
     * Shapes dismissed potential issues for the table.
     *
     * Each row carries what the restore action needs (element, rule, context),
     * so the button posts the same payload the page report's Restore does and
     * both routes clear a verdict the same way.
     *
     * @param array<int, array<string, mixed>> $rows Dismissed issue rows.
     * @param int $siteId The site being listed.
     * @return array<int, array<string, mixed>>
     */
    private function _dismissedTableData(array $rows, int $siteId): array
    {
        $data = [];
        $siteHandle = Craft::$app->getSites()->getSiteById($siteId)?->handle;

        // One query for the whole page of rows, not one per row. Keyed on the
        // target rather than the element, so a row from a page scanned by URL
        // finds its ruling too.
        $verdicts = AccessibilityAudit::getInstance()->verdicts;
        $targetHashes = array_map(
            static fn(array $r): string => AccessibilityAudit::getInstance()->verdicts->targetHash(
                $r['elementId'] !== null ? (int)$r['elementId'] : null,
                $r['url'] ?? null,
            ),
            $rows,
        );
        $meta = $verdicts->metaForTargets($targetHashes, $siteId);
        $formatter = Craft::$app->getFormatter();

        foreach ($rows as $index => $row) {
            $elementId = (int)$row['elementId'];
            $element = $elementId !== 0
                ? Craft::$app->getElements()->getElementById($elementId, null, $siteId)
                : null;
            $ruling = $verdicts->lookupMeta($meta, $targetHashes[$index], (string)$row['ruleId'], $row['context'] ?? null);
            $author = $ruling !== null && $ruling['userId'] !== null
                ? Craft::$app->getUsers()->getUserById($ruling['userId'])
                : null;

            $data[] = [
                'id' => (int)$row['id'],
                'page' => [
                    'title' => ScanTarget::label($row, $element),
                    // The row's own id is the issue's, so the scan id is put in
                    // its place before the target is read off it.
                    'inspectUrl' => UrlHelper::cpUrl(
                        'accessibility-audit/page-report',
                        ScanTarget::reportParams(array_merge($row, ['id' => (int)$row['scanId']]), $siteHandle),
                    ),
                ],
                'rule' => (string)$row['ruleId'],
                // One object, not two columns: a table callback only gets its own
                // cell value, so the text and context have to travel together.
                'question' => [
                    'text' => (string)($row['message'] ?? ''),
                    'context' => (string)($row['context'] ?? ''),
                ],
                // A deleted user leaves the ruling standing but unattributed,
                // so the name falls back rather than the whole cell going blank.
                'dismissedBy' => [
                    'name' => $author?->getName() ?? Craft::t('accessibility-audit', 'Unknown'),
                    'date' => $ruling !== null && $ruling['date'] !== null
                        ? $formatter->asDate($ruling['date'], 'short')
                        : '',
                ],
                'restore' => [
                    'elementId' => $elementId,
                    'url' => (string)($row['url'] ?? ''),
                    'ruleId' => (string)$row['ruleId'],
                    'context' => (string)($row['context'] ?? ''),
                    'siteId' => $siteId,
                ],
            ];
        }

        return $data;
    }

    /**
     * Table data for the Readability page results table.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     */
    public function actionReadabilityTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:viewReports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE));
        $search = trim((string)($this->request->getParam('search') ?? ''));

        // The table posts the column name; map it to a real column rather than
        // letting a query parameter reach ORDER BY.
        $sortField = match ($this->request->getParam('sort.0.field')) {
            'page' => 'title',
            'ease' => 'readingEase',
            'grade' => 'gradeLevel',
            'words' => 'wordCount',
            'wcag' => 'wcag315Pass',
            default => 'dateAnalysed',
        };
        $sortDir = $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC;

        $result = $plugin->readability->getResultsPaged($siteId, $page, $limit, $search, $sortField, $sortDir);

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $result['total'], $limit),
            'data' => $this->_readabilityTableData($result['results']),
        ]);
    }

    /**
     * Shapes readability rows for the table.
     *
     * The two-line cells (score plus its label, grade plus reading age) are
     * built here rather than in Twig: the table renders from JSON, so the
     * server has to hand it everything a cell needs.
     *
     * @param array<int, array<string, mixed>> $rows Stored readability results.
     * @return array<int, array<string, mixed>>
     * @throws \Exception
     */
    private function _readabilityTableData(array $rows): array
    {
        $data = [];

        foreach ($rows as $row) {
            $analysed = $row['dateAnalysed'] ? DateTimeHelper::toDateTime($row['dateAnalysed']) : false;
            $ease = (float)$row['readingEase'];
            $grade = (float)$row['gradeLevel'];

            $data[] = [
                'id' => (int)($row['id'] ?? 0),
                'page' => [
                    'title' => (string)($row['title'] ?: $row['url']),
                    'url' => (string)($row['url'] ?? ''),
                ],
                'ease' => [
                    'value' => $ease,
                    'label' => (string)($row['readingEaseLabel'] ?? ''),
                    'class' => $ease >= 70 ? 'pass' : ($ease >= 50 ? 'warn' : 'error'),
                ],
                'grade' => [
                    'value' => $grade,
                    'age' => (int)$row['readingAge'],
                    'class' => $grade <= 9 ? 'pass' : ($grade <= 12 ? 'warn' : 'error'),
                ],
                'words' => (int)$row['wordCount'],
                'wcag' => (bool)$row['wcag315Pass'],
                'analysed' => $analysed !== false ? $analysed->format('d M Y') : '—',
                // Elements re-analyse through analyse-entry (live field values);
                // URL-only rows re-fetch through analyse, matching the split
                // ReadabilityController makes.
                'reanalyse' => [
                    'elementId' => (int)($row['elementId'] ?? 0),
                    'siteId' => (int)($row['siteId'] ?? 0),
                    'url' => (string)($row['url'] ?? ''),
                ],
            ];
        }

        return $data;
    }

    /**
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     */
    public function actionPotentialPagesExport(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $baseUrl = $site !== null ? rtrim((string)$site->getBaseUrl(), '/') : '';
        $rows = AccessibilityAudit::getInstance()->audit->getPotentialPagesExport($siteId);

        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fputcsv($handle, ['Page', 'URL', 'Issues', 'Count', 'Last scanned']);

        foreach ($rows as $row) {
            $uri = (string)($row['uri'] ?? '');
            $url = ($uri === '' || $uri === '__home__') ? $baseUrl : $baseUrl . '/' . $uri;
            $lastScanned = $row['dateScanned'] ? DateTimeHelper::toDateTime($row['dateScanned']) : false;

            fputcsv($handle, [
                Csv::guard((string)($row['title'] ?? ('Element #' . $row['elementId']))),
                Csv::guard($url),
                (int)$row['issueCount'],
                (int)$row['occurrences'],
                $lastScanned !== false ? $lastScanned->format('Y-m-d') : '',
            ]);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="accessibility-potential-pages.csv"');
        $response->content = $csv;

        return $response;
    }

    /**
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     * @throws \yii\base\Exception
     * @throws \Exception
     */
    public function actionIssuePagesTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:viewReports');

        $ruleId = trim((string)($this->request->getParam('ruleId') ?? ''));
        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE));
        $search = trim((string)($this->request->getParam('search') ?? ''));

        $sortField = match ($this->request->getParam('sort.0.field')) {
            'page' => 'title',
            'score' => 'score',
            'firstDetected' => 'firstDetected',
            'lastScanned' => 'dateScanned',
            default => 'occurrences',
        };
        $sortDir = $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC;

        // Pagination, title search, and sorting all run in SQL (see
        // getPagesForRule), so only the current page's rows are ever loaded.
        $result = AccessibilityAudit::getInstance()->audit->getPagesForRule($ruleId, $siteId, $page, $limit, $search, $sortField, $sortDir);

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, (int)$result['total'], $limit),
            'data' => $this->_issuePagesTableData($result['entries'], $siteId),
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     */
    public function actionIssuePagesExport(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $ruleId = trim((string)($this->request->getParam('ruleId') ?? ''));
        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));

        $site = Craft::$app->getSites()->getSiteById($siteId);
        $baseUrl = $site !== null ? rtrim((string)$site->getBaseUrl(), '/') : '';
        $rows = AccessibilityAudit::getInstance()->audit->getPagesForRuleExport($ruleId, $siteId);

        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fputcsv($handle, ['Page', 'URL', 'Score', 'Count', 'First detected', 'Last scanned']);

        foreach ($rows as $row) {
            $uri = (string)($row['uri'] ?? '');
            $url = ($uri === '' || $uri === '__home__') ? $baseUrl : $baseUrl . '/' . $uri;
            $firstDetected = $row['firstDetected'] ? DateTimeHelper::toDateTime($row['firstDetected']) : false;
            $lastScanned = $row['dateScanned'] ? DateTimeHelper::toDateTime($row['dateScanned']) : false;

            fputcsv($handle, [
                Csv::guard((string)($row['title'] ?? ('Element #' . $row['elementId']))),
                Csv::guard($url),
                (int)$row['score'],
                (int)$row['occurrences'],
                $firstDetected !== false ? $firstDetected->format('Y-m-d') : '',
                $lastScanned !== false ? $lastScanned->format('Y-m-d') : '',
            ]);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        $filename = 'accessibility-' . (string)preg_replace('/[^a-z0-9]+/i', '-', $ruleId) . '-pages.csv';

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->content = $csv;

        return $response;
    }

    // ─── Table data builders ─────────────────────────────────────────────────
    // Titles use getUiLabel() so non-Entry element types (Users, Products, …)
    // resolve to a real label rather than an empty title → "Untitled". Inspect
    // links carry the `site` handle, matching the navigable page routes.

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     * @throws \Exception
     */
    private function _scannedPagesTableData(array $entries, int $siteId): array
    {
        $data = [];
        $siteHandle = Craft::$app->getSites()->getSiteById($siteId)?->handle;

        foreach ($entries as $row) {
            $scan = $row['scan'];
            $element = $row['element'] ?? null;
            $elementId = (int)$scan['elementId'];
            $reportUrl = UrlHelper::cpUrl(
                'accessibility-audit/page-report',
                ScanTarget::reportParams($scan, $siteHandle),
            );
            $lastScanned = !empty($scan['dateScanned']) ? DateTimeHelper::toDateTime($scan['dateScanned']) : false;

            $data[] = [
                'id' => $elementId,
                'page' => [
                    'title' => ScanTarget::label($scan, $element),
                    'inspectUrl' => $reportUrl,
                    'url' => ScanTarget::url($scan, $element),
                    'scanId' => (int)$scan['id'],
                ],
                'score' => (int)$scan['score'],
                'issues' => [
                    'errors' => (int)($scan['errorCount'] ?? 0),
                    'warnings' => (int)($scan['warningCount'] ?? 0),
                    'notices' => (int)($scan['noticeCount'] ?? 0),
                ],
                'lastScanned' => $lastScanned !== false ? $lastScanned->format('d M Y') : '—',
                'rescan' => $element?->id ?? 0,
                'report' => $reportUrl,
            ];
        }

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function _potentialTableData(array $rows, int $siteId): array
    {
        $data = [];
        $siteHandle = Craft::$app->getSites()->getSiteById($siteId)?->handle;

        foreach ($rows as $row) {
            $ruleId = (string)$row['ruleId'];
            $data[] = [
                'question' => [
                    'title' => $this->_potentialQuestion($ruleId),
                    'url' => UrlHelper::cpUrl('accessibility-audit/issues/detail', ['ruleId' => $ruleId, 'site' => $siteHandle]),
                ],
                'conformance' => $row['wcagLevel'] ?? null,
                'sc' => (string)($row['wcagCriterion'] ?? '—'),
                'responsibility' => $row['responsibility'] ?? null,
                'elementType' => $row['elementType'] ?? null,
                'count' => (int)$row['occurrences'],
                'pages' => (int)$row['pageCount'],
            ];
        }

        return $data;
    }

    /**
     * Which width a question came from, when it came from only one.
     *
     * @param string[] $viewports The viewports the question was recorded at.
     * @return string|null
     */
    private function _viewportLabel(array $viewports): ?string
    {
        $set = array_unique(array_filter($viewports));

        // Found at both widths, or on a scan that recorded none: saying so adds
        // nothing, and a label on every row stops meaning anything.
        if (count($set) !== 1) {
            return null;
        }

        return match (reset($set)) {
            AuditService::VIEWPORT_MOBILE => Craft::t('accessibility-audit', 'Mobile only'),
            AuditService::VIEWPORT_DESKTOP => Craft::t('accessibility-audit', 'Desktop only'),
            default => null,
        };
    }

    /**
     * Human-readable question for a potential-issue rule.
     *
     * @param string $ruleId
     * @return string
     */
    private function _potentialQuestion(string $ruleId): string
    {
        return match ($ruleId) {
            'potential:short-alt' => Craft::t('accessibility-audit', 'Is this image alt text descriptive enough?'),
            'potential:long-alt' => Craft::t('accessibility-audit', 'Is this image alt text too long?'),
            'potential:identical-links' => Craft::t('accessibility-audit', 'Are these identical links going to different places?'),
            'potential:url-as-link-text' => Craft::t('accessibility-audit', 'Is this link text meaningful?'),
            'potential:decorative-image' => Craft::t('accessibility-audit', 'Is this image purely decorative?'),
            'potential:possible-heading' => Craft::t('accessibility-audit', 'Should this bold paragraph be a heading?'),
            'potential:table-layout' => Craft::t('accessibility-audit', 'Is this a data table or a layout table?'),
            'potential:video-audio-desc' => Craft::t('accessibility-audit', 'Does this video need an audio description?'),
            'potential:contrast-unmeasurable' => Craft::t('accessibility-audit', 'Does this text have enough contrast against what is behind it?'),
            default => str_replace('potential:', '', $ruleId),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     * @throws \Exception
     */
    private function _potentialPagesTableData(array $entries, int $siteId): array
    {
        $data = [];
        $siteHandle = Craft::$app->getSites()->getSiteById($siteId)?->handle;

        foreach ($entries as $item) {
            $row = $item['row'];
            $element = $item['element'] ?? null;
            $reportUrl = UrlHelper::cpUrl(
                'accessibility-audit/page-report',
                ScanTarget::reportParams($row, $siteHandle),
            );
            $lastScanned = $row['dateScanned'] ? DateTimeHelper::toDateTime($row['dateScanned']) : false;

            $data[] = [
                'id' => (int)$row['elementId'],
                'page' => [
                    'title' => ScanTarget::label($row, $element),
                    'inspectUrl' => $reportUrl,
                    'url' => ScanTarget::url($row, $element),
                ],
                'issues' => (int)$row['issueCount'],
                'count' => (int)$row['occurrences'],
                'lastScanned' => $lastScanned !== false ? $lastScanned->format('d M Y') : '—',
                'rescan' => $element?->id ?? 0,
                'report' => $reportUrl,
            ];
        }

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     * @throws \Exception
     */
    private function _issuePagesTableData(array $entries, int $siteId): array
    {
        $data = [];
        $siteHandle = Craft::$app->getSites()->getSiteById($siteId)?->handle;

        foreach ($entries as $row) {
            $element = $row['element'] ?? null;
            $reportUrl = UrlHelper::cpUrl(
                'accessibility-audit/page-report',
                ScanTarget::reportParams($row, $siteHandle),
            );
            $firstDetected = $row['firstDetected'] ? DateTimeHelper::toDateTime($row['firstDetected']) : false;
            $lastScanned = $row['dateScanned'] ? DateTimeHelper::toDateTime($row['dateScanned']) : false;

            $data[] = [
                'id' => (int)$row['elementId'],
                'page' => [
                    'title' => ScanTarget::label($row, $element),
                    'inspectUrl' => $reportUrl,
                    'url' => ScanTarget::url($row, $element),
                ],
                'score' => (int)$row['score'],
                'count' => (int)$row['occurrences'],
                'firstDetected' => $firstDetected !== false ? $firstDetected->format('d M Y') : '—',
                'lastScanned' => $lastScanned !== false ? $lastScanned->format('d M Y') : '—',
                'rescan' => $element?->id ?? 0,
                'report' => $reportUrl,
            ];
        }

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     * @throws \Exception
     */
    private function _resolvedTableData(array $rows, int $siteId): array
    {
        $data = [];
        $siteHandle = Craft::$app->getSites()->getSiteById($siteId)?->handle;

        foreach ($rows as $row) {
            $ruleId = (string)$row['ruleId'];
            $resolved = !empty($row['dateResolved']) ? DateTimeHelper::toDateTime($row['dateResolved']) : false;

            $data[] = [
                'rule' => [
                    'ruleId' => $ruleId,
                    'severity' => (string)($row['severity'] ?? ''),
                    'elementType' => (string)($row['elementType'] ?? ''),
                    'detailUrl' => UrlHelper::cpUrl('accessibility-audit/issues/detail', [
                        'ruleId' => $ruleId,
                        'site' => $siteHandle,
                    ]),
                ],
                'wcagLevel' => (string)($row['wcagLevel'] ?? ''),
                'sc' => (string)($row['wcagCriterion'] ?? ''),
                'owner' => (string)($row['responsibility'] ?? ''),
                'occurrences' => (int)$row['occurrences'],
                'points' => number_format((float)$row['pointsGained'], 2),
                'resolved' => $resolved !== false ? $resolved->format('d M Y') : '—',
            ];
        }

        return $data;
    }

    /**
     * Refuses an issue-detail read whose `scanId` belongs to a site the edition
     * or user is not allowed to view. The install-wide `viewReports` permission
     * does not scope by site, so the per-site fence has to be enforced here, or
     * a crafted scanId would expose another site's findings. Returns a JSON
     * refusal, or null when the scan's site is allowed (or the scan is unknown).
     *
     * @param int $scanId The requested scan ID.
     * @return Response|null
     * @throws SiteNotFoundException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.1
     */
    private function _requireAllowedScanSite(int $scanId): ?Response
    {
        $plugin = AccessibilityAudit::getInstance();
        $scanSiteId = $plugin->audit->getScanSiteId($scanId);

        if ($scanSiteId !== null && !$plugin->isSiteAllowed($scanSiteId)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'You do not have permission to view that scan.'),
            ]);
        }

        return null;
    }

    /**
     * Builds a Craft-native `_includes/pagination` compatible pageInfo array
     * for a manually (non-ElementQuery) paginated result set.
     *
     * @param string $route CP route to page against (e.g. `accessibility-audit/assets`).
     * @param int $page Current 1-based page number.
     * @param int $perPage Items per page.
     * @param int $total Total item count across all pages.
     * @param int $totalPages Total page count.
     * @param array<string, mixed> $extraParams Additional query params to preserve on prev/next URLs (e.g. site, tab).
     * @return array{total: int, first: int, last: int, prevUrl: string|null, nextUrl: string|null}
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _pageInfo(string $route, int $page, int $perPage, int $total, int $totalPages, array $extraParams = []): array
    {
        return [
            'total' => $total,
            'first' => $total > 0 ? ($page - 1) * $perPage + 1 : 0,
            'last' => min($page * $perPage, $total),
            'prevUrl' => $page > 1 ? UrlHelper::cpUrl($route, $extraParams + ['page' => $page - 1]) : null,
            'nextUrl' => $page < $totalPages ? UrlHelper::cpUrl($route, $extraParams + ['page' => $page + 1]) : null,
        ];
    }
}
