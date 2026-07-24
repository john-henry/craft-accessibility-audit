<?php

use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Db;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\Asset as AssetFactory;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Inserts an assets-table row (kind image) scoped to a real volume and returns
 * an in-memory Asset carrying that id and volumeId. Mirrors the AssetScannerTest
 * makeStoredAuditAsset helper, but pins the volume so the exclusion paths, which
 * key off volumeId, have something concrete to filter on. Nothing is uploaded:
 * the checks under test read the assets row and the in-memory element only.
 */
function makeVolumeAuditAsset(int $volumeId, ?string $alt): Asset
{
    $elementId = UserFactory::factory()->create()->id;

    $folderId = (new Query())
        ->select('id')
        ->from('{{%volumefolders}}')
        ->where(['volumeId' => $volumeId])
        ->scalar();

    $now = Db::prepareDateForDb(new \DateTime());
    Craft::$app->getDb()->createCommand()->insert('{{%assets}}', [
        'id' => $elementId,
        'volumeId' => $volumeId,
        'folderId' => $folderId,
        'filename' => 'photo.jpg',
        'kind' => Asset::KIND_IMAGE,
        'dateCreated' => $now,
        'dateUpdated' => $now,
    ])->execute();

    $asset = new Asset();
    $asset->kind = Asset::KIND_IMAGE;
    $asset->alt = $alt;
    $asset->filename = 'photo.jpg';
    $asset->title = 'Test image';
    $asset->id = $elementId;
    $asset->volumeId = $volumeId;

    return $asset;
}

/**
 * @return int[] The asset ids in a scanImagesPaged() result page.
 */
function pagedAssetIds(array $paged): array
{
    return array_map(
        static fn(array $row): int => (int)$row['asset']->id,
        $paged['results'],
    );
}

/**
 * @return string[] The rule ids stored for the given asset id, sorted.
 */
function excludedTestStoredRuleIds(int $assetId): array
{
    $rules = (new Query())
        ->select(['ruleId'])
        ->from('{{%accessibilityaudit_asset_issues}}')
        ->where(['assetId' => $assetId])
        ->column();

    sort($rules);
    return $rules;
}

beforeEach(function() {
    // Settings are a singleton across tests, so remember the configured
    // exclusions, then pin a clean slate for the test: no exclusions, the
    // native alt field, and an empty stored audit.
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $this->savedExcludedVolumes = $settings->excludedVolumes;
    $settings->excludedVolumes = [];
    $settings->altTextField = 'alt';

    Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_asset_issues}}')->execute();
    Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_asset_stats}}')->execute();
});

afterEach(function() {
    // Restore the configured exclusions so nothing leaks into other test files.
    AccessibilityAudit::getInstance()->getSettings()->excludedVolumes = $this->savedExcludedVolumes;
});

// ---------------------------------------------------------------------------
// Resolving UIDs to ids
// ---------------------------------------------------------------------------

describe('AssetScanner::excludedVolumeIds', function() {
    it('is empty when nothing is configured', function() {
        expect(AccessibilityAudit::getInstance()->assets->excludedVolumeIds())->toBe([]);
    });

    it('resolves configured UIDs to ids and drops unknown ones', function() {
        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            expect(true)->toBeTrue();
            return;
        }

        AccessibilityAudit::getInstance()->getSettings()->excludedVolumes = [
            $volume->uid,
            'not-a-real-volume-uid',
        ];

        expect(AccessibilityAudit::getInstance()->assets->excludedVolumeIds())
            ->toBe([(int)$volume->id]);
    });
});

// ---------------------------------------------------------------------------
// Per-save re-audit no-op
// ---------------------------------------------------------------------------

