<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// Text a reader hears and never sees.
//
// sr-only, visually-hidden, whatever the class is called: put where a screen
// reader will read it and an eye will not. It is announced, so it belongs in
// the accessibility tree, but there is no contrast question because nothing is
// on screen to have contrast with.
//
// Easy to get wrong in the direction that floods the report. Tailwind 4 hides
// with a one-pixel box and clip-path, so the element is "visible", has a size,
// carries no aria-hidden, and inherits a light grey that fails against white.
// A category page with twenty cards then reports forty contrast failures that
// nobody can see or fix.
//
// Runs for real, in the Chromium the plugin already depends on, because
// clip-path and offset sizes only exist once something has laid the page out.
// ---------------------------------------------------------------------------

/** A page carrying every shape of hidden text, and visible text to match. */
function hiddenFixtureHtml(): string
{
    $js = (string) file_get_contents(
        dirname(__DIR__, 2) . '/src/resources/js/accessibility-audit-shared.js',
    );

    return <<<HTML
        <!DOCTYPE html>
        <html lang="en"><head><meta charset="utf-8"><title>fixture</title>
        <style>
          body { background: #ffffff; color: #111111; font-size: 16px; }

          /* Tailwind 4. A pixel of box, clipped away, in a colour that fails. */
          .tw { position: absolute; width: 1px; height: 1px; overflow: hidden;
                clip-path: inset(50%); color: #99a1af; }

          /* The older idiom, and still the commonest. */
          .legacy { position: absolute; width: 1px; height: 1px; overflow: hidden;
                    clip: rect(0, 0, 0, 0); color: #99a1af; }

          /* Full size, clipped to nothing. */
          .clipped { width: 200px; height: 40px; clip-path: inset(100%); color: #99a1af; }

          /* Hidden on a wrapper, with the text on a child. */
          .wrap { position: absolute; width: 1px; height: 1px; overflow: hidden;
                  clip-path: inset(50%); }

          /* Genuinely on screen, and genuinely failing: must survive. */
          .real { color: #99a1af; background-color: #ffffff; }

          /* Small, but not hidden: 8px of box still shows a glyph. */
          .small { width: 8px; height: 8px; overflow: hidden; color: #99a1af; }
        </style></head>
        <body>
          <p class="tw">Announced only, Tailwind</p>
          <p class="legacy">Announced only, legacy clip</p>
          <p class="clipped">Announced only, clipped</p>
          <div class="wrap"><span>Announced only, on a child</span></div>
          <p class="real">Seen and failing</p>
          <p class="small">x</p>
          <script>{$js}</script>
        </body></html>
        HTML;
}

/** Which of the fixture's paragraphs the contrast pass counts as painted. */
function paintedSelectors(): array
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

    $browser = (new BrowserFactory($chromePath))->createBrowser([
        'headless' => true,
        'noSandbox' => true,
        'startupTimeout' => 30,
        'customFlags' => ['--disable-dev-shm-usage'],
    ]);

    try {
        $page = $browser->createPage();
        $page->navigate('data:text/html;charset=utf-8,' . rawurlencode(hiddenFixtureHtml()))
            ->waitForNavigation(Page::LOAD, 15000);

        $script = <<<'JS'
            JSON.stringify(
              ['.tw', '.legacy', '.clipped', '.wrap span', '.real', '.small'].filter(function (sel) {
                return AccessibilityAuditShared.isPaintedText(document.querySelector(sel), window);
              })
            )
            JS;

        return json_decode((string) $page->evaluate($script)->getReturnValue(15000), true) ?: [];
    } finally {
        $browser->close();
    }
}

it('does not ask about text nobody can see', function() {
    $painted = paintedSelectors();

    expect($painted)->not->toContain('.tw')
        ->and($painted)->not->toContain('.legacy')
        ->and($painted)->not->toContain('.clipped');
});

it('sees through a wrapper that carries the hiding', function() {
    // The class usually sits on a wrapper rather than on the element holding
    // the text, so checking the element alone catches none of them.
    expect(paintedSelectors())->not->toContain('.wrap span');
});

it('still reports text that is on screen and failing', function() {
    // The direction that matters. Over-reaching here would silence real
    // findings, which is worse than the flood it is fixing.
    $painted = paintedSelectors();

    expect($painted)->toContain('.real')
        ->and($painted)->toContain('.small');
});
