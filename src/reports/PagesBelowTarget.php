<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\reports;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\DateTimeHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\ReportFilters;

/**
 * Every page scoring under the site's target, worst first.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class PagesBelowTarget extends BaseReport
{
    // Private Properties
    // =========================================================================

    /**
     * @var array<int, string> Section names already looked up, keyed by id.
     */
    private array $_sectionNames = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function handle(): string
    {
        return 'pages-below-target';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return Craft::t('accessibility-audit', 'Pages below target');
    }

    /**
     * @inheritdoc
     */
    public function description(): string
    {
        return Craft::t('accessibility-audit', 'Every page scoring under your target, worst first, with its section and how many errors it has. A to-do list for whoever looks after the content.');
    }

    /**
     * @inheritdoc
     */
    public function category(): string
    {
        return self::CATEGORY_EDITORS;
    }

    /**
     * @inheritdoc
     */
    public function filters(): array
    {
        return [self::FILTER_SECTION];
    }

    /**
     * @inheritdoc
     */
    public function data(ReportFilters $filters): array
    {
        $target = AccessibilityAudit::getInstance()->getSettings()->targetScore;

        if ($target <= 0) {
            return ['target' => 0, 'total' => 0, 'rows' => [], 'sectionName' => null];
        }

        $query = $this->_query($filters, $target);
        $total = (int)(clone $query)->count();
        $rows = array_map(
            fn(array $row): array => $this->_shape($row, $filters->siteId),
            $query->limit(self::PRINT_ROW_LIMIT)->all(),
        );

        return [
            'target' => $target,
            'total' => $total,
            'rows' => $rows,
            'sectionName' => $filters->sectionId !== null ? $this->_sectionName($filters->sectionId) : null,
        ];
    }

    /**
     * @inheritdoc
     */
    public function csvHeader(): array
    {
        return ['Page', 'URL', 'Section', 'Score', 'Errors', 'Warnings', 'Last scanned'];
    }

    /**
     * @inheritdoc
     */
    public function csvRows(ReportFilters $filters): iterable
    {
        $target = AccessibilityAudit::getInstance()->getSettings()->targetScore;

        if ($target <= 0) {
            return;
        }

        foreach ($this->_query($filters, $target)->batch(500) as $batch) {
            foreach ($batch as $row) {
                $page = $this->_shape($row, $filters->siteId);

                yield [
                    $page['title'],
                    $page['url'],
                    $page['section'],
                    $page['score'],
                    $page['errors'],
                    $page['warnings'],
                    $page['lastScanned'],
                ];
            }
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * The latest scans under the target, worst first.
     *
     * @param ReportFilters $filters The filters the report runs with.
     * @param int $target The target score.
     * @return Query<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _query(ReportFilters $filters, int $target): Query
    {
        $query = (new Query())
            ->select([
                's.elementId',
                's.url',
                'scanTitle' => 's.title',
                'es.title',
                'es.uri',
                's.score',
                's.errorCount',
                's.warningCount',
                's.dateScanned',
                'en.sectionId',
            ])
            ->from(['s' => '{{%accessibilityaudit_scans}}'])
            ->leftJoin(
                ['es' => Table::ELEMENTS_SITES],
                ['and', '[[es.elementId]] = [[s.elementId]]', ['es.siteId' => $filters->siteId]],
            )
            ->leftJoin(['en' => Table::ENTRIES], '[[en.id]] = [[s.elementId]]')
            ->where(['s.id' => AccessibilityAudit::getInstance()->getAudit()->latestScanIdsQuery($filters->siteId)])
            ->andWhere(['<', 's.score', $target])
            ->orderBy(['s.score' => SORT_ASC, 's.errorCount' => SORT_DESC, 's.id' => SORT_ASC]);

        if ($filters->sectionId !== null) {
            $query->andWhere(['en.sectionId' => $filters->sectionId]);
        }

        return $query;
    }

    /**
     * One row as the page and the CSV show it.
     *
     * @param array<string, mixed> $row A row from the query.
     * @param int $siteId The site the report covers.
     * @return array{title: string, url: string, section: string, score: int, errors: int, warnings: int, lastScanned: string}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _shape(array $row, int $siteId): array
    {
        $audit = AccessibilityAudit::getInstance()->getAudit();
        $url = '';

        if ($row['elementId'] !== null) {
            $uri = (string)($row['uri'] ?? '');
            $base = rtrim((string)Craft::$app->getSites()->getSiteById($siteId)?->getBaseUrl(), '/');
            $url = ($uri === '' || $uri === '__home__') ? $base : $base . '/' . $uri;
        } elseif (($row['url'] ?? '') !== '') {
            $url = (string)($audit->absoluteUrl((string)$row['url'], $siteId) ?? $row['url']);
        }

        $title = (string)($row['title'] ?? '');

        if ($title === '') {
            $title = (string)($row['scanTitle'] ?? '');
        }

        if ($title === '') {
            $title = $url !== '' ? $url : Craft::t('accessibility-audit', 'Element #{id}', ['id' => (int)$row['elementId']]);
        }

        $scanned = DateTimeHelper::toDateTime($row['dateScanned']);

        return [
            'title' => $title,
            'url' => $url,
            'section' => $row['sectionId'] !== null ? $this->_sectionName((int)$row['sectionId']) : '',
            'score' => (int)$row['score'],
            'errors' => (int)$row['errorCount'],
            'warnings' => (int)$row['warningCount'],
            'lastScanned' => $scanned !== false ? $scanned->format('Y-m-d') : '',
        ];
    }

    /**
     * A section's name, looked up once per report.
     *
     * @param int $sectionId The section.
     * @return string The name, or an empty string for a section that is gone.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _sectionName(int $sectionId): string
    {
        if (!isset($this->_sectionNames[$sectionId])) {
            $this->_sectionNames[$sectionId] = (string)Craft::$app->getEntries()->getSectionById($sectionId)?->name;
        }

        return $this->_sectionNames[$sectionId];
    }
}
