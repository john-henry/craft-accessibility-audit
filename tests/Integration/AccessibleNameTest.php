<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\helpers\AccessibleName;

// ---------------------------------------------------------------------------
// The name a screen reader announces.
//
// Four rules judge links and controls on this, so a mistake here is a mistake
// in all four at once, and it shows up as a report that flags correct markup.
// The order is the one the accessible name spec sets: aria-labelledby, then
// aria-label, then the element's own subtree, then title.
// ---------------------------------------------------------------------------

/** Names the first element matching $selector in a fragment. */
function nameOf(string $html, string $tag = 'a'): string
{
    $dom = new DOMDocument('1.0', 'utf-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $el = $xpath->query('//' . $tag)->item(0);

    expect($el)->toBeInstanceOf(DOMElement::class);

    return AccessibleName::for($el, $xpath);
}

describe('AccessibleName precedence', function() {
    it('prefers aria-labelledby over everything else', function() {
        expect(nameOf(
            '<span id="lbl">Download the annual report</span>'
            . '<a href="/r.pdf" aria-labelledby="lbl" aria-label="ignored" title="ignored">Download</a>'
        ))->toBe('Download the annual report');
    });

    it('joins several aria-labelledby references in order', function() {
        expect(nameOf(
            '<span id="a">Download</span><span id="b">annual report</span>'
            . '<a href="/r.pdf" aria-labelledby="a b">x</a>'
        ))->toBe('Download annual report');
    });

    it('falls through to aria-label when the reference resolves to nothing', function() {
        // A dangling reference names nothing, so the next source wins rather
        // than the link ending up with no name at all.
        expect(nameOf('<a href="/r.pdf" aria-labelledby="nope" aria-label="Annual report">x</a>'))
            ->toBe('Annual report');
    });

    it('prefers aria-label over the visible text', function() {
        expect(nameOf('<a href="/r.pdf" aria-label="Annual report (PDF)">Download</a>'))
            ->toBe('Annual report (PDF)');
    });

    it('falls back to the title when there is nothing else', function() {
        expect(nameOf('<a href="/r.pdf" title="Annual report"><span></span></a>'))
            ->toBe('Annual report');
    });

    it('returns an empty name when there is genuinely none', function() {
        expect(nameOf('<a href="/r.pdf"></a>'))->toBe('');
    });
});

describe('AccessibleName subtree reading', function() {
    it('counts an image alt as part of the name', function() {
        expect(nameOf('<a href="/"><img src="/logo.svg" alt="John Henry"></a>'))
            ->toBe('John Henry');
    });

    it('counts an SVG title as part of the name', function() {
        expect(nameOf('<a href="/"><svg><title>Search</title><path d="M0 0"/></svg></a>'))
            ->toBe('Search');
    });

    it('counts visually hidden text, which is announced like any other', function() {
        expect(nameOf('<a href="/news/1">Read more<span class="sr-only"> about the harvest</span></a>'))
            ->toBe('Read more about the harvest');
    });

    it('ignores an aria-hidden branch, which is announced to nobody', function() {
        expect(nameOf('<a href="/news/1">Read more<span aria-hidden="true"> →</span></a>'))
            ->toBe('Read more');
    });

    it('collapses whitespace so markup indentation does not reach the name', function() {
        expect(nameOf("<a href=\"/\">\n    Read     more\n</a>"))->toBe('Read more');
    });

    it('reads a name out of a nested subtree', function() {
        expect(nameOf('<a href="/"><span><strong>Read</strong> <em>more</em></span></a>'))
            ->toBe('Read more');
    });
});

describe('AccessibleName safety', function() {
    it('ignores an aria-labelledby id containing a quote', function() {
        // The id is interpolated into an XPath literal, so a double quote would
        // break out of it. Such an id is skipped rather than built into a query.
        expect(nameOf('<a href="/" aria-labelledby=\'x" or "1\' aria-label="Safe">x</a>'))
            ->toBe('Safe');
    });
});
