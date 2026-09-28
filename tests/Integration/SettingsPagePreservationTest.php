<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\SettingsModel;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The settings are split across five screens but saved through one handler,
// which reads all forty-odd fields from the request. A screen only posts its
// own, so every read has to fall back to the value already stored. One read
// written without that fallback wipes its field whenever any other screen is
// saved, and nothing about saving that other screen would look wrong.
// ---------------------------------------------------------------------------

describe('saving one settings screen', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $this->actingAs(UserFactory::factory()->admin(true)->create());
    });

    it('leaves the fields belonging to the other screens alone', function() {
        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        // Distinctive values across screens the General save does not post:
        // notifications, tools, scanning and maintenance.
        $settings->notifyEmailRecipients = 'preserve-me@example.com';
        $settings->notifySlackEnabled = true;
        $settings->altTextContext = 'A shop selling fountain pens.';
        $settings->excludedSelectors = '#preserve-widget';
        $settings->browserSettleMs = 750;
        $settings->retainDays = 45;
        Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());

        // A General-screen save, posting only General's own fields.
        $this->post('actions/accessibility-audit/settings/save-general', [
            'settings' => [
                'wcagLevel' => 'AA',
                'targetScore' => 80,
                'en301549' => '1',
            ],
        ]);

        $after = AccessibilityAudit::getInstance()->getSettings();

        expect($after->notifyEmailRecipients)->toBe('preserve-me@example.com')
            ->and($after->notifySlackEnabled)->toBeTrue()
            ->and($after->altTextContext)->toBe('A shop selling fountain pens.')
            ->and($after->excludedSelectors)->toBe('#preserve-widget')
            ->and($after->browserSettleMs)->toBe(750)
            ->and($after->retainDays)->toBe(45);

        // And the screen that was saved did take.
        expect($after->targetScore)->toBe(80);
    });

    it('reads every settings field with the stored value as its fallback', function() {
        // The guarantee above rests on each read passing the current value as
        // getBodyParam's default. Checked directly, because a new field added
        // without one breaks the screen it is not on rather than its own.
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/SettingsController.php');

        preg_match_all(
            '/\$settings->(\w+)\s*=\s*[^;]*?getBodyParam\(\s*\'settings\[(\w+)\]\'\s*(,)?/s',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        expect($matches)->not->toBeEmpty();

        $faulty = [];

        foreach ($matches as $match) {
            [, $field, $param, $default] = $match + [3 => null];

            if ($default === null || $field !== $param) {
                $faulty[] = $field;
            }
        }

        expect($faulty)->toBe([]);
    });
});
