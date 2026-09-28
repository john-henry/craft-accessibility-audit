<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\Csv;
use johnhenry\accessibilityaudit\helpers\ElementLabel;
use yii\base\Component;

/**
 * Builds accessibility reports from stored scan data.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class ReportService extends Component
{
    // Public Methods
    // =========================================================================

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
                $issue['context'] ?? '',
                $issue['helpUrl'] ?? '',
                $issue['source'],
                $issue['dateScanned'],
            ]));
        }

        fclose($fp);
        return ob_get_clean();
    }
}
