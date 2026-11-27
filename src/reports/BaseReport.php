<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\reports;

use craft\db\Query;
use craft\helpers\DateTimeHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\ReportFilters;
use johnhenry\accessibilityaudit\models\SettingsModel;

/**
 * A named report on the Reports screen: what it is, who it's for, the filters
 * it takes, and the data behind its printable page and its CSV.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
abstract class BaseReport
{
    // Const Properties
    // =========================================================================

    /**
     * @var string Reports for leadership.
     */
    public const CATEGORY_LEADERSHIP = 'leadership';

    /**
     * @var string Reports for people managing the site and its team.
     */
    public const CATEGORY_MANAGERS = 'managers';

    /**
     * @var string Reports for the people writing the content.
     */
    public const CATEGORY_EDITORS = 'editors';

    /**
     * @var string The period filter.
     */
    public const FILTER_PERIOD = 'period';

    /**
     * @var string The section filter.
     */
    public const FILTER_SECTION = 'section';

    /**
     * @var int Most rows a printable table shows. The CSV holds the lot.
     */
    public const PRINT_ROW_LIMIT = 200;

    // Public Methods
    // =========================================================================

    /**
     * The report's handle, used in its URL.
     *
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    abstract public function handle(): string;

    /**
     * The report's name.
     *
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    abstract public function name(): string;

    /**
     * One or two sentences on what the report shows and who it suits.
     *
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    abstract public function description(): string;

    /**
     * The CATEGORY_* the report is listed under.
     *
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    abstract public function category(): string;

    /**
     * The FILTER_* fields the report takes, beyond the site.
     *
     * @return string[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Whether the report can be downloaded as a CSV.
     *
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function hasCsv(): bool
    {
        return true;
    }

    /**
     * The template that renders the report's printable page.
     *
     * @return string A CP template path.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function printTemplate(): string
    {
        return 'accessibility-audit/reports/_print/' . $this->handle();
    }

    /**
     * Everything the printable page reads.
     *
     * @param ReportFilters $filters The filters the report runs with.
     * @return array<string, mixed>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    abstract public function data(ReportFilters $filters): array;

    /**
     * The CSV's header row.
     *
     * @return string[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function csvHeader(): array
    {
        return [];
    }

    /**
     * The CSV's rows, one array of cells each, unguarded.
     *
     * @param ReportFilters $filters The filters the report runs with.
     * @return iterable<int, array<int, string|int|float|null>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function csvRows(ReportFilters $filters): iterable
    {
        return [];
    }

    /**
     * How fresh the scans behind a report are, for the line every printed
     * report ends with.
     *
     * @param int $siteId The site the report covers.
     * @return array{scanned: int, scannable: int, sweeping: bool, oldestDays: int|null, newestDays: int|null}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function freshness(int $siteId): array
    {
        $audit = AccessibilityAudit::getInstance()->getAudit();
        $coverage = $audit->getCoverage($siteId);

        $range = (new Query())
            ->select(['oldest' => 'MIN([[dateScanned]])', 'newest' => 'MAX([[dateScanned]])'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['id' => $audit->latestScanIdsQuery($siteId)])
            ->one();

        return [
            'scanned' => $coverage['scanned'],
            'scannable' => $coverage['scannable'],
            'sweeping' => $coverage['sweeping'],
            'oldestDays' => $this->_daysAgo($range['oldest'] ?? null),
            'newestDays' => $this->_daysAgo($range['newest'] ?? null),
        ];
    }

    /**
     * Whether the period starts before the plugin still holds the scans that
     * would answer for it, so a report can say its counts may be short.
     *
     * @param ReportFilters $filters The filters the report runs with.
     * @return bool
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function periodOutrunsRetention(ReportFilters $filters): bool
    {
        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        if ($plugin->isPro() && $settings->resolvedRetention === SettingsModel::RESOLVED_RETENTION_FOREVER) {
            return false;
        }

        $days = $settings->retainDays;
        $cap = $plugin->retentionCap();

        if ($cap !== null) {
            $days = $days > 0 ? min($days, $cap) : $cap;
        }

        if ($days <= 0) {
            return false;
        }

        $oldestKept = DateTimeHelper::now()->modify("-{$days} days")->format('Y-m-d');

        return $filters->range()[0] < $oldestKept;
    }

    // Private Methods
    // =========================================================================

    /**
     * Whole days between a stored datetime and now.
     *
     * @param string|null $value A naive UTC datetime from the database.
     * @return int|null The days, or null with no value.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _daysAgo(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value);

        if ($date === false) {
            return null;
        }

        return (int)$date->diff(DateTimeHelper::now())->days;
    }
}
