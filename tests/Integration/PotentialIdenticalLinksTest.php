<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// potential:identical-links destination matching
// ---------------------------------------------------------------------------
// The check asks whether links sharing text point somewhere different. It used
// to compare raw href attributes, so a site's own navigation, written absolute
// in one place and relative in another, was reported as a fault. That was the
// bulk of what this rule produced. Destinations are compared resolved now, and
// these pin both halves: the noise stays gone, the real ambiguity still lands.

/** @return string[] The identical-links rule ids found in some markup. */
function identicalLinkHits(string $html): array
{
    $issues = AccessibilityAudit::getInstance()->potential->scan($html);

    return array_values(array_filter(
        array_map(static fn($issue) => $issue->ruleId, $issues),
        static fn(string $ruleId): bool => $ruleId === 'potential:identical-links',
    ));
}

describe('PotentialScanner::checkIdenticalLinks', function() {
    it('does not flag the same page written absolute and relative', function() {
        $host = parse_url((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST);

        expect(identicalLinkHits(
            '<a href="https://' . $host . '/">Home</a><a href="/">Home</a>'
        ))->toBeEmpty();
    });

    it('does not flag a trailing slash as a different destination', function() {
        expect(identicalLinkHits(
            '<a href="/contact">Contact</a><a href="/contact/">Contact</a>'
        ))->toBeEmpty();
    });

    it('still flags shared text that genuinely goes to two places', function() {
        expect(identicalLinkHits(
            '<a href="/news/one">Read more</a><a href="/news/two">Read more</a>'
        ))->toHaveCount(1);
    });

    it('keeps a query string as a real difference', function() {
        // ?page=2 is another destination, not another spelling of the same one.
        expect(identicalLinkHits(
            '<a href="/archive">Archive</a><a href="/archive?page=2">Archive</a>'
        ))->toHaveCount(1);
    });

    it('does not merge two different sites that share a path', function() {
        expect(identicalLinkHits(
            '<a href="https://example.com/about">About</a><a href="https://example.org/about">About</a>'
        ))->toHaveCount(1);
    });
});
