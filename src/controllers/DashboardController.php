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
use craft\helpers\Html;
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
use johnhenry\accessibilityaudit\helpers\FindingHtml;
use johnhenry\accessibilityaudit\helpers\OccurrenceCluster;
use johnhenry\accessibilityaudit\helpers\RuleRows;
use johnhenry\accessibilityaudit\helpers\ScanTarget;
use johnhenry\accessibilityaudit\jobs\DraftAltText;
use johnhenry\accessibilityaudit\models\StatementExclusionModel;
use johnhenry\accessibilityaudit\services\AltDrafts;
use johnhenry\accessibilityaudit\services\AssetScanner;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\PdfAuditService;
use johnhenry\accessibilityaudit\services\PdfChecker;
use johnhenry\accessibilityaudit\services\PotentialScanner;
use johnhenry\accessibilityaudit\services\RuleRegistry;
use johnhenry\accessibilityaudit\services\StatementProfiles;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Renders the Accessibility Audit CP dashboard, issue reports, and detail pages.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
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

    /**
     * @var int The largest page a table endpoint will answer with.
     *
     * per_page comes off the query string, and floored without a ceiling one
     * request can ask for the whole table in a single page. The listings carry
     * issue context and page titles, so that is a lot of rows to build in
     * memory on an endpoint any reader can call. Well clear of the hundred the
     * table actually asks for.
     */
    private const TABLE_PER_PAGE_MAX = 200;

    /**
     * @var int Occurrences shown under each page of a rule's page listing. The
     * rest are a click away on the page's own report.
     */
    private const OCCURRENCES_PER_PAGE = 10;

    /**
     * @var int PDFs per page on the Assets screen's PDFs tab.
     */
    private const PDF_PER_PAGE = 50;

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Renders the Overview screen: the site's current score and what is driving it.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $sites = $plugin->allowedSites();

        $plugin->getSnapshots()->recordIfMissing($siteId);
        $plugin->getScheduledReports()->queueDue();

        $summary = $plugin->getAudit()->getSiteSummary($siteId);

        // Compute totals from the whole site, not the top-10 slice, so the
        // severity-band totals and subhead match the Issues page.
        $byImpactAll = $plugin->getAudit()->getIssuesByImpact($siteId, PHP_INT_MAX);
        $byImpact = array_slice($byImpactAll, 0, 10);
        $templateIssues = $plugin->getAudit()->getTemplateIssues($siteId);
        $resolvedIssues = $plugin->getAudit()->getResolvedIssues($siteId, 10);
        $scoreHistory = array_reverse($plugin->getAudit()->getScoreHistory($siteId, 30));
        $settings = $plugin->getSettings();

        $isPro = $plugin->isPro();
        $scanLimit = $plugin->scanLimit();

        // Lite's scan cap counts distinct pages across the whole install (all
        // sites), matching Craft's plugin licensing. Only show the "across all
        // sites" wording on multi-site installs.
        $isMultiSite = count($sites) > 1;

        // A score of 100 with unanswered questions behind it is not the same
        // thing as a site that has been through them, and the headline could
        // not tell the difference because nothing here knew the questions
        // existed.
        $potentialRows = $plugin->getAudit()->getPotentialIssues($siteId);
        $pendingPotential = array_sum(array_map(
            static fn(array $row): int => (int) $row['occurrences'],
            $potentialRows,
        ));
        $potentialPages = $pendingPotential > 0
            ? $plugin->getAudit()->getPagesWithPotentialIssues($siteId, 1, 1)['total']
            : 0;

        // The review screen counts one row per rule, this counts one per
        // occurrence. The note names both so the two figures agree.
        $potentialKinds = count($potentialRows);

        return $this->renderTemplate('accessibility-audit/index', [
            'summary' => $summary,
            'coverage' => $plugin->getAudit()->getCoverage($siteId),
            'pendingPotential' => $pendingPotential,
            'potentialPages' => $potentialPages,
            'potentialKinds' => $potentialKinds,
            'byImpact' => $byImpact,
            'byImpactAll' => $byImpactAll,
            'templateIssues' => $templateIssues,
            'resolvedIssues' => $resolvedIssues,
            'scoreHistory' => $scoreHistory,
            'siteId' => $siteId,
            'siteHandle' => $siteHandle,
            'sites' => $sites,
            'targetScore' => $settings->targetScore,
            'wcagLevel' => strtoupper($settings->wcagLevel),
            // Drives the EN 301 549 note under the conformance scores. Derived
            // from the WCAG level, not axe tags: clause 9 of the standard restates
            // WCAG 2.1 AA, so the level is the reliable signal for every issue.
            'en301549' => $settings->en301549,
            'assetStats' => $plugin->getAssets()->getStoredAssetStats(),
            // The assets band's other figures, all already kept by the Assets
            // page: drafts waiting, a drafting run under way, decorative images,
            // and from Standard up the PDF results.
            'assetDraftCount' => $plugin->isStandard() ? $plugin->getAltDrafts()->countReady() : 0,
            'assetDraftRunning' => DraftAltText::isRunning(),
            'assetDecorativeCount' => $plugin->getAssets()->getDecorativeCount(),
            'assetHasApiKey' => $plugin->canGenerateAltText(),
            'pdfStats' => $plugin->getPdfAudit()->isEnabled() ? $plugin->getPdfAudit()->getStats() : null,
            'edition' => $plugin->edition,
            'isPro' => $isPro,
            'isMultiSite' => $isMultiSite,
            'scannedElementCount' => $scanLimit !== null ? $plugin->getAudit()->getScannedElementCount() : 0,
            'scanLimit' => $scanLimit,
        ]);
    }

    /**
     * Renders the Issues screen, listing every outstanding issue for the site.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws Exception
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIssues(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $activeTab = $this->request->getQueryParam('tab', 'issues');
        $page = max(1, (int) ($this->request->getQueryParam('page') ?? 1));
        $perPage = self::TABLE_PER_PAGE;

        // The tab is named for pages with issues, so it lists those. The full
        // count is kept separately: a clean site and a site nobody has scanned
        // both produce an empty list, and they need different words.
        $result = $plugin->getAudit()->getScannedElements($siteId, $page, $perPage, withIssuesOnly: true);
        $scannedCount = $plugin->getAudit()->getScannedElements($siteId, 1, 1)['total'];
        // The Issues tab is the full list, so no rule cap: a silently
        // truncated "full list" would contradict the dashboard.
        $byRule = $plugin->getAudit()->getIssuesByImpact($siteId, PHP_INT_MAX);
        $resolvedByRule = $plugin->getAudit()->getResolvedIssuesByImpact($siteId);
        $sites = $plugin->allowedSites();
        $totalPages = (int) ceil($result['total'] / $perPage);

        // The remediation-over-time chart is only rendered on the Resolved tab,
        // so its data is fetched only then (the range persists via ?range=).
        $resolvedTrendRange = max(1, min(365, (int) ($this->request->getQueryParam('range') ?? 90)));
        $resolvedTrend = $activeTab === 'resolved'
            ? $plugin->getAudit()->getResolvedTrend($siteId, $resolvedTrendRange)
            : [];

        return $this->renderTemplate('accessibility-audit/issues', [
            'result' => $result,
            'scannedCount' => $scannedCount,
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
     * Renders the report for a single scanned page.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPageReport(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $plugin = AccessibilityAudit::getInstance();
        $elementId = (int)($this->request->getQueryParam('elementId') ?? 0);
        $scanId = (int)($this->request->getQueryParam('scanId') ?? 0);
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;

        if ($elementId === 0 && $scanId === 0) {
            return $this->redirect(UrlHelper::cpUrl('accessibility-audit/issues'));
        }

        $audit = $plugin->getAudit();

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

                $row['markup'] = AuditService::contextMarkup($row['context'] ?? null);
                // Whether this occurrence can be answered for every page at once.
                $row['everyPage'] = AccessibilityAudit::getInstance()->getVerdicts()->siteKey(
                    $row['context'] ?? null,
                    $row['selector'] ?? null,
                ) !== null;
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
                $row['markup'] = AuditService::contextMarkup($row['context'] ?? null);
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
                // With a server-side browser the focus walk answers 2.4.7, so
                // the page skips reading focus outlines from the stylesheet.
                'headlessAvailable' => $plugin->getHeadless()->isAvailable(),
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
            'canRunScans' => Craft::$app->getUser()->checkPermission('accessibility-audit:run-scans'),
            'siteId' => $siteId,
            'siteHandle' => $siteHandle,
            'sites' => $sites,
        ]);
    }

    /**
     * Returns the occurrences of one rule on one scanned page.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPageRuleOccurrences(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $scanId = (int)($this->request->getQueryParam('scanId') ?? 0);
        $ruleId = trim((string)($this->request->getQueryParam('ruleId') ?? ''));

        if ($scanId === 0 || $ruleId === '') {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Invalid parameters.')]);
        }

        if (($refusal = $this->_requireAllowedScanSite($scanId)) !== null) {
            return $refusal;
        }

        $occurrences = AccessibilityAudit::getInstance()->getAudit()->getOccurrencesForRule($scanId, $ruleId);

        return $this->asJson(['success' => true, 'occurrences' => $occurrences]);
    }

    /**
     * Returns the issues found by one scan.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPageIssues(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $scanId = (int)($this->request->getQueryParam('scanId') ?? 0);
        if ($scanId === 0) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Invalid scan ID.')]);
        }

        if (($refusal = $this->_requireAllowedScanSite($scanId)) !== null) {
            return $refusal;
        }

        $issues = AccessibilityAudit::getInstance()->getAudit()->getIssuesGroupedByScan($scanId);

        return $this->asJson(['success' => true, 'issues' => $issues]);
    }

    /**
     * Renders the detail screen for a single rule.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIssueDetail(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $plugin = AccessibilityAudit::getInstance();
        $ruleId = trim((string)($this->request->getQueryParam('ruleId') ?? ''));
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $page = max(1, (int)($this->request->getQueryParam('page') ?? 1));
        $perPage = self::TABLE_PER_PAGE;

        if ($ruleId === '') {
            return $this->redirect(UrlHelper::cpUrl('accessibility-audit/issues'));
        }

        $audit = $plugin->getAudit();
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

        // An axe rule's guidance link goes to Deque's page for the rule, which
        // is a guide to fixing it. The plugin's own rules link to WCAG's
        // Understanding documents, which explain the criterion instead.
        $helpUrl = (string)($detail['helpUrl'] ?? '');
        $isFixGuide = str_starts_with($helpUrl, 'https://dequeuniversity.com/');
        $pluginRule = RuleRegistry::pluginRuleFor($ruleId);
        $ruleTitle = RuleRegistry::title($ruleId);
        $ruleDescription = RuleRegistry::description($ruleId);
        $exampleFinding = RuleRegistry::exampleFinding((string)($detail['message'] ?? ''), $ruleTitle, $ruleDescription);

        return $this->renderTemplate('accessibility-audit/issue-detail', [
            'ruleId' => $ruleId,
            'ruleTitle' => $ruleTitle,
            'ruleDescription' => $ruleDescription,
            // Encoded by the formatter, so the template prints it raw.
            'exampleFinding' => $exampleFinding !== '' ? FindingHtml::format($exampleFinding) : '',
            'ruleFix' => RuleRegistry::fix($ruleId),
            'fixGuideUrl' => $isFixGuide ? $helpUrl : null,
            'guidanceUrl' => !$isFixGuide && preg_match('/^https?:\/\//i', $helpUrl) === 1 ? $helpUrl : null,
            'pluginRule' => $pluginRule !== null ? ['ruleId' => $pluginRule, 'title' => RuleRegistry::title($pluginRule)] : null,
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
     * Renders the Potential Issues screen: the things a scan can only flag for a person to judge.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPotential(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $activeTab = $this->request->getQueryParam('tab', 'issues');
        $page = max(1, (int) ($this->request->getQueryParam('page') ?? 1));
        $perPage = self::TABLE_PER_PAGE;

        $audit = $plugin->getAudit();
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
            'dismissedTotal' => $plugin->getAudit()->getDismissedPotential($siteId, 1, 1)['total'],
            'canRunScans' => Craft::$app->getUser()->checkPermission('accessibility-audit:run-scans'),
            'pageInfo' => $this->_pageInfo('accessibility-audit/potential', $page, $perPage, (int) $pages['total'], $totalPages, ['site' => $siteHandle, 'tab' => 'pages']),
        ]);
    }

    /**
     * Renders the Utilities screen.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionUtilities(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

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
            'wcagLevel' => strtoupper($settings->wcagLevel),
            'activeTab' => $tab,
        ]);
    }

    /**
     * Renders the VPAT screen.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws Exception
     * @throws \yii\base\Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionVpat(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $sites = $plugin->allowedSites();

        // VPAT is Pro-only, but the page is reachable from the CP nav, so render
        // an upsell state below Pro instead of throwing. The report is only
        // built when it will actually be shown.
        $isPro = $plugin->isPro();
        $report = $isPro ? $plugin->getVpat()->getFullReport($siteId) : null;

        // AI remark drafting is only offered when an Anthropic API key is
        // configured; without one the button would just error.
        $settings = $plugin->getSettings();
        $canDraftRemarks = $isPro && trim((string)App::parseEnv($settings->anthropicApiKey)) !== '';

        // Pages the scanner has actually covered, offered to the editor's
        // "Fill from scanned pages" button so the Scope section starts from
        // evidence rather than memory.
        $scopeSuggestions = [];
        if ($isPro) {
            $scanned = $plugin->getAudit()->getScannedElements($siteId, 1, 200);
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
                'recordRevisionUrl' => UrlHelper::actionUrl('accessibility-audit/vpat/record-revision'),
                'deleteRevisionUrl' => UrlHelper::actionUrl('accessibility-audit/vpat/delete-latest-revision'),
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
            'revisionCount' => $isPro ? $plugin->getVpat()->countRevisions($siteId) : 0,
        ]);
    }

    /**
     * Renders the accessibility statement editor.
     *
     * Viewing is gated on view-reports like every other report page; editing is
     * gated separately inside StatementController, so a reader can see what the
     * organisation has published without being able to change it.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionStatement(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();
        $siteHandle = $plugin->requestedSite()->handle;
        $sites = $plugin->allowedSites();

        // Available on every edition: in the EU and UK a statement is legally
        // required, so it is not something to hold back for an upgrade.
        $statement = $plugin->getStatement()->getFullStatement($siteId);
        $derivation = $plugin->getStatement()->deriveComplianceStatus($siteId);
        $suggestions = $plugin->getStatement()->deriveSuggestions($siteId);

        // Turn the bare criterion numbers into a readable checklist so a
        // reviewer can see which criteria still need a human eye, not just the
        // count. Unknown numbers are filtered out defensively.
        $unconfirmedDetails = array_values(array_filter(array_map(
            static fn(string $number): ?array => $plugin->getVpat()->criterionMeta($number),
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
                static fn(array $criterion): string => (string)$criterion['name'],
                $plugin->getVpat()->getCriteria(),
            ),
            // Placeholders, not values: shown greyed and never saved, so a
            // published statement can only ever carry what somebody wrote.
            'affectedExamples' => array_merge(
                array_map(
                    static fn(string $number): string => AffectedExample::for($number),
                    array_combine(
                        array_keys($plugin->getVpat()->getCriteria()),
                        array_keys($plugin->getVpat()->getCriteria()),
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
     * Renders the Assets screen: the alt text on the site's images, and on
     * Pro the accessibility of its PDFs.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionAssets(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();
        $page = max(1, (int) ($this->request->getQueryParam('page') ?? 1));
        $perPage = 100;

        // Volume filter: an unknown handle simply yields no matches, so it's
        // validated against the real volume list here rather than trusting the
        // query param, and dropped back to "all" when it doesn't resolve.
        // Excluded volumes are dropped from the list so you can't narrow to a
        // volume the audit ignores, which would only ever show nothing.
        $excludedVolumes = $settings->excludedVolumes;
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        if (!empty($excludedVolumes)) {
            $volumes = array_values(array_filter(
                $volumes,
                static fn($v) => !in_array($v->uid, $excludedVolumes, true),
            ));
        }
        $volume = trim((string) ($this->request->getQueryParam('volume') ?? ''));
        $volumeModel = $volume !== '' ? ArrayHelper::firstWhere($volumes, 'handle', $volume) : null;
        if ($volumeModel === null) {
            $volume = '';
        }
        $volumeId = $volumeModel !== null ? (int)$volumeModel->id : null;

        $search = trim((string) ($this->request->getQueryParam('search') ?? ''));

        // Issue-type filter acts on the whole library server-side, so a chip's
        // count matches the rows it shows. Validated against the known asset
        // rules so a junk param can't reach the query. The PDFs tab reads its
        // own status and problem params, and older PDF filter links are mapped
        // onto them, so the tab always carries the one canonical filter.
        $filter = trim((string) ($this->request->getQueryParam('filter') ?? ''));
        $pdfAudit = $plugin->getPdfAudit();
        $pdfListing = $pdfAudit->resolveListing(
            $filter,
            trim((string) ($this->request->getQueryParam('status') ?? '')),
            trim((string) ($this->request->getQueryParam('problem') ?? '')),
        );
        $pdfView = $pdfListing !== null;

        // The drafts list is the AI alt text review queue, which Lite lacks.
        $aiAltText = $plugin->isStandard();
        $filters = ['asset-alt-missing', 'asset-alt-filename', 'asset-alt-short', 'decorative'];
        if ($aiAltText) {
            $filters[] = AltDrafts::FILTER;
        }

        if ($pdfView) {
            $filter = PdfAuditService::FILTER_ALL;
        } elseif (!in_array($filter, $filters, true)) {
            $filter = '';
        }

        $pdfEnabled = $pdfAudit->isEnabled();
        $pdfStats = $pdfEnabled ? $pdfAudit->getStats() : null;
        $pdfPaged = $pdfEnabled && $pdfListing !== null
            ? $pdfAudit->listPaged($page, self::PDF_PER_PAGE, $volume ?: null, $search ?: null, $pdfListing['status'], $pdfListing['problem'])
            : null;

        // Paginate: scanning every image on every render OOMs/times out on
        // large media libraries. The decorative filter lists images currently
        // marked decorative (from the flags table) instead of the issues list,
        // so a marked image can be reviewed and switched back off; the drafts
        // filter lists the AI drafts waiting for review; every other filter
        // runs the issue scan.
        $altDrafts = $plugin->getAltDrafts();
        $paged = match (true) {
            $pdfView => ['results' => [], 'total' => 0, 'page' => 1, 'perPage' => $perPage, 'totalPages' => 0],
            $filter === 'decorative' => $plugin->getAssets()->listDecorativePaged($page, $perPage, $volume ?: null, $search ?: null),
            $filter === AltDrafts::FILTER => $altDrafts->listReadyPaged($page, $perPage, $volume ?: null, $search ?: null),
            default => $plugin->getAssets()->scanImagesPaged($page, $perPage, $volume ?: null, $search ?: null, $filter ?: null),
        };
        $stats = $plugin->getAssets()->getSiteAssetStats();

        // Which images on this page already have a draft waiting, so their rows
        // can say so. One query for the page, not one per row.
        $draftedIds = [];
        if ($aiAltText && !$pdfView && $filter !== AltDrafts::FILTER) {
            $pageIds = array_map(static fn(array $row): int => (int)$row['asset']->id, $paged['results']);
            foreach ($altDrafts->forAssets($pageIds) as $assetId => $draft) {
                if ($draft['status'] === AltDrafts::STATUS_READY) {
                    $draftedIds[] = (int)$assetId;
                }
            }
        }

        // Preserve the volume filter, filename search, and issue-type filter
        // across pagination links.
        $extraParams = array_filter([
            'volume' => $volume,
            'search' => $search,
            'filter' => $filter,
        ], static fn(string $value): bool => $value !== '');

        // The PDF status and problem only travel when they differ from the
        // default, so the tab's own link and page one are the same URL.
        $pdfPageInfo = $pdfPaged !== null
            ? $this->_pageInfo('accessibility-audit/assets', $pdfPaged['page'], $pdfPaged['perPage'], $pdfPaged['total'], $pdfPaged['totalPages'], array_filter([
                'site' => trim((string) ($this->request->getQueryParam('site') ?? '')),
                'status' => $pdfListing['status'] !== PdfAuditService::LIST_ISSUES ? $pdfListing['status'] : '',
                'problem' => (string) $pdfListing['problem'],
            ], static fn(string $value): bool => $value !== '') + $extraParams)
            : null;

        return $this->renderTemplate('accessibility-audit/assets', [
            'isStandard' => $aiAltText,
            'pdfEnabled' => $pdfEnabled,
            'pdfView' => $pdfView,
            'pdfStatus' => $pdfListing['status'] ?? PdfAuditService::LIST_ISSUES,
            'pdfProblem' => $pdfListing['problem'] ?? null,
            'pdfProblemOptions' => $pdfStats !== null ? $pdfAudit->problemOptions($pdfStats['byRule'], $pdfListing['problem'] ?? null) : [],
            'pdfRows' => $pdfPaged['results'] ?? [],
            'pdfTotalPages' => $pdfPaged['totalPages'] ?? 0,
            'pdfPageInfo' => $pdfPageInfo,
            'pdfStats' => $pdfStats,
            'pdfReasons' => PdfChecker::reasonTexts(),
            'assetIssues' => $paged['results'],
            'stats' => $stats,
            'storedStats' => $plugin->getAssets()->getStoredAssetStats(),
            'canRunScans' => Craft::$app->getUser()->checkPermission('accessibility-audit:run-scans'),
            'page' => $paged['page'],
            'perPage' => $paged['perPage'],
            'total' => $paged['total'],
            'totalPages' => $paged['totalPages'],
            'pageInfo' => $this->_pageInfo('accessibility-audit/assets', $paged['page'], $paged['perPage'], $paged['total'], $paged['totalPages'], $extraParams),
            'hasApiKey' => $plugin->canGenerateAltText(),
            // The same number the long-alt check reports on, so the count
            // beside the field agrees with the finding.
            'altGuideline' => PotentialScanner::MAX_ALT_LENGTH,
            'volumes' => $volumes,
            'currentVolume' => $volume,
            'currentSearch' => $search,
            'activeFilter' => $filter,
            'decorativeCount' => $plugin->getAssets()->getDecorativeCount($volume ?: null, $search ?: null),
            'draftCount' => $aiAltText ? $altDrafts->countReady($volume ?: null, $search ?: null) : 0,
            'draftedIds' => $draftedIds,
            'failedDraftCount' => $aiAltText ? $altDrafts->countFailed($volume ?: null, $search ?: null) : 0,
            'draftingRunning' => $aiAltText && DraftAltText::isRunning(),
            // The drafting run covers the volume filter but not the filename
            // search, so the count it asks to confirm is the volume's alone.
            'pendingDraftCount' => $pdfView || !$aiAltText ? 0 : $altDrafts->pendingCount($volumeId),
        ]);
    }

    /**
     * Renders one PDF's page under the Assets screen: what its check found,
     * failure by failure, and what to do about it (Pro).
     *
     * A file the audit does not cover gets the same 404 as one that does not
     * exist, and with PDF checks off the audit covers nothing.
     *
     * @param int $assetId The PDF's asset id.
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws SiteNotFoundException
     * @throws InvalidConfigException
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionPdf(int $assetId): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $pdfAudit = AccessibilityAudit::getInstance()->getPdfAudit();
        $asset = $pdfAudit->isEnabled() ? AssetScanner::findAsset($assetId) : null;

        if ($asset === null || !$pdfAudit->covers($asset)) {
            throw new NotFoundHttpException('PDF not found.');
        }

        if (!Craft::$app->getElements()->canView($asset)) {
            throw new ForbiddenHttpException('User is not authorized to view this asset.');
        }

        return $this->renderTemplate('accessibility-audit/_assets/pdf', $pdfAudit->detail($asset) + [
            'asset' => $asset,
            'canRunScans' => Craft::$app->getUser()->checkPermission('accessibility-audit:run-scans'),
        ]);
    }

    // ─── VueAdminTable endpoints ─────────────────────────────────────────────
    // These are AJAX endpoints, not navigable pages: their URLs are rebuilt on
    // every table load and never bookmarked, so they read an integer `siteId`
    // param via resolveSiteId() rather than the `site` handle.

    /**
     * Returns the scanned-pages table, a page at a time.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionScannedPagesTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:view-reports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, min(self::TABLE_PER_PAGE_MAX, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE)));
        $search = trim((string)($this->request->getParam('search') ?? ''));

        $sortField = match ($this->request->getParam('sort.0.field')) {
            'page' => 'title',
            'issues' => 'issues',
            'lastScanned' => 'dateScanned',
            default => 'score',
        };
        $sortDir = $this->request->getParam('sort.0.direction') === 'desc' ? SORT_DESC : SORT_ASC;

        // Filtered the same way the tab's own count is. Filtering one and not
        // the other is how the heading came to say nothing while the rows
        // underneath it listed the whole site.
        $result = AccessibilityAudit::getInstance()->getAudit()->getScannedElements(
            $siteId,
            $page,
            $limit,
            $search,
            $sortField,
            $sortDir,
            withIssuesOnly: true,
        );

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, (int)$result['total'], $limit),
            'data' => $this->_scannedPagesTableData($result['entries'], $siteId),
        ]);
    }

    /**
     * Exports the scanned pages as a CSV.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionScannedPagesExport(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));

        $site = Craft::$app->getSites()->getSiteById($siteId);
        $baseUrl = $site !== null ? rtrim((string)$site->getBaseUrl(), '/') : '';
        $rows = AccessibilityAudit::getInstance()->getAudit()->getScannedElementsExport($siteId);

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

        return Csv::download($csv, 'accessibility-scanned-pages.csv');
    }

    /**
     * Returns the resolved-issues table, a page at a time.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws SiteNotFoundException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionResolvedTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:view-reports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, min(self::TABLE_PER_PAGE_MAX, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE)));
        $sortDir = $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC;

        $rows = AccessibilityAudit::getInstance()->getAudit()->getResolvedIssuesByImpact($siteId);

        // The list is bounded (one row per rule) and its points/owner columns are
        // derived after the query, so it is sorted and paged in PHP here.
        $key = match ($this->request->getParam('sort.0.field')) {
            'rule' => static fn(array $r): string => (string)$r['title'],
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
     * Returns the open issues, one row per rule, a page at a time.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionOpenIssuesTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:view-reports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, min(self::TABLE_PER_PAGE_MAX, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE)));

        $rows = RuleRows::filter(
            AccessibilityAudit::getInstance()->getAudit()->getIssuesByImpact($siteId, PHP_INT_MAX),
            (string)$this->request->getParam('search', ''),
            (string)$this->request->getParam('elementType', ''),
        );
        $rows = RuleRows::sort(
            $rows,
            $this->request->getParam('sort.0.field'),
            $this->request->getParam('sort.0.direction') === 'asc',
        );

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, count($rows), $limit),
            'data' => $this->_openIssuesTableData(array_slice($rows, ($page - 1) * $limit, $limit), $siteId),
        ]);
    }

    /**
     * Returns the resolved-issue counts over time, for the trend chart.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionResolvedTrend(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:view-reports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $range = max(1, min(365, (int)$this->request->getParam('range', 90)));

        return $this->asJson([
            'success' => true,
            'trend' => AccessibilityAudit::getInstance()->getAudit()->getResolvedTrend($siteId, $range),
        ]);
    }

    /**
     * Returns one rule's occurrence counts over time, for its trend chart.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionRuleTrend(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:view-reports');

        $ruleId = trim((string)($this->request->getParam('ruleId') ?? ''));
        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        // Bounded at both ends, as the resolved-issues trend beside it is: the
        // range comes off the query string, and without a ceiling one request
        // can ask for a span of any length.
        $range = max(1, min(365, (int)$this->request->getParam('range', 90)));

        return $this->asJson([
            'success' => true,
            'trend' => AccessibilityAudit::getInstance()->getAudit()->getRuleTrend($ruleId, $siteId, $range),
        ]);
    }

    /**
     * Returns the potential-issues table, a page at a time.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPotentialTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:view-reports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, min(self::TABLE_PER_PAGE_MAX, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE)));

        $sortField = match ($this->request->getParam('sort.0.field')) {
            'count' => 'occurrences',
            default => 'pageCount',
        };
        $asc = $this->request->getParam('sort.0.direction') === 'asc';

        $rows = AccessibilityAudit::getInstance()->getAudit()->getPotentialIssues($siteId);
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
     * Returns the pages carrying potential issues, a page at a time.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPotentialPagesTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:view-reports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, min(self::TABLE_PER_PAGE_MAX, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE)));
        $search = trim((string)($this->request->getParam('search') ?? ''));

        $sortField = match ($this->request->getParam('sort.0.field')) {
            'page' => 'title',
            'issues' => 'issues',
            'lastScanned' => 'dateScanned',
            default => 'occurrences',
        };
        $sortDir = $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC;

        $result = AccessibilityAudit::getInstance()->getAudit()->getPagesWithPotentialIssues($siteId, $page, $limit, $search, $sortField, $sortDir);

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionDismissedTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:view-reports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, min(self::TABLE_PER_PAGE_MAX, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE)));
        $search = trim((string)($this->request->getParam('search') ?? ''));

        $sortField = match ($this->request->getParam('sort.0.field')) {
            'page' => 'elementId',
            default => 'ruleId',
        };
        $sortDir = $this->request->getParam('sort.0.direction') === 'desc' ? SORT_DESC : SORT_ASC;

        $result = $plugin->getAudit()->getDismissedPotential($siteId, $page, $limit, $search, $sortField, $sortDir);

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _dismissedTableData(array $rows, int $siteId): array
    {
        $data = [];
        $siteHandle = Craft::$app->getSites()->getSiteById($siteId)?->handle;

        // One query for the whole page of rows, not one per row. Keyed on the
        // target rather than the element, so a row from a page scanned by URL
        // finds its ruling too.
        $verdicts = AccessibilityAudit::getInstance()->getVerdicts();
        $targetHashes = array_map(
            static fn(array $r): string => AccessibilityAudit::getInstance()->getVerdicts()->targetHash(
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
            $ruling = $verdicts->lookupMeta($meta, $targetHashes[$index], (string)$row['ruleId'], $row['context'] ?? null, $row['selector'] ?? null);
            $author = $ruling !== null && $ruling['userId'] !== null
                ? Craft::$app->getUsers()->getUserById($ruling['userId'])
                : null;

            $data[] = [
                'id' => (int)$row['id'],
                'page' => [
                    'title' => ScanTarget::label($row, $element),
                    // Titles are not unique. A plugin's landing page and its
                    // support page carry the same one, share the same layout,
                    // and so throw up the same findings: without the address
                    // beside it there is no telling two rows apart, and a
                    // ruling made on one looks like a ruling that did not hold
                    // on the other.
                    'url' => ScanTarget::url($row, $element),
                    // The row's own id is the issue's, so the scan id is put in
                    // its place before the target is read off it.
                    'inspectUrl' => UrlHelper::cpUrl(
                        'accessibility-audit/page-report',
                        ScanTarget::reportParams(array_merge($row, ['id' => (int)$row['scanId']]), $siteHandle),
                    ),
                ],
                'rule' => [
                    'title' => RuleRegistry::title((string)$row['ruleId']),
                    'ruleId' => (string)$row['ruleId'],
                ],
                // One object, not two columns: a table callback only gets its own
                // cell value, so the text and context have to travel together.
                'question' => [
                    'text' => (string)($row['message'] ?? ''),
                    'context' => AuditService::contextMarkup($row['context'] ?? null),
                ],
                // A deleted user leaves the ruling standing but unattributed,
                // so the name falls back rather than the whole cell going blank.
                'dismissedBy' => [
                    'name' => $author?->getName() ?? Craft::t('accessibility-audit', 'Unknown'),
                    'date' => $ruling !== null && $ruling['date'] !== null
                        ? $formatter->asDate($ruling['date'], 'short')
                        : '',
                    'everyPage' => $ruling['everyPage'] ?? false,
                ],
                'restore' => [
                    'elementId' => $elementId,
                    'url' => (string)($row['url'] ?? ''),
                    'ruleId' => (string)$row['ruleId'],
                    'context' => (string)($row['context'] ?? ''),
                    'selector' => (string)($row['selector'] ?? ''),
                    'everyPage' => $ruling['everyPage'] ?? false,
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionReadabilityTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:view-reports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, min(self::TABLE_PER_PAGE_MAX, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE)));
        $search = trim((string)($this->request->getParam('search') ?? ''));

        // The table posts the column name; map it to a real column rather than
        // letting a query parameter reach ORDER BY.
        $sortField = match ($this->request->getParam('sort.0.field')) {
            'page' => 'title',
            'grade' => 'gradeLevel',
            'words' => 'wordCount',
            'hard' => 'hardSentences',
            'veryHard' => 'veryHardSentences',
            'analysed' => 'dateAnalysed',
            default => 'worst',
        };
        $sortDir = $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC;

        $elementId = $this->request->getParam('elementId');
        $result = $plugin->getReadability()->getResultsPaged(
            $siteId,
            $page,
            $limit,
            $search,
            $sortField,
            $sortDir,
            $elementId !== null && $elementId !== '' ? (int)$elementId : null,
        );

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _readabilityTableData(array $rows): array
    {
        $data = [];
        $editUrls = $this->_editUrls($rows);

        foreach ($rows as $row) {
            $analysed = $row['dateAnalysed'] ? DateTimeHelper::toDateTime($row['dateAnalysed']) : false;
            $grade = (float)$row['gradeLevel'];

            $data[] = [
                'id' => (int)($row['id'] ?? 0),
                'page' => [
                    'title' => (string)($row['title'] ?: $row['url']),
                    'url' => (string)($row['url'] ?? ''),
                    'editUrl' => $editUrls[(int)($row['elementId'] ?? 0) . ':' . (int)($row['siteId'] ?? 0)] ?? null,
                ],
                'grade' => [
                    'value' => $grade,
                    'class' => $grade <= 9 ? 'pass' : ($grade <= 12 ? 'warn' : 'error'),
                    'pass' => (bool)$row['wcag315Pass'],
                ],
                'words' => (int)$row['wordCount'],
                // Null until the page is next analysed.
                'hard' => isset($row['hardSentences']) ? (int)$row['hardSentences'] : null,
                'veryHard' => isset($row['veryHardSentences']) ? (int)$row['veryHardSentences'] : null,
                'analysed' => $analysed !== false ? $analysed->format('d M Y') : '—',
                // Elements re-analyse through analyse-entry (live field values);
                // URL-only rows re-fetch through analyse, matching the split
                // ReadabilityController makes.
                'reanalyse' => [
                    'elementId' => (int)($row['elementId'] ?? 0),
                    'siteId' => (int)($row['siteId'] ?? 0),
                    'url' => (string)($row['url'] ?? ''),
                    'title' => (string)($row['title'] ?: $row['url']),
                ],
            ];
        }

        return $data;
    }

    /**
     * The control panel edit URLs of the elements a page of stored results
     * belongs to, for the ones the current user may view.
     *
     * One query per element type and site rather than one per row.
     *
     * @param array<int, array<string, mixed>> $rows Stored readability results.
     * @return array<string, string> Edit URLs keyed by "elementId:siteId".
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _editUrls(array $rows): array
    {
        $idsBySite = [];

        foreach ($rows as $row) {
            if (!empty($row['elementId']) && !empty($row['siteId'])) {
                $idsBySite[(int)$row['siteId']][] = (int)$row['elementId'];
            }
        }

        $elementsService = Craft::$app->getElements();
        $urls = [];

        foreach ($idsBySite as $siteId => $ids) {
            /** @var class-string<\craft\base\ElementInterface> $type */
            foreach ($elementsService->getElementTypesByIds($ids) as $type) {
                foreach ($type::find()->id($ids)->siteId($siteId)->status(null)->all() as $element) {
                    $url = $elementsService->canView($element) ? $element->getCpEditUrl() : null;

                    if ($url !== null) {
                        $urls[$element->id . ':' . $siteId] = $url;
                    }
                }
            }
        }

        return $urls;
    }

    /**
     * Exports the pages carrying potential issues as a CSV.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPotentialPagesExport(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $baseUrl = $site !== null ? rtrim((string)$site->getBaseUrl(), '/') : '';
        $rows = AccessibilityAudit::getInstance()->getAudit()->getPotentialPagesExport($siteId);

        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fputcsv($handle, ['Page', 'URL', 'Question types', 'Needs review', 'Last scanned']);

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

        return Csv::download($csv, 'accessibility-potential-pages.csv');
    }

    /**
     * Returns the pages one rule was found on, a page at a time.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     * @throws \yii\base\Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIssuePagesTable(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:view-reports');

        $ruleId = trim((string)($this->request->getParam('ruleId') ?? ''));
        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, min(self::TABLE_PER_PAGE_MAX, (int)$this->request->getParam('per_page', self::TABLE_PER_PAGE)));
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
        $result = AccessibilityAudit::getInstance()->getAudit()->getPagesForRule($ruleId, $siteId, $page, $limit, $search, $sortField, $sortDir);

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, (int)$result['total'], $limit),
            'data' => $this->_issuePagesTableData($result['entries'], $siteId, $ruleId),
        ]);
    }

    /**
     * Exports the pages one rule was found on as a CSV.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIssuePagesExport(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $ruleId = trim((string)($this->request->getParam('ruleId') ?? ''));
        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getParam('siteId'));

        $site = Craft::$app->getSites()->getSiteById($siteId);
        $baseUrl = $site !== null ? rtrim((string)$site->getBaseUrl(), '/') : '';
        $rows = AccessibilityAudit::getInstance()->getAudit()->getPagesForRuleExport($ruleId, $siteId);

        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fputcsv($handle, ['Page', 'URL', 'Score', 'Occurrences', 'First detected', 'Last scanned']);

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

        return Csv::download($csv, $filename);
    }

    // ─── Table data builders ─────────────────────────────────────────────────
    // Titles use getUiLabel() so non-Entry element types (Users, Products, …)
    // resolve to a real label rather than an empty title → "Untitled". Inspect
    // links carry the `site` handle, matching the navigable page routes.

    /**
     * Shapes scanned-page rows for the table.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
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
            $label = ScanTarget::label($scan, $element);

            $data[] = [
                'id' => $elementId,
                'page' => [
                    'title' => $label,
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
                // The row buttons carry the page's name, so each one is told
                // apart from the next by its accessible name.
                'rescan' => ['id' => $element->id ?? 0, 'title' => $label],
                'report' => ['url' => $reportUrl, 'title' => $label],
            ];
        }

        return $data;
    }

    /**
     * Shapes potential-issue rows for the table.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _potentialTableData(array $rows, int $siteId): array
    {
        $data = [];
        $siteHandle = Craft::$app->getSites()->getSiteById($siteId)?->handle;

        foreach ($rows as $row) {
            $ruleId = (string)$row['ruleId'];
            $data[] = [
                'question' => [
                    'title' => $this->_potentialQuestion($ruleId, $row['wcagCriterion'] ?? null),
                    'ruleId' => $ruleId,
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
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
     * @param string|null $criterion The criterion this grouping is for, where
     *                               the caller groups on it.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _potentialQuestion(string $ruleId, ?string $criterion = null): string
    {
        // A rule that judges each occurrence on its own can land on more than
        // one criterion, and the listing groups on criterion, so one question
        // per rule puts the same sentence on two rows with no way to tell them
        // apart. Only rules that actually split need an entry here.
        if ($ruleId === 'potential:identical-links' && $criterion === '2.4.9') {
            return Craft::t('accessibility-audit', 'Do these identical links read the same out of context?');
        }

        return RuleRegistry::title($ruleId);
    }

    /**
     * Shapes potential-issue page rows for the table.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
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
            $label = ScanTarget::label($row, $element);

            $data[] = [
                'id' => (int)$row['elementId'],
                'page' => [
                    'title' => $label,
                    'inspectUrl' => $reportUrl,
                    'url' => ScanTarget::url($row, $element),
                ],
                'issues' => (int)$row['issueCount'],
                'count' => (int)$row['occurrences'],
                'lastScanned' => $lastScanned !== false ? $lastScanned->format('d M Y') : '—',
                'rescan' => ['id' => $element->id ?? 0, 'title' => $label],
                'report' => ['url' => $reportUrl, 'title' => $label],
            ];
        }

        return $data;
    }

    /**
     * Shapes issue-page rows for the table, each with the rule's first few
     * occurrences on that page in its detail row.
     *
     * @param array<int, array<string, mixed>> $entries
     * @param int $siteId The site the rows are for.
     * @param string $ruleId The rule the pages are listed for.
     * @return array<int, array<string, mixed>>
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _issuePagesTableData(array $entries, int $siteId, string $ruleId): array
    {
        $data = [];
        $siteHandle = Craft::$app->getSites()->getSiteById($siteId)?->handle;
        $occurrences = AccessibilityAudit::getInstance()->getAudit()->getRuleOccurrencesForScans(
            $ruleId,
            array_column($entries, 'id'),
            self::OCCURRENCES_PER_PAGE,
        );

        foreach ($entries as $row) {
            $element = $row['element'] ?? null;
            $reportUrl = UrlHelper::cpUrl(
                'accessibility-audit/page-report',
                ScanTarget::reportParams($row, $siteHandle),
            );
            $firstDetected = $row['firstDetected'] ? DateTimeHelper::toDateTime($row['firstDetected']) : false;
            $lastScanned = $row['dateScanned'] ? DateTimeHelper::toDateTime($row['dateScanned']) : false;
            $label = ScanTarget::label($row, $element);
            $elementId = (int)$row['elementId'];

            $data[] = [
                // The table tracks its detail rows by this, and every page
                // scanned by URL shares an empty element ID, so those rows are
                // told apart by their scan.
                'id' => $elementId !== 0 ? $elementId : 'scan-' . (int)$row['id'],
                'detail' => $this->_occurrencesDetail(
                    $occurrences[(int)$row['id']] ?? [],
                    (int)$row['occurrences'],
                    $reportUrl,
                    $label,
                ),
                'page' => [
                    'title' => $label,
                    'inspectUrl' => $reportUrl,
                    'url' => ScanTarget::url($row, $element),
                ],
                'score' => (int)$row['score'],
                'count' => (int)$row['occurrences'],
                'firstDetected' => $firstDetected !== false ? $firstDetected->format('d M Y') : '—',
                'lastScanned' => $lastScanned !== false ? $lastScanned->format('d M Y') : '—',
                'rescan' => ['id' => $element->id ?? 0, 'title' => $label],
                'report' => ['url' => $reportUrl, 'title' => $label],
            ];
        }

        return $data;
    }

    /**
     * The detail row for one page of a rule's page listing: what each
     * occurrence said and the markup it was found on.
     *
     * Built as HTML because that is what the table's detail row renders. Every
     * value in it came off a scanned page, so all of it is encoded.
     *
     * @param array<int, array{message: string, markup: string, selector: string}> $occurrences
     *        The occurrences to show, already capped.
     * @param int $total How many occurrences the page has in all.
     * @param string $reportUrl The page's report, where the rest are.
     * @param string $label The page's name, for the toggle's accessible name.
     * @return array{handle: string, content: string}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _occurrencesDetail(array $occurrences, int $total, string $reportUrl, string $label): array
    {
        if ($occurrences === []) {
            return ['handle' => '', 'content' => ''];
        }

        // Most rules store no selector, and a column of dashes takes room the
        // markup needs, so the column is only drawn when a row has one.
        $hasSelector = array_filter($occurrences, static fn(array $o): bool => $o['selector'] !== '') !== [];
        $rows = '';

        foreach ($occurrences as $occurrence) {
            $cells = [
                Html::tag('th', FindingHtml::format(self::_withoutLeadQuestion($occurrence['message'])), ['scope' => 'row']),
                Html::tag('td', $occurrence['markup'] !== ''
                    ? Html::tag('code', Html::encode($occurrence['markup']), ['class' => 'accessibility-audit-occ-markup'])
                    : Html::tag('span', '—', ['class' => 'light'])),
            ];

            if ($hasSelector) {
                $cells[] = Html::tag('td', $occurrence['selector'] !== ''
                    ? Html::tag('code', Html::encode($occurrence['selector']), ['class' => 'accessibility-audit-occ-selector'])
                    : Html::tag('span', '—', ['class' => 'light']));
            }

            $rows .= Html::tag('tr', implode('', $cells));
        }

        $headings = [
            Html::tag('th', Html::encode(Craft::t('accessibility-audit', 'Finding')), ['scope' => 'col']),
            Html::tag('th', Html::encode(Craft::t('accessibility-audit', 'Markup')), ['scope' => 'col']),
        ];

        if ($hasSelector) {
            $headings[] = Html::tag('th', Html::encode(Craft::t('accessibility-audit', 'Selector')), ['scope' => 'col']);
        }

        $head = Html::tag('thead', Html::tag('tr', implode('', $headings)));

        $content = Html::tag('table', $head . Html::tag('tbody', $rows), [
            'class' => array_filter([
                'data',
                'fullwidth',
                'accessibility-audit-page-issues-table',
                'accessibility-audit-occurrences',
                $hasSelector ? null : 'accessibility-audit-occurrences--no-selector',
            ]),
        ]);
        $more = $total - count($occurrences);

        if ($more > 0) {
            $content .= Html::tag(
                'p',
                Html::encode(Craft::t('accessibility-audit', 'And {n} more on this page.', ['n' => $more])) . ' '
                    . Html::a(Html::encode(Craft::t('accessibility-audit', 'View report')), $reportUrl, [
                        'target' => '_blank',
                        'rel' => 'noopener',
                    ]),
                ['class' => 'light accessibility-audit-occ-more'],
            );
        }

        return [
            // A button inside the table's own toggle, so the toggle can be
            // reached and pressed from the keyboard. The table's click handler
            // sits on the wrapper and still fires.
            'handle' => Html::tag(
                'button',
                Html::tag('span', '', [
                    'class' => 'accessibility-audit-expand-icon',
                    'data-icon' => 'downangle',
                    'aria-hidden' => 'true',
                ]),
                [
                    'type' => 'button',
                    'class' => 'accessibility-audit-expand-btn accessibility-audit-occ-toggle',
                    'aria-expanded' => 'false',
                    'aria-label' => Craft::t('accessibility-audit', 'Show the occurrences on {page}', ['page' => $label]),
                ],
            ),
            'content' => Html::tag('div', $content, ['class' => 'accessibility-audit-issues-detail']),
        ];
    }

    /**
     * A finding's message without the question it opens with.
     *
     * A review question's message starts by asking it ("Is this image alt text
     * too long?"), and these rows sit under a heading that already asks the
     * same thing, so on every row the part that differs comes second. Only a
     * leading question with more text after it is dropped.
     *
     * @param string $message The finding's message, as stored.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _withoutLeadQuestion(string $message): string
    {
        if (preg_match('/^[^?.!\n]{3,}\?\s+(?=\S)/u', $message, $m) !== 1) {
            return $message;
        }

        return mb_substr($message, mb_strlen($m[0]));
    }

    /**
     * Shapes resolved-issue rows for the table.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
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
                    'title' => (string)$row['title'],
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
     * Shapes open-issue rule rows for the table.
     *
     * @param array<int, array<string, mixed>> $rows Rows from getIssuesByImpact().
     * @param int $siteId The site the rows are for.
     * @return array<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _openIssuesTableData(array $rows, int $siteId): array
    {
        $data = [];
        $siteHandle = Craft::$app->getSites()->getSiteById($siteId)?->handle;

        foreach ($rows as $row) {
            $ruleId = (string)$row['ruleId'];
            $severity = (string)($row['severity'] ?? '');

            $data[] = [
                // A rule can list more than once, split by criterion or by
                // severity, so the ID alone does not identify a row.
                'id' => implode('|', [$ruleId, (string)($row['wcagCriterion'] ?? ''), $severity]),
                'rule' => [
                    'title' => (string)$row['title'],
                    'ruleId' => $ruleId,
                    'severity' => $severity,
                    'elementType' => (string)($row['elementType'] ?? ''),
                    'detailUrl' => UrlHelper::cpUrl('accessibility-audit/issues/detail', [
                        'ruleId' => $ruleId,
                        'site' => $siteHandle,
                    ]),
                ],
                'wcagLevel' => (string)($row['wcagLevel'] ?? ''),
                'sc' => (string)($row['wcagCriterion'] ?? ''),
                'difficulty' => (string)($row['difficulty'] ?? ''),
                'owner' => (string)($row['responsibility'] ?? ''),
                'count' => (int)$row['occurrences'],
                'points' => number_format((float)$row['pointsToGain'], 2),
            ];
        }

        return $data;
    }

    /**
     * Refuses an issue-detail read whose `scanId` belongs to a site the edition
     * or user is not allowed to view. The install-wide `view-reports` permission
     * does not scope by site, so the per-site fence has to be enforced here, or
     * a crafted scanId would expose another site's findings. Returns a JSON
     * refusal, or null when the scan's site is allowed (or the scan is unknown).
     *
     * @param int $scanId The requested scan ID.
     * @return Response|null
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.1
     */
    private function _requireAllowedScanSite(int $scanId): ?Response
    {
        $plugin = AccessibilityAudit::getInstance();
        $scanSiteId = $plugin->getAudit()->getScanSiteId($scanId);

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
