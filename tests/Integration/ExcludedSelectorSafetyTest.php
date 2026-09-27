<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\ExcludedElements;

// ---------------------------------------------------------------------------
// An excluded selector that matches html, body or head takes the whole page out
// of the document with it, and a scan of an empty document reports a clean one.
// Refusing bare tags is not enough: classes sit on <html> on a great many sites
// (no-js, dark), so the refusal has to happen where nodes are removed.
// ---------------------------------------------------------------------------

/**
 * Runs the exclusions over a document and hands back what survived.
 *
 * @param string[] $selectors
 */
function runExclusions(string $html, array $selectors): string
{
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $original = $settings->excludedSelectors;
    $settings->excludedSelectors = implode("\n", $selectors);

    try {
        $dom = new DOMDocument();
        $errors = libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);

        ExcludedElements::removeFrom(new DOMXPath($dom));

        return (string)$dom->saveHTML();
    } finally {
        $settings->excludedSelectors = $original;
    }
}

it('will not let a class selector on the html element blank the document', function () {
    $html = '<html class="no-js"><body><main><p>Real content</p></main></body></html>';

    expect(runExclusions($html, ['.no-js']))->toContain('Real content');
});

it('will not let a tag-qualified selector remove the body', function () {
    $html = '<html><body class="page"><main><p>Real content</p></main></body></html>';

    expect(runExclusions($html, ['body.page']))->toContain('Real content');
});

it('will not let an id selector remove the body', function () {
    $html = '<html><body id="top"><main><p>Real content</p></main></body></html>';

    expect(runExclusions($html, ['body#top']))->toContain('Real content');
});

it('still removes the page furniture it is there to remove', function () {
    $html = '<html><body><div id="cookie-banner">Accept cookies</div>'
        . '<main><p>Real content</p></main></body></html>';

    $result = runExclusions($html, ['#cookie-banner']);

    expect($result)->toContain('Real content');
    expect($result)->not->toContain('Accept cookies');
});

it('still removes a banner by class without touching its ancestors', function () {
    $html = '<html class="no-js"><body><div class="cmp-container">Consent</div>'
        . '<main><p>Real content</p></main></body></html>';

    $result = runExclusions($html, ['.cmp-container']);

    expect($result)->toContain('Real content');
    expect($result)->not->toContain('Consent');
});
