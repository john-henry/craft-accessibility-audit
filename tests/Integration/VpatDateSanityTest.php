<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\models\VpatMetaModel;

// ---------------------------------------------------------------------------
// Every date on the report describes work already done: when it was published,
// and the period the evaluation covered. A future one claims testing nobody
// carried out, in a document a buyer uses to decide about a purchase.
// ---------------------------------------------------------------------------

function vpatMetaWith(array $attributes): VpatMetaModel
{
    $meta = new VpatMetaModel();

    foreach ($attributes as $name => $value) {
        $meta->$name = $value;
    }

    return $meta;
}

describe('the report date', function() {
    it('refuses a date in the future', function() {
        $meta = vpatMetaWith(['reportDate' => (new DateTime('+1 day'))->format('Y-m-d')]);

        expect($meta->validate())->toBeFalse()
            ->and($meta->getErrors('reportDate'))->not->toBeEmpty();
    });

    it('accepts today', function() {
        expect(vpatMetaWith(['reportDate' => (new DateTime('today'))->format('Y-m-d')])->validate())
            ->toBeTrue();
    });

    it('still accepts being left empty', function() {
        expect(vpatMetaWith([])->validate())->toBeTrue();
    });
});

describe('the evaluation period', function() {
    it('refuses a start in the future', function() {
        $meta = vpatMetaWith(['reportPeriodFrom' => (new DateTime('+1 month'))->format('Y-m-d')]);

        expect($meta->validate())->toBeFalse()
            ->and($meta->getErrors('reportPeriodFrom'))->not->toBeEmpty();
    });

    it('refuses an end in the future', function() {
        $meta = vpatMetaWith(['reportPeriodTo' => (new DateTime('+1 month'))->format('Y-m-d')]);

        expect($meta->validate())->toBeFalse()
            ->and($meta->getErrors('reportPeriodTo'))->not->toBeEmpty();
    });

    it('refuses a period that ends before it starts', function() {
        $meta = vpatMetaWith([
            'reportPeriodFrom' => '2026-08-01',
            'reportPeriodTo' => '2026-07-01',
        ]);

        expect($meta->validate())->toBeFalse()
            ->and($meta->getErrors('reportPeriodTo'))->not->toBeEmpty();
    });

    it('accepts a period in the right order', function() {
        $meta = vpatMetaWith([
            'reportPeriodFrom' => '2026-07-01',
            'reportPeriodTo' => '2026-08-01',
        ]);

        expect($meta->validate())->toBeTrue();
    });

    it('accepts a period that starts and ends on the same day', function() {
        $meta = vpatMetaWith([
            'reportPeriodFrom' => '2026-07-01',
            'reportPeriodTo' => '2026-07-01',
        ]);

        expect($meta->validate())->toBeTrue();
    });

    it('leaves a half-filled period alone', function() {
        // One end on its own is incomplete rather than wrong, and the ordering
        // check has nothing to compare against.
        expect(vpatMetaWith(['reportPeriodTo' => '2026-07-01'])->validate())->toBeTrue()
            ->and(vpatMetaWith(['reportPeriodFrom' => '2026-07-01'])->validate())->toBeTrue();
    });
});
