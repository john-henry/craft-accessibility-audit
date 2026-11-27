<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use Dompdf\Dompdf;
use Dompdf\Options;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\events\RegisterReportsEvent;
use johnhenry\accessibilityaudit\helpers\Csv;
use johnhenry\accessibilityaudit\helpers\ElementLabel;
use johnhenry\accessibilityaudit\reports\BaseReport;
use johnhenry\accessibilityaudit\reports\DirectorsSummary;
use johnhenry\accessibilityaudit\reports\FixedAndNew;
use johnhenry\accessibilityaudit\reports\PagesBelowTarget;
use yii\base\Component;

/**
 * Builds accessibility reports from stored scan data, and keeps the list of
 * reports on the Reports screen.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class ReportService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @event RegisterReportsEvent Fired when the list of reports is built, so a
     * module can add its own or take one away.
     */
    public const EVENT_REGISTER_REPORTS = 'registerReports';

    /**
     * @var int The longest team note the directors summary keeps, in characters.
     */
    public const NOTE_MAX_LENGTH = 2000;

    // Private Properties
    // =========================================================================

    /**
     * @var BaseReport[]|null The reports, keyed by handle, once built.
     */
    private ?array $_reports = null;

    // Public Methods
    // =========================================================================

    /**
     * Every report on the Reports screen, keyed by handle, in listing order.
     *
     * @return array<string, BaseReport>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function getReports(): array
    {
        if ($this->_reports !== null) {
            return $this->_reports;
        }

        $event = new RegisterReportsEvent([
            'reports' => [
                new DirectorsSummary(),
                new FixedAndNew(),
                new PagesBelowTarget(),
            ],
        ]);
        $this->trigger(self::EVENT_REGISTER_REPORTS, $event);

        $this->_reports = [];

        foreach ($event->reports as $report) {
            $this->_reports[$report->handle()] = $report;
        }

        return $this->_reports;
    }

    /**
     * One report by its handle.
     *
     * @param string $handle The report's handle.
     * @return BaseReport|null The report, or null for a handle nothing uses.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function getReport(string $handle): ?BaseReport
    {
        return $this->getReports()[$handle] ?? null;
    }

    /**
     * The team's note for a site's directors summary.
     *
     * @param int $siteId The site.
     * @return string The note, empty when none has been written.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function getNote(int $siteId): string
    {
        return (string)(new Query())
            ->select(['note'])
            ->from('{{%accessibilityaudit_report_notes}}')
            ->where(['siteId' => $siteId])
            ->scalar();
    }

    /**
     * Saves the team's note for a site's directors summary.
     *
     * Trimmed and cut to {@see self::NOTE_MAX_LENGTH} characters; an empty
     * note clears it.
     *
     * @param int $siteId The site.
     * @param string $note The note.
     * @param int|null $userId Who wrote it.
     * @return void
     * @throws \yii\db\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function saveNote(int $siteId, string $note, ?int $userId): void
    {
        $note = mb_substr(trim($note), 0, self::NOTE_MAX_LENGTH);
        $values = ['note' => $note, 'userId' => $userId];

        Db::upsert('{{%accessibilityaudit_report_notes}}', ['siteId' => $siteId] + $values, $values);
    }

    /**
     * Turns a report's HTML into a PDF with dompdf.
     *
     * Nothing outside the document is fetched: remote resources, PHP and
     * JavaScript are all off, since the HTML carries editor-written titles and
     * notes. DejaVu Sans ships with dompdf and covers the accented and
     * typographic characters the translations use. The paper size and
     * orientation come from the pdfPaperSize and pdfPaperOrientation
     * settings, so the template sets margins only. Dompdf's font cache and
     * temp files go under Craft's runtime folder, because the vendor folder
     * is read-only on many hosts.
     *
     * @param string $html A complete HTML document.
     * @return string The PDF bytes.
     * @throws \yii\base\Exception If the runtime folder cannot be created.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function htmlToPdf(string $html): string
    {
        $workDir = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'accessibility-audit' . DIRECTORY_SEPARATOR . 'dompdf';
        FileHelper::createDirectory($workDir);

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize($settings->pdfPaperSize);
        $options->setDefaultPaperOrientation($settings->pdfPaperOrientation);
        $options->setTempDir($workDir);
        $options->setFontCache($workDir);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string)$dompdf->output();
    }

    /**
     * Every outstanding issue on a site, as a CSV a person can open.
     *
     * One row per issue, taken from the most recent scan of each page.
     *
     * @param int $siteId The site to export issues for.
     * @return string The CSV content, empty where nothing has been scanned.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function exportCsv(int $siteId): string
    {
        $latestScanIds = (new Query())
            ->select(['MAX(id)'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['siteId' => $siteId])
            // Grouped by url as well as elementId: every URL scan shares a null
            // elementId, so grouping on that alone folds the lot into one row
            // and only the most recently scanned URL reaches the export.
            ->groupBy(['elementId', 'url'])
            ->column();

        if (empty($latestScanIds)) {
            return '';
        }

        $issues = (new Query())
            ->select([
                'i.ruleId',
                'i.wcagCriterion',
                'i.wcagLevel',
                'i.severity',
                'i.message',
                'i.context',
                'i.helpUrl',
                'i.source',
                's.elementId',
                's.url',
                's.score',
                's.dateScanned',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->where(['i.scanId' => $latestScanIds])
            ->orderBy(['i.severity' => SORT_ASC, 's.elementId' => SORT_ASC])
            ->all();

        // One query per element type rather than one per element: an export of
        // a few hundred pages was a few hundred round trips.
        $elementIds = array_map('intval', array_unique(array_filter(array_column($issues, 'elementId'))));
        $entries = AccessibilityAudit::getInstance()->getAudit()->elementsByIds($elementIds, $siteId);

        ob_start();
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Page', 'URL', 'Score', 'Severity', 'Rule', 'WCAG', 'Level', 'Message', 'Context', 'Help URL', 'Source', 'Scanned']);

        foreach ($issues as $issue) {
            $entry = $entries[$issue['elementId']] ?? null;
            // Guarded: title, message, and context are editor-controlled, so a
            // cell like `=cmd()` would run as a formula when the CSV is opened.
            fputcsv($fp, Csv::guardRow([
                ElementLabel::for($entry, (int) $issue['elementId'], (string) ($issue['url'] ?? '')),
                $entry ? ($entry->getUrl() ?? '') : (string) ($issue['url'] ?? ''),
                $issue['score'],
                $issue['severity'],
                $issue['ruleId'],
                $issue['wcagCriterion'] ?? '',
                $issue['wcagLevel'] ?? '',
                $issue['message'],
                AuditService::contextMarkup($issue['context'] ?? null),
                $issue['helpUrl'] ?? '',
                $issue['source'],
                $issue['dateScanned'],
            ]));
        }

        fclose($fp);
        return ob_get_clean();
    }
}
