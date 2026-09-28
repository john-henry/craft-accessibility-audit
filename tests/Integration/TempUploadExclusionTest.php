<?php

use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Db;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Craft's temporary uploads sit in a folder with no volume, so a part-built
// upload carries a NULL volumeId. No volume exclusion can reach it, and it
// never appears in the settings list to be picked, so the audit has to leave it
// alone on its own account.
//
// Left in, the sweep reported every working file anyone had ever dropped on a
// field as missing alt text, and the Images screen filled with "photo.jpg /
// Temporary Uploads" rows whose files Craft had since cleared.
//
// The old behaviour was also inconsistent: with no excluded volumes the NOT IN
// test was absent and temp rows were swept; with any excluded volume the test
// evaluated to NULL against a NULL volumeId and they silently vanished. Which
// of those you got depended on an unrelated setting.
// ---------------------------------------------------------------------------

/** An assets row with no volume, which is what a temporary upload is. */
function makeTempUploadAsset(): int
{
    $elementId = UserFactory::factory()->create()->id;

    $folderId = (new Query())
        ->select('id')
        ->from('{{%volumefolders}}')
        ->where(['volumeId' => null])
        ->scalar();

    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%assets}}', [
        'id' => $elementId,
        'volumeId' => null,
        'folderId' => $folderId,
        'filename' => 'photo.jpg',
        'kind' => Asset::KIND_IMAGE,
        'dateCreated' => $now,
        'dateUpdated' => $now,
    ])->execute();

    return (int)$elementId;
}

it('keeps temporary uploads out of the image sweep', function() {
    $tempId = makeTempUploadAsset();

    $ids = AccessibilityAudit::getInstance()->getAssets()->imageQuery()->ids();

    expect($ids)->not->toContain($tempId);
});

it('keeps them out whether or not a volume is excluded', function() {
    $tempId = makeTempUploadAsset();
    $assets = AccessibilityAudit::getInstance()->getAssets();
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $original = $settings->excludedVolumes;

    try {
        // The state where the old NOT IN test was absent entirely.
        $settings->excludedVolumes = [];
        expect($assets->imageQuery()->ids())->not->toContain($tempId);

        // And the state where it was present and dropped them by accident.
        $volumeUid = (new Query())->select('uid')->from('{{%volumes}}')->scalar();
        $settings->excludedVolumes = [$volumeUid];
        expect($assets->imageQuery()->ids())->not->toContain($tempId);
    } finally {
        $settings->excludedVolumes = $original;
    }
});

it('still covers images that live in a real volume', function() {
    makeTempUploadAsset();

    $realCount = (int)(new Query())
        ->from('{{%assets}} a')
        ->innerJoin('{{%elements}} e', 'e.id = a.id')
        ->where(['a.kind' => Asset::KIND_IMAGE, 'e.dateDeleted' => null])
        ->andWhere(['not', ['a.volumeId' => null]])
        ->count();

    // The exclusion must take the temporary uploads and nothing else with them.
    expect((int)AccessibilityAudit::getInstance()->getAssets()->imageQuery()->count())
        ->toBe($realCount);
});

it('does not store a finding when a temporary upload is saved', function() {
    $tempId = makeTempUploadAsset();

    $asset = new Asset();
    $asset->id = $tempId;
    $asset->kind = Asset::KIND_IMAGE;
    $asset->filename = 'photo.jpg';
    $asset->volumeId = null;
    $asset->alt = null;

    AccessibilityAudit::getInstance()->getAssets()->syncAssetAudit($asset);

    $stored = (new Query())
        ->from('{{%accessibilityaudit_asset_issues}}')
        ->where(['assetId' => $tempId])
        ->count();

    // Dropping a file on a field saves it to the temporary folder first. Left
    // unchecked that is where the "no alt text" rows came from, one per upload.
    expect((int)$stored)->toBe(0);
});

it('still judges an unsaved asset handed to it directly', function() {
    // No id means never saved, which is not the same as belonging to no volume.
    // The paths that scan an element in hand pass exactly this.
    $asset = new Asset();
    $asset->kind = Asset::KIND_IMAGE;
    $asset->filename = 'photo.jpg';
    $asset->alt = null;

    expect(AccessibilityAudit::getInstance()->getAssets()->scanAsset($asset))
        ->not->toBeEmpty();
});
