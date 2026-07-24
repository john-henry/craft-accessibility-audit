<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\OrganisationMetaModel;
use johnhenry\accessibilityaudit\models\VpatMetaModel;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The invariant this refactor rests on: metadata is stored in two rows but must
// read back as the one flat array the editor, the export and the Twig variable
// have always seen. If that breaks, every VPAT consumer silently loses fields.
// ---------------------------------------------------------------------------

describe('split metadata storage', function() {
    it('reads both halves back as a single flat array', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        saveVpatMetaFlat($siteId, [
            'productName' => 'Acme Website',
            'contactEmail' => 'access@example.com',
            'evalMethods' => ['Keyboard-only testing'],
            'productVersion' => '2.0',
            'reportDate' => '2026-07-01',
            'legalDisclaimer' => 'For information only.',
        ]);

        $meta = AccessibilityAudit::getInstance()->vpat->getRecord($siteId)['meta'];

        expect($meta['productName'])->toBe('Acme Website')
            ->and($meta['contactEmail'])->toBe('access@example.com')
            ->and($meta['evalMethods'])->toBe(['Keyboard-only testing'])
            ->and($meta['productVersion'])->toBe('2.0')
            ->and($meta['reportDate'])->toBe('2026-07-01')
            ->and($meta['legalDisclaimer'])->toBe('For information only.');
    });

    it('covers every field between the two models with no overlap', function() {
        $shared = OrganisationMetaModel::storageKeys();
        $vpat = VpatMetaModel::storageKeys();

        // A key in both halves would be written twice; a key in neither would be
        // dropped by the controller without anything failing.
        expect(array_intersect($shared, $vpat))->toBe([])
            ->and(count(array_merge($shared, $vpat)))->toBe(14);
    });

    it('keeps the shared half when only the VPAT half is saved again', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        saveVpatMetaFlat($siteId, [
            'productName' => 'Acme Website',
            'contactEmail' => 'access@example.com',
        ]);

        // A VPAT-only write must not strand the organisation values: this is the
        // failure mode that made the old single-blob storage unsafe to share.
        $vpatOnly = new VpatMetaModel();
        $vpatOnly->notes = 'Revised notes.';
        AccessibilityAudit::getInstance()->vpat->saveMeta($siteId, $vpatOnly);

        $meta = AccessibilityAudit::getInstance()->vpat->getRecord($siteId)['meta'];

        expect($meta['productName'])->toBe('Acme Website')
            ->and($meta['contactEmail'])->toBe('access@example.com')
            ->and($meta['notes'])->toBe('Revised notes.');
    });
});

// ---------------------------------------------------------------------------
// Controller: one posted form, two rows written
// ---------------------------------------------------------------------------

describe('VpatController::actionSaveMeta split write', function() {
    it('writes the shared half where a second document can read it', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $json = $this->postJson('actions/accessibility-audit/vpat/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Website',
            'contactEmail' => 'access@example.com',
            'productVersion' => '3.1',
        ])->getJsonContent();

        expect($json['success'])->toBeTrue();

        // Read through OrganisationService directly, the way an accessibility
        // statement would, rather than through the VPAT's merged view.
        $shared = AccessibilityAudit::getInstance()->organisation->getMeta($siteId);

        expect($shared['productName'])->toBe('Acme Website')
            ->and($shared['contactEmail'])->toBe('access@example.com')
            ->and($shared)->not->toHaveKey('productVersion');
    });

    it('writes neither half when the shared half fails validation', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        saveVpatMetaFlat($siteId, ['productName' => 'Original Name', 'notes' => 'Original notes.']);

        // productName is required: the whole save must be refused, not half of it.
        $json = $this->postJson('actions/accessibility-audit/vpat/save-meta', [
            'siteId' => $siteId,
            'productName' => '',
            'notes' => 'Notes that must not land.',
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['errors'])->toHaveKey('productName');

        $meta = AccessibilityAudit::getInstance()->vpat->getRecord($siteId)['meta'];

        expect($meta['productName'])->toBe('Original Name')
            ->and($meta['notes'])->toBe('Original notes.');
    });
});
