<?php

use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use johnhenry\accessibilityaudit\helpers\UrlSafety;

// ---------------------------------------------------------------------------
// Own-site exemption
// ---------------------------------------------------------------------------
// The SSRF guard rejects any host resolving to a private address, which also
// blocked the plugin from reading the site it is installed on: local and
// intranet installs are private by nature. A host Craft is configured to serve
// is exempt. These pin the exemption to exactly that, since a loose match here
// would reopen the hole the guard exists to close.

describe('UrlSafety own-site exemption', function() {
    it('allows the install\'s own site even on a private address', function() {
        $baseUrl = (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
        $host = parse_url($baseUrl, PHP_URL_HOST);

        // Only meaningful if this install is in fact on a private address,
        // which is the situation the exemption exists for.
        $ips = gethostbynamel((string)$host) ?: [];
        $isPrivate = $ips !== [] && UrlSafety::isPrivateIp($ips[0]);

        expect(fn() => UrlSafety::assertSafeUrl($baseUrl))->not->toThrow(UnsafeUrlException::class)
            ->and($isPrivate || $ips === [])->toBeTrue();
    });

    it('still blocks the cloud metadata endpoint', function() {
        expect(fn() => UrlSafety::assertSafeUrl('http://169.254.169.254/latest/meta-data/'))
            ->toThrow(UnsafeUrlException::class);
    });

    it('still blocks a private address that is not this site', function() {
        expect(fn() => UrlSafety::assertSafeUrl('http://192.168.1.1/admin'))
            ->toThrow(UnsafeUrlException::class);
    });

    it('still blocks loopback by IP literal', function() {
        expect(fn() => UrlSafety::assertSafeUrl('http://127.0.0.1/'))
            ->toThrow(UnsafeUrlException::class);
    });

    it('does not exempt a host that merely ends with the site host', function() {
        // A suffix match would let an attacker register `evil-<sitehost>` and
        // point it anywhere internal, so the exemption is an exact match only.
        $host = (string)parse_url(
            (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(),
            PHP_URL_HOST
        );

        expect(fn() => UrlSafety::assertSafeUrl('http://evil-' . $host . '/'))
            ->toThrow(UnsafeUrlException::class);
    });

    it('still rejects a non-http scheme on the site\'s own host', function() {
        $host = (string)parse_url(
            (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(),
            PHP_URL_HOST
        );

        expect(fn() => UrlSafety::assertSafeUrl('file://' . $host . '/etc/passwd'))
            ->toThrow(UnsafeUrlException::class);
    });
});
