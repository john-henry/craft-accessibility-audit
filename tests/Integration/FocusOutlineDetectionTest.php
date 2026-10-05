<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// Reading removed focus outlines out of the stylesheet.
//
// The Inspect report's answer to 2.4.7 where no server-side browser walks
// focus. It returns a list, and an empty list looks like a clean page, so it
// runs in Chromium against a fixture whose answers are known. Skipped where
// there is no browser.
// ---------------------------------------------------------------------------

function focusOutlineFixtureHtml(): string
{
    $js = (string) file_get_contents(
        dirname(__DIR__, 2) . '/src/resources/js/accessibility-audit-shared.js',
    );

    return <<<HTML
        <!DOCTYPE html>
        <html lang="en"><head><meta charset="utf-8"><title>fixture</title>
        <style>
          .bare:focus { outline: none; }
          .shadow:focus { outline: none; box-shadow: 0 0 0 3px #1a73e8; }
          .fv:focus:not(:focus-visible) { outline: none; }
          .fv:focus-visible { outline: 3px solid #000; }
          .ring:focus { outline: 2px solid transparent; outline-offset: 2px; }
          .ring-2:focus { box-shadow: var(--tw-ring-offset-shadow), var(--tw-ring-shadow); }
          .tw-only:focus { outline: 2px solid transparent; outline-offset: 2px; }
          .within:focus { outline: none; }
          .wrap:focus-within { box-shadow: 0 0 0 2px #c00; }
          #cb:focus { outline: none; }
          #cb:focus + label { outline: 2px solid #00f; }
          .txt:focus { outline: none; }
          button { outline: 0; }
          .many:focus { outline: none; }
          .hover-only:hover { outline: none; }
          .icon-link:focus::after { outline: none; }
          .js-focus-visible .poly:focus:not(.focus-visible) { outline: none; }
          .var-case:focus { outline: var(--focus-outline); }
          @media print { .printonly:focus { outline: none; } }
          @layer components { .layered:focus { outline: none; } }
          .card { & a:focus { outline: none; } }
          .absent-component { & a:focus { outline: none; } }
        </style></head>
        <body class="js-focus-visible"><main>
          <a href="#1" class="bare">Bare</a>
          <a href="#2" class="shadow">Shadow</a>
          <a href="#3" class="fv">Focus visible</a>
          <a href="#4" class="ring ring-2">Ring</a>
          <a href="#5" class="tw-only">Transparent outline, no ring</a>
          <span class="wrap"><a href="#6" class="within">Within</a></span>
          <input type="checkbox" id="cb"><label for="cb">Checkbox</label>
          <input type="text" class="txt" aria-label="Name">
          <button type="button">Button</button>
          <a href="#7" class="many">One</a> <a href="#8" class="many">Two</a> <a href="#9" class="many">Three</a>
          <a href="#10" class="hover-only">Hover</a>
          <a href="#11" class="icon-link">Icon</a>
          <a href="#12" class="poly">Polyfill</a>
          <a href="#13" class="var-case">Var</a>
          <a href="#14" class="printonly">Print</a>
          <a href="#15" class="layered">Layered</a>
          <div class="card"><a href="#16" id="in-card">In a card</a></div>
          <a href="#17" class="plain">Plain</a>
        </main>
        <script>{$js}</script>
        </body></html>
        HTML;
}

/** @return array<int, array{selector: string, html: string, count: int}> */
function focusOutlineFindings(): array
{
    static $findings = null;

    if ($findings !== null) {
        return $findings;
    }

    // Found directly rather than through isAvailable(), which also asks about
    // the edition and settings that other tests change.
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

    $browser = (new BrowserFactory($chromePath))->createBrowser([
        'headless' => true,
        'noSandbox' => true,
        'startupTimeout' => 30,
        'customFlags' => ['--disable-dev-shm-usage'],
    ]);

    try {
        $page = $browser->createPage();
        $page->setViewport(1280, 900)->await(15000);
        $page->navigate('data:text/html;charset=utf-8,' . rawurlencode(focusOutlineFixtureHtml()))
            ->waitForNavigation(Page::LOAD, 15000);

        $json = (string) $page->evaluate(
            'JSON.stringify(AccessibilityAuditShared.collectFocusIndicatorRemovals(document, {}))',
        )->getReturnValue(15000);

        return $findings = (json_decode($json, true) ?: []);
    } finally {
        $browser->close();
    }
}

/** The finding for a selector, or null. */
function focusOutlineFor(string $selector): ?array
{
    foreach (focusOutlineFindings() as $finding) {
        if ($finding['selector'] === $selector) {
            return $finding;
        }
    }

    return null;
}

it('flags an outline removed with nothing in its place', function() {
    expect(focusOutlineFor('.bare:focus'))->toBe([
        'selector' => '.bare:focus',
        'html' => '<a href="#1" class="bare">',
        'count' => 1,
    ]);
});

it('flags a transparent outline with no ring to go with it', function() {
    expect(focusOutlineFor('.tw-only:focus'))->not->toBeNull();
});

it('flags a bare element rule, which beats the browser default', function() {
    expect(focusOutlineFor('button')['html'] ?? null)->toBe('<button type="button">');
});

it('counts every focusable element a rule leaves without an indicator', function() {
    expect(focusOutlineFor('.many:focus')['count'] ?? null)->toBe(3);
});

it('reads rules inside a layer, and resolves nested ones against their parent', function() {
    expect(focusOutlineFor('.layered:focus'))->not->toBeNull()
        ->and(focusOutlineFor('.card a:focus')['html'] ?? null)->toBe('<a href="#16" id="in-card">');
});

it('does not apply a nested rule for a component that is not on the page', function() {
    // Unresolved, "& a:focus" matches every link in the document.
    expect(focusOutlineFor('.absent-component a:focus'))->toBeNull();
});

it('accepts anything drawn in its place', function(string $selector) {
    expect(focusOutlineFor($selector))->toBeNull();
})->with([
    'a box-shadow in the same rule' => ['.shadow:focus'],
    'a Tailwind ring over a transparent outline' => ['.ring:focus'],
    'a ring on a wrapper with :focus-within' => ['.within:focus'],
    'the label of a checkbox lighting up' => ['#cb:focus'],
]);

it('leaves alone what is not the keyboard outline', function(string $selector) {
    expect(focusOutlineFor($selector))->toBeNull();
})->with([
    'mouse-only :focus:not(:focus-visible)' => ['.fv:focus:not(:focus-visible)'],
    'the focus-visible polyfill version of it' => ['.js-focus-visible .poly:focus:not(.focus-visible)'],
    'a text field, where the caret shows focus' => ['.txt:focus'],
    'a hover rule' => ['.hover-only:hover'],
    'generated content' => ['.icon-link:focus::after'],
    'a value it cannot resolve' => ['.var-case:focus'],
    'a media query that does not apply' => ['.printonly:focus'],
]);

it('does not flag an unstyled link', function() {
    foreach (focusOutlineFindings() as $finding) {
        expect($finding['html'])->not->toContain('class="plain"');
    }
});
