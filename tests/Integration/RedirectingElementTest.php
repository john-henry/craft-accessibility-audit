<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\services\AuditService;

// ---------------------------------------------------------------------------
// An entry whose address redirects has no page of its own.
//
// A section landing page sending readers to its first child is the usual
// shape: /docs/api redirects to /docs/api/overview, and both are entries. What
// comes back belongs to the child, and the child is scanned in its own right,
// so storing it against the parent files one page under two names. The
// findings double, both count against the edition's page limit, and answering
// a question on one leaves its twin asking.
//
// The comparison is the delicate part. Too strict and ordinary redirects stop
// pages being scanned at all, which is a far worse failure than the one being
// fixed: http to https, a trailing slash and a tracking parameter are all the
// same page arriving by a slightly different road.
// ---------------------------------------------------------------------------

/** Whether the scanner counts two addresses as the same page. */
function isSamePage(string $requested, string $landed): bool
{
    $method = new ReflectionMethod(AuditService::class, '_isSamePage');
    $method->setAccessible(true);

    return (bool) $method->invoke(new AuditService(), $requested, $landed);
}

describe('a redirect that lands on another page', function() {
    it('spots a landing page sending readers to its first child', function() {
        // The real case: two entries, one page between them.
        expect(isSamePage(
            'https://example.test/plugins/x/docs/api',
            'https://example.test/plugins/x/docs/api/overview',
        ))->toBeFalse();
    });

    it('is not fooled by a path that merely starts the same', function() {
        expect(isSamePage('https://example.test/docs/api', 'https://example.test/docs/apiary'))
            ->toBeFalse();
    });
});

describe('redirects that are the same page arriving differently', function() {
    it('accepts a scheme change', function() {
        expect(isSamePage('http://example.test/a', 'https://example.test/a'))->toBeTrue();
    });

    it('accepts a trailing slash', function() {
        expect(isSamePage('https://example.test/a', 'https://example.test/a/'))->toBeTrue()
            ->and(isSamePage('https://example.test/a/', 'https://example.test/a'))->toBeTrue();
    });

    it('accepts a query string picked up on the way', function() {
        // A redirect that appends a tracking parameter has not moved anybody.
        expect(isSamePage('https://example.test/a', 'https://example.test/a?ref=nav'))->toBeTrue();
    });

    it('accepts a fragment', function() {
        expect(isSamePage('https://example.test/a', 'https://example.test/a#main'))->toBeTrue();
    });

    it('ignores the case of the host but not of the path', function() {
        // Hosts are case-insensitive by definition; paths are not, and two
        // paths differing in case can be two pages.
        expect(isSamePage('https://EXAMPLE.test/a', 'https://example.TEST/a'))->toBeTrue()
            ->and(isSamePage('https://example.test/About', 'https://example.test/about'))->toBeFalse();
    });

    it('treats the root and an empty path as one', function() {
        expect(isSamePage('https://example.test', 'https://example.test/'))->toBeTrue();
    });
});

it('skips the scan rather than filing another page under this one', function() {
    $source = (string) file_get_contents((new ReflectionClass(AuditService::class))->getFileName());

    $start = strpos($source, 'public function scanElement(');
    $body = substr($source, (int) $start, 2600);

    expect($body)->toContain('if (!$this->_isSamePage($url, $page[\'url\'])) {')
        ->and($body)->toContain('which is scanned as its own page.');
});

it('leaves a scan by address filing under where it landed', function() {
    // The opposite rule, and deliberately so. A URL scan has no element that
    // owns the address, so recording it under the one it ended on is right.
    // An element scan has one, and the landed page belongs to a different
    // element entirely.
    $source = (string) file_get_contents((new ReflectionClass(AuditService::class))->getFileName());

    $start = strpos($source, 'public function scanUrl(');
    $body = substr($source, (int) $start, 2200);

    expect($body)->toContain('$url = $page[\'url\'];')
        ->and($body)->not->toContain('_isSamePage');
});
