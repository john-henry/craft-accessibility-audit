<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\web\View;
use DateTime;
use DateTimeZone;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\InLanguage;
use johnhenry\accessibilityaudit\jobs\SendScheduledReport;
use johnhenry\accessibilityaudit\models\ReportFilters;
use johnhenry\accessibilityaudit\models\SettingsModel;
use johnhenry\accessibilityaudit\reports\DirectorsSummary;
use Throwable;
use yii\base\Component;

/**
 * Sends the directors summary on a schedule, by email and optionally to Slack.
 *
 * Craft has no scheduler of its own, so the check runs from two places: the
 * `accessibility-audit/audit/send-reports` console command for sites with
 * cron, and the same moments the daily snapshot is recorded (a finished
 * sweep, the first Overview visit of the day). The second route only fires
 * when someone or something touches the site, so on a quiet install a
 * summary can go out late; cron is the reliable route.
 *
 * A site's send date is claimed before the job is queued, so two requests
 * checking at once queue it once. A send that reaches no destination at all
 * gives the claim back, so the next check tries again. The checks run at most
 * once a day from page loads (they ride on the daily snapshot), so a broken
 * mailer costs one failed attempt a day, not one per request.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class ScheduledReportService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The table holding each site's last send date.
     */
    public const TABLE = '{{%accessibilityaudit_report_sends}}';

    // Public Methods
    // =========================================================================

    /**
     * Whether scheduled summaries are on and have somewhere to go.
     *
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function isEnabled(): bool
    {
        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        if (!$plugin->isPro() || $settings->reportSchedule === SettingsModel::REPORT_SCHEDULE_OFF) {
            return false;
        }

        return $this->recipients() !== [] || $this->_slackOn();
    }

    /**
     * Who the summary is emailed to: its own list, or the notification
     * recipients when that is empty.
     *
     * @return string[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function recipients(): array
    {
        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();
        $raw = trim($settings->reportScheduleRecipients) !== ''
            ? $settings->reportScheduleRecipients
            : $settings->notifyEmailRecipients;

        return $plugin->getNotifications()->parseRecipients($raw);
    }

    /**
     * The day a site's summary last went out.
     *
     * @param int $siteId The site.
     * @return string|null The day as Y-m-d, or null when never sent.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function lastSent(int $siteId): ?string
    {
        $value = (new Query())
            ->select(['lastSent'])
            ->from(self::TABLE)
            ->where(['siteId' => $siteId])
            ->scalar();

        return $value !== false && $value !== null ? (string)$value : null;
    }

    /**
     * Whether a site's summary is due: never sent, or last sent a week or
     * more ago (weekly), or in an earlier calendar month (monthly).
     *
     * @param int $siteId The site.
     * @param string|null $today Today as Y-m-d, or null for the real today.
     * @return bool
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function isDue(int $siteId, ?string $today = null): bool
    {
        $today ??= $this->today();
        $last = $this->lastSent($siteId);

        if ($last === null) {
            return true;
        }

        return match (AccessibilityAudit::getInstance()->getSettings()->reportSchedule) {
            SettingsModel::REPORT_SCHEDULE_WEEKLY => (new DateTime($last))->modify('+7 days')->format('Y-m-d') <= $today,
            SettingsModel::REPORT_SCHEDULE_MONTHLY => substr($last, 0, 7) < substr($today, 0, 7),
            default => false,
        };
    }

    /**
     * Queues the summary for every site that is due one.
     *
     * Called from page loads and the end of a sweep, so a failure is logged
     * rather than thrown.
     *
     * @return int How many sends were queued.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function queueDue(): int
    {
        $queued = 0;

        try {
            if (!$this->isEnabled()) {
                return 0;
            }

            foreach ($this->_scannedSiteIds() as $siteId) {
                $claim = $this->_claim($siteId);

                if ($claim['claimed']) {
                    Craft::$app->getQueue()->push(new SendScheduledReport([
                        'siteId' => $siteId,
                        'previousSent' => $claim['previous'],
                    ]));
                    $queued++;
                }
            }
        } catch (Throwable $e) {
            Craft::warning('A11y: could not check for scheduled summaries: ' . $e->getMessage(), 'accessibility-audit');
        }

        return $queued;
    }

    /**
     * Sends the summary straight away for every site that is due one, or for
     * every scanned site when forced. For the console command.
     *
     * @param bool $force Send whether or not a summary is due.
     * @return int How many sites were sent a summary.
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function sendDue(bool $force = false): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $sent = 0;

        foreach ($this->_scannedSiteIds() as $siteId) {
            if ($force) {
                $previous = $this->lastSent($siteId);
                $this->_markSent($siteId);
            } else {
                $claim = $this->_claim($siteId);

                if (!$claim['claimed']) {
                    continue;
                }

                $previous = $claim['previous'];
            }

            if ($this->sendClaimed($siteId, $previous)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Sends a summary whose send date has already been claimed, and gives the
     * claim back when it reaches no destination, so the next check retries.
     *
     * The schedule is checked again here, since a queued send can run after
     * it was switched off.
     *
     * @param int $siteId The site.
     * @param string|null $previous The last send date before the claim, or
     * null when it had never been sent.
     * @return bool Whether it reached at least one destination.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function sendClaimed(int $siteId, ?string $previous): bool
    {
        if ($this->isEnabled() && $this->send($siteId)) {
            return true;
        }

        try {
            $this->_release($siteId, $previous);
        } catch (Throwable $e) {
            Craft::error("A11y: could not give back the scheduled summary claim for site {$siteId}: " . $e->getMessage(), 'accessibility-audit');
        }

        return false;
    }

    /**
     * Builds and sends one site's summary now, whether or not it is due.
     *
     * @param int $siteId The site.
     * @return bool Whether it reached at least one destination.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function send(int $siteId): bool
    {
        $plugin = AccessibilityAudit::getInstance();
        $site = Craft::$app->getSites()->getSiteById($siteId);

        if ($site === null) {
            return false;
        }

        try {
            $filters = $this->filtersFor($siteId);
            $report = new DirectorsSummary();
            $data = $report->data($filters);
            $reportUrl = UrlHelper::cpUrl('accessibility-audit/reports/' . $report->handle(), ['site' => $site->handle]);
            $context = $plugin->getNotifications()->contextLine();

            $withPdf = $plugin->getSettings()->reportScheduleAttachPdf && $this->recipients() !== [];

            [$subject, $text, $html, $pdf] = InLanguage::run($site->language, function() use ($site, $report, $data, $filters, $reportUrl, $context, $siteId, $withPdf): array {
                $organisation = AccessibilityAudit::getInstance()->getOrganisation()->getMeta($siteId);
                $organisationName = (string)($organisation['organisationName'] ?? '');
                [$from, $to] = $filters->range();
                $subject = Craft::t('accessibility-audit', '{site}: accessibility summary, {from} to {to}', [
                    'site' => $site->getName(),
                    'from' => $from,
                    'to' => $to,
                ]);
                $pdf = $withPdf ? $this->_pdf($report, $data, $filters, $subject, $site->getName(), $organisationName, $site->language) : null;
                $text = $this->textBody($data, $reportUrl);
                $html = Craft::$app->getView()->renderTemplate('accessibility-audit/reports/_email/directors-summary', [
                    'subject' => $subject,
                    'data' => $data,
                    'siteName' => $site->getName(),
                    'organisationName' => $organisationName,
                    'reportUrl' => $reportUrl,
                    'contextLine' => $context,
                    'language' => $site->language,
                    'attached' => $pdf !== null,
                ], View::TEMPLATE_MODE_CP);

                return [$subject, $text, $html, $pdf];
            });
        } catch (Throwable $e) {
            Craft::error("A11y: could not build the scheduled summary for site {$siteId}: " . $e->getMessage(), 'accessibility-audit');

            return false;
        }

        $delivered = false;
        $recipients = $this->recipients();

        if ($recipients !== []) {
            try {
                $message = Craft::$app->getMailer()
                    ->compose()
                    ->setTo($recipients)
                    ->setSubject($subject)
                    ->setTextBody($text)
                    ->setHtmlBody($html);

                if ($pdf !== null) {
                    $message->attachContent($pdf, [
                        'fileName' => 'accessibility-summary-' . $site->handle . '-' . $this->today() . '.pdf',
                        'contentType' => 'application/pdf',
                    ]);
                }

                $delivered = $message->send();
            } catch (Throwable $e) {
                Craft::error("A11y: the scheduled summary email for site {$siteId} was not sent: " . $e->getMessage(), 'accessibility-audit');
            }
        }

        if ($this->_slackOn()) {
            $posted = $plugin->getNotifications()->notifySlack(
                $subject,
                $this->slackBody($data),
                null,
                $context,
                $reportUrl,
                Craft::t('accessibility-audit', 'Open the report'),
            );
            $delivered = $delivered || $posted;
        }

        return $delivered;
    }

    /**
     * The filters a site's scheduled summary runs with: the last 7 days for
     * a weekly send, the month before for a monthly one.
     *
     * @param int $siteId The site.
     * @return ReportFilters
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function filtersFor(int $siteId): ReportFilters
    {
        $weekly = AccessibilityAudit::getInstance()->getSettings()->reportSchedule === SettingsModel::REPORT_SCHEDULE_WEEKLY;

        return new ReportFilters([
            'siteId' => $siteId,
            'period' => $weekly ? ReportFilters::PERIOD_7_DAYS : ReportFilters::PERIOD_LAST_MONTH,
        ]);
    }

    /**
     * The plain-text version of the email.
     *
     * @param array<string, mixed> $data The directors summary's data.
     * @param string $reportUrl Where the full report is in the control panel.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function textBody(array $data, string $reportUrl): string
    {
        if ($data['score'] === null) {
            return Craft::t('accessibility-audit', 'No pages have been scanned yet, so there is nothing to summarise.');
        }

        $lines = [$this->slackBody($data), ''];

        foreach ($data['risks'] as $risk) {
            $lines[] = '- ' . Craft::t('accessibility-audit', '{title}: on {n, plural, =1{# page} other{# pages}}', [
                'title' => $risk['title'],
                'n' => $risk['pages'],
            ]);
        }

        if ($data['note'] !== '') {
            $lines[] = '';
            $lines[] = Craft::t('accessibility-audit', 'What we’re doing about it');
            $lines[] = $data['note'];
        }

        $lines[] = '';
        $lines[] = Craft::t('accessibility-audit', 'View the full report: {url}', ['url' => $reportUrl]);

        return implode("\n", $lines);
    }

    /**
     * The short version: score, change, and fixed and new counts.
     *
     * @param array<string, mixed> $data The directors summary's data.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function slackBody(array $data): string
    {
        if ($data['score'] === null) {
            return Craft::t('accessibility-audit', 'No pages have been scanned yet, so there is nothing to summarise.');
        }

        $parts = [Craft::t('accessibility-audit', 'Average page score: {score}/100.', ['score' => $data['score']])];

        if ($data['scoreAtStart'] !== null) {
            $change = $data['score'] - $data['scoreAtStart'];
            $parts[] = Craft::t('accessibility-audit', 'Change since {date}: {change}.', [
                'date' => $data['startDate'],
                'change' => $change > 0 ? '+' . $change : (string)$change,
            ]);
        }

        $parts[] = Craft::t('accessibility-audit', '{fixed, plural, =1{# problem was} other{# problems were}} fixed, and {new, plural, =1{# new one turned up that is} other{# new ones turned up that are}} still open.', [
            'fixed' => $data['fixed'],
            'new' => $data['new'],
        ]);

        return implode(' ', $parts);
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
    public function today(): string
    {
        return (new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d');
    }

    // Private Methods
    // =========================================================================

    /**
     * The summary as a PDF to attach, or null when it could not be made.
     *
     * A PDF that fails is logged and the email goes without it: the email
     * body carries the same figures, so the summary still lands.
     *
     * @param DirectorsSummary $report The report.
     * @param array<string, mixed> $data The report's data.
     * @param ReportFilters $filters The filters it ran with.
     * @param string $title The document title, the email's subject.
     * @param string $siteName The site's name.
     * @param string $organisationName The organisation's name, or empty.
     * @param string $language The site's language.
     * @return string|null The PDF bytes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _pdf(DirectorsSummary $report, array $data, ReportFilters $filters, string $title, string $siteName, string $organisationName, string $language): ?string
    {
        try {
            $html = Craft::$app->getView()->renderTemplate('accessibility-audit/reports/_pdf/directors-summary', [
                'title' => $title,
                'data' => $data,
                'freshness' => $report->freshness($filters->siteId),
                'siteName' => $siteName,
                'organisationName' => $organisationName,
                'generated' => $this->today(),
                'language' => $language,
            ], View::TEMPLATE_MODE_CP);

            return AccessibilityAudit::getInstance()->getReport()->htmlToPdf($html);
        } catch (Throwable $e) {
            Craft::warning("A11y: the summary PDF for site {$filters->siteId} could not be made, so the email goes without it: " . $e->getMessage(), 'accessibility-audit');

            return null;
        }
    }

    /**
     * Marks a site's summary as sent today if it is due, under a lock so two
     * checks at once can't both take it.
     *
     * @param int $siteId The site.
     * @return array{claimed: bool, previous: string|null} Whether this call
     * took the send, and the send date it replaced, for giving it back.
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _claim(int $siteId): array
    {
        $mutex = Craft::$app->getMutex();
        $lock = 'accessibility-audit:report-send:' . $siteId;

        if (!$mutex->acquire($lock)) {
            return ['claimed' => false, 'previous' => null];
        }

        try {
            if (!$this->isDue($siteId)) {
                return ['claimed' => false, 'previous' => null];
            }

            $previous = $this->lastSent($siteId);
            $this->_markSent($siteId);

            return ['claimed' => true, 'previous' => $previous];
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Puts a site's send date back to what it was before a claim.
     *
     * @param int $siteId The site.
     * @param string|null $previous The date to restore, or null to clear it.
     * @return void
     * @throws \yii\db\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _release(int $siteId, ?string $previous): void
    {
        if ($previous === null) {
            Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['siteId' => $siteId])->execute();

            return;
        }

        $values = ['lastSent' => $previous];

        Db::upsert(self::TABLE, ['siteId' => $siteId] + $values, $values);
    }

    /**
     * Records today as a site's last send date.
     *
     * @param int $siteId The site.
     * @return void
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _markSent(int $siteId): void
    {
        $values = ['lastSent' => $this->today()];

        Db::upsert(self::TABLE, ['siteId' => $siteId] + $values, $values);
    }

    /**
     * The sites with at least one scan, which are the only ones with
     * anything to summarise. A deleted site's scans can outlast it, so only
     * sites that still exist are returned.
     *
     * @return int[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _scannedSiteIds(): array
    {
        $scanned = array_map('intval', (new Query())
            ->select(['siteId'])
            ->distinct()
            ->from('{{%accessibilityaudit_scans}}')
            ->column());

        return array_values(array_intersect($scanned, Craft::$app->getSites()->getAllSiteIds()));
    }

    /**
     * Whether the summary also goes to Slack: switched on, with a webhook set.
     *
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _slackOn(): bool
    {
        $settings = AccessibilityAudit::getInstance()->getSettings();

        return $settings->reportScheduleSlack && trim((string)App::parseEnv($settings->notifySlackWebhookUrl)) !== '';
    }
}
