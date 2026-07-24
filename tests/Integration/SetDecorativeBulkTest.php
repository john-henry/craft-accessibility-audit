<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\Asset;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\Asset as AssetFactory;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers (uniquely named: Pest loads every test file into one process, so
// helper names must not collide with other Integration tests)
// ---------------------------------------------------------------------------

/** @return int[] The subset of the given ids currently flagged decorative. */
function bulkDecorativeIds(array $assetIds): array
{
    $ids = array_map('intval', (new Query())
        ->select(['assetId'])
        ->from('{{%accessibilityaudit_asset_flags}}')
        ->where(['assetId' => $assetIds, 'isDecorative' => true])
        ->column());

    sort($ids);
    return $ids;
}

beforeEach(function() {
    AccessibilityAudit::getInstance()->getSettings()->altTextField = 'alt';
});

// ---------------------------------------------------------------------------
// Controller: bulk mark / unmark decorative
// ---------------------------------------------------------------------------

describe('AltController::actionSetDecorativeBulk', function() {
    it('marks several image assets decorative and skips a non-image', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            expect(true)->toBeTrue();
            return;
        }

        $imageA = AssetFactory::factory()->volume($volume->handle)->create();
        $imageB = AssetFactory::factory()->volume($volume->handle)->create();

        // A non-image row: the bulk action must skip it and never flag it.
        $strayId = (int) UserFactory::factory()->create()->id;

        $json = $this->postJson('actions/accessibility-audit/alt/set-decorative-bulk', [
            'assetIds' => [(int) $imageA->id, (int) $imageB->id, $strayId],
            'decorative' => '1',
        ])->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and($json['count'])->toBe(2)
            ->and(bulkDecorativeIds([(int) $imageA->id, (int) $imageB->id, $strayId]))
            ->toEqualCanonicalizing([(int) $imageA->id, (int) $imageB->id]);
    });

    it('unmarks a set of decorative image assets', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            expect(true)->toBeTrue();
            return;
        }

        $assets = AccessibilityAudit::getInstance()->getAssets();
        $imageA = AssetFactory::factory()->volume($volume->handle)->create();
        $imageB = AssetFactory::factory()->volume($volume->handle)->create();

        $assets->setDecorative((int) $imageA->id, true);
        $assets->setDecorative((int) $imageB->id, true);
        expect(bulkDecorativeIds([(int) $imageA->id, (int) $imageB->id]))
            ->toEqualCanonicalizing([(int) $imageA->id, (int) $imageB->id]);

        $json = $this->postJson('actions/accessibility-audit/alt/set-decorative-bulk', [
            'assetIds' => [(int) $imageA->id, (int) $imageB->id],
            'decorative' => '',
        ])->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and($json['count'])->toBe(2)
            ->and(bulkDecorativeIds([(int) $imageA->id, (int) $imageB->id]))->toBe([]);
    });

    it('applies nothing and reports zero for an empty id list', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $json = $this->postJson('actions/accessibility-audit/alt/set-decorative-bulk', [
            'assetIds' => [],
            'decorative' => '1',
        ])->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and($json['count'])->toBe(0);
    });
});
