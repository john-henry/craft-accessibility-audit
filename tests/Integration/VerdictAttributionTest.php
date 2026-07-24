<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\VerdictService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// A dismissal is a person's judgement call, and on a team of five the useful
// question months later is who made it. Attribution is recorded on the verdicts
// table but the issues row carries only the ruling, so a listing has to resolve
// the two by a hash it derives itself. These cover that resolution.
// ---------------------------------------------------------------------------

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
});

it('reports who recorded a ruling, and when', function() {
    $user = UserFactory::factory()->admin(true)->create();
    $this->actingAs($user);

    $entry = scannableEntry();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $verdicts = AccessibilityAudit::getInstance()->verdicts;
    $context = '<a href="/a">Read more</a>';

    $verdicts->setVerdict(
        $siteId,
        $entry->id,
        'potential:identical-links',
        $context,
        VerdictService::VERDICT_DISMISSED,
    );

    $map = $verdicts->metaForElements([$entry->id], $siteId);
    $meta = $verdicts->lookupMeta($map, $entry->id, 'potential:identical-links', $context);

    expect($meta)->not->toBeNull()
        ->and($meta['userId'])->toBe((int) $user->id)
        ->and($meta['date'])->not->toBeEmpty();
});

it('does not confuse one occurrence with another on the same page', function() {
    // Two links, same rule, same element. Only the one that was dismissed
    // should come back with an author.
    $this->actingAs(UserFactory::factory()->admin(true)->create());

    $entry = scannableEntry();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $verdicts = AccessibilityAudit::getInstance()->verdicts;

    $verdicts->setVerdict(
        $siteId,
        $entry->id,
        'potential:identical-links',
        '<a href="/a">Read more</a>',
        VerdictService::VERDICT_DISMISSED,
    );

    $map = $verdicts->metaForElements([$entry->id], $siteId);

    expect($verdicts->lookupMeta($map, $entry->id, 'potential:identical-links', '<a href="/a">Read more</a>'))->not->toBeNull()
        ->and($verdicts->lookupMeta($map, $entry->id, 'potential:identical-links', '<a href="/b">Read more</a>'))->toBeNull();
});

it('returns nothing rather than querying when given no elements', function() {
    expect(AccessibilityAudit::getInstance()->verdicts->metaForElements([], 1))->toBe([]);
});
