<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use Throwable;
use yii\base\Component;

/**
 * Records each site's headline figures once a day, so a trend can be read back
 * after the scans behind it are pruned.
 *
 * A day's row is written when a full sweep finishes, the first time the
 * Overview is opened that day, or from the console. The last write of a day
 * wins. Rows are recorded on every edition and never pruned.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class SnapshotService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The table holding one row per site per day.
     */
    public const TABLE = '{{%accessibilityaudit_snapshots}}';

    // Public Methods
    // =========================================================================

    /**
     * Writes today's row for a site from its current figures.
     *
     * A site with nothing scanned gets no row: a run of zeros before the first
     * scan would read as a site scoring nothing.
     *
     * @param int $siteId The site to record.
     * @return bool Whether a row was written.
     * @throws \yii\db\Exception
     * @throws \Exception If today's date cannot be built.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function record(int $siteId): bool
    {
        $plugin = AccessibilityAudit::getInstance();
        $audit = $plugin->getAudit();
        $summary = $audit->getSiteSummary($siteId);

        if ($summary['scannedCount'] === 0) {
            return false;
        }

        $targetScore = $plugin->getSettings()->targetScore;
        $coverage = $audit->getCoverage($siteId);
        $counts = $audit->getSnapshotCounts($siteId, $targetScore);

        $figures = [
            'avgScore' => $summary['avgScore'],
            'avgScoreA' => $summary['avgScoreA'],
            'avgScoreAA' => $summary['avgScoreAA'],
            'avgScoreAAA' => $summary['avgScoreAAA'],
            'scannedCount' => $summary['scannedCount'],
            'scannableCount' => $coverage['scannable'],
            'errorCount' => $summary['errorCount'],
            'warningCount' => $summary['warningCount'],
            'noticeCount' => $summary['noticeCount'],
            'criticalPages' => $summary['criticalPages'],
            'failingCriteriaAA' => $summary['failingCriteriaAA'],
            'targetScore' => $targetScore,
            'pagesBelowTarget' => $counts['pagesBelowTarget'],
            'pendingReview' => $counts['pendingReview'],
            'sweepRunning' => $coverage['sweeping'],
        ];

        Db::upsert(self::TABLE, ['siteId' => $siteId, 'date' => $this->today()] + $figures, $figures);

        return true;
    }

    /**
     * Writes today's row for a site unless one is already there.
     *
     * Called from page loads, so a failure is logged rather than thrown: a
     * missed snapshot costs one point on a trend, a thrown one costs the page.
     *
     * @param int $siteId The site to record.
     * @return bool Whether this call wrote the day's row, which makes it the
     * first visit of the day.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function recordIfMissing(int $siteId): bool
    {
        try {
            if ($this->hasRow($siteId, $this->today())) {
                return false;
            }

            return $this->record($siteId);
        } catch (Throwable $e) {
            Craft::warning("A11y: could not record the daily snapshot for site {$siteId}: " . $e->getMessage(), 'accessibility-audit');

            return false;
        }
    }

    /**
     * Writes today's row for every site.
     *
     * @return int How many sites got a row.
     * @throws \yii\db\Exception
     * @throws \Exception If today's date cannot be built.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function recordAll(): int
    {
        $recorded = 0;

        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            if ($this->record((int)$siteId)) {
                $recorded++;
            }
        }

        return $recorded;
    }

    /**
     * Whether a site already has a row for a day.
     *
     * @param int $siteId The site to check.
     * @param string $date The day, as Y-m-d.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function hasRow(int $siteId, string $date): bool
    {
        return (new Query())
            ->from(self::TABLE)
            ->where(['siteId' => $siteId, 'date' => $date])
            ->exists();
    }

    /**
     * A site's rows between two days, both included, oldest first.
     *
     * @param int $siteId The site to read.
     * @param string $from The first day, as Y-m-d.
     * @param string $to The last day, as Y-m-d.
     * @return array<int, array<string, mixed>> One row per recorded day.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function between(int $siteId, string $from, string $to): array
    {
        return (new Query())
            ->from(self::TABLE)
            ->where(['siteId' => $siteId])
            ->andWhere(['between', 'date', $from, $to])
            ->orderBy(['date' => SORT_ASC])
            ->all();
    }

    /**
     * Today's date in the system time zone, which is the day the people
     * reading the trend are living in.
     *
     * @return string The day, as Y-m-d.
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function today(): string
    {
        return (new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d');
    }
}
