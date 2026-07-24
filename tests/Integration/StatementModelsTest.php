<?php

use johnhenry\accessibilityaudit\models\StatementExclusionModel;
use johnhenry\accessibilityaudit\models\StatementMetaModel;
use johnhenry\accessibilityaudit\services\StatementProfiles;

// ---------------------------------------------------------------------------
// Helper: a statement that validates by default
// ---------------------------------------------------------------------------

function validStatementMeta(array $overrides = []): StatementMetaModel
{
    $model = new StatementMetaModel();
    $model->statementDate = '2026-07-01';

    foreach ($overrides as $attr => $value) {
        $model->$attr = $value;
    }

    return $model;
}

// ---------------------------------------------------------------------------
// Jurisdiction profiles
// ---------------------------------------------------------------------------

describe('StatementProfiles', function() {
    it('falls back to the generic profile for an unknown handle', function() {
        // A stored profile from a future version must not throw on the very
        // page an editor would use to correct it.
        expect(StatementProfiles::get('atlantis'))
            ->toBe(StatementProfiles::get(StatementProfiles::PROFILE_GENERIC));
    });

    it('requires an enforcement body only where the law does', function() {
        expect(StatementProfiles::get(StatementProfiles::PROFILE_EU)['requiresEnforcement'])->toBeTrue()
            ->and(StatementProfiles::get(StatementProfiles::PROFILE_UK)['requiresEnforcement'])->toBeTrue()
            ->and(StatementProfiles::get(StatementProfiles::PROFILE_GENERIC)['requiresEnforcement'])->toBeFalse();
    });

    it('rejects a Level A target for jurisdictions that mandate AA', function() {
        // Both mandated regimes require AA. Publishing a statement claiming
        // them off a Level A target would assert a standard never measured.
        expect(StatementProfiles::targetLevelSatisfies(StatementProfiles::PROFILE_EU, 'A'))->toBeFalse()
            ->and(StatementProfiles::targetLevelSatisfies(StatementProfiles::PROFILE_EU, 'AA'))->toBeTrue()
            ->and(StatementProfiles::targetLevelSatisfies(StatementProfiles::PROFILE_EU, 'AAA'))->toBeTrue()
            ->and(StatementProfiles::targetLevelSatisfies(StatementProfiles::PROFILE_UK, 'A'))->toBeFalse();
    });

    it('offers every profile as a select option', function() {
        expect(StatementProfiles::options())->toHaveCount(count(StatementProfiles::handles()));
    });
});

// ---------------------------------------------------------------------------
// Statement metadata
// ---------------------------------------------------------------------------

