<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\migrations\m260920_000000_homepage_uri_pattern as Migration;

// ---------------------------------------------------------------------------
// A blank pattern used to mean the homepage, and now means nothing. An install
// carrying one has to come out the far side still excluding the homepage, or
// the upgrade quietly puts a page back into every scan and every score.
// ---------------------------------------------------------------------------

it('writes a switched-on blank as the homepage expression', function() {
    $rewritten = Migration::rewrite([
        ['enabled' => true, 'siteId' => '', 'uriPattern' => ''],
    ]);

    expect($rewritten)->toBe([
        ['enabled' => true, 'siteId' => '', 'uriPattern' => '^$'],
    ]);
});

it('keeps the row scoped to whatever site it was scoped to', function() {
    $rewritten = Migration::rewrite([
        ['enabled' => true, 'siteId' => 2, 'uriPattern' => ''],
    ]);

    expect($rewritten)->toBe([
        ['enabled' => true, 'siteId' => 2, 'uriPattern' => '^$'],
    ]);
});

it('drops a blank that was switched off, which excluded nothing either way', function() {
    expect(Migration::rewrite([
        ['enabled' => false, 'siteId' => '', 'uriPattern' => ''],
    ]))->toBe([]);
});

it('leaves a row that already has a pattern alone', function() {
    $rows = [
        ['enabled' => true, 'siteId' => '', 'uriPattern' => '^checkout'],
        ['enabled' => false, 'siteId' => '', 'uriPattern' => 'print$'],
    ];

    expect(Migration::rewrite($rows))->toBe($rows);
});

it('reindexes so the saved rows are a list, not a gapped map', function() {
    $rewritten = Migration::rewrite([
        ['enabled' => false, 'siteId' => '', 'uriPattern' => ''],
        ['enabled' => true, 'siteId' => '', 'uriPattern' => '^checkout'],
    ]);

    expect(array_keys($rewritten))->toBe([0]);
});

it('produces a pattern the matcher agrees means the homepage', function() {
    // The two halves are written in different files, so the expression the
    // migration writes is checked against the matcher that has to honour it
    // rather than against itself.
    $pattern = Migration::rewrite([['enabled' => true, 'siteId' => '', 'uriPattern' => '']])[0]['uriPattern'];

    $settings = johnhenry\accessibilityaudit\AccessibilityAudit::getInstance()->getSettings();
    $stored = $settings->excludedUriPatterns;
    $settings->excludedUriPatterns = [['enabled' => true, 'siteId' => '', 'uriPattern' => $pattern]];

    $audit = johnhenry\accessibilityaudit\AccessibilityAudit::getInstance()->getAudit();
    $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

    try {
        expect($audit->isUriExcluded('__home__', $siteId))->toBeTrue()
            ->and($audit->isUriExcluded('about', $siteId))->toBeFalse();
    } finally {
        $settings->excludedUriPatterns = $stored;
    }
});
