<?php

use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\ScannableElementTypes;

// ---------------------------------------------------------------------------
// ScannableElementTypes helper
// ---------------------------------------------------------------------------

it('lists URL-bearing element types and excludes assets', function() {
    $all = ScannableElementTypes::all();

    expect($all)->toHaveKey(Entry::class)
        ->and($all)->not->toHaveKey(Asset::class);
});

it('treats the craft namespace as native', function() {
    $native = ScannableElementTypes::native();

    expect($native)->toContain(Entry::class);

    foreach ($native as $type) {
        expect(str_starts_with($type, 'craft\\'))->toBeTrue();
    }
});

// ---------------------------------------------------------------------------
// SettingsModel::resolvedScannedElementTypes()
// ---------------------------------------------------------------------------

it('defaults to the native types when unconfigured', function() {
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $settings->scannedElementTypes = null;

    expect($settings->resolvedScannedElementTypes())->toEqual(ScannableElementTypes::native());
});

it('intersects a configured allow-list with the available types', function() {
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $settings->scannedElementTypes = [Entry::class, 'some\\uninstalled\\Element'];

    expect($settings->resolvedScannedElementTypes())->toEqual([Entry::class]);
});

it('resolves an explicit empty allow-list to scanning nothing', function() {
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $settings->scannedElementTypes = [];

    expect($settings->resolvedScannedElementTypes())->toBe([]);
});

// ---------------------------------------------------------------------------
// getUrlElementsQuery() filter
// ---------------------------------------------------------------------------

it('drops an entry from scan discovery once its type is unticked', function() {
    $entry = scannableEntry('Element type filter fixture');
    $siteId = (int) $entry->siteId;
    $audit = AccessibilityAudit::getInstance()->audit;
    $settings = AccessibilityAudit::getInstance()->getSettings();

    $default = array_map('intval', array_column($audit->getUrlElementsQuery($siteId)->all(), 'elementId'));
    expect($default)->toContain((int) $entry->id);

    $settings->scannedElementTypes = [Category::class];
    $excluded = array_map('intval', array_column($audit->getUrlElementsQuery($siteId)->all(), 'elementId'));
    expect($excluded)->not->toContain((int) $entry->id);
});

// ---------------------------------------------------------------------------
// isElementExcluded()
// ---------------------------------------------------------------------------

it('marks an element excluded when its type is not in the scan set', function() {
    $entry = scannableEntry('Exclusion gate fixture');
    $audit = AccessibilityAudit::getInstance()->audit;
    $settings = AccessibilityAudit::getInstance()->getSettings();

    expect($audit->isElementExcluded($entry))->toBeFalse();

    $settings->scannedElementTypes = [Category::class];
    expect($audit->isElementExcluded($entry))->toBeTrue();
});

// ---------------------------------------------------------------------------
// pruneScansForElementTypes()
// ---------------------------------------------------------------------------

it('prunes stored scans for the given element types', function() {
    $entry = scannableEntry('Prune fixture');
    $audit = AccessibilityAudit::getInstance()->audit;

    $scanId = $audit->ensureScan((int) $entry->id, Entry::class, (int) $entry->siteId);
    expect($scanId)->toBeGreaterThan(0);

    $removed = $audit->pruneScansForElementTypes([Entry::class]);
    expect($removed)->toBeGreaterThan(0);

    $stillThere = (new Query())
        ->from('{{%accessibilityaudit_scans}}')
        ->where(['id' => $scanId])
        ->exists();
    expect($stillThere)->toBeFalse();
});
