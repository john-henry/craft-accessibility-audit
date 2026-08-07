<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\SettingsModel;
use johnhenry\accessibilityaudit\services\AuditService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// SettingsController::_saveSettings
//
// The field-by-field reads are mechanical casts; the logic worth guarding is
// the edition gating (Standard can't buy unbounded retention) and the
// ignoreRules textarea parsing. These run through the real save action.
// ---------------------------------------------------------------------------

/** Posts a settings-save with the given settings overlaid on valid defaults. */
function saveSettings(array $settings): void
{
    $body = array_merge([
        'wcagLevel' => 'AA',
        'retainDays' => 30,
        'resolvedRetention' => SettingsModel::RESOLVED_RETENTION_KEEP_DAYS,
        'targetScore' => 80,
        'altTextField' => 'alt',
    ], $settings);

    test()->post('actions/accessibility-audit/settings/save-general', [
        'settings' => $body,
    ]);
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

describe('SettingsController retention gating', function() {
    it('clamps Standard retention over the cap down to the cap', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        saveSettings(['retainDays' => 999]);

        expect(AccessibilityAudit::getInstance()->getSettings()->retainDays)
            ->toBe(AuditService::STANDARD_RETENTION_CAP);
    });

    it('clamps Standard "never prune" (0) up to the cap', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        saveSettings(['retainDays' => 0]);

        expect(AccessibilityAudit::getInstance()->getSettings()->retainDays)
            ->toBe(AuditService::STANDARD_RETENTION_CAP);
    });

    it('lets Pro keep an over-cap retention and "never prune"', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

        saveSettings(['retainDays' => 999]);
        expect(AccessibilityAudit::getInstance()->getSettings()->retainDays)->toBe(999);

        saveSettings(['retainDays' => 0]);
        expect(AccessibilityAudit::getInstance()->getSettings()->retainDays)->toBe(0);
    });

    it('downgrades Standard "keep resolved forever" to keep-for-days', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        saveSettings(['resolvedRetention' => SettingsModel::RESOLVED_RETENTION_FOREVER]);

        expect(AccessibilityAudit::getInstance()->getSettings()->resolvedRetention)
            ->toBe(SettingsModel::RESOLVED_RETENTION_KEEP_DAYS);
    });

    it('lets Pro keep resolved issues forever', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

        saveSettings(['resolvedRetention' => SettingsModel::RESOLVED_RETENTION_FOREVER]);

        expect(AccessibilityAudit::getInstance()->getSettings()->resolvedRetention)
            ->toBe(SettingsModel::RESOLVED_RETENTION_FOREVER);
    });
});

describe('SettingsController ignoreRules parsing', function() {
    it('splits the textarea on newlines, trims, and drops blank lines', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

        saveSettings(['ignoreRules' => "img-alt\n  link-name  \n\n\ncolor-contrast\n"]);

        expect(array_values(AccessibilityAudit::getInstance()->getSettings()->ignoreRules))
            ->toBe(['img-alt', 'link-name', 'color-contrast']);
    });
});

// ---------------------------------------------------------------------------
// Browser scanning is Pro-only, including saving its settings
// ---------------------------------------------------------------------------

describe('SettingsController browser scanning gating', function() {
    // A site under test may already have an endpoint or binary configured, so
    // these assert against whatever is stored rather than against empty.
    beforeEach(function() {
        $settings = AccessibilityAudit::getInstance()->getSettings();

        $this->storedChrome = [
            'path' => $settings->chromePath,
            'endpoint' => $settings->chromeWsEndpoint,
            'noSandbox' => $settings->chromeNoSandbox,
        ];
    });

    // These settings live in-memory for the run, so put them back rather than
    // leaking a test's Chrome endpoint into another test's expectations.
    afterEach(function() {
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $settings->chromePath = $this->storedChrome['path'];
        $settings->chromeWsEndpoint = $this->storedChrome['endpoint'];
        $settings->chromeNoSandbox = $this->storedChrome['noSandbox'];
    });

    it('ignores a posted Chrome endpoint on Standard', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        saveSettings(['chromeWsEndpoint' => 'wss://sneaky.example.com/?token=x']);

        expect(AccessibilityAudit::getInstance()->getSettings()->chromeWsEndpoint)
            ->toBe($this->storedChrome['endpoint']);
    });

    it('ignores a posted Chrome binary path on Standard', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        saveSettings(['chromePath' => '/usr/bin/chromium']);

        expect(AccessibilityAudit::getInstance()->getSettings()->chromePath)
            ->toBe($this->storedChrome['path']);
    });

    it('ignores a posted sandbox toggle on Standard', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        saveSettings(['chromeNoSandbox' => $this->storedChrome['noSandbox'] ? '' : '1']);

        expect(AccessibilityAudit::getInstance()->getSettings()->chromeNoSandbox)
            ->toBe($this->storedChrome['noSandbox']);
    });

    it('leaves a stored endpoint intact when Standard saves the page', function() {
        // The downgrade case: a site that loses Pro must not have its endpoint
        // wiped by an admin saving settings, since the licence may come back.
        AccessibilityAudit::getInstance()->getSettings()->chromeWsEndpoint = 'wss://kept.example.com/?token=x';
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        saveSettings([]);

        expect(AccessibilityAudit::getInstance()->getSettings()->chromeWsEndpoint)
            ->toBe('wss://kept.example.com/?token=x');
    });

    it('lets Pro set both the endpoint and the binary path', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

        saveSettings([
            'chromeWsEndpoint' => 'wss://browserless.example.com/?token=x',
            'chromePath' => '/usr/bin/chromium',
        ]);

        $settings = AccessibilityAudit::getInstance()->getSettings();

        expect($settings->chromeWsEndpoint)->toBe('wss://browserless.example.com/?token=x')
            ->and($settings->chromePath)->toBe('/usr/bin/chromium');
    });
});
