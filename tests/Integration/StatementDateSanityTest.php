<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\models\StatementMetaModel;
use johnhenry\accessibilityaudit\services\StatementProfiles;

// ---------------------------------------------------------------------------
// Both dates on the statement describe something that already happened. A
// future one is published as a claim about work nobody has done, on the page a
// procurement reader uses to decide whether to trust the rest of it.
// ---------------------------------------------------------------------------

function statementMetaWith(array $attributes): StatementMetaModel
{
    $meta = new StatementMetaModel();
    $meta->profile = StatementProfiles::PROFILE_GENERIC;

    foreach ($attributes as $name => $value) {
        $meta->$name = $value;
    }

    return $meta;
}

describe('the last reviewed date', function() {
    it('refuses a date in the future', function() {
        $meta = statementMetaWith(['reviewDate' => (new DateTime('+1 year'))->format('Y-m-d')]);

        expect($meta->validate())->toBeFalse()
            ->and($meta->getErrors('reviewDate'))->not->toBeEmpty();
    });

    it('accepts today', function() {
        $meta = statementMetaWith(['reviewDate' => (new DateTime('today'))->format('Y-m-d')]);

        expect($meta->validate())->toBeTrue();
    });

    it('accepts a date in the past', function() {
        $meta = statementMetaWith(['reviewDate' => '2026-01-15']);

        expect($meta->validate())->toBeTrue();
    });

    it('still accepts being left empty', function() {
        expect(statementMetaWith(['reviewDate' => ''])->validate())->toBeTrue();
    });
});

describe('the statement date', function() {
    it('refuses a date in the future', function() {
        $meta = statementMetaWith(['statementDate' => (new DateTime('+1 day'))->format('Y-m-d')]);

        expect($meta->validate())->toBeFalse()
            ->and($meta->getErrors('statementDate'))->not->toBeEmpty();
    });

    it('accepts a date in the past', function() {
        expect(statementMetaWith(['statementDate' => '2026-02-01'])->validate())->toBeTrue();
    });
});
