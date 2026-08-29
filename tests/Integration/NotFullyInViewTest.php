<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\HeadlessScanner;

// ---------------------------------------------------------------------------
// Not measured, as against covered up.
//
// axe samples an element's background at points on its rects. Where a rect
// runs past the edge of the viewport there is nothing under the part that ran
// off, and it comes back as an overlap: "another element covers part of it".
// Nothing covers it. A long identifier in a heading, or a block of
// preformatted code, simply ran off the side at the width the page was
// measured at.
//
// The two read identically in the report and call for completely different
// work: one is a layering bug, the other is a limit of the measurement. This
// runs the shipped probe against known geometry in a real browser, because
// rects only exist once something has laid the page out.
// ---------------------------------------------------------------------------

/** Which of the fixture's elements the probe counts as fully measured. */
function inViewVerdicts(): array
{
    $chromePath = '';

    foreach ([
        trim((string) (AccessibilityAudit::getInstance()->getSettings()->chromePath ?? '')),
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome',
    ] as $candidate) {
        if ($candidate !== '' && file_exists($candidate)) {
            $chromePath = $candidate;
            break;
        }
    }

    if ($chromePath === '') {
        test()->markTestSkipped('No Chrome/Chromium on this machine.');
    }

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>t</title>'
        . '<style>body{margin:0;padding:0;font:16px/1.5 sans-serif;width:375px}'
        . '#fits{width:200px}'
        . '#overflows{white-space:nowrap;font-size:32px}'
        . '#below{margin-top:2000px}'
        . '#gone{display:none}'
        . '</style></head><body>'
        . '<p id="fits">Short line</p>'
        . '<h2 id="overflows">HttpChecker::EVENT_DEFINE_VERDICT_AND_THEN_SOME_MORE</h2>'
        . '<p id="below">Far down the page</p>'
        . '<p id="gone">Not laid out at all</p>'
        . '</body></html>';

    $browser = (new BrowserFactory($chromePath))->createBrowser([
        'headless' => true,
        'noSandbox' => true,
        'startupTimeout' => 30,
        'customFlags' => ['--disable-dev-shm-usage'],
    ]);

    try {
        $page = $browser->createPage();
        $page->setViewport(375, 812)->await(15000);
        $page->navigate('data:text/html;charset=utf-8,' . rawurlencode($html))
            ->waitForNavigation(Page::LOAD, 15000);

        // The shipped source, not a copy of it.
        $page->evaluate(HeadlessScanner::IN_VIEW_PROBE_JS)->waitForResponse(15000);

        $script = <<<'JS'
            JSON.stringify({
              fits: window.__aaFullyInView(['#fits']),
              overflows: window.__aaFullyInView(['#overflows']),
              below: window.__aaFullyInView(['#below']),
              gone: window.__aaFullyInView(['#gone']),
              missing: window.__aaFullyInView(['#not-on-this-page']),
              rubbishSelector: window.__aaFullyInView([':::']),
            })
            JS;

        return json_decode((string) $page->evaluate($script)->getReturnValue(15000), true) ?: [];
    } finally {
        $browser->close();
    }
}

it('counts an element that fits as measured', function() {
    expect(inViewVerdicts()['fits'])->toBeTrue();
});

it('counts one that runs off the side as not measured', function() {
    // The real case: a long identifier in a heading at a phone width.
    expect(inViewVerdicts()['overflows'])->toBeFalse();
});

it('counts one below the fold as not measured', function() {
    // The pass never scrolls, so anything down the page was never under a
    // sample point either.
    expect(inViewVerdicts()['below'])->toBeFalse();
});

it('counts one that was never laid out as not measured', function() {
    expect(inViewVerdicts()['gone'])->toBeFalse();
});

it('says yes when it cannot tell', function() {
    // A selector that resolves to nothing, or does not parse, is no evidence
    // of anything. Relabelling on a guess would bury real questions.
    $verdicts = inViewVerdicts();

    expect($verdicts['missing'])->toBeTrue()
        ->and($verdicts['rubbishSelector'])->toBeTrue();
});

it('only ever relabels a question, never a failure', function() {
    // The relabel runs on incomplete results. A violation is a measured
    // result and must reach the report as axe stated it.
    $source = (string) file_get_contents((new ReflectionClass(HeadlessScanner::class))->getFileName());

    expect($source)->toContain('violations: r.violations.map(slim),')
        ->and($source)->toContain('}).map(slim).map(relabel),');
});

it('has a sentence for the relabelled key', function() {
    $method = new ReflectionMethod(AuditService::class, '_contrastNeedsReviewMessage');
    $method->setAccessible(true);

    $message = $method->invoke(new AuditService(), ['messageKey' => 'notFullyInView']);

    expect($message)->toContain('outside the area the page was measured in')
        ->and($message)->not->toContain('covers part of it');
});
