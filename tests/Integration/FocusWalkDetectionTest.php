<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\HeadlessScanner;

// ---------------------------------------------------------------------------
// The keyboard walk, in a real browser.
//
// The walk returns lists, and an empty list looks exactly like a page with
// nothing wrong. The ways it can go quietly wrong are all in the browser: a
// Tab key Chrome reads as "T", a tab that never matches :focus because it is
// not the active one, a transition that reads back as the unfocused colour.
// So it runs for real, through the shipped PHP method, against fixtures whose
// answers are known. One browser for the file, each fixture walked once.
// Skipped where there is no browser.
// ---------------------------------------------------------------------------

/** The fixture pages, keyed by name. */
function focusWalkFixtures(): array
{
    $head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>fixture</title>';

    return [
        'indicators' => $head . '<style>
              body { margin: 0; padding: 20px; font: 16px/1.5 sans-serif; }
              .bare:focus { outline: none; }
              .shadow:focus { outline: none; box-shadow: 0 0 0 3px #1a73e8; }
              .fv:focus:not(:focus-visible) { outline: none; }
              .fv:focus-visible { outline: 3px solid #000; }
              .js:focus { outline: none; }
              .js.is-focused { background-color: #ffeb3b; }
              .within:focus { outline: none; }
              .wrap:focus-within { box-shadow: 0 0 0 2px #c00; }
              .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
              #cb:focus { outline: none; }
              #cb:focus + label { outline: 2px solid #00f; }
              .txt:focus { outline: none; }
              .tw { outline: 2px solid transparent; outline-offset: 2px; }
              .tw:focus { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2563eb; }
              .tw-bare { outline: 2px solid transparent; }
              .tw-bare:focus { box-shadow: 0 0 #0000, 0 0 #0000; }
              .tw4:focus { outline: none; scale: 1.1; }
              .skip { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
              .skip:focus { outline: none; position: static; width: auto; height: auto; overflow: visible; clip: auto; }
            </style></head><body><a id="skip" class="skip" href="#main">Skip to content</a><main id="main">
              <p><a id="bare" class="bare" href="#a">No indicator</a></p>
              <p><a id="shadow" class="shadow" href="#b">Shadow ring</a></p>
              <p><a id="fv" class="fv" href="#c">Focus visible ring</a></p>
              <p><a id="js" class="js" href="#d">Script class</a></p>
              <p><span class="wrap"><a id="within" class="within" href="#e">Focus within</a></span></p>
              <p><input type="checkbox" id="cb" class="sr-only"><label for="cb">Hidden checkbox</label></p>
              <p><input type="text" id="txt" class="txt" aria-label="Name"></p>
              <p><a id="tw" class="tw" href="#f">Tailwind ring</a></p>
              <p><a id="twbare" class="tw-bare" href="#g">Tailwind with no ring</a></p>
              <p><a id="tw4" class="tw4" href="#h">Tailwind 4 scale</a></p>
            </main>
            <script>
              var js = document.getElementById("js");
              js.addEventListener("focus", function () { js.classList.add("is-focused"); });
              js.addEventListener("blur", function () { js.classList.remove("is-focused"); });
            </script></body></html>',

        'header' => $head . '<style>
              body { margin: 0; font: 16px/1.5 sans-serif; }
              header { position: fixed; top: 0; left: 0; right: 0; height: 100px; background: #fff; z-index: 10; }
              #half { position: absolute; top: 70px; left: 300px; width: 200px; height: 60px; display: block; }
            </style></head><body>
              <header id="site-header"><span>Logo</span></header>
              <main>
                <a id="under" href="#u">Under the header</a>
                <a id="half" href="#h">Half under</a>
                <div style="height: 2000px"></div>
              </main></body></html>',

        'bottom-bar' => $head . '<style>
              body { margin: 0; font: 16px/1.5 sans-serif; }
              .bar { position: fixed; bottom: 0; left: 0; right: 0; height: 60vh; background: #333; z-index: 10; }
            </style></head><body>
              <main><div style="height: 1500px"></div><a id="deep" href="#d">Far down</a><div style="height: 1500px"></div></main>
              <div class="bar" id="promo-bar"><span>Offer</span></div></body></html>',

        'bottom-bar-padded' => $head . '<style>
              html { scroll-padding-bottom: 60vh; }
              body { margin: 0; font: 16px/1.5 sans-serif; }
              .bar { position: fixed; bottom: 0; left: 0; right: 0; height: 60vh; background: #333; z-index: 10; }
            </style></head><body>
              <main><div style="height: 1500px"></div><a id="deep" href="#d">Far down</a><div style="height: 1500px"></div></main>
              <div class="bar" id="promo-bar"><span>Offer</span></div></body></html>',

        'consent' => $head . '<style>
              body { margin: 0; font: 16px/1.5 sans-serif; }
              #CybotCookiebotDialog { position: fixed; bottom: 0; left: 0; right: 0; height: 300px; background: #eee; z-index: 10; }
              #accept:focus { outline: none; }
              #low { position: absolute; top: 700px; left: 20px; }
            </style></head><body>
              <main><a id="low" href="#l">Behind the banner</a></main>
              <div id="CybotCookiebotDialog"><button id="accept">Accept all</button></div></body></html>',

        'many' => $head . '<style>body { margin: 0; }</style></head><body><main>'
            . implode('', array_map(static fn(int $i): string => "<a href=\"#l{$i}\">Link {$i}</a> ", range(1, 160)))
            . '</main></body></html>',

        'dialog' => $head . '<style>
              body { margin: 0; padding: 20px; }
              .bare:focus { outline: none; }
            </style></head><body><main>
              <p><a id="first" class="bare" href="#1">Before</a></p>
              <p><a id="opener" href="#2">Opens a dialog on focus</a></p>
              <p><a id="after" class="bare" href="#3">After</a></p>
            </main>
            <dialog id="dlg"><button>Close</button></dialog>
            <script>
              document.getElementById("opener").addEventListener("focus", function () {
                document.getElementById("dlg").showModal();
              });
            </script></body></html>',
    ];
}

/**
 * Every fixture walked through the shipped method, plus one page that never
 * had the Tab key pressed. Memoized: Chrome launches once per file.
 *
 * @return array<string, array<string, mixed>|null>
 */
function focusWalkResults(): array
{
    static $results = null;

    if ($results !== null) {
        return $results;
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

    $scanner = AccessibilityAudit::getInstance()->getHeadless();
    $walk = new ReflectionMethod($scanner, '_runFocusWalk');
    $walk->setAccessible(true);

    $open = static function(string $html) use ($browser): Page {
        $page = $browser->createPage();
        $page->setViewport(1280, 900)->await(15000);
        $page->navigate('data:text/html;charset=utf-8,' . rawurlencode($html))
            ->waitForNavigation(Page::LOAD, 15000);

        return $page;
    };

    try {
        $results = [];

        foreach (focusWalkFixtures() as $name => $html) {
            $page = $open($html);
            $results[$name] = $walk->invoke($scanner, $page, "fixture:{$name}");
            $page->close();
        }

        // The walk injected and run without the Tab key: no keyboard focus.
        $page = $open(focusWalkFixtures()['indicators']);
        $page->evaluate(
            file_get_contents(dirname(__DIR__, 2) . '/src/resources/js/accessibility-audit-shared.js') . "\n"
            . file_get_contents(dirname(__DIR__, 2) . '/src/resources/js/focus-walk.js')
            . "\nwindow.__aaFocusWalk.prepare({ exclude: [] });",
        )->waitForResponse(15000);
        $results['no-tab'] = $page->callFunction(
            'function () { return window.__aaFocusWalk.run({ max: 150, budgetMs: 15000 }); }',
        )->getReturnValue(30000);
        $page->close();
    } finally {
        $browser->close();
    }

    return $results;
}

/** The ids of the elements a walk reported as showing no indicator. */
function focusWalkNotVisibleIds(string $fixture): array
{
    $ids = [];

    foreach (focusWalkResults()[$fixture]['notVisible'] ?? [] as $row) {
        if (preg_match('/\bid="([^"]+)"/', (string) $row['html'], $m)) {
            $ids[] = $m[1];
        }
    }

    return $ids;
}

describe('2.4.7: an indicator that never appears', function() {
    it('runs, with real keyboard focus', function() {
        $result = focusWalkResults()['indicators'];

        expect($result['ran'])->toBeTrue()
            ->and($result['focusVisible'])->toBeTrue()
            ->and($result['stopped'])->toBeNull();
    });

    it('flags a control whose outline is removed with nothing in its place', function() {
        expect(focusWalkNotVisibleIds('indicators'))->toContain('bare');
    });

    it('stores the opening tag alone', function() {
        $row = array_values(array_filter(
            focusWalkResults()['indicators']['notVisible'],
            static fn(array $r): bool => str_contains((string) $r['html'], 'id="bare"'),
        ))[0];

        expect($row['html'])->toBe('<a id="bare" class="bare" href="#a">');
    });

    it('accepts a box-shadow in place of the outline', function() {
        expect(focusWalkNotVisibleIds('indicators'))->not->toContain('shadow');
    });

    it('sees :focus-visible styles, so the walk is treated as keyboard focus', function() {
        // The common modern pattern hides the ring for mouse focus only. If
        // the walk's focus were not keyboard focus, every control styled this
        // way would be reported.
        expect(focusWalkNotVisibleIds('indicators'))->not->toContain('fv');
    });

    it('sees a class added by script when focus arrives', function() {
        expect(focusWalkNotVisibleIds('indicators'))->not->toContain('js');
    });

    it('sees a ring drawn on a wrapper with :focus-within', function() {
        expect(focusWalkNotVisibleIds('indicators'))->not->toContain('within');
    });

    it('sees the label of a visually hidden checkbox light up', function() {
        expect(focusWalkNotVisibleIds('indicators'))->not->toContain('cb');
    });

    it('leaves a text field alone, since the caret shows where focus is', function() {
        expect(focusWalkNotVisibleIds('indicators'))->not->toContain('txt');
    });

    it('accepts a Tailwind ring over a transparent outline', function() {
        expect(focusWalkNotVisibleIds('indicators'))->not->toContain('tw');
    });

    it('accepts a control that grows with the standalone scale property', function() {
        // Tailwind 4's focus:scale-* sets `scale`, not `transform`.
        expect(focusWalkNotVisibleIds('indicators'))->not->toContain('tw4');
    });

    it('accepts a skip link revealed on focus', function() {
        // The outline goes, but the link moves from a clipped pixel into view.
        expect(focusWalkNotVisibleIds('indicators'))->not->toContain('skip');
    });

    it('does not mistake one invisible shadow for another', function() {
        // Frameworks swap zero-sized transparent shadows around at rest. That
        // is no indicator, and the outline is transparent too.
        expect(focusWalkNotVisibleIds('indicators'))->toContain('twbare');
    });

    it('does not fall back on the browser default ring for plain links', function() {
        // The default ring is an indicator; nothing unstyled is reported.
        expect(focusWalkResults()['many']['notVisible'])->toBe([]);
    });
});

describe('2.4.11: an element hidden under fixed content', function() {
    it('names the fixed header that covers a control completely', function() {
        $obscured = focusWalkResults()['header']['obscured'];

        expect($obscured)->toHaveCount(1)
            ->and($obscured[0]['html'])->toBe('<header id="site-header">')
            ->and($obscured[0]['position'])->toBe('fixed')
            ->and($obscured[0]['count'])->toBe(1)
            ->and($obscured[0]['examples'])->toBe(['#under']);
    });

    it('does not report a control that is only partly covered', function() {
        // Minimum is about being entirely hidden. Half a control showing is
        // enough to see where focus is.
        expect(focusWalkResults()['header']['obscured'][0]['examples'] ?? [])->not->toContain('#half');
    });

    it('reports a bottom bar tall enough to cover where the browser scrolls focus to', function() {
        $obscured = focusWalkResults()['bottom-bar']['obscured'];

        expect($obscured)->toHaveCount(1)
            ->and($obscured[0]['html'])->toBe('<div class="bar" id="promo-bar">');
    });

    it('lets scroll-padding keep focus clear of the bar', function() {
        expect(focusWalkResults()['bottom-bar-padded']['obscured'])->toBe([]);
    });

    it('neither walks nor blames an excluded consent banner', function() {
        $result = focusWalkResults()['consent'];

        expect($result['obscured'])->toBe([])
            ->and(focusWalkNotVisibleIds('consent'))->not->toContain('accept')
            ->and($result['total'])->toBe(1);
    });
});

describe('where the walk stops', function() {
    it('checks the first 150 and says how many there were', function() {
        $result = focusWalkResults()['many'];

        expect($result['total'])->toBe(160)
            ->and($result['limit'])->toBe(HeadlessScanner::FOCUS_WALK_MAX_ELEMENTS)
            ->and($result['checked'])->toBe(150);
    });

    it('stops when focus opens a dialog, keeping what it found before', function() {
        $result = focusWalkResults()['dialog'];

        expect($result['stopped'])->toBe('dialog')
            ->and(focusWalkNotVisibleIds('dialog'))->toContain('first')
            ->and(focusWalkNotVisibleIds('dialog'))->not->toContain('after');
    });

    it('will not run on a page keyboard focus never reached', function() {
        expect(focusWalkResults()['no-tab']['ran'])->toBeFalse();
    });
});
