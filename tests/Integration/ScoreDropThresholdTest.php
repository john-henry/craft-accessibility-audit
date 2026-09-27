<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\NotificationService;

// ---------------------------------------------------------------------------
// The score-drop threshold is validated to a minimum of one on the settings
// form, but a config file sets settings straight onto the model and never goes
// near the validation rules. At zero the comparison is true of any score that
// merely failed to improve, so a full sweep sends a message for every page on
// the site; below zero it is true of scores that went up.
//
// Same reasoning as the browser settle time, which is clamped at the point of
// use for exactly this.
// ---------------------------------------------------------------------------

/** A notification service that records what it would have sent. */
function recordingNotifier(): NotificationService
{
    return new class extends NotificationService {
        /** @var string[] */
        public array $sent = [];

        public function dispatch(string $subject, string $body, ?string $color = null, ?string $actionUrl = null): void
        {
            $this->sent[] = $subject;
        }
    };
}

describe('the score-drop threshold', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

        $settings = AccessibilityAudit::getInstance()->getSettings();
        $settings->notifyOnScoreDrop = true;
        $settings->notifyOnNewError = false;
    });

    it('does not fire on a score that held steady, however the threshold was set', function(int $configured) {
        AccessibilityAudit::getInstance()->getSettings()->notifyScoreDropThreshold = $configured;

        $notifier = recordingNotifier();
        $notifier->evaluateScan(
            ['score' => 80, 'errorRuleIds' => [], 'label' => 'a page'],
            ['score' => 80, 'errorRuleIds' => []],
        );

        expect($notifier->sent)->toBe([]);
    })->with([
        'zero from a config file' => [0],
        'negative from a config file' => [-5],
    ]);

    it('does not fire on a score that improved', function() {
        AccessibilityAudit::getInstance()->getSettings()->notifyScoreDropThreshold = 0;

        $notifier = recordingNotifier();
        $notifier->evaluateScan(
            ['score' => 95, 'errorRuleIds' => [], 'label' => 'a page'],
            ['score' => 60, 'errorRuleIds' => []],
        );

        expect($notifier->sent)->toBe([]);
    });

    it('still fires on a real drop past the configured threshold', function() {
        AccessibilityAudit::getInstance()->getSettings()->notifyScoreDropThreshold = 10;

        $notifier = recordingNotifier();
        $notifier->evaluateScan(
            ['score' => 60, 'errorRuleIds' => [], 'label' => 'a page'],
            ['score' => 90, 'errorRuleIds' => []],
        );

        expect($notifier->sent)->toHaveCount(1);
    });

    it('fires on a one-point drop when the floor is what applies', function() {
        // The floor is one, not "never": a drop of a single point with the
        // threshold bottomed out still counts.
        AccessibilityAudit::getInstance()->getSettings()->notifyScoreDropThreshold = 0;

        $notifier = recordingNotifier();
        $notifier->evaluateScan(
            ['score' => 79, 'errorRuleIds' => [], 'label' => 'a page'],
            ['score' => 80, 'errorRuleIds' => []],
        );

        expect($notifier->sent)->toHaveCount(1);
    });
});
