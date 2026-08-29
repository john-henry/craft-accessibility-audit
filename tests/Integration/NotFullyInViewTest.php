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
// Not measured, so not asked.
//
// axe samples an element's background at points on its rects. Where a rect
// runs past the edge of the viewport, or sits below the fold on a pass that
// never scrolls, there is nothing under the part that ran off, and it comes
// back as an overlap: "another element covers part of it". Nothing covers it.
//
// Those are dropped rather than reported. "The scanner could not see this" is
// not something a person can settle by looking at the page, and which
// elements it lands on shifts with layout timing, so the same page yields a
// different one on every run: answering one never ends the queue, a new
// question just takes its place. That churn is what teaches people to stop
// reading the queue at all.
//
// Violations are untouched. Nothing measured and failing is ever hidden.
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

it('only ever drops a question, never a failure', function() {
    // The filter runs on incomplete results alone. A violation is a measured
    // result and must reach the report as axe stated it.
    $source = (string) file_get_contents((new ReflectionClass(HeadlessScanner::class))->getFileName());

    expect($source)->toContain('violations: r.violations.map(slim),')
        ->and($source)->toContain('}).map(slim).map(measured).filter(function(v) {');
});

it('drops the whole result when nothing measurable is left in it', function() {
    // Otherwise an empty rule row reaches the report and shows as a finding
    // with no occurrences under it.
    $source = (string) file_get_contents((new ReflectionClass(HeadlessScanner::class))->getFileName());

    expect($source)->toContain('return v.nodes.length > 0;');
});

it('keeps no sentence for a key it can no longer produce', function() {
    // The relabel is gone, so the wording that went with it would be dead.
    $method = new ReflectionMethod(AuditService::class, '_contrastNeedsReviewMessage');
    $method->setAccessible(true);

    expect($method->invoke(new AuditService(), ['messageKey' => 'notFullyInView']))
        ->toContain('could not be worked out');
});

// ---------------------------------------------------------------------------
// The whole chain, in a browser.
//
// Two paragraphs, same colours, same gradient behind them, so axe returns the
// same undecided verdict for both. One sits where the pass can see it and one
// is two thousand pixels further down. Exactly the shape of the page that
// started this: a subtitle near the top and a licensing line near the bottom,
// with only one of them reported on any given run.
// ---------------------------------------------------------------------------

/** The shipped run script's output for a fixture, in a real browser. */
function runScriptResult(): array
{
    $chromePath = '';

    foreach ([
        trim((string) (AccessibilityAudit::getInstance()->getSettings()->chromePath ?? '')),
        '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
    ] as $candidate) {
        if ($candidate !== '' && file_exists($candidate)) {
            $chromePath = $candidate;
            break;
        }
    }

    if ($chromePath === '') {
        test()->markTestSkipped('No Chrome/Chromium on this machine.');
    }

    $axe = (string) file_get_contents(dirname(__DIR__, 2) . '/src/resources/axe/axe.min.js');

    $script = new ReflectionMethod(HeadlessScanner::class, '_axeRunScript');
    $script->setAccessible(true);
    $runScript = (string) $script->invoke(AccessibilityAudit::getInstance()->headless);

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>t</title>'
        . '<style>body{margin:0;font:16px/1.5 sans-serif}'
        . '.grad{background:linear-gradient(#ffffff,#eeeeee);color:#8a8a8a;padding:8px}'
        . '#far{margin-top:2200px}'
        . '</style></head><body>'
        . '<p class="grad" id="near">Near the top, where the pass can see it</p>'
        . '<p class="grad" id="far">Far down the page, never under a sample point</p>'
        . '</body></html>';

    $browser = (new BrowserFactory($chromePath))->createBrowser([
        'headless' => true, 'noSandbox' => true, 'startupTimeout' => 30,
        'customFlags' => ['--disable-dev-shm-usage'],
    ]);

    try {
        $page = $browser->createPage();
        $page->setViewport(1280, 900)->await(15000);
        $page->navigate('data:text/html;charset=utf-8,' . rawurlencode($html))
            ->waitForNavigation(Page::LOAD, 15000);
        $page->evaluate($axe)->waitForResponse(20000);
        $page->evaluate(HeadlessScanner::IN_VIEW_PROBE_JS)->waitForResponse(15000);

        $json = (string) $page->evaluate($runScript)->getReturnValue(60000);

        return json_decode($json, true) ?: [];
    } finally {
        $browser->close();
    }
}

it('reports the one it could see and drops the one it could not', function() {
    $result = runScriptResult();

    $targets = [];

    foreach ($result['incomplete'] ?? [] as $entry) {
        foreach ($entry['nodes'] ?? [] as $node) {
            $targets[] = implode(' ', (array) ($node['target'] ?? []));
        }
    }

    $joined = implode(' | ', $targets);

    expect($joined)->toContain('#near')
        ->and($joined)->not->toContain('#far');
});
