<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// Reading hover, focus and selection colours out of the stylesheet.
//
// This is the half of the state-contrast feature that lives in JavaScript, and
// the half that can fail silently: it returns an array, and an empty one looks
// exactly like a clean page. Reading the CSSOM has traps that produce exactly
// that, one of them being that a CSSStyleRule carries its own cssRules list for
// nested CSS, so a walk treating "has cssRules" as "is a grouping rule" skips
// every declaration on the page. Nothing catches that but running it.
//
// So it runs for real, in the Chromium the plugin already depends on, against
// a fixture whose numbers are known. Skipped where there is no browser.
// ---------------------------------------------------------------------------

/** The fixture page, with the shared collector inlined. */
function stateFixtureHtml(): string
{
    $js = (string) file_get_contents(
        dirname(__DIR__, 2) . '/src/resources/js/accessibility-audit-shared.js',
    );

    return <<<HTML
        <!DOCTYPE html>
        <html lang="en"><head><meta charset="utf-8"><title>fixture</title>
        <style>
          body { background: #ffffff; color: #111111; font-size: 16px; }

          /* Inside a layer, the way Tailwind 4 emits everything. */
          @layer components {
            .btn { color: #111111; background-color: #ffffff; }
            .btn:hover { color: #7c7c7c; }                 /* 4.17:1, fails */

            .ok { color: #111111; }
            .ok:hover { color: #222222; }                  /* passes, must stay quiet */

            .fbad { color: #111111; background-color: #ffffff; }
            .fbad:focus { color: #949494; }                /* 2.85:1, fails */

            .varcase { color: #111111; }
            .varcase:hover { color: var(--nope); }         /* unresolvable, skip */

            .after:hover::after { color: #eeeeee; }        /* generated content, skip */
          }

          ::selection { color: #ffffff; background-color: #ffe08a; }  /* 1.29:1, fails */

          @media print { .printonly:hover { color: #fdfdfd; } }
          .printonly { color: #111111; }
        </style></head>
        <body>
          <a href="#" class="btn">Hover me</a>
          <a href="#" class="ok">Safe hover</a>
          <a href="#" class="fbad">Focus me</a>
          <a href="#" class="varcase">Var case</a>
          <a href="#" class="after">After case</a>
          <p class="printonly">Print only</p>
          <script>{$js}</script>
        </body></html>
        HTML;
}

/**
 * Runs the state collector over the fixture in a real browser.
 *
 * @return array<int, array<string, mixed>>
 */
function stateFindings(): array
{
    // The browser is found here rather than through the plugin, on purpose.
    // HeadlessScanner::isAvailable() also asks about the Pro edition, and both
    // the edition and the settings are singleton state that other tests change
    // and a transaction rollback does not put back. Asking the plugin made
    // this skip silently in a full run while passing on its own, which is the
    // worst way for a regression test to behave. Reading a stylesheet has
    // nothing to do with the edition or the settings: it needs a browser.
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

        // A data URL, so the fixture needs no web server and no route.
        $page->navigate('data:text/html;charset=utf-8,' . rawurlencode(stateFixtureHtml()))
            ->waitForNavigation(Page::LOAD, 15000);

        $json = (string) $page->evaluate(
            'JSON.stringify(AccessibilityAuditShared.collectStateContrastFailures(document, {}))',
        )->getReturnValue(15000);

        return json_decode($json, true) ?: [];
    } finally {
        $browser->close();
    }
}

describe('state contrast detection', function() {
    it('finds the hover, focus and selection failures and nothing else', function() {
        $findings = stateFindings();
        $states = array_column($findings, 'state');

        sort($states);

        expect($states)->toBe(['focus', 'hover', 'selection']);
    });

    it('measures the hover colour against the background it sits on', function() {
        $hover = array_values(array_filter(stateFindings(), fn($f) => $f['state'] === 'hover'));

        expect($hover)->toHaveCount(1)
            ->and($hover[0]['fg'])->toBe('#7C7C7C')
            ->and($hover[0]['bg'])->toBe('#FFFFFF')
            ->and($hover[0]['ratio'])->toBeLessThan(4.5)
            ->and($hover[0]['selector'])->toContain('btn');
    });

    it('reads a selection pair declared for the whole page', function() {
        $selection = array_values(array_filter(stateFindings(), fn($f) => $f['state'] === 'selection'));

        expect($selection)->toHaveCount(1)
            ->and($selection[0]['fg'])->toBe('#FFFFFF')
            ->and($selection[0]['bg'])->toBe('#FFE08A')
            ->and($selection[0]['ratio'])->toBeLessThan(2.0);
    });

    it('reads rules nested inside a cascade layer', function() {
        // Tailwind 4 puts the whole framework inside @layer, so a pass that
        // only reads top-level rules finds nothing on a modern site. The hover
        // and focus findings are both inside one.
        $states = array_column(stateFindings(), 'state');

        expect($states)->toContain('hover')->and($states)->toContain('focus');
    });

    it('stays quiet about everything it cannot be sure of', function() {
        // A passing hover, a var() it cannot evaluate, a generated-content
        // pseudo-element, and a media query that does not apply on screen.
        // Any of these turning up is a false positive on correct code.
        $selectors = array_column(stateFindings(), 'selector');
        $joined = implode(' ', $selectors);

        expect($joined)->not->toContain('ok')
            ->and($joined)->not->toContain('varcase')
            ->and($joined)->not->toContain('after')
            ->and($joined)->not->toContain('printonly');
    });
});
