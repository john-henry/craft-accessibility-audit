<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// URI exclusion is one regex-matched gate every scan path consults. These lock
// its behaviour: regex matching, homepage-as-empty-pattern, per-site scoping,
// the enabled toggle, and a malformed pattern failing safe (no match, no throw).
// ---------------------------------------------------------------------------

function withExcluded(array $patterns, callable $fn): void
{
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $original = $settings->excludedUriPatterns;
    $settings->excludedUriPatterns = $patterns;
    try {
        $fn(AccessibilityAudit::getInstance()->audit);
    } finally {
        $settings->excludedUriPatterns = $original;
    }
}

it('matches a URI against a regular expression', function() {
    withExcluded([['uriPattern' => '^shop/']], function($audit) {
        expect($audit->isUriExcluded('shop/drinks/7up', 1))->toBeTrue()
            ->and($audit->isUriExcluded('/shop/drinks/7up', 1))->toBeTrue()   // leading slash normalised
            ->and($audit->isUriExcluded('blog/hello', 1))->toBeFalse();
    });
});

it('treats an empty pattern as the homepage only', function() {
    withExcluded([['uriPattern' => '']], function($audit) {
        expect($audit->isUriExcluded('__home__', 1))->toBeTrue()
            ->and($audit->isUriExcluded(null, 1))->toBeTrue()
            ->and($audit->isUriExcluded('about', 1))->toBeFalse();
    });
});

it('scopes a pattern to one site when siteId is set', function() {
    withExcluded([['uriPattern' => '^print', 'siteId' => 2]], function($audit) {
        expect($audit->isUriExcluded('print/order', 2))->toBeTrue()
            ->and($audit->isUriExcluded('print/order', 1))->toBeFalse();  // different site
    });
});

it('respects the enabled toggle', function() {
    withExcluded([['uriPattern' => '^shop', 'enabled' => false]], function($audit) {
        expect($audit->isUriExcluded('shop/x', 1))->toBeFalse();
    });
});

it('returns false with no patterns set', function() {
    withExcluded([], function($audit) {
        expect($audit->isUriExcluded('anything', 1))->toBeFalse();
    });
});

it('fails safe on a malformed pattern rather than throwing', function() {
    withExcluded([['uriPattern' => '(unclosed']], function($audit) {
        expect($audit->isUriExcluded('anything', 1))->toBeFalse();
    });
});

it('filters scan rows down to the excluded ids', function() {
    withExcluded([['uriPattern' => '^shop/']], function($audit) {
        $rows = [
            ['id' => 10, 'siteId' => 1, 'uri' => 'shop/drinks/7up'],
            ['id' => 11, 'siteId' => 1, 'uri' => 'blog/hello'],
            ['id' => 12, 'siteId' => 1, 'uri' => 'shop/food'],
        ];
        expect($audit->filterExcludedScanRows($rows))->toBe([10, 12]);
    });
});

it('prunes nothing when no scanned page matches the list', function() {
    // Exercises the query path without deleting: no real scan URI matches this.
    withExcluded([['uriPattern' => '^zzz-no-such-path/']], function($audit) {
        expect($audit->pruneExcludedPages())->toBe(['pages' => 0, 'issues' => 0]);
    });
});
