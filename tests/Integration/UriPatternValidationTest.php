<?php

use johnhenry\accessibilityaudit\models\SettingsModel;

// ---------------------------------------------------------------------------
// Excluded URI patterns are regular expressions an admin types in.
//
// At scan time a pattern that does not compile is swallowed on purpose: one
// bad row must not derail a sweep. The cost is that it silently matches
// nothing, so the pages it was written to keep out carry on being scanned and
// counted against the edition's page limit, with nothing anywhere to say why.
// The save is the one place that can tell somebody.
// ---------------------------------------------------------------------------

/** A settings model carrying the given exclusion patterns. */
function settingsWithPatterns(array $patterns): SettingsModel
{
    $settings = new SettingsModel();
    $settings->excludedUriPatterns = array_map(
        static fn(string $p): array => ['uriPattern' => $p, 'enabled' => true, 'siteId' => ''],
        $patterns,
    );

    return $settings;
}

describe('excluded URI patterns', function() {
    it('refuses a pattern that cannot compile', function(string $pattern) {
        $settings = settingsWithPatterns([$pattern]);

        expect($settings->validate())->toBeFalse()
            ->and($settings->getErrors('excludedUriPatterns'))->not->toBeEmpty();
    })->with([
        'unbalanced group' => ['^checkout('],
        'dangling quantifier' => ['*admin'],
        'unclosed class' => ['^[a-z'],
    ]);

    it('accepts the patterns the settings screen suggests', function(string $pattern) {
        expect(settingsWithPatterns([$pattern])->validate())->toBeTrue();
    })->with([
        'anchored prefix' => ['^checkout'],
        'anchored suffix' => ['print$'],
        'path prefix' => ['^admin/'],
        'containing a tilde' => ['^~user'],
    ]);

    it('leaves the empty pattern alone, which is how the homepage is written', function() {
        expect(settingsWithPatterns([''])->validate())->toBeTrue();
    });
});
