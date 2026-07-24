<?php

use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Db;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds an in-memory image Asset with the given alt text. Nothing is saved:
 * checkImageAlt() only reads properties off the element, so a constructed
 * instance exercises the real logic without needing a volume or filesystem.
 */
function makeMemoryImageAsset(?string $alt, string $filename = 'photo.jpg', ?int $id = null): Asset
{
    $asset = new Asset();
    $asset->kind = Asset::KIND_IMAGE;
    $asset->alt = $alt;
    $asset->filename = $filename;
    $asset->title = 'Test image';

    if ($id !== null) {
        $asset->id = $id;
    }

    return $asset;
}

/**
 * The asset-issues table has a foreign key to the elements table, so
 * stored-audit tests borrow a freshly created user's element id as the
 * "asset" id.
 *
 * That id also gets a matching row in the assets table. getStoredAssetStats()
 * and pruneOrphanedAssetIssues() both decide what counts as a real image by
 * joining assets on kind, which is what keeps their numbers equal to the rows
 * the Assets page lists. A fixture without that row isn't the shape those
 * queries read, and testing against it once hid a live counting bug.
 */
function makeStoredAuditAsset(?string $alt): Asset
{
    $elementId = UserFactory::factory()->create()->id;

    $folderId = (new Query())
        ->select('id')
        ->from('{{%volumefolders}}')
        ->where(['not', ['volumeId' => null]])
        ->scalar();

    $now = Db::prepareDateForDb(new \DateTime());
    Craft::$app->getDb()->createCommand()->insert('{{%assets}}', [
        'id' => $elementId,
        'folderId' => $folderId,
        'filename' => 'photo.jpg',
        'kind' => Asset::KIND_IMAGE,
        'dateCreated' => $now,
        'dateUpdated' => $now,
    ])->execute();

    return makeMemoryImageAsset($alt, 'photo.jpg', $elementId);
}

/**
 * @return string[] The rule ids stored for the given asset id, sorted.
 */
function storedAssetRuleIds(int $assetId): array
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
    // The settings model is a singleton across tests; pin the field the
    // scanner reads so another test file can't redirect it.
    AccessibilityAudit::getInstance()->getSettings()->altTextField = 'alt';
});

// ---------------------------------------------------------------------------
// checkImageAlt variants (via the public scanAsset surface)
// ---------------------------------------------------------------------------

