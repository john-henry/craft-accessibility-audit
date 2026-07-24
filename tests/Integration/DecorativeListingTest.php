<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\Asset as AssetFactory;

// ---------------------------------------------------------------------------
// Helpers (uniquely named: Pest loads every test file into one process, so
// helper names must not collide with other Integration tests)
// ---------------------------------------------------------------------------

/**
 * @return int[] The asset ids in a listDecorativePaged() result page.
 */
function decorativeListedIds(array $paged): array
{
    return array_map(
        static fn(array $row): int => (int)$row['asset']->id,
        $paged['results'],
    );
}

beforeEach(function() {
    // Settings are a singleton across tests, so remember the configured
    // exclusions, then pin a clean slate: no exclusions, the native alt field,
    // and an empty flags table.
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $this->savedExcludedVolumes = $settings->excludedVolumes;
    $settings->excludedVolumes = [];
    $settings->altTextField = 'alt';

    Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_asset_flags}}')->execute();
});

afterEach(function() {
    AccessibilityAudit::getInstance()->getSettings()->excludedVolumes = $this->savedExcludedVolumes;
});

// ---------------------------------------------------------------------------
// Paged decorative listing
// ---------------------------------------------------------------------------

describe('AssetScanner::listDecorativePaged', function() {
    it('lists only decorative images and leaves a plain one out', function() {
        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            expect(true)->toBeTrue();
            return;
        }
        $assets = AccessibilityAudit::getInstance()->getAssets();

        $decorative = AssetFactory::factory()->volume($volume->handle)->create();
        $plain = AssetFactory::factory()->volume($volume->handle)->create();

        $assets->setDecorative((int)$decorative->id, true);

        $listed = decorativeListedIds($assets->listDecorativePaged(1, 200));

        expect($listed)->toContain((int)$decorative->id)
            ->and($listed)->not->toContain((int)$plain->id);
    });

    it('returns a well-formed empty page when nothing is marked', function() {
        $paged = AccessibilityAudit::getInstance()->getAssets()->listDecorativePaged(1, 10);

        expect($paged)->toHaveKeys(['results', 'total', 'page', 'perPage', 'totalPages'])
            ->and($paged['total'])->toBe(0)
            ->and($paged['results'])->toBe([]);
    });

    it('drops a decorative image in an excluded volume from the listing', function() {
        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            expect(true)->toBeTrue();
            return;
        }
        $assets = AccessibilityAudit::getInstance()->getAssets();

        $decorative = AssetFactory::factory()->volume($volume->handle)->create();
        $assets->setDecorative((int)$decorative->id, true);

        expect(decorativeListedIds($assets->listDecorativePaged(1, 200)))
            ->toContain((int)$decorative->id);

        AccessibilityAudit::getInstance()->getSettings()->excludedVolumes = [$volume->uid];

        expect(decorativeListedIds($assets->listDecorativePaged(1, 200)))
            ->not->toContain((int)$decorative->id);
    });
});

// ---------------------------------------------------------------------------
// Decorative count (chip badge)
// ---------------------------------------------------------------------------

describe('AssetScanner::getDecorativeCount', function() {
    it('counts only decorative images', function() {
        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            expect(true)->toBeTrue();
            return;
        }
        $assets = AccessibilityAudit::getInstance()->getAssets();

        expect($assets->getDecorativeCount())->toBe(0);

        $a = AssetFactory::factory()->volume($volume->handle)->create();
        $b = AssetFactory::factory()->volume($volume->handle)->create();
        AssetFactory::factory()->volume($volume->handle)->create();

        $assets->setDecorative((int)$a->id, true);
        $assets->setDecorative((int)$b->id, true);

        expect($assets->getDecorativeCount())->toBe(2);
    });

    it('excludes an excluded volume from the count', function() {
        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            expect(true)->toBeTrue();
            return;
        }
        $assets = AccessibilityAudit::getInstance()->getAssets();

        $a = AssetFactory::factory()->volume($volume->handle)->create();
        $assets->setDecorative((int)$a->id, true);

        expect($assets->getDecorativeCount())->toBe(1);

        AccessibilityAudit::getInstance()->getSettings()->excludedVolumes = [$volume->uid];

        expect($assets->getDecorativeCount())->toBe(0);
    });
});
