<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\reports;

use Craft;
use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\ReportFilters;

/**
 * A one-page summary for leadership: the score and how it has moved, the
 * biggest risks, what was fixed, and a note from the team.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class DirectorsSummary extends BaseReport
{
    // Const Properties
    // =========================================================================

    /**
     * @var int How many risks the summary lists.
     */
    public const RISK_LIMIT = 5;

    /**
     * @var int How many repeated problems the summary lists.
     */
    public const SHARED_LIMIT = 3;

    /**
     * @var int The trend chart's drawing width, in SVG units.
     */
    public const CHART_WIDTH = 600;

    /**
     * @var int The trend chart's drawing height, in SVG units.
     */
    public const CHART_HEIGHT = 120;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function handle(): string
    {
        return 'directors-summary';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return Craft::t('accessibility-audit', 'Directors summary');
    }

    /**
     * @inheritdoc
     */
    public function description(): string
    {
        return Craft::t('accessibility-audit', 'One page in plain words for leadership: the score and how it has moved, the biggest risks, what was fixed, and a note from the team on what they are doing about it.');
    }

    /**
     * @inheritdoc
     */
    public function category(): string
    {
        return self::CATEGORY_LEADERSHIP;
    }

    /**
     * @inheritdoc
     */
    public function filters(): array
    {
        return [self::FILTER_PERIOD];
    }

    /**
     * @inheritdoc
     */
    public function hasCsv(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function data(ReportFilters $filters): array
    {
        $plugin = AccessibilityAudit::getInstance();
        $audit = $plugin->getAudit();
        $siteId = $filters->siteId;
        [$from, $to] = $filters->range();

        $summary = $audit->getSiteSummary($siteId);
        $target = $plugin->getSettings()->targetScore;
        $counts = $audit->getSnapshotCounts($siteId, $target);
        $trend = $plugin->getSnapshots()->between($siteId, $from, $to);
        // With a single day recorded the first day is today, and a change
        // measured against itself would always read 0.
        $start = count($trend) >= 2 ? $trend[0] : null;

        $pageCounts = $audit->getRulePageCounts($siteId);
        $risks = array_map(static fn(array $row): array => [
            'title' => (string)$row['title'],
            'severity' => (string)$row['severity'],
            'wcagCriterion' => (string)($row['wcagCriterion'] ?? ''),
            'occurrences' => (int)$row['occurrences'],
            'pages' => (int)($pageCounts[$row['ruleId']] ?? 0),
        ], $audit->getIssuesByImpact($siteId, self::RISK_LIMIT));

        $shared = array_map(static fn(array $row): array => [
            'title' => (string)$row['title'],
            'pages' => (int)$row['pageCount'],
        ], array_slice($audit->getTemplateIssues($siteId), 0, self::SHARED_LIMIT));

        $fixedAndNew = new FixedAndNew();
        $fixedRows = $fixedAndNew->rows($filters);

        return [
            'range' => [$from, $to],
            'score' => $summary['scannedCount'] > 0 ? $summary['avgScore'] : null,
            'scoreAtStart' => $start !== null ? (int)$start['avgScore'] : null,
            'startDate' => $start['date'] ?? null,
            'failingCriteriaAA' => $summary['failingCriteriaAA'],
            'criticalPages' => $summary['criticalPages'],
            'target' => $target,
            'pagesBelowTarget' => $counts['pagesBelowTarget'],
            'pendingReview' => $counts['pendingReview'],
            'risks' => $risks,
            'shared' => $shared,
            'fixed' => array_sum(array_column($fixedRows, 'fixed')),
            'new' => array_sum(array_column($fixedRows, 'new')),
            'outrunsRetention' => $this->periodOutrunsRetention($filters),
            'trend' => $trend,
            'chart' => self::chartPoints($trend),
            'vpatRecorded' => $plugin->isPro() ? $this->_latestVpatRevision($siteId) : null,
            'statementStale' => $plugin->getStatement()->isStale($siteId),
            'note' => $plugin->getReport()->getNote($siteId),
        ];
    }

    /**
     * Polyline points for the score trend, scaled to the chart box with the
     * score axis fixed at 0 to 100 so a small wobble isn't drawn as a cliff.
     *
     * @param array<int, array<string, mixed>> $trend Snapshot rows, oldest first.
     * @return string The `points` attribute, empty with fewer than two days.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function chartPoints(array $trend): string
    {
        $count = count($trend);

        if ($count < 2) {
            return '';
        }

        $points = [];

        foreach (array_values($trend) as $i => $row) {
            $x = round($i / ($count - 1) * self::CHART_WIDTH, 1);
            $y = round(self::CHART_HEIGHT - ((int)$row['avgScore'] / 100) * self::CHART_HEIGHT, 1);
            $points[] = $x . ',' . $y;
        }

        return implode(' ', $points);
    }

    // Private Methods
    // =========================================================================

    /**
     * The day the site's VPAT was last recorded as a revision.
     *
     * @param int $siteId The site.
     * @return string|null The naive UTC datetime, or null when never recorded.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _latestVpatRevision(int $siteId): ?string
    {
        $value = (new Query())
            ->from('{{%accessibilityaudit_vpat_revisions}}')
            ->where(['siteId' => $siteId])
            ->max('[[dateCreated]]');

        return $value !== null && $value !== false ? (string)$value : null;
    }
}
