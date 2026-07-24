<?php

use johnhenry\accessibilityaudit\models\OrganisationMetaModel;
use johnhenry\accessibilityaudit\models\VpatMetaModel;

// ---------------------------------------------------------------------------
// Helper: a model that validates by default
// ---------------------------------------------------------------------------

function validVpatMeta(array $overrides = []): VpatMetaModel
{
    $model = new VpatMetaModel();
    $model->reportDate = '2026-07-01';

    foreach ($overrides as $attr => $value) {
        $model->$attr = $value;
    }

    return $model;
}

// ---------------------------------------------------------------------------
// Date fields: must accept flat Y-m-d and NOT mutate the stored value
// ---------------------------------------------------------------------------

describe('VpatMetaModel date fields', function() {
    it('passes with a well-formed ISO date', function() {
        expect(validVpatMeta(['reportDate' => '2026-01-15'])->validate())->toBeTrue();
    });

    it('fails with a malformed date', function() {
        $model = validVpatMeta(['reportDate' => '15/01/2026']);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('reportDate'))->not->toBeEmpty();
    });

    it('fails with a non-date string', function() {
        $model = validVpatMeta(['reportPeriodFrom' => 'yesterday']);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('reportPeriodFrom'))->not->toBeEmpty();
    });

    it('passes with empty date fields (optional)', function() {
        expect(validVpatMeta(['reportDate' => '', 'reportPeriodFrom' => '', 'reportPeriodTo' => ''])->validate())
            ->toBeTrue();
    });

    it('keeps the date as a flat Y-m-d string after validation', function() {
        $model = validVpatMeta(['reportDate' => '2026-03-04']);
        $model->validate();

        // Must NOT be reformatted into a DateTime, timestamp, or other shape:
        // the export template and native date inputs consume the flat string.
        expect($model->reportDate)->toBe('2026-03-04')
            ->and($model->toStorageArray()['reportDate'])->toBe('2026-03-04');
    });
});

// ---------------------------------------------------------------------------
// toStorageArray
// ---------------------------------------------------------------------------

describe('VpatMetaModel::toStorageArray', function() {
    it('returns every field keyed by name', function() {
        $keys = array_keys(validVpatMeta()->toStorageArray());

        expect($keys)->toBe([
            'productVersion',
            'reportDate',
            'reportPeriodFrom',
            'reportPeriodTo',
            'notes',
            'legalDisclaimer',
        ])
            // The shared half lives on OrganisationMetaModel; a field appearing
            // in both would be written twice and read back inconsistently.
            ->and(array_intersect($keys, OrganisationMetaModel::storageKeys()))->toBe([]);
    });
});
