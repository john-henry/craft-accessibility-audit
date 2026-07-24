<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\helpers\App;
use craft\helpers\Html;
use craft\helpers\Json;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use Throwable;
use yii\base\Component;
use yii\base\Exception;

/**
 * Sends email and Slack notifications when an element's accessibility scan
 * crosses a configured threshold: a newly introduced error-severity issue, or
 * a sharp drop in the element's score compared to its previous scan.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class NotificationService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string Slack attachment bar colour for error notifications.
     * Mirrors the CP's --accessibility-audit-sev-error token (red-600).
     */
    public const COLOR_ERROR = '#dc2626';

    /**
     * @var string Slack attachment bar colour for warning-grade notifications
     * (score drops). Mirrors --accessibility-audit-sev-warning (amber-600).
     */
    public const COLOR_WARNING = '#d97706';

    /**
     * @var string Slack attachment bar colour for informational messages
     * (connection tests). Mirrors --accessibility-audit-sev-notice (blue-500).
     */
    public const COLOR_INFO = '#3b82f6';

    // Public Methods
    // =========================================================================

    /**
     * Compares a freshly recorded scan against the element's previous scan and
     * fires email/Slack notifications for any threshold that has been crossed.
     *
     * Both arrays share the same shape:
     *   - `score` (int): the scan's overall score.
     *   - `errorRuleIds` (string[]): rule IDs of error-severity issues in the scan.
     *   - `label` (string, optional): a human-readable label for the element/page.
     *   - `reportUrl` (string, optional, new result only): CP page-report URL,
     *     attached to every message as a "view the report" link/button.
     *
     * A null `$previousResult` means this is the element's first scan, so there
     * is nothing to compare against and no notification is sent.
     *
     * @param array{score: int, errorRuleIds: string[], label?: string, reportUrl?: string} $newResult
     * @param array{score: int, errorRuleIds: string[]}|null $previousResult
     * @throws Exception
     */
    public function evaluateScan(array $newResult, ?array $previousResult): void
    {
        // Notifications are a Pro-only feature. Bail immediately on Standard so
        // nothing fires even if something internally still calls this: a
        // belt-and-suspenders guard alongside the controller-level gating.
        if (!AccessibilityAudit::getInstance()->isPro()) {
            return;
        }

        if ($previousResult === null) {
            return;
        }

        $settings = AccessibilityAudit::getInstance()->getSettings();
        $label = $newResult['label'] ?? Craft::t('accessibility-audit', 'a page');
        $reportUrl = $newResult['reportUrl'] ?? null;

        // Both triggers are evaluated independently: a scan that introduces a
        // new error AND drops past the score threshold sends BOTH messages,
        // as two separate emails/Slack posts rather than one combined one.
        if ($settings->notifyOnNewError) {
            $newErrors = array_values(array_diff($newResult['errorRuleIds'], $previousResult['errorRuleIds']));
            if (!empty($newErrors)) {
                $message = $this->buildNewErrorNotification($label, $newErrors);
                $this->dispatch($message['subject'], $message['body'], $message['color'], $reportUrl);
            }
        }

        if ($settings->notifyOnScoreDrop) {
            $drop = $previousResult['score'] - $newResult['score'];
            if ($drop >= $settings->notifyScoreDropThreshold) {
                $message = $this->buildScoreDropNotification($label, $previousResult['score'], $newResult['score']);
                $this->dispatch($message['subject'], $message['body'], $message['color'], $reportUrl);
            }
        }
    }

    /**
     * Builds the "new error found" notification message. The single source of
     * this wording: both real scans and the settings page's dummy preview
     * render through here, so the preview always shows exactly what
     * production sends.
     *
     * @param string $label A human-readable label for the element/page.
     * @param string[] $newErrorRuleIds Rule IDs of the newly introduced errors.
     * @return array{subject: string, body: string, color: string}
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function buildNewErrorNotification(string $label, array $newErrorRuleIds): array
    {
        return [
            'subject' => Craft::t('accessibility-audit', 'New accessibility error on {label}', ['label' => $label]),
            'body' => Craft::t(
                'accessibility-audit',
                'A new error-severity accessibility issue was found on {label}. New rules: {rules}.',
                ['label' => $label, 'rules' => implode(', ', $newErrorRuleIds)]
            ),
            'color' => self::COLOR_ERROR,
        ];
    }

    /**
     * Builds the "score dropped" notification message. Single source of the
     * wording, shared by real scans and the settings page's dummy preview.
     *
     * @param string $label A human-readable label for the element/page.
     * @param int $previousScore The score before this scan.
     * @param int $newScore The score after this scan.
     * @return array{subject: string, body: string, color: string}
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function buildScoreDropNotification(string $label, int $previousScore, int $newScore): array
    {
        return [
            'subject' => Craft::t('accessibility-audit', 'Accessibility score dropped on {label}', ['label' => $label]),
            'body' => Craft::t(
                'accessibility-audit',
                'The accessibility score for {label} dropped {drop} points, from {from} to {to}.',
                [
                    'label' => $label,
                    'drop' => $previousScore - $newScore,
                    'from' => $previousScore,
                    'to' => $newScore,
                ]
            ),
            'color' => self::COLOR_WARNING,
        ];
    }

    /**
     * Sends the given subject/body through every enabled channel, stamped
     * with the site name and environment so a team watching one channel for
     * several environments can tell a staging blip from a production
     * regression at a glance.
     *
     * @param string $subject The message subject (email subject, Slack lead line).
     * @param string $body The message body.
     * @param string|null $color Slack attachment bar colour (a COLOR_* constant), or null for informational blue.
     * @param string|null $actionUrl A CP URL to attach: a plain link line on email, a button on Slack.
     * @throws Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function dispatch(string $subject, string $body, ?string $color = null, ?string $actionUrl = null): void
    {
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $context = $this->_contextLine();

        if ($settings->notifyEmailEnabled) {
            $this->notifyEmail($subject, $body, $color, $actionUrl, $context);
        }

        if ($settings->notifySlackEnabled) {
            $this->notifySlack($subject, $body, $color, $context, $actionUrl);
        }
    }

    /**
     * Sends an email to every configured recipient: a designed HTML body
     * (severity-coloured accent bar, report button, muted footer) with a
     * plain-text alternative carrying the same content. Failures are logged
     * and never thrown to the caller so notification problems can't break a
     * scan.
     *
     * @param string $subject The email subject and card heading.
     * @param string $body The message body.
     * @param string|null $color Accent colour (a COLOR_* constant), or null for informational blue.
     * @param string|null $actionUrl A CP URL rendered as a button (HTML) and plain link line (text), or null for none.
     * @param string $contextLine Muted footer line (plugin, site, environment), or empty to omit.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function notifyEmail(string $subject, string $body, ?string $color = null, ?string $actionUrl = null, string $contextLine = ''): void
    {
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $recipients = $this->_parseRecipients($settings->notifyEmailRecipients);

        if (empty($recipients)) {
            return;
        }

        $textBody = $body;
        if ($actionUrl !== null && $actionUrl !== '') {
            $textBody .= "\n\n" . Craft::t('accessibility-audit', 'View the full report: {url}', ['url' => $actionUrl]);
        }
        if ($contextLine !== '') {
            $textBody .= "\n\n" . $contextLine;
        }

        try {
            $message = Craft::$app->getMailer()
                ->compose()
                ->setTo($recipients)
                ->setSubject($subject)
                ->setTextBody($textBody)
                ->setHtmlBody($this->_emailHtml($subject, $body, $color, $actionUrl, $contextLine));

            $message->send();
        } catch (Throwable $e) {
            Craft::error(
                'Failed to send accessibility notification email: ' . $e->getMessage(),
                'accessibility-audit'
            );
        }
    }

    /**
     * Posts a message to the configured Slack incoming webhook, formatted
     * with Block Kit: a bold subject line, the body, a muted context line
     * (site + environment), and a severity-coloured attachment bar. A plain
     * `text` fallback rides along for OS notifications and clients that
     * can't render blocks. Failures are logged and never thrown to the
     * caller.
     *
     * @param string $subject The lead line, rendered bold.
     * @param string $body The message body.
     * @param string|null $color The attachment bar colour (a COLOR_* constant), or null for informational blue.
     * @param string $contextLine Muted footer line (site name and environment), or empty to omit.
     * @param string|null $actionUrl A URL rendered as a link button under the message, or null for none.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function notifySlack(string $subject, string $body, ?string $color = null, string $contextLine = '', ?string $actionUrl = null): void
    {
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $webhookUrl = trim(App::parseEnv($settings->notifySlackWebhookUrl ?? '') ?: '');

        if ($webhookUrl === '') {
            return;
        }

        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*' . $this->_slackEscape($subject) . "*\n" . $this->_slackEscape($body),
                ],
            ],
        ];

        if ($actionUrl !== null && $actionUrl !== '') {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => Craft::t('accessibility-audit', 'Open page report'),
                        ],
                        'url' => $actionUrl,
                    ],
                ],
            ];
        }

        if ($contextLine !== '') {
            $blocks[] = [
                'type' => 'context',
                'elements' => [
                    ['type' => 'mrkdwn', 'text' => $this->_slackEscape($contextLine)],
                ],
            ];
        }

        try {
            Craft::createGuzzleClient(['timeout' => 10, 'connect_timeout' => 5])
                ->post($webhookUrl, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => Json::encode([
                        // Honoured by legacy webhooks; app-based webhooks use
                        // the Slack app's own name and ignore this.
                        'username' => Craft::t('accessibility-audit', 'Accessibility Audit'),
                        'attachments' => [
                            [
                                'color' => $color ?? self::COLOR_INFO,
                                // OS-notification text. As a top-level `text`
                                // key Slack renders it in the channel too,
                                // duplicating the card above itself.
                                'fallback' => $subject . "\n" . $body,
                                'blocks' => $blocks,
                            ],
                        ],
                    ]),
                ]);
        } catch (Throwable $e) {
            Craft::error(
                'Failed to post accessibility notification to Slack: ' . $e->getMessage(),
                'accessibility-audit'
            );
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the designed HTML email body. Table-based with inline styles
     * only, so it renders the same in Outlook, Gmail, and Apple Mail: a
     * white card on a grey canvas, a severity-coloured top bar, the subject
     * as heading, a bulletproof link button, and a muted footer.
     *
     * @param string $subject The card heading.
     * @param string $body The message body.
     * @param string|null $color Accent colour, or null for informational blue.
     * @param string|null $actionUrl Button URL, or null for no button.
     * @param string $contextLine Muted footer line, or empty to omit.
     * @return string
     */
    private function _emailHtml(string $subject, string $body, ?string $color, ?string $actionUrl, string $contextLine): string
    {
        $accent = $color ?? self::COLOR_INFO;
        $font = "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $subjectHtml = Html::encode($subject);
        $bodyHtml = nl2br(Html::encode($body), false);

        $buttonHtml = '';
        if ($actionUrl !== null && $actionUrl !== '') {
            $urlAttr = Html::encode($actionUrl);
            $buttonLabel = Html::encode(Craft::t('accessibility-audit', 'Open page report'));
            $buttonHtml = <<<HTML
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 4px 0;">
                    <tr>
                        <td style="border-radius:6px;background:{$accent};">
                            <a href="{$urlAttr}" style="display:inline-block;padding:11px 20px;font-family:{$font};font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">{$buttonLabel}</a>
                        </td>
                    </tr>
                </table>
                HTML;
        }

        $footerHtml = '';
        if ($contextLine !== '') {
            $contextHtml = Html::encode($contextLine);
            $footerHtml = <<<HTML
                <p style="margin:24px 0 0 0;padding-top:16px;border-top:1px solid #e5e7eb;font-family:{$font};font-size:12px;line-height:1.5;color:#6b7280;">{$contextHtml}</p>
                HTML;
        }

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <body style="margin:0;padding:0;background:#f3f4f6;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f3f4f6;padding:32px 16px;">
                    <tr>
                        <td align="center">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;border-top:4px solid {$accent};box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                                <tr>
                                    <td style="padding:28px 32px 32px 32px;">
                                        <h1 style="margin:0 0 12px 0;font-family:{$font};font-size:18px;line-height:1.4;color:#111827;">{$subjectHtml}</h1>
                                        <p style="margin:0;font-family:{$font};font-size:14px;line-height:1.6;color:#374151;">{$bodyHtml}</p>
                                        {$buttonHtml}
                                        {$footerHtml}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>
            HTML;
    }

    /**
     * The site name and environment line stamped onto every notification,
     * e.g. "Craft 5 Boilerplate · production". The environment comes from
     * CRAFT_ENVIRONMENT, falling back to dev/production from devMode when
     * unset.
     *
     * @return string
     * @throws Exception
     */
    private function _contextLine(): string
    {
        $environment = trim((string)(App::env('CRAFT_ENVIRONMENT') ?: ''));

        if ($environment === '') {
            $environment = Craft::$app->getConfig()->getGeneral()->devMode ? 'dev' : 'production';
        }

        return Craft::t('accessibility-audit', 'Accessibility Audit')
            . ' · ' . Craft::$app->getSystemName()
            . ' · ' . $environment;
    }

    /**
     * Escapes the three characters Slack's mrkdwn requires escaping in text
     * content. URLs keep auto-linking; this only stops stray angle brackets
     * or ampersands in page titles from being eaten as markup.
     *
     * @param string $text The raw text.
     * @return string
     */
    private function _slackEscape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * Splits a comma- or newline-separated recipient string into a clean list
     * of email addresses.
     *
     * @return string[]
     */
    private function _parseRecipients(string $raw): array
    {
        $parts = preg_split('/[,\r\n]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn(string $address): bool => $address !== ''));
    }
}