describe('AssetScanner::scanAsset alt-text checks', function() {
    it('flags a missing alt as a warning and stops there', function() {
        $issues = AccessibilityAudit::getInstance()->assets->scanAsset(makeMemoryImageAsset(null));

        expect($issues)->toHaveCount(1)
            ->and($issues[0]->ruleId)->toBe('asset-alt-missing')
            ->and($issues[0]->severity)->toBe('warning');
    });

    it('treats whitespace-only alt as missing', function() {
        $issues = AccessibilityAudit::getInstance()->assets->scanAsset(makeMemoryImageAsset('   '));

        expect($issues)->toHaveCount(1)
            ->and($issues[0]->ruleId)->toBe('asset-alt-missing');
    });

    it('flags filename-as-alt as a warning', function() {
        $issues = AccessibilityAudit::getInstance()->assets->scanAsset(makeMemoryImageAsset('IMG_4032.jpg'));

        expect($issues)->toHaveCount(1)
            ->and($issues[0]->ruleId)->toBe('asset-alt-filename')
            ->and($issues[0]->severity)->toBe('warning');
    });

    it('flags very short alt text as a notice', function() {
        $issues = AccessibilityAudit::getInstance()->assets->scanAsset(makeMemoryImageAsset('ab'));

        expect($issues)->toHaveCount(1)
            ->and($issues[0]->ruleId)->toBe('asset-alt-short')
            ->and($issues[0]->severity)->toBe('notice');
    });

    it('passes a proper alt text clean', function() {
        $issues = AccessibilityAudit::getInstance()->assets->scanAsset(
            makeMemoryImageAsset('A red setter running on the strand')
        );

        expect($issues)->toBeEmpty();
    });

    it('raises the PDF accessibility notice for PDFs', function() {
        $asset = new Asset();
        $asset->kind = Asset::KIND_PDF;
        $asset->filename = 'report.pdf';
        $asset->title = 'Report';

        $issues = AccessibilityAudit::getInstance()->assets->scanAsset($asset);

        expect($issues)->toHaveCount(1)
            ->and($issues[0]->ruleId)->toBe('pdf-accessibility');
    });

    it('ignores kinds it does not audit', function() {
        $asset = new Asset();
        $asset->kind = Asset::KIND_VIDEO;
        $asset->filename = 'clip.mp4';

        expect(AccessibilityAudit::getInstance()->assets->scanAsset($asset))->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// scanImagesPaged volume filter
// ---------------------------------------------------------------------------

describe('AssetScanner::scanImagesPaged volume filter', function() {
    it('returns a well-formed page with no volume filter', function() {
        $paged = AccessibilityAudit::getInstance()->assets->scanImagesPaged(1, 10);

        expect($paged)->toHaveKeys(['results', 'total', 'page', 'perPage', 'totalPages'])
            ->and($paged['results'])->toBeArray()
            ->and($paged['total'])->toBeInt()
            ->and($paged['page'])->toBe(1);
    });

    it('restricts the count to a single volume', function() {
        // Whatever the dev library holds, a per-volume count can never exceed
        // the whole-library count, and the shape must hold.
        $all = AccessibilityAudit::getInstance()->assets->scanImagesPaged(1, 10);

        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            expect(true)->toBeTrue();
            return;
        }

        $scoped = AccessibilityAudit::getInstance()->assets->scanImagesPaged(1, 10, $volume->handle);

        expect($scoped['total'])->toBeLessThanOrEqual($all['total']);
    });

    it('yields an empty, non-crashing page for an unknown volume handle', function() {
        $paged = AccessibilityAudit::getInstance()->assets->scanImagesPaged(1, 10, 'no-such-volume-xyz');

        expect($paged['total'])->toBe(0)
            ->and($paged['results'])->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// syncAssetAudit row lifecycle
// ---------------------------------------------------------------------------

describe('AssetScanner::syncAssetAudit', function() {
    it('inserts a row per finding', function() {
        $asset = makeStoredAuditAsset(null);

        AccessibilityAudit::getInstance()->assets->syncAssetAudit($asset);

        expect(storedAssetRuleIds($asset->id))->toBe(['asset-alt-missing']);
    });

    it('replaces a stale rule when the finding changes', function() {
        $assets = AccessibilityAudit::getInstance()->assets;
        $asset = makeStoredAuditAsset(null);
        $assets->syncAssetAudit($asset);

        // The alt gets filled in, but with the filename: the missing-alt row
        // must go and the filename row take its place.
        $asset->alt = 'IMG_4032.jpg';
        $assets->syncAssetAudit($asset);

        expect(storedAssetRuleIds($asset->id))->toBe(['asset-alt-filename']);
    });

    it('clears the asset entirely once the alt text is fixed', function() {
        $assets = AccessibilityAudit::getInstance()->assets;
        $asset = makeStoredAuditAsset(null);
        $assets->syncAssetAudit($asset);

        $asset->alt = 'A red setter running on the strand';
        $assets->syncAssetAudit($asset);

        expect(storedAssetRuleIds($asset->id))->toBeEmpty();
    });

    it('is idempotent when the finding has not changed', function() {
        $assets = AccessibilityAudit::getInstance()->assets;
        $asset = makeStoredAuditAsset(null);

        $assets->syncAssetAudit($asset);
        $assets->syncAssetAudit($asset);

        expect(storedAssetRuleIds($asset->id))->toBe(['asset-alt-missing']);
    });
});

// ---------------------------------------------------------------------------
// Stored stats round-trip
// ---------------------------------------------------------------------------

describe('AssetScanner stored stats', function() {
    // The dev database these tests wrap in a transaction may already hold a
    // real sweep, so each test clears the stored tables first (rolled back
    // with everything else on teardown).
    beforeEach(function() {
        Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_asset_stats}}')->execute();
        Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_asset_issues}}')->execute();
    });

    it('returns null before any sweep has run', function() {
        expect(AccessibilityAudit::getInstance()->assets->getStoredAssetStats())->toBeNull();
    });

    it('inserts on the first update and updates in place afterwards', function() {
        $assets = AccessibilityAudit::getInstance()->assets;

        $assets->updateStoredStats(5);
        $assets->updateStoredStats(9);

        $rows = (new Query())->select(['totalImages'])->from('{{%accessibilityaudit_asset_stats}}')->all();

        expect($rows)->toHaveCount(1)
            ->and((int)$rows[0]['totalImages'])->toBe(9);
    });

    it('round-trips issue counts, per-rule breakdown, and a live total', function() {
        $assets = AccessibilityAudit::getInstance()->assets;

        $assets->syncAssetAudit(makeStoredAuditAsset(null));
        $assets->syncAssetAudit(makeStoredAuditAsset('IMG_4032.jpg'));
        $assets->updateStoredStats(2);

        $stats = $assets->getStoredAssetStats();

        // The total is the live image count, not the swept figure, so it's
        // asserted against the same query the service runs.
        $liveTotal = (int) (new Query())
            ->from('{{%assets}}')
            ->where(['kind' => Asset::KIND_IMAGE])
            ->count();

        expect($stats)->not->toBeNull()
            ->and($stats['withIssues'])->toBe(2)
            ->and($stats['byRule'])->toEqualCanonicalizing(['asset-alt-filename' => 1, 'asset-alt-missing' => 1])
            ->and($stats['total'])->toBe($liveTotal)
            ->and($stats['dateScanned'])->not->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// Deletion and orphan handling
// ---------------------------------------------------------------------------

describe('AssetScanner deletion handling', function() {
    beforeEach(function() {
        Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_asset_stats}}')->execute();
        Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_asset_issues}}')->execute();
    });

    it('drops a trashed (soft-deleted) asset from the stored stats', function() {
        $assets = AccessibilityAudit::getInstance()->assets;

        $live = makeStoredAuditAsset(null);
        $trashed = makeStoredAuditAsset('IMG_4032.jpg');
        $assets->syncAssetAudit($live);
        $assets->syncAssetAudit($trashed);
        $assets->updateStoredStats(2);

        expect($assets->getStoredAssetStats()['withIssues'])->toBe(2);

        // Soft-delete leaves the elements row in place with dateDeleted set;
        // the stats must exclude it so a chip's count matches the Assets page.
        Craft::$app->getDb()->createCommand()
            ->update('{{%elements}}', ['dateDeleted' => Db::prepareDateForDb(new \DateTime())], ['id' => $trashed->id])
            ->execute();

        $stats = $assets->getStoredAssetStats();

        expect($stats['withIssues'])->toBe(1)
            ->and($stats['byRule'])->toEqual(['asset-alt-missing' => 1]);
    });

    it('keeps a trashed asset\'s rows so a restore brings its history back', function() {
        $assets = AccessibilityAudit::getInstance()->assets;

        $trashed = makeStoredAuditAsset(null);
        $assets->syncAssetAudit($trashed);

        Craft::$app->getDb()->createCommand()
            ->update('{{%elements}}', ['dateDeleted' => Db::prepareDateForDb(new \DateTime())], ['id' => $trashed->id])
            ->execute();

        // Prune only clears rows whose element is gone entirely (hard-deleted);
        // a soft-deleted asset's history is left intact.
        $assets->pruneOrphanedAssetIssues();

        expect(storedAssetRuleIds($trashed->id))->toBe(['asset-alt-missing']);
    });

    it('leaves live assets untouched when pruning', function() {
        $assets = AccessibilityAudit::getInstance()->assets;

        $a = makeStoredAuditAsset(null);
        $b = makeStoredAuditAsset('IMG_4032.jpg');
        $assets->syncAssetAudit($a);
        $assets->syncAssetAudit($b);

        expect($assets->pruneOrphanedAssetIssues())->toBe(0)
            ->and(storedAssetRuleIds($a->id))->toBe(['asset-alt-missing'])
            ->and(storedAssetRuleIds($b->id))->toBe(['asset-alt-filename']);
    });

    it('ignores and prunes a row that does not point at an image asset', function() {
        $assets = AccessibilityAudit::getInstance()->assets;

        $live = makeStoredAuditAsset(null);
        $assets->syncAssetAudit($live);
        $assets->updateStoredStats(1);

        // A real, live element that simply is not an image asset. The Assets
        // page resolves ids through an element query, so a row like this can
        // never render: counting it made a filter chip claim more images than
        // the page could show.
        $strayId = (int) UserFactory::factory()->create()->id;
        $now = Db::prepareDateForDb(new \DateTime());
        Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_asset_issues}}', [
            'assetId' => $strayId,
            'ruleId' => 'asset-alt-filename',
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        $stats = $assets->getStoredAssetStats();

        expect($stats['withIssues'])->toBe(1)
            ->and($stats['byRule'])->toEqual(['asset-alt-missing' => 1])
            ->and($assets->pruneOrphanedAssetIssues())->toBe(1)
            ->and(storedAssetRuleIds($strayId))->toBeEmpty();
    });

    it('clears a single asset\'s stored rows', function() {
        $assets = AccessibilityAudit::getInstance()->assets;

        $a = makeStoredAuditAsset(null);
        $b = makeStoredAuditAsset('IMG_4032.jpg');
        $assets->syncAssetAudit($a);
        $assets->syncAssetAudit($b);

        $assets->clearStoredIssues($a->id);

        expect(storedAssetRuleIds($a->id))->toBeEmpty()
            ->and(storedAssetRuleIds($b->id))->toBe(['asset-alt-filename']);
    });
});

// ---------------------------------------------------------------------------
// Decorative flags
// ---------------------------------------------------------------------------

describe('AssetScanner decorative flags', function() {
    it('persists a decorative flag and reads it back, then clears it on unmark', function() {
        $assets = AccessibilityAudit::getInstance()->assets;
        $asset = makeStoredAuditAsset(null);

        expect($assets->isDecorative($asset->id))->toBeFalse();

        $assets->setDecorative($asset->id, true);
        expect($assets->isDecorative($asset->id))->toBeTrue();

        $assets->setDecorative($asset->id, false);
        expect($assets->isDecorative($asset->id))->toBeFalse();
    });

    it('raises no missing-alt issue for a decorative image with empty alt', function() {
        $assets = AccessibilityAudit::getInstance()->assets;
        $asset = makeStoredAuditAsset(null);

        // Before marking: an empty alt is a warning.
        $before = $assets->scanAsset($asset);
        expect($before)->toHaveCount(1)
            ->and($before[0]->ruleId)->toBe('asset-alt-missing')
            ->and($before[0]->severity)->toBe('warning');

        // After marking decorative: nothing is raised.
        $assets->setDecorative($asset->id, true);
        expect($assets->scanAsset($asset))->toBeEmpty();
    });

    it('batch-loads only the decorative subset of a set of ids', function() {
        $assets = AccessibilityAudit::getInstance()->assets;
        $decorative = makeStoredAuditAsset(null);
        $plain = makeStoredAuditAsset(null);

        $assets->setDecorative($decorative->id, true);

        $subset = $assets->decorativeAssetIds([$decorative->id, $plain->id]);

        expect($subset)->toBe([$decorative->id]);
    });

    it('drops a decorative image from the stored missing-alt counts', function() {
        $assets = AccessibilityAudit::getInstance()->assets;
        Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_asset_stats}}')->execute();
        Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_asset_issues}}')->execute();

        // Two images, both with a stored missing-alt row.
        $a = makeStoredAuditAsset(null);
        $b = makeStoredAuditAsset(null);
        $assets->syncAssetAudit($a);
        $assets->syncAssetAudit($b);
        $assets->updateStoredStats(2);

        expect($assets->getStoredAssetStats()['withIssues'])->toBe(2);

        // Mark one decorative: its missing-alt row is excluded from the counts
        // at query time, even before the next sync rewrites the stored rows.
        $assets->setDecorative($a->id, true);

        $stats = $assets->getStoredAssetStats();
        expect($stats['withIssues'])->toBe(1)
            ->and($stats['byRule'])->toEqual(['asset-alt-missing' => 1]);
    });
});

describe('AssetScanner::clearAssetAudit', function() {
    it('empties every stored asset issue and the cached stats', function() {
        // Changing the Alt Text Field setting must invalidate the whole stored
        // audit, not one asset's rows, so seed both a stored issue and stats.
        $assets = AccessibilityAudit::getInstance()->assets;

        $a = makeStoredAuditAsset(null);
        $assets->syncAssetAudit($a);
        $assets->updateStoredStats(7);

        expect(storedAssetRuleIds($a->id))->not->toBeEmpty()
            ->and((new Query())->from('{{%accessibilityaudit_asset_stats}}')->count())->toBeGreaterThan(0);

        $assets->clearAssetAudit();

        expect((int) (new Query())->from('{{%accessibilityaudit_asset_issues}}')->count())->toBe(0)
            ->and((int) (new Query())->from('{{%accessibilityaudit_asset_stats}}')->count())->toBe(0);
    });
});
