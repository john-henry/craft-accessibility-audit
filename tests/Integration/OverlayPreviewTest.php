<?php

use craft\db\Query;
use craft\elements\Entry;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The overlay runs inside Craft's preview pane, where the matched element is
// whatever is being previewed. Once an edit creates a provisional draft, that
// is a draft, and a scan filed against it never reaches the canonical
// element's report and is orphaned when the draft is published or dropped.
//
// The overlay scans a preview and shows what it finds. It stores nothing.
// ---------------------------------------------------------------------------

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

function draftOf(Entry $entry): Entry
{
    $draft = Craft::$app->getDrafts()->createDraft($entry, $entry->authorId ?: 1, 'preview test');

    return Entry::find()
        ->draftId($draft->draftId)
        ->siteId($entry->siteId)
        ->status(null)
        ->one();
}

it('tells the overlay not to store when the previewed element is a draft', function() {
    $entry = scannableEntry();
    $config = AccessibilityAudit::getInstance()->getOverlay()->buildConfig(draftOf($entry), $entry->siteId);

    expect($config['storeResults'])->toBeFalse();
});

it('still stores for a canonical element', function() {
    $entry = scannableEntry();
    $config = AccessibilityAudit::getInstance()->getOverlay()->buildConfig($entry, $entry->siteId);

    expect($config['storeResults'])->toBeTrue()
        ->and($config['elementId'])->toBe((int)$entry->id);
});

it('hands the overlay no element to attach a draft scan to', function() {
    // Belt and braces: even if the flag were ignored, there is no id to post.
    $entry = scannableEntry();
    $config = AccessibilityAudit::getInstance()->getOverlay()->buildConfig(draftOf($entry), $entry->siteId);

    expect($config['elementId'])->toBe(0)
        ->and($config['scanId'])->toBe(0);
});

it('does not show the published page score while previewing a draft', function() {
    // The stored scan describes what is live, not the draft on screen.
    $entry = scannableEntry();
    AccessibilityAudit::getInstance()->getAudit()->ensureScan((int)$entry->id, Entry::class, (int)$entry->siteId);

    $config = AccessibilityAudit::getInstance()->getOverlay()->buildConfig(draftOf($entry), $entry->siteId);

    expect($config['storedScan'])->toBeNull();
});

it('points Open full report at the canonical element, not the draft', function() {
    $entry = scannableEntry();
    $draft = draftOf($entry);
    $config = AccessibilityAudit::getInstance()->getOverlay()->buildConfig($draft, $entry->siteId);

    expect($config['reportUrl'])
        ->toContain('elementId=' . $entry->id)
        ->not->toContain('elementId=' . $draft->id);
});

it('refuses to create a scan for a draft, whatever the client posts', function() {
    // The overlay sends the element id, so the flag alone cannot be what
    // enforces this. ensureScan is the funnel every write goes through.
    $entry = scannableEntry();
    $draft = draftOf($entry);

    $scanId = AccessibilityAudit::getInstance()->getAudit()
        ->ensureScan((int)$draft->id, Entry::class, (int)$entry->siteId);

    $rows = (new Query())
        ->from('{{%accessibilityaudit_scans}}')
        ->where(['elementId' => (int)$draft->id])
        ->count();

    expect($scanId)->toBe(0)
        ->and((int)$rows)->toBe(0);
});

it('still creates a scan for the canonical element', function() {
    // The guard has to refuse drafts without refusing everything.
    $entry = scannableEntry();

    $scanId = AccessibilityAudit::getInstance()->getAudit()
        ->ensureScan((int)$entry->id, Entry::class, (int)$entry->siteId);

    expect($scanId)->toBeGreaterThan(0);
});
