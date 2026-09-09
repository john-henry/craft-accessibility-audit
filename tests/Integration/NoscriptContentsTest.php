<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// A noscript's children are only markup when scripting is off. With scripting
// on the contents are one raw text node and no elements exist in there, but
// libxml has no scripting flag and parses them every time.
//
// Google Tag Manager's body snippet is the common case: a hidden, sizeless,
// untitled iframe reported on every page of every site carrying GTM.
// ---------------------------------------------------------------------------

function noscriptScanIds(string $html): array
{
    return array_map(
        static fn($issue) => $issue->ruleId,
        AccessibilityAudit::getInstance()->content->scan($html),
    );
}

describe('markup inside a noscript', function() {
    it('does not report the Google Tag Manager iframe', function() {
        $html = <<<'HTML'
            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-ABC123"
                height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
            <p>Real content</p>
            HTML;

        expect(noscriptScanIds($html))->not->toContain('iframe-title');
    });

    it('does not report an untitled iframe in a noscript generally', function() {
        expect(noscriptScanIds('<noscript><iframe src="/a"></iframe></noscript>'))
            ->not->toContain('iframe-title');
    });

    it('does not report an image with no alt inside one', function() {
        expect(noscriptScanIds('<noscript><img src="/pixel.gif"></noscript>'))
            ->not->toContain('img-alt');
    });

    it('does not report an empty heading inside one', function() {
        expect(noscriptScanIds('<noscript><h2></h2></noscript>'))->not->toContain('empty-heading');
    });

    it('handles a noscript in the head', function() {
        $html = '<html><head><noscript><iframe src="/a"></iframe></noscript></head>'
            . '<body><p>Hi</p></body></html>';

        expect(noscriptScanIds($html))->not->toContain('iframe-title');
    });

    it('reaches a noscript nested inside a template', function() {
        $html = '<template><noscript><iframe src="/a"></iframe></noscript></template>';

        expect(noscriptScanIds($html))->not->toContain('iframe-title');
    });
});

// ---------------------------------------------------------------------------
// Skipping noscript contents is only right while everything outside them is
// still examined. A fix that quietened the rule generally would pass every
// test above.
// ---------------------------------------------------------------------------

describe('markup outside a noscript', function() {
    it('still reports an untitled iframe on the page itself', function() {
        expect(noscriptScanIds('<iframe src="/embed"></iframe>'))->toContain('iframe-title');
    });

    it('still reports one sitting beside a noscript in the same document', function() {
        $html = <<<'HTML'
            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-ABC123"></iframe></noscript>
            <iframe src="/embed"></iframe>
            HTML;

        expect(noscriptScanIds($html))->toContain('iframe-title');
    });

    it('still accepts a titled iframe without complaint', function() {
        expect(noscriptScanIds('<iframe src="/embed" title="A map of the office"></iframe>'))
            ->not->toContain('iframe-title');
    });

    it('still reports an image with no alt on the page itself', function() {
        $html = '<noscript><img src="/pixel.gif"></noscript><img src="/hero.jpg">';

        expect(noscriptScanIds($html))->toContain('img-alt');
    });
});

it('keeps noscript contents out of the potential-issue questions too', function() {
    // A question about an unrendered element cannot be answered by looking at
    // the page.
    $html = '<noscript><img src="/a.png" alt="a"></noscript>';

    $questions = array_map(
        static fn($issue) => $issue->ruleId,
        AccessibilityAudit::getInstance()->potential->scan($html),
    );

    expect($questions)->not->toContain('potential:short-alt');
});
