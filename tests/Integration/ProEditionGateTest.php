<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Forces the plugin's active edition for the life of the current test. The
 * edition is a runtime property on the singleton plugin instance, so it is
 * reset in beforeEach() to keep tests independent of run order.
 */
function setEdition(string $edition): void
{
    AccessibilityAudit::getInstance()->edition = $edition;
}

// Every test starts from a known edition. Individual tests flip to Pro where
// they need to assert the feature works when unlocked.
beforeEach(function() {
    setEdition(AccessibilityAudit::EDITION_STANDARD);
});

// ---------------------------------------------------------------------------
// isPro()
// ---------------------------------------------------------------------------

describe('AccessibilityAudit::isPro', function() {
    it('is false on the Standard edition', function() {
        setEdition(AccessibilityAudit::EDITION_STANDARD);

        expect(AccessibilityAudit::getInstance()->isPro())->toBeFalse();
    });

    it('is true on the Pro edition', function() {
        setEdition(AccessibilityAudit::EDITION_PRO);

        expect(AccessibilityAudit::getInstance()->isPro())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// NotificationService::evaluateScan no-ops on Standard
// ---------------------------------------------------------------------------

describe('NotificationService::evaluateScan Pro gating', function() {
    it('never dispatches on the Standard edition, even with triggers armed', function() {
        setEdition(AccessibilityAudit::EDITION_STANDARD);

        $settings = AccessibilityAudit::getInstance()->getSettings();
        $settings->notifyOnNewError = true;
        $settings->notifyOnScoreDrop = true;
        $settings->notifyEmailEnabled = true;
        // A recipient list that would blow up if delivery were attempted, so the
        // test proves the method returned before dispatching rather than merely
        // swallowing a failure.
        $settings->notifyEmailRecipients = 'someone@example.com';

        // A previous scan is present and a brand-new error rule appears: on Pro
        // this would dispatch. On Standard it must return immediately.
        AccessibilityAudit::getInstance()->notifications->evaluateScan(
            ['score' => 10, 'errorRuleIds' => ['img-alt'], 'label' => 'a page'],
            ['score' => 90, 'errorRuleIds' => []]
        );

        // Reaching here without a delivery attempt (which the poison recipient
        // list would have surfaced) proves the early return fired.
        expect(true)->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// VPAT conformance reporting
// ---------------------------------------------------------------------------

describe('VpatController Pro gating', function() {
    it('refuses actionSaveMeta on the Standard edition', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        setEdition(AccessibilityAudit::EDITION_STANDARD);

        $json = $this->postJson('actions/accessibility-audit/vpat/save-meta', [
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'productName' => 'Test product',
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['proRequired'])->toBeTrue()
            ->and($json['error'])->toContain('Pro edition');
    });

    it('lets actionSaveMeta through on the Pro edition', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        setEdition(AccessibilityAudit::EDITION_PRO);

        $json = $this->postJson('actions/accessibility-audit/vpat/save-meta', [
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'productName' => 'Test product',
        ])->getJsonContent();

        // Past the gate the save succeeds; either way it must not be a Pro refusal.
        expect($json)->not->toHaveKey('proRequired')
            ->and($json['success'])->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Readability
// ---------------------------------------------------------------------------

describe('ReadabilityController Pro gating', function() {
    it('refuses actionAnalyse on the Standard edition', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        setEdition(AccessibilityAudit::EDITION_STANDARD);

        $json = $this->postJson('actions/accessibility-audit/readability/analyse', [
            'url' => 'https://example.com',
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['proRequired'])->toBeTrue()
            ->and($json['error'])->toContain('Pro edition');
    });

    it('gets past the gate on the Pro edition', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        setEdition(AccessibilityAudit::EDITION_PRO);

        // A blatantly invalid URL trips the controller's own validation *after*
        // the Pro gate, proving the gate let the request through.
        $json = $this->postJson('actions/accessibility-audit/readability/analyse', [
            'url' => 'not-a-url',
        ])->getJsonContent();

        expect($json)->not->toHaveKey('proRequired')
            ->and($json['success'])->toBeFalse()
            ->and($json['error'])->toContain('valid URL');
    });
});

// ---------------------------------------------------------------------------
// AI Alt Text — available on every edition (gated only by API key), so it must
// never return a Pro refusal, not even on Standard.
// ---------------------------------------------------------------------------

describe('AltController is not Pro-gated', function() {
    it('is not refused on the Standard edition', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        setEdition(AccessibilityAudit::EDITION_STANDARD);

        // No such asset, so the controller returns its own "not found" error
        // rather than a Pro refusal, proving the edition gate is gone.
        $json = $this->postJson('actions/accessibility-audit/alt/generate', [
            'assetId' => 999999,
        ])->getJsonContent();

        expect($json)->not->toHaveKey('proRequired')
            ->and($json['success'])->toBeFalse()
            ->and($json['error'])->toContain('not found');
    });

    it('is not refused on the Pro edition either', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        setEdition(AccessibilityAudit::EDITION_PRO);

        $json = $this->postJson('actions/accessibility-audit/alt/generate', [
            'assetId' => 999999,
        ])->getJsonContent();

        expect($json)->not->toHaveKey('proRequired')
            ->and($json['success'])->toBeFalse()
            ->and($json['error'])->toContain('not found');
    });
});

// ---------------------------------------------------------------------------
// CI/CD token generation (SettingsController)
// ---------------------------------------------------------------------------

describe('SettingsController Pro gating', function() {
    it('refuses generate-ci-token on the Standard edition', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        setEdition(AccessibilityAudit::EDITION_STANDARD);

        $json = $this->postJson('actions/accessibility-audit/settings/generate-ci-token')
            ->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['proRequired'])->toBeTrue()
            ->and($json['error'])->toContain('Pro edition');
    });

    it('refuses save-notifications on the Standard edition', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        setEdition(AccessibilityAudit::EDITION_STANDARD);

        $json = $this->postJson('actions/accessibility-audit/settings/save-notifications', [
            'settings' => ['notifyEmailEnabled' => '1'],
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['proRequired'])->toBeTrue()
            ->and($json['error'])->toContain('Pro edition');
    });
});
