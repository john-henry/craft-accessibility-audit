<?php

// ---------------------------------------------------------------------------
// A var() naming a custom property nobody defined does not fail loudly. With a
// fallback it silently renders the literal, so the declaration drops out of the
// design system and lands on a colour nobody contrast-checked; without one the
// whole declaration is invalid and the property resets.
//
// That is not hypothetical here: `--aa-ok` never existed, so a 12px semibold
// label rendered its #3f8f5b fallback at 3.97:1 on white, under the 4.5:1 that
// text size needs, in this plugin's own control panel.
// ---------------------------------------------------------------------------

/** Every stylesheet the plugin ships. */
function pluginStylesheets(): array
{
    return glob(dirname(__DIR__, 2) . '/src/resources/css/*.css') ?: [];
}

/** Custom properties defined anywhere in the plugin's CSS. */
function definedCustomProperties(): array
{
    $defined = [];

    foreach (pluginStylesheets() as $path) {
        preg_match_all('/^\s*(--[\w-]+)\s*:/m', (string)file_get_contents($path), $m);
        $defined = array_merge($defined, $m[1]);
    }

    return array_unique($defined);
}

it('ships stylesheets to check', function() {
    expect(pluginStylesheets())->not->toBeEmpty();
});

it('never references a plugin custom property that nothing defines', function() {
    // Set on documentElement by vpat.js against the live CP header, which no
    // stylesheet can know the height of.
    $setAtRuntime = ['--aa-cp-header-height'];

    $defined = array_merge(definedCustomProperties(), $setAtRuntime);
    $orphans = [];

    foreach (pluginStylesheets() as $path) {
        $css = (string)file_get_contents($path);
        preg_match_all('/var\(\s*(--(?:aa|accessibility-audit)-[\w-]+)/', $css, $m);

        foreach (array_unique($m[1]) as $name) {
            if (!in_array($name, $defined, true)) {
                $orphans[] = basename($path) . ': ' . $name;
            }
        }
    }

    expect($orphans)->toBe([]);
});

it('keeps every animated rule under a reduced-motion opt-out', function() {
    $missing = [];

    foreach (pluginStylesheets() as $path) {
        $css = (string)file_get_contents($path);

        if (!preg_match('/transition:|animation:/', $css)) {
            continue;
        }

        if (!str_contains($css, 'prefers-reduced-motion')) {
            $missing[] = basename($path);
        }
    }

    expect($missing)->toBe([]);
});

it('styles the control panel against the light theme alone', function() {
    // The control panel has one theme. A prefers-color-scheme block keys off
    // the reader's operating system instead, so the same screen renders in
    // colours the surrounding CP never uses and nothing here contrast-checks.
    //
    // Templates are searched alongside the stylesheets because the rule is
    // about what the CP renders, not about where the declaration is written:
    // the statement preview carried its dark override in an inline <style>,
    // out of reach of a check that only read src/resources/css.
    $templates = glob(dirname(__DIR__, 2) . '/src/templates/{,*/}*.twig', GLOB_BRACE) ?: [];
    $offenders = [];

    foreach (array_merge(pluginStylesheets(), $templates) as $path) {
        if (str_contains((string)file_get_contents($path), 'prefers-color-scheme')) {
            $offenders[] = basename($path);
        }
    }

    expect($templates)->not->toBeEmpty()
        ->and($offenders)->toBe([]);
});