describe('AssetScanner per-save exclusion', function() {
    it('raises nothing from scanAsset for an image in an excluded volume', function() {
        $assets = AccessibilityAudit::getInstance()->assets;
        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            expect(true)->toBeTrue();
            return;
        }

        $asset = new Asset();
        $asset->kind = Asset::KIND_IMAGE;
        $asset->alt = null;
        $asset->filename = 'photo.jpg';
        $asset->title = 'Test image';
        $asset->volumeId = (int)$volume->id;

        // Not excluded: a missing alt is a warning.
        expect($assets->scanAsset($asset))->toHaveCount(1)
            ->and($assets->scanAsset($asset)[0]->ruleId)->toBe('asset-alt-missing');

        // Excluded: nothing is raised at all.
        AccessibilityAudit::getInstance()->getSettings()->excludedVolumes = [$volume->uid];
        expect($assets->scanAsset($asset))->toBeEmpty();
    });

    it('stores nothing from syncAssetAudit for an excluded volume, but does otherwise', function() {
        $assets = AccessibilityAudit::getInstance()->assets;
        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            expect(true)->toBeTrue();
            return;
        }

        $asset = makeVolumeAuditAsset((int)$volume->id, null);

        // Excluded: the per-save re-audit stores nothing.
        AccessibilityAudit::getInstance()->getSettings()->excludedVolumes = [$volume->uid];
        $assets->syncAssetAudit($asset);
        expect(excludedTestStoredRuleIds($asset->id))->toBeEmpty();

        // Back in the audit: the same save now stores the missing-alt finding.
        AccessibilityAudit::getInstance()->getSettings()->excludedVolumes = [];
        $assets->syncAssetAudit($asset);
        expect(excludedTestStoredRuleIds($asset->id))->toBe(['asset-alt-missing']);
    });
});

// ---------------------------------------------------------------------------
// Volume-scoped clear and stored stats
// ---------------------------------------------------------------------------

describe('AssetScanner volume-scoped clear and stats', function() {
    it('clears the excluded volume\'s stored rows and leaves other volumes alone', function() {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        if (count($volumes) < 2) {
            expect(true)->toBeTrue();
            return;
        }
        $assets = AccessibilityAudit::getInstance()->assets;

        $inExcluded = makeVolumeAuditAsset((int)$volumes[0]->id, null);
        $inKept = makeVolumeAuditAsset((int)$volumes[1]->id, null);
        $assets->syncAssetAudit($inExcluded);
        $assets->syncAssetAudit($inKept);

        $assets->clearAssetAuditForVolumes([(int)$volumes[0]->id]);

        expect(excludedTestStoredRuleIds($inExcluded->id))->toBeEmpty()
            ->and(excludedTestStoredRuleIds($inKept->id))->toBe(['asset-alt-missing']);
    });

    it('drops an excluded volume\'s images from the stored stats', function() {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        if (count($volumes) < 2) {
            expect(true)->toBeTrue();
            return;
        }
        $assets = AccessibilityAudit::getInstance()->assets;

        $inExcluded = makeVolumeAuditAsset((int)$volumes[0]->id, null);
        $inKept = makeVolumeAuditAsset((int)$volumes[1]->id, null);
        $assets->syncAssetAudit($inExcluded);
        $assets->syncAssetAudit($inKept);
        $assets->updateStoredStats(2);

        $before = $assets->getStoredAssetStats();
        expect($before['withIssues'])->toBe(2);

        AccessibilityAudit::getInstance()->getSettings()->excludedVolumes = [$volumes[0]->uid];

        $stats = $assets->getStoredAssetStats();
        expect($stats['withIssues'])->toBe(1)
            ->and($stats['byRule'])->toEqual(['asset-alt-missing' => 1])
            // The total is the audited library, so the excluded volume's image
            // leaves the denominator too, not just the issue counts.
            ->and($stats['total'])->toBe($before['total'] - 1);
    });
});

// ---------------------------------------------------------------------------
// Paged listing
// ---------------------------------------------------------------------------

describe('AssetScanner::scanImagesPaged exclusion', function() {
    it('drops a real image in an excluded volume from the listing while keeping others', function() {
        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            expect(true)->toBeTrue();
            return;
        }
        $assets = AccessibilityAudit::getInstance()->assets;

        // A real asset element, so the Asset::find()-backed listing returns it.
        $asset = AssetFactory::factory()->volume($volume->handle)->create();

        // Isolate the listing to just this asset: it only surfaces images that
        // carry a stored issue row, so clear the table then sync this one.
        Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_asset_issues}}')->execute();
        $assets->syncAssetAudit($asset);

        expect(pagedAssetIds($assets->scanImagesPaged(1, 200)))->toContain((int)$asset->id);

        AccessibilityAudit::getInstance()->getSettings()->excludedVolumes = [$volume->uid];

        expect(pagedAssetIds($assets->scanImagesPaged(1, 200)))->not->toContain((int)$asset->id);
    });

    it('empties the listing total when every volume is excluded', function() {
        $assets = AccessibilityAudit::getInstance()->assets;

        AccessibilityAudit::getInstance()->getSettings()->excludedVolumes = array_map(
            static fn($volume): string => $volume->uid,
            Craft::$app->getVolumes()->getAllVolumes(),
        );

        expect($assets->scanImagesPaged(1, 10)['total'])->toBe(0);
    });
});
