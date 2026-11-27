<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\reports;

use Craft;
use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\ReportFilters;
use johnhenry\accessibilityaudit\services\RuleRegistry;

/**
 * What was fixed over a period and what turned up new, rule by rule.
 *
 * A rule counts as fixed on a page when a fresh scan of that page no longer
 * finds it, so fixes show up as pages are scanned again. New means first
 * found during the period and still there on the page's latest scan. Only
 * definite failures count: a potential issue that drops away was never
 * established as a problem, and ignored rules are muted rather than fixed.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class FixedAndNew extends BaseReport
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function handle(): string
    {
        return 'fixed-and-new';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return Craft::t('accessibility-audit', 'Fixed and new');
    }

    /**
     * @inheritdoc
     */
    public function description(): string
    {
        return Craft::t('accessibility-audit', 'What the team fixed over a period and what turned up new, rule by rule. Handy for a monthly round-up.');
    }

    /**
     * @inheritdoc
     */
    public function category(): string
    {
        return self::CATEGORY_MANAGERS;
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
    public function data(ReportFilters $filters): array
    {
        $rows = $this->rows($filters);

        return [
            'rows' => array_slice($rows, 0, self::PRINT_ROW_LIMIT),
            'total' => count($rows),
            'fixed' => array_sum(array_column($rows, 'fixed')),
            'fixedPages' => $this->_fixedPageCount($filters),
            'new' => array_sum(array_column($rows, 'new')),
            'range' => $filters->range(),
            'outrunsRetention' => $this->periodOutrunsRetention($filters),
        ];
    }

    /**
     * @inheritdoc
     */
    public function csvHeader(): array
    {
        return ['Rule', 'Title', 'WCAG', 'Severity', 'Fixed', 'Pages fixed on', 'New', 'Pages new on', 'Net change'];
    }

    /**
     * @inheritdoc
     */
    public function csvRows(ReportFilters $filters): iterable
    {
        foreach ($this->rows($filters) as $row) {
            yield [
                $row['ruleId'],
                $row['title'],
                $row['wcagCriterion'],
                $row['severity'],
                $row['fixed'],
                $row['fixedPages'],
                $row['new'],
                $row['newPages'],
                $row['new'] - $row['fixed'],
            ];
        }
    }

    /**
     * One row per rule fixed or new in the period, the most fixed first.
     *
     * @param ReportFilters $filters The filters the report runs with.
     * @return array<int, array{ruleId: string, title: string, wcagCriterion: string, severity: string, fixed: int, fixedPages: int, new: int, newPages: int}>
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function rows(ReportFilters $filters): array
    {
        $rules = [];

        foreach ($this->_fixedQuery($filters)->all() as $row) {
            $rules[$row['ruleId']] = $this->_blank($row);
            $rules[$row['ruleId']]['fixed'] = (int)$row['occurrences'];
            $rules[$row['ruleId']]['fixedPages'] = (int)$row['pages'];
        }

        foreach ($this->_newQuery($filters)->all() as $row) {
            $rules[$row['ruleId']] ??= $this->_blank($row);
            $rules[$row['ruleId']]['new'] = (int)$row['occurrences'];
            $rules[$row['ruleId']]['newPages'] = (int)$row['pages'];
        }

        $rows = array_values($rules);
        usort($rows, static fn(array $a, array $b): int => [$b['fixed'], $b['new'], $a['ruleId']] <=> [$a['fixed'], $a['new'], $b['ruleId']]);

        return $rows;
    }

    // Private Methods
    // =========================================================================

    /**
     * Occurrences resolved in the period, per rule.
     *
     * Pages are counted by the scan the fix was recorded against, one per
     * page each time the page was scanned again.
     *
     * @param ReportFilters $filters The filters the report runs with.
     * @return Query<int, array<string, mixed>>
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _fixedQuery(ReportFilters $filters): Query
    {
        $audit = AccessibilityAudit::getInstance()->getAudit();

        return $this->_ignoring((new Query())
            ->select([
                'i.ruleId',
                'wcagCriterion' => 'MAX([[i.wcagCriterion]])',
                'severity' => 'MAX([[i.severity]])',
                'occurrences' => 'COUNT(*)',
                'pages' => 'COUNT(DISTINCT [[i.scanId]])',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], '[[s.id]] = [[i.scanId]]')
            ->where(['s.siteId' => $filters->siteId, 'i.isResolved' => true])
            ->andWhere($audit->definiteCondition('i'))
            ->andWhere(['between', 'i.dateResolved', $filters->fromUtc(), $filters->toUtc()])
            ->groupBy(['i.ruleId']));
    }

    /**
     * Occurrences first found in the period and still open, per rule.
     *
     * @param ReportFilters $filters The filters the report runs with.
     * @return Query<int, array<string, mixed>>
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _newQuery(ReportFilters $filters): Query
    {
        $audit = AccessibilityAudit::getInstance()->getAudit();

        return $this->_ignoring((new Query())
            ->select([
                'i.ruleId',
                'wcagCriterion' => 'MAX([[i.wcagCriterion]])',
                'severity' => 'MAX([[i.severity]])',
                'occurrences' => 'COUNT(*)',
                'pages' => 'COUNT(DISTINCT [[i.scanId]])',
            ])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->where(['i.scanId' => $audit->latestScanIdsQuery($filters->siteId), 'i.isResolved' => false])
            ->andWhere($audit->definiteCondition('i'))
            ->andWhere(['between', 'i.firstDetected', $filters->fromUtc(), $filters->toUtc()])
            ->groupBy(['i.ruleId']));
    }

    /**
     * How many distinct page fixes the period holds, across every rule.
     *
     * @param ReportFilters $filters The filters the report runs with.
     * @return int
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _fixedPageCount(ReportFilters $filters): int
    {
        $query = $this->_fixedQuery($filters);
        $query->select(['n' => 'COUNT(DISTINCT [[i.scanId]])'])->groupBy([]);

        return (int)$query->scalar();
    }

    /**
     * Leaves rules on the ignore list out of a query on the `i` alias.
     *
     * @param Query<int, array<string, mixed>> $query The query.
     * @return Query<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _ignoring(Query $query): Query
    {
        $ignored = AccessibilityAudit::getInstance()->getSettings()->ignoreRules;

        if (!empty($ignored)) {
            $query->andWhere(['not in', 'i.ruleId', $ignored]);
        }

        return $query;
    }

    /**
     * An empty row for a rule.
     *
     * @param array<string, mixed> $row A row naming the rule.
     * @return array{ruleId: string, title: string, wcagCriterion: string, severity: string, fixed: int, fixedPages: int, new: int, newPages: int}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _blank(array $row): array
    {
        return [
            'ruleId' => (string)$row['ruleId'],
            'title' => RuleRegistry::title((string)$row['ruleId']),
            'wcagCriterion' => (string)($row['wcagCriterion'] ?? ''),
            'severity' => (string)($row['severity'] ?? ''),
            'fixed' => 0,
            'fixedPages' => 0,
            'new' => 0,
            'newPages' => 0,
        ];
    }
}
