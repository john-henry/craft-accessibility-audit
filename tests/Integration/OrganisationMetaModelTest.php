<?php

use johnhenry\accessibilityaudit\models\OrganisationMetaModel;

// ---------------------------------------------------------------------------
// Helper: a model that validates by default
// ---------------------------------------------------------------------------

function validOrganisationMeta(array $overrides = []): OrganisationMetaModel
{
    $model = new OrganisationMetaModel();
    $model->productName = 'Acme Website';
    $model->contactEmail = 'accessibility@example.com';
    $model->contactPhone = '+353 1 234 5678';

    foreach ($overrides as $attr => $value) {
        $model->$attr = $value;
    }

    return $model;
}

// ---------------------------------------------------------------------------
// productName (required)
// ---------------------------------------------------------------------------

describe('OrganisationMetaModel::productName', function() {
    it('passes with a product name set', function() {
        expect(validOrganisationMeta()->validate())->toBeTrue();
    });

    it('fails when the product name is empty', function() {
        $model = validOrganisationMeta(['productName' => '']);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('productName'))->not->toBeEmpty();
    });

    it('fails when the product name exceeds the length cap', function() {
        $model = validOrganisationMeta(['productName' => str_repeat('a', 256)]);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('productName'))->not->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// contactEmail
// ---------------------------------------------------------------------------

describe('OrganisationMetaModel::contactEmail', function() {
    it('passes with a valid email', function() {
        expect(validOrganisationMeta(['contactEmail' => 'user@example.com'])->validate())->toBeTrue();
    });

    it('fails with an invalid email', function() {
        $model = validOrganisationMeta(['contactEmail' => 'not-an-email']);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('contactEmail'))->not->toBeEmpty();
    });

    it('fails with arbitrary junk in the email field', function() {
        $model = validOrganisationMeta(['contactEmail' => 'javascript:alert(1)']);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('contactEmail'))->not->toBeEmpty();
    });

    it('passes with an empty email (optional field)', function() {
        expect(validOrganisationMeta(['contactEmail' => ''])->validate())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// contactPhone
// ---------------------------------------------------------------------------

describe('OrganisationMetaModel::contactPhone', function() {
    it('passes with a plausible phone number', function() {
        expect(validOrganisationMeta(['contactPhone' => '+1 (555) 123-4567'])->validate())->toBeTrue();
    });

    it('fails when the phone contains letters', function() {
        $model = validOrganisationMeta(['contactPhone' => 'call me maybe']);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('contactPhone'))->not->toBeEmpty();
    });

    it('fails when the phone exceeds the length cap', function() {
        $model = validOrganisationMeta(['contactPhone' => str_repeat('1', 41)]);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('contactPhone'))->not->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// Evaluation methods and scope
// ---------------------------------------------------------------------------

describe('OrganisationMetaModel evaluation methods and scope', function() {
    it('accepts lists of strings', function() {
        $model = validOrganisationMeta([
            'evalMethods' => ['Keyboard-only testing', 'Screen reader testing with NVDA'],
            'scopePages' => ['Home (https://example.com/)', 'Contact us'],
        ]);

        expect($model->validate())->toBeTrue();
    });

    it('rejects an entry longer than 255 characters', function() {
        $model = validOrganisationMeta(['evalMethods' => [str_repeat('x', 256)]]);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('evalMethods'))->not->toBeEmpty();
    });

    it('round-trips the list fields through toStorageArray', function() {
        $stored = validOrganisationMeta([
            'evalMethods' => ['Keyboard-only testing'],
            'scopePages' => ['Home'],
        ])->toStorageArray();

        expect($stored['evalMethods'])->toBe(['Keyboard-only testing'])
            ->and($stored['scopePages'])->toBe(['Home']);
    });
});

// ---------------------------------------------------------------------------
// storageKeys: the contract that keeps the two halves from overlapping
// ---------------------------------------------------------------------------

describe('OrganisationMetaModel::storageKeys', function() {
    it('lists exactly the keys toStorageArray writes', function() {
        $keys = array_keys((new OrganisationMetaModel())->toStorageArray());

        // Drift here is what silently strands a field in the wrong half:
        // the migration and the controller both split on storageKeys().
        expect(OrganisationMetaModel::storageKeys())->toBe($keys);
    });
});
