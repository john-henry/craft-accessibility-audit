<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\models;

use Craft;
use craft\base\Model;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;

/**
 * The filters a report runs with: the site, a period and a section.
 *
 * Dates are days in the system time zone, the day the people reading the
 * report live in. {@see self::fromUtc()} and {@see self::toUtc()} give the
 * same days as UTC bounds for comparing against stored datetimes.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class ReportFilters extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The last 7 days, today included.
     */
    public const PERIOD_7_DAYS = 'last-7-days';

    /**
     * @var string The last 30 days, today included.
     */
    public const PERIOD_30_DAYS = 'last-30-days';

    /**
     * @var string The whole of the calendar month before this one.
     */
    public const PERIOD_LAST_MONTH = 'last-month';

    /**
     * @var string The last 90 days, today included.
     */
    public const PERIOD_90_DAYS = 'last-90-days';

    /**
     * @var string From the first day of this quarter to today.
     */
    public const PERIOD_THIS_QUARTER = 'this-quarter';

    /**
     * @var string The whole of the quarter before this one.
     */
    public const PERIOD_LAST_QUARTER = 'last-quarter';

    /**
     * @var string The from and to days given.
     */
    public const PERIOD_CUSTOM = 'custom';

    // Public Properties
    // =========================================================================

    /**
     * @var int The site the report covers.
     */
    public int $siteId = 0;

    /**
     * @var string One of the PERIOD_* handles.
     */
    public string $period = self::PERIOD_30_DAYS;

    /**
     * @var string The first day of a custom period, as Y-m-d.
     */
    public string $from = '';

    /**
     * @var string The last day of a custom period, as Y-m-d.
     */
    public string $to = '';

    /**
     * @var int|null The section to narrow to, or null for every page.
     */
    public ?int $sectionId = null;

    // Public Methods
    // =========================================================================

    /**
     * The periods a report can be run over, keyed by handle.
     *
     * @return array<string, string> Labels keyed by period handle.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function periodOptions(): array
    {
        return [
            self::PERIOD_7_DAYS => Craft::t('accessibility-audit', 'Last 7 days'),
            self::PERIOD_30_DAYS => Craft::t('accessibility-audit', 'Last 30 days'),
            self::PERIOD_LAST_MONTH => Craft::t('accessibility-audit', 'Last month'),
            self::PERIOD_90_DAYS => Craft::t('accessibility-audit', 'Last 90 days'),
            self::PERIOD_THIS_QUARTER => Craft::t('accessibility-audit', 'This quarter'),
            self::PERIOD_LAST_QUARTER => Craft::t('accessibility-audit', 'Last quarter'),
            self::PERIOD_CUSTOM => Craft::t('accessibility-audit', 'Custom dates'),
        ];
    }

    /**
     * The first and last day of the period, both included.
     *
     * A custom range with a missing or unreadable day falls back to the last
     * 30 days, and one given back to front is turned round.
     *
     * @return array{0: string, 1: string} The first and last day, as Y-m-d.
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function range(): array
    {
        $today = $this->_today();
        $month = (int)$today->format('n');
        $quarterStart = (clone $today)->setDate((int)$today->format('Y'), $month - (($month - 1) % 3), 1);

        $monthStart = (clone $today)->setDate((int)$today->format('Y'), $month, 1);

        return match ($this->period) {
            self::PERIOD_7_DAYS => [(clone $today)->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')],
            self::PERIOD_LAST_MONTH => [
                (clone $monthStart)->modify('-1 month')->format('Y-m-d'),
                (clone $monthStart)->modify('-1 day')->format('Y-m-d'),
            ],
            self::PERIOD_90_DAYS => [(clone $today)->modify('-89 days')->format('Y-m-d'), $today->format('Y-m-d')],
            self::PERIOD_THIS_QUARTER => [$quarterStart->format('Y-m-d'), $today->format('Y-m-d')],
            self::PERIOD_LAST_QUARTER => [
                (clone $quarterStart)->modify('-3 months')->format('Y-m-d'),
                (clone $quarterStart)->modify('-1 day')->format('Y-m-d'),
            ],
            self::PERIOD_CUSTOM => $this->_customRange($today),
            default => [(clone $today)->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    /**
     * The start of the period's first day, as a UTC datetime string for the
     * database.
     *
     * @return string
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function fromUtc(): string
    {
        return (string)Db::prepareDateForDb(new DateTime($this->range()[0] . ' 00:00:00', $this->_zone()));
    }

    /**
     * The end of the period's last day, as a UTC datetime string for the
     * database.
     *
     * @return string
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function toUtc(): string
    {
        return (string)Db::prepareDateForDb(new DateTime($this->range()[1] . ' 23:59:59', $this->_zone()));
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['siteId'], 'integer', 'min' => 1],
            [['period'], 'in', 'range' => array_keys(self::periodOptions())],
            [['from', 'to'], 'date', 'format' => 'php:Y-m-d', 'skipOnEmpty' => true],
            [['sectionId'], 'integer', 'min' => 1, 'skipOnEmpty' => true],
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * The custom range, checked and put in order.
     *
     * @param DateTime $today Today in the system time zone.
     * @return array{0: string, 1: string}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _customRange(DateTime $today): array
    {
        $from = DateTime::createFromFormat('!Y-m-d', $this->from, $this->_zone());
        $to = DateTime::createFromFormat('!Y-m-d', $this->to, $this->_zone());

        if ($from === false || $to === false) {
            return [(clone $today)->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')];
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /**
     * Today, at midnight, in the system time zone.
     *
     * @return DateTime
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _today(): DateTime
    {
        return new DateTime('today', $this->_zone());
    }

    /**
     * The system time zone.
     *
     * @return DateTimeZone
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _zone(): DateTimeZone
    {
        return new DateTimeZone(Craft::$app->getTimeZone());
    }
}
