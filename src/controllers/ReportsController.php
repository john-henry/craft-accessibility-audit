<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\web\Controller;
use craft\web\View;
use DateTime;
use DateTimeZone;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\Csv;
use johnhenry\accessibilityaudit\helpers\InLanguage;
use johnhenry\accessibilityaudit\models\ReportFilters;
use johnhenry\accessibilityaudit\reports\BaseReport;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Reports screen: a list of named reports, each with its filters, a
 * printable page to save as PDF and, where it has rows, a CSV.
 *
 * Reports are Pro. The list and each report's page show the upgrade note on
 * Lite and Plus; the printable page, the CSV and saving the team note
 * refuse outright.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class ReportsController extends Controller
{
    // Traits
    // =========================================================================

    use ProGateTrait;

    // Const Properties
    // =========================================================================

    /**
     * @var string The permission every report read needs.
     */
    public const PERMISSION_VIEW = 'accessibility-audit:view-reports';

    /**
     * @var string The permission saving the team note needs.
     */
    public const PERMISSION_EDIT_NOTE = 'accessibility-audit:run-scans';

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    // Public Methods
    // =========================================================================

    /**
     * The list of reports, a category at a time.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionIndex(): Response
    {
        $this->requirePermission(self::PERMISSION_VIEW);

        $plugin = AccessibilityAudit::getInstance();
        $categories = self::categories();
        $category = (string)$this->request->getQueryParam('category', BaseReport::CATEGORY_LEADERSHIP);

        if (!isset($categories[$category])) {
            $category = BaseReport::CATEGORY_LEADERSHIP;
        }

        $reports = array_filter(
            $plugin->getReport()->getReports(),
            static fn(BaseReport $report): bool => $category === 'all' || $report->category() === $category,
        );

        return $this->renderTemplate('accessibility-audit/reports/index', [
            'isPro' => $plugin->isPro(),
            'categories' => $categories,
            'category' => $category,
            'reports' => $reports,
            'sites' => $plugin->allowedSites(),
            'siteHandle' => $plugin->requestedSite()->handle,
        ]);
    }

    /**
     * One report's page: what it shows, its filters, and the buttons that
     * open the printable page and download the CSV.
     *
     * @param string|null $handle The report's handle, from the URL rule.
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionReport(?string $handle = null): Response
    {
        $this->requirePermission(self::PERMISSION_VIEW);

        $plugin = AccessibilityAudit::getInstance();
        $report = $this->_report($handle);
        $filters = $this->_filters();
        $isPro = $plugin->isPro();

        return $this->renderTemplate('accessibility-audit/reports/report', [
            'isPro' => $isPro,
            'report' => $report,
            'filters' => $filters,
            'periodOptions' => ReportFilters::periodOptions(),
            'sectionOptions' => $this->_sectionOptions($filters->siteId),
            'note' => $isPro && $report->handle() === 'directors-summary' ? $plugin->getReport()->getNote($filters->siteId) : null,
            'canEditNote' => Craft::$app->getUser()->checkPermission(self::PERMISSION_EDIT_NOTE),
            'targetScore' => $plugin->getSettings()->targetScore,
            'sweeping' => $plugin->getAudit()->isSweepRunning($filters->siteId),
            'categories' => self::categories(),
            'sites' => $plugin->allowedSites(),
            'siteHandle' => $plugin->requestedSite()->handle,
        ]);
    }

    /**
     * The printable page, a standalone document to print or save as PDF.
     *
     * Written in the language of the site it covers rather than the reader's
     * control panel language, since it is sent on to other people.
     *
     * @param string|null $handle The report's handle, from the URL rule.
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionView(?string $handle = null): Response
    {
        $this->requirePermission(self::PERMISSION_VIEW);
        $this->requirePro('Reports');

        $report = $this->_report($handle);
        $filters = $this->_filters();
        $site = Craft::$app->getSites()->getSiteById($filters->siteId);
        $language = $site->language ?? Craft::$app->language;

        $html = InLanguage::run($language, function() use ($report, $filters, $site, $language): string {
            $organisation = AccessibilityAudit::getInstance()->getOrganisation()->getMeta($filters->siteId);

            return Craft::$app->getView()->renderTemplate($report->printTemplate(), [
                'report' => $report,
                'filters' => $filters,
                'data' => $report->data($filters),
                'freshness' => $report->freshness($filters->siteId),
                'siteName' => (string)$site?->getName(),
                'organisationName' => (string)($organisation['organisationName'] ?? ''),
                'generated' => $this->_today(),
                'language' => $language,
            ], View::TEMPLATE_MODE_CP);
        });

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->content = $html;

        return $response;
    }

    /**
     * Downloads a report's rows as a CSV.
     *
     * @param string|null $handle The report's handle, from the URL rule.
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionCsv(?string $handle = null): Response
    {
        $this->requirePermission(self::PERMISSION_VIEW);
        $this->requirePro('Reports');

        $report = $this->_report($handle);

        if (!$report->hasCsv()) {
            throw new NotFoundHttpException(Craft::t('accessibility-audit', 'That report has no CSV.'));
        }

        $filters = $this->_filters();
        $handleOut = fopen('php://temp', 'r+');
        assert($handleOut !== false);
        fputcsv($handleOut, $report->csvHeader());

        // Every cell is guarded: titles, URLs and messages are editor
        // controlled, and a cell like `=cmd()` runs as a formula when the
        // file is opened in a spreadsheet.
        foreach ($report->csvRows($filters) as $row) {
            fputcsv($handleOut, Csv::guardRow($row));
        }

        rewind($handleOut);
        $csv = (string)stream_get_contents($handleOut);
        fclose($handleOut);

        $site = Craft::$app->getSites()->getSiteById($filters->siteId);
        $filename = 'accessibility-' . $report->handle() . '-' . ($site->handle ?? 'site') . '-' . $this->_today() . '.csv';

        return Csv::download($csv, $filename);
    }

    /**
     * Saves the team's note for the directors summary.
     *
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     * @throws \yii\db\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionSaveNote(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_VIEW);
        $this->requirePermission(self::PERMISSION_EDIT_NOTE);
        $this->requirePro('Reports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = (int)$this->request->getRequiredBodyParam('siteId');

        if (!$plugin->isSiteAllowed($siteId)) {
            throw new ForbiddenHttpException(Craft::t('accessibility-audit', 'You do not have permission to edit that site.'));
        }

        $plugin->getReport()->saveNote(
            $siteId,
            (string)$this->request->getBodyParam('note', ''),
            Craft::$app->getUser()->getId(),
        );

        $this->setSuccessFlash(Craft::t('accessibility-audit', 'Note saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * The report categories, keyed by handle, in sidebar order.
     *
     * @return array<string, string> Labels keyed by category handle.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function categories(): array
    {
        return [
            BaseReport::CATEGORY_LEADERSHIP => Craft::t('accessibility-audit', 'For leadership'),
            BaseReport::CATEGORY_MANAGERS => Craft::t('accessibility-audit', 'For managers'),
            BaseReport::CATEGORY_EDITORS => Craft::t('accessibility-audit', 'For editors'),
            'all' => Craft::t('accessibility-audit', 'All reports'),
            'downloads' => Craft::t('accessibility-audit', 'Other downloads'),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * The report a handle names.
     *
     * The CP URL rules hand the handle to the action as an argument, which
     * Craft does not add to the request's params; a plain action URL carries
     * it in the query string instead.
     *
     * @param string|null $handle The handle from the URL rule, if any.
     * @return BaseReport
     * @throws NotFoundHttpException For a handle no report uses.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _report(?string $handle): BaseReport
    {
        $handle ??= (string)$this->request->getParam('handle', '');
        $report = AccessibilityAudit::getInstance()->getReport()->getReport($handle);

        if ($report === null) {
            throw new NotFoundHttpException(Craft::t('accessibility-audit', 'There is no report by that name.'));
        }

        return $report;
    }

    /**
     * The filters from the query string, with anything that doesn't validate
     * put back to its default.
     *
     * @return ReportFilters
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _filters(): ReportFilters
    {
        $filters = new ReportFilters();
        $filters->siteId = AccessibilityAudit::getInstance()->requestedSiteId();
        $filters->period = (string)$this->request->getQueryParam('period', ReportFilters::PERIOD_30_DAYS);
        $filters->from = trim((string)$this->request->getQueryParam('from', ''));
        $filters->to = trim((string)$this->request->getQueryParam('to', ''));
        $section = (int)$this->request->getQueryParam('sectionId', 0);
        $filters->sectionId = $section > 0 ? $section : null;

        if (!$filters->validate()) {
            $defaults = new ReportFilters();

            foreach (array_keys($filters->getErrors()) as $attribute) {
                $filters->$attribute = $defaults->$attribute;
            }

            $filters->clearErrors();
        }

        return $filters;
    }

    /**
     * Today in the system time zone.
     *
     * @return string The day, as Y-m-d.
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _today(): string
    {
        return (new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d');
    }

    /**
     * The sections with pages on a site, for the section filter.
     *
     * @param int $siteId The site.
     * @return array<int, array{label: string, value: int|string}>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _sectionOptions(int $siteId): array
    {
        $options = [['label' => Craft::t('accessibility-audit', 'Every section'), 'value' => '']];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $siteSettings = $section->getSiteSettings()[$siteId] ?? null;

            if ($siteSettings === null || !$siteSettings->hasUrls) {
                continue;
            }

            $options[] = ['label' => Craft::t('site', $section->name), 'value' => (int)$section->id];
        }

        return $options;
    }
}
