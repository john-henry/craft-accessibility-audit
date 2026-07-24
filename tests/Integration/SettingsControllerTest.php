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
