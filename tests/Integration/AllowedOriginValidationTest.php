<?php

use johnhenry\accessibilityaudit\models\SettingsModel;

// ---------------------------------------------------------------------------
// The overlay allow-list is compared to the Origin header exactly, so anything
// that is not a bare scheme://host matches nothing a browser will ever send.
// Without this the save succeeds and the overlay is then refused on the very
// origin the line was added for, with nothing anywhere to say why. Same
// reasoning as the URI pattern check next to it.
// ---------------------------------------------------------------------------

/** Validates one allow-list value and says whether it was accepted. */
function originsValidate(string $value): bool
{
    $settings = new SettingsModel();
    $settings->overlayAllowedOrigins = $value;

    return $settings->validate(['overlayAllowedOrigins']);
}

it('accepts a plain origin', function() {
    expect(originsValidate('https://preview.example.com'))->toBeTrue();
});

it('accepts an origin carrying a port', function() {
    expect(originsValidate('http://localhost:3000'))->toBeTrue();
});

it('accepts several origins across lines and commas', function() {
    expect(originsValidate("http://localhost:3000\nhttps://preview.example.com,https://staging.example.com"))
        ->toBeTrue();
});

it('accepts a trailing slash, which is only a typo', function() {
    expect(originsValidate('https://preview.example.com/'))->toBeTrue();
});

it('accepts an empty list, since the setting is optional', function() {
    expect(originsValidate(''))->toBeTrue();
});

it('refuses an origin carrying a path', function() {
    expect(originsValidate('https://example.com/app'))->toBeFalse();
});

it('refuses a bare hostname with no scheme', function() {
    expect(originsValidate('example.com'))->toBeFalse();
});

it('refuses a wildcard, which would quietly allow nothing', function() {
    expect(originsValidate('*'))->toBeFalse();
});

it('refuses a wildcard subdomain, which the exact match cannot expand', function() {
    expect(originsValidate('https://*.example.com'))->toBeFalse();
});

it('refuses a scheme the browser will not send as an origin', function() {
    expect(originsValidate('ftp://example.com'))->toBeFalse();
});

it('names the offending line when several are listed', function() {
    $settings = new SettingsModel();
    $settings->overlayAllowedOrigins = "https://good.example.com\nexample.com/bad";

    $settings->validate(['overlayAllowedOrigins']);

    expect(implode(' ', $settings->getErrors('overlayAllowedOrigins')))->toContain('example.com/bad');
});
