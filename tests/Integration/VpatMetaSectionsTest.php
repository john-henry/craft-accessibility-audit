<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\VpatMetaModel;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * A VpatMetaModel that validates by default.
 *
 * Evaluation methods and scope pages moved to OrganisationMetaModel, which has
 * its own test file; what remains here is the disclaimer and the controller
 * round-trip that proves both halves come back as one flat array.
 */
function sectionsVpatMeta(array $overrides = []): VpatMetaModel
{
    $model = new VpatMetaModel();

    foreach ($overrides as $attr => $value) {
        $model->$attr = $value;
    }

    return $model;
}

// ---------------------------------------------------------------------------
// Model validation
// ---------------------------------------------------------------------------

describe('VpatMetaModel legal disclaimer', function() {
    it('caps the legal disclaimer at 5000 characters', function() {
        expect(sectionsVpatMeta(['legalDisclaimer' => str_repeat('x', 5000)])->validate())->toBeTrue()
            ->and(sectionsVpatMeta(['legalDisclaimer' => str_repeat('x', 5001)])->validate())->toBeFalse();
    });

    it('round-trips the disclaimer through toStorageArray', function() {
        $stored = sectionsVpatMeta(['legalDisclaimer' => 'For information only.'])->toStorageArray();

        expect($stored['legalDisclaimer'])->toBe('For information only.');
    });
});

// ---------------------------------------------------------------------------
// Controller save round-trip
// ---------------------------------------------------------------------------

describe('VpatController::actionSaveMeta list fields', function() {
    it('splits newline lists, trims entries, and drops blanks and duplicates', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $json = $this->postJson('actions/accessibility-audit/vpat/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Website',
            'evalMethods' => "Keyboard-only testing\n  Visual inspection  \n\nKeyboard-only testing",
            'scopePages' => "Home (https://example.com/)\n\nContact us\n",
            'legalDisclaimer' => 'This report reflects the state of the site at the report date.',
        ])->getJsonContent();

        expect($json['success'])->toBeTrue();

        $meta = AccessibilityAudit::getInstance()->vpat->getRecord($siteId)['meta'];

        expect($meta['evalMethods'])->toBe(['Keyboard-only testing', 'Visual inspection'])
            ->and($meta['scopePages'])->toBe(['Home (https://example.com/)', 'Contact us'])
            ->and($meta['legalDisclaimer'])->toBe('This report reflects the state of the site at the report date.');
    });

    it('stores empty lists when the fields are omitted', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $json = $this->postJson('actions/accessibility-audit/vpat/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Website',
        ])->getJsonContent();

        expect($json['success'])->toBeTrue();

        $meta = AccessibilityAudit::getInstance()->vpat->getRecord($siteId)['meta'];

        expect($meta['evalMethods'])->toBe([])
            ->and($meta['scopePages'])->toBe([]);
    });
});
