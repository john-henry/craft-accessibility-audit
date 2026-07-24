<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\Csv;
use johnhenry\accessibilityaudit\helpers\ElementLabel;
use yii\base\Component;

/**
 * Builds accessibility reports from stored scan data: CSV exports and
 * summary breakdowns for the CP dashboard.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class ReportService extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Generate a CSV export of all issues for a site.
     *
     * @param int $siteId The site to export issues for.
     * @return string The CSV content.
     */
    public function exportCsv(int $siteId): string
    {
        $latestScanIds = (new Query())
            ->select(['MAX(id)'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['siteId' => $siteId])
            ->groupBy(['elementId'])
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
                's.score',
                's.dateScanned',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
            ->where(['i.scanId' => $latestScanIds])
            ->orderBy(['i.severity' => SORT_ASC, 's.elementId' => SORT_ASC])
            ->all();

        $elementIds = array_unique(array_column($issues, 'elementId'));

        // Load each element individually so categories, assets, Commerce
        // products, and other element types are included, not just entries.
        $entries = [];
        foreach ($elementIds as $elementId) {
            $element = Craft::$app->getElements()->getElementById((int) $elementId, null, $siteId);
            if ($element !== null) {
                $entries[(int) $elementId] = $element;
            }
        }

        ob_start();
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Page', 'URL', 'Score', 'Severity', 'Rule', 'WCAG', 'Level', 'Message', 'Context', 'Help URL', 'Source', 'Scanned']);

        foreach ($issues as $issue) {
            $entry = $entries[$issue['elementId']] ?? null;
            // Guarded: title, message, and context are editor-controlled, so a
            // cell like `=cmd()` would run as a formula when the CSV is opened.
            fputcsv($fp, Csv::guardRow([
                ElementLabel::for($entry, (int) $issue['elementId']),
                $entry ? ($entry->getUrl() ?? '') : '',
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

    /**
     * Generate a summary array suitable for a report page.
     *
     * @param int $siteId The site to summarise.
     * @return array
     */
    public function getSummaryReport(int $siteId): array
    {
        $audit = AccessibilityAudit::getInstance()->audit;
        $summary = $audit->getSiteSummary($siteId);
        $byRule = $audit->getIssuesByRule($siteId);

        $wcagBreakdown = [];
        foreach ($byRule as $row) {
            $criterion = $row['wcagCriterion'] ?? 'Other';
            $wcagBreakdown[$criterion] = ($wcagBreakdown[$criterion] ?? 0) + (int) $row['count'];
        }
        arsort($wcagBreakdown);

        return array_merge($summary, [
            'byRule' => $byRule,
            'wcagBreakdown' => $wcagBreakdown,
        ]);
    }
}
