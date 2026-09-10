<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\models\SettingsModel;

// ---------------------------------------------------------------------------
// Additional URLs as rows.
//
// The setting was one newline-separated string up to 1.3.0 and is now a row
// per URL. Two things have to hold for a site that upgrades: a string handed
// to the model still reads back as URLs, and a line that was commented out
// stays out of the scan. Neither is visible in the control panel, which only
// ever shows rows, so a regression here would look like URLs quietly
// appearing in or dropping out of a sweep.
//
// Helpers are prefixed cur: Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** A settings model carrying the given Additional URLs value. */
function curSettings(mixed $customUrls): SettingsModel
{
    $settings = new SettingsModel();
    $settings->setAttributes(['customUrls' => $customUrls], false);

    return $settings;
}

/** One enabled row, optionally scoped to a site. */
function curRow(string $url, int|string $siteId = '', bool $enabled = true): array
{
    return ['enabled' => $enabled, 'siteId' => $siteId, 'url' => $url];
}

describe('The old newline-separated setting', function() {
    it('still reads back as URLs', function() {
        $settings = curSettings("/search?q=craft\n/paginated/2");

        expect($settings->resolvedCustomUrls())->toBe(['/search?q=craft', '/paginated/2']);
    });

    it('keeps a commented-out line as a row that is switched off', function() {
        $settings = curSettings("/live\n# /parked\n/also-live");

        expect($settings->customUrls)->toBe([
            ['enabled' => true, 'siteId' => '', 'url' => '/live'],
            ['enabled' => false, 'siteId' => '', 'url' => '/parked'],
            ['enabled' => true, 'siteId' => '', 'url' => '/also-live'],
        ])
            ->and($settings->resolvedCustomUrls())->toBe(['/live', '/also-live']);
    });

    it('ignores blank lines and surrounding space', function() {
        $settings = curSettings("  /a  \n\n\n  /b\n");

        expect($settings->resolvedCustomUrls())->toBe(['/a', '/b']);
    });

    it('takes an empty string as no URLs at all', function() {
        expect(curSettings('')->resolvedCustomUrls())->toBe([]);
    });
});

describe('resolvedCustomUrls', function() {
    it('leaves out a row that is switched off', function() {
        $settings = curSettings([curRow('/a'), curRow('/b', '', false)]);

        expect($settings->resolvedCustomUrls())->toBe(['/a']);
    });

    it('leaves out a row scoped to another site', function() {
        $settings = curSettings([
            curRow('/search'),
            curRow('/recherche', 2),
            curRow('/search-uk', 1),
        ]);

        expect($settings->resolvedCustomUrls(1))->toBe(['/search', '/search-uk'])
            ->and($settings->resolvedCustomUrls(2))->toBe(['/search', '/recherche']);
    });

    it('takes every row when no site is named', function() {
        $settings = curSettings([curRow('/search'), curRow('/recherche', 2)]);

        expect($settings->resolvedCustomUrls())->toBe(['/search', '/recherche']);
    });

    it('reads a site id posted as a string', function() {
        $settings = curSettings([curRow('/recherche', '2')]);

        expect($settings->resolvedCustomUrls(2))->toBe(['/recherche'])
            ->and($settings->resolvedCustomUrls(1))->toBe([]);
    });

    it('de-duplicates and drops empty rows', function() {
        $settings = curSettings([curRow('/a'), curRow('/a'), curRow('  '), curRow('/b')]);

        expect($settings->resolvedCustomUrls())->toBe(['/a', '/b']);
    });
});