describe('StatementMetaModel', function() {
    it('passes with only a date set on the generic profile', function() {
        expect(validStatementMeta()->validate())->toBeTrue();
    });

    it('requires an enforcement body on the EU profile', function() {
        $model = validStatementMeta(['profile' => StatementProfiles::PROFILE_EU]);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('enforcementBody'))->not->toBeEmpty();
    });

    it('accepts the EU profile once an enforcement body is named', function() {
        $model = validStatementMeta([
            'profile' => StatementProfiles::PROFILE_EU,
            'enforcementBody' => 'Ombudsman',
            'enforcementUrl' => 'https://example.com/complaints',
        ]);

        expect($model->validate())->toBeTrue();
    });

    it('does not require an enforcement body on the generic profile', function() {
        expect(validStatementMeta(['profile' => StatementProfiles::PROFILE_GENERIC])->validate())->toBeTrue();
    });

    it('requires a named evaluator for a third-party claim', function() {
        // Claiming an independent evaluation without naming who did it is an
        // unverifiable assertion in a legal document.
        $model = validStatementMeta(['preparationMethod' => StatementMetaModel::METHOD_THIRD_PARTY]);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('preparedBy'))->not->toBeEmpty();
    });

    it('accepts a third-party claim that names the evaluator', function() {
        expect(validStatementMeta([
            'preparationMethod' => StatementMetaModel::METHOD_THIRD_PARTY,
            'preparedBy' => 'Some Auditor Ltd',
        ])->validate())->toBeTrue();
    });

    it('rejects an unknown profile', function() {
        $model = validStatementMeta(['profile' => 'atlantis']);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('profile'))->not->toBeEmpty();
    });

    it('rejects a compliance status outside the three legal claims', function() {
        expect(validStatementMeta(['statusOverride' => 'mostlyGrand'])->validate())->toBeFalse()
            ->and(validStatementMeta(['statusOverride' => StatementMetaModel::STATUS_PARTIAL])->validate())->toBeTrue()
            ->and(validStatementMeta(['statusOverride' => ''])->validate())->toBeTrue();
    });

    it('rejects a malformed enforcement URL', function() {
        expect(validStatementMeta(['enforcementUrl' => 'not a url'])->validate())->toBeFalse();
    });

    it('rejects a malformed date', function() {
        expect(validStatementMeta(['statementDate' => '01/07/2026'])->validate())->toBeFalse();
    });

    it('keeps profile out of the meta blob', function() {
        // profile has its own column; storing it twice invites the two copies
        // to disagree about which jurisdiction the statement is written to.
        expect(StatementMetaModel::storageKeys())->not->toContain('profile')
            ->and(array_keys((new StatementMetaModel())->toStorageArray()))
            ->toBe(StatementMetaModel::storageKeys());
    });
});

// ---------------------------------------------------------------------------
// Non-accessible content entries
// ---------------------------------------------------------------------------

describe('StatementExclusionModel', function() {
    it('requires content', function() {
        $model = StatementExclusionModel::fromArray(['content' => '']);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('content'))->not->toBeEmpty();
    });

    it('accepts each of the three legal categories', function() {
        foreach (StatementExclusionModel::categories() as $category) {
            $model = StatementExclusionModel::fromArray([
                'category' => $category,
                'content' => 'PDF menus published before 2023',
            ]);

            expect($model->validate())->toBeTrue();
        }
    });

    it('rejects a category outside the three', function() {
        $model = StatementExclusionModel::fromArray([
            'category' => 'somethingElse',
            'content' => 'Whatever',
        ]);

        expect($model->validate())->toBeFalse()
            ->and($model->getErrors('category'))->not->toBeEmpty();
    });

    it('accepts a criterion number but not prose', function() {
        expect(StatementExclusionModel::fromArray(['content' => 'x', 'criterion' => '1.4.3'])->validate())->toBeTrue()
            ->and(StatementExclusionModel::fromArray(['content' => 'x', 'criterion' => ''])->validate())->toBeTrue()
            ->and(StatementExclusionModel::fromArray(['content' => 'x', 'criterion' => 'contrast'])->validate())->toBeFalse();
    });

    it('trims values when built from a posted row', function() {
        $model = StatementExclusionModel::fromArray([
            'content' => '  Archived minutes  ',
            'reason' => "  Out of scope\n",
        ]);

        expect($model->content)->toBe('Archived minutes')
            ->and($model->reason)->toBe('Out of scope');
    });

    it('round-trips through toStorageArray', function() {
        $stored = StatementExclusionModel::fromArray([
            'category' => StatementExclusionModel::CATEGORY_BURDEN,
            'content' => 'Legacy video archive',
            'reason' => 'Recaptioning 4,000 hours is disproportionate.',
            'criterion' => '1.2.2',
            'plannedDate' => '2027-01-01',
        ])->toStorageArray();

        expect($stored)->toBe([
            'category' => StatementExclusionModel::CATEGORY_BURDEN,
            'content' => 'Legacy video archive',
            'reason' => 'Recaptioning 4,000 hours is disproportionate.',
            'criterion' => '1.2.2',
            'plannedDate' => '2027-01-01',
        ]);
    });
});
