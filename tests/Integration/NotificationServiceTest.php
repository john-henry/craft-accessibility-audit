<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\NotificationService;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function a11ySettings(): \johnhenry\accessibilityaudit\models\SettingsModel
{
    return AccessibilityAudit::getInstance()->getSettings();
}

function callPrivate(object $object, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($object, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($object, $args);
}

/**
 * A NotificationService that records what it would dispatch instead of hitting
 * email/Slack, so evaluateScan()'s trigger decisions can be asserted directly
 * (did it dispatch, how many times, with what wording) rather than settled for
 * "it didn't throw". Each captured entry is subject/body/color/url.
 */
function notificationSpy(): NotificationService
{
    return new class extends NotificationService {
        /** @var array<int, array{subject: string, body: string, color: ?string, url: ?string}> */
        public array $sent = [];

        public function dispatch(string $subject, string $body, ?string $color = null, ?string $actionUrl = null): void
        {
            $this->sent[] = ['subject' => $subject, 'body' => $body, 'color' => $color, 'url' => $actionUrl];
        }
    };
}

// ---------------------------------------------------------------------------
// _parseRecipients
// ---------------------------------------------------------------------------

describe('NotificationService::_parseRecipients', function() {
    it('splits a comma-separated list', function() {
        $service = new NotificationService();
        $result = callPrivate($service, '_parseRecipients', ['a@example.com, b@example.com']);

        expect($result)->toBe(['a@example.com', 'b@example.com']);
    });

    it('splits a newline-separated list and trims blanks', function() {
        $service = new NotificationService();
        $result = callPrivate($service, '_parseRecipients', ["a@example.com\n\n b@example.com \n"]);

        expect($result)->toBe(['a@example.com', 'b@example.com']);
    });

    it('returns an empty array for an empty string', function() {
        $service = new NotificationService();

        expect(callPrivate($service, '_parseRecipients', ['']))->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// evaluateScan: the paths that must dispatch nothing
// ---------------------------------------------------------------------------

describe('NotificationService::evaluateScan silent paths', function() {
    // Pro so the edition gate isn't the reason nothing fires; each test proves
    // its own condition is what stops the dispatch.
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $settings = a11ySettings();
        $settings->notifyOnNewError = true;
        $settings->notifyOnScoreDrop = true;
        $settings->notifyScoreDropThreshold = 10;
    });

    it('dispatches nothing when there is no previous scan', function() {
        $spy = notificationSpy();

        // A first-ever scan has nothing to compare against, so neither trigger
        // can fire however bad the result is.
        $spy->evaluateScan(['score' => 20, 'errorRuleIds' => ['img-alt', 'link-name']], null);

        expect($spy->sent)->toBe([]);
    });

    it('dispatches nothing when both triggers are disabled', function() {
        $settings = a11ySettings();
        $settings->notifyOnNewError = false;
        $settings->notifyOnScoreDrop = false;

        $spy = notificationSpy();

        // A new error AND a 80-point drop, but with both switches off nothing
        // should be sent.
        $spy->evaluateScan(
            ['score' => 10, 'errorRuleIds' => ['img-alt', 'link-name']],
            ['score' => 90, 'errorRuleIds' => []]
        );

        expect($spy->sent)->toBe([]);
    });

    it('dispatches nothing when the error set is unchanged', function() {
        $settings = a11ySettings();
        $settings->notifyOnScoreDrop = false; // isolate the new-error trigger

        $spy = notificationSpy();
        $spy->evaluateScan(
            ['score' => 80, 'errorRuleIds' => ['img-alt']],
            ['score' => 80, 'errorRuleIds' => ['img-alt']]
        );

        expect($spy->sent)->toBe([]);
    });

    it('dispatches nothing when the score drop is below the threshold', function() {
        $settings = a11ySettings();
        $settings->notifyOnNewError = false; // isolate the score-drop trigger

        $spy = notificationSpy();
        // 90 -> 85 is only 5 points, under the threshold of 10.
        $spy->evaluateScan(
            ['score' => 85, 'errorRuleIds' => []],
            ['score' => 90, 'errorRuleIds' => []]
        );

        expect($spy->sent)->toBe([]);
    });

    it('dispatches nothing on the Standard edition even when both triggers cross', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        $spy = notificationSpy();
        $spy->evaluateScan(
            ['score' => 50, 'errorRuleIds' => ['img-alt', 'link-name']],
            ['score' => 90, 'errorRuleIds' => []]
        );

        expect($spy->sent)->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// evaluateScan: the paths that must dispatch
// ---------------------------------------------------------------------------

describe('NotificationService::evaluateScan firing paths', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $settings = a11ySettings();
        $settings->notifyScoreDropThreshold = 10;
    });

    it('sends only the new-error message when a new error rule appears', function() {
        $settings = a11ySettings();
        $settings->notifyOnNewError = true;
        $settings->notifyOnScoreDrop = false;

        $spy = notificationSpy();
        $spy->evaluateScan(
            ['score' => 80, 'errorRuleIds' => ['img-alt', 'link-name'], 'label' => 'Test page'],
            ['score' => 80, 'errorRuleIds' => ['img-alt']] // img-alt already there; link-name is new
        );

        expect($spy->sent)->toHaveCount(1)
            ->and($spy->sent[0]['subject'])->toContain('New accessibility error')
            ->and($spy->sent[0]['body'])->toContain('link-name');
    });

    it('sends the score-drop message when the drop meets the threshold', function() {
        $settings = a11ySettings();
        $settings->notifyOnNewError = false;
        $settings->notifyOnScoreDrop = true;

        $spy = notificationSpy();
        // 90 -> 78 is a 12-point drop, at or over the threshold of 10.
        $spy->evaluateScan(
            ['score' => 78, 'errorRuleIds' => [], 'label' => 'Test page'],
            ['score' => 90, 'errorRuleIds' => []]
        );

        expect($spy->sent)->toHaveCount(1)
            ->and($spy->sent[0]['subject'])->toContain('score dropped')
            ->and($spy->sent[0]['body'])->toContain('from 90 to 78');
    });
});

// ---------------------------------------------------------------------------
// evaluateScan: both triggers crossed by one scan
// ---------------------------------------------------------------------------

describe('NotificationService::evaluateScan with both triggers crossed', function() {
    it('sends the new-error and score-drop messages as two separate dispatches', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

        $settings = a11ySettings();
        $settings->notifyOnNewError = true;
        $settings->notifyOnScoreDrop = true;
        $settings->notifyScoreDropThreshold = 10;

        $spy = notificationSpy();

        // One scan that both introduces a new error rule AND drops 30 points.
        $spy->evaluateScan(
            ['score' => 60, 'errorRuleIds' => ['img-alt', 'link-name'], 'label' => 'Test page', 'reportUrl' => 'https://example.test/admin/accessibility-audit/page-report?elementId=1'],
            ['score' => 90, 'errorRuleIds' => ['img-alt']]
        );

        expect($spy->sent)->toHaveCount(2)
            ->and($spy->sent[0]['subject'])->toContain('New accessibility error')
            ->and($spy->sent[0]['body'])->toContain('link-name')
            ->and($spy->sent[1]['subject'])->toContain('score dropped')
            ->and($spy->sent[1]['body'])->toContain('from 90 to 60')
            // The page-report deep link rides along on both messages.
            ->and($spy->sent[0]['url'])->toContain('page-report')
            ->and($spy->sent[1]['url'])->toContain('page-report');
    });
});
