<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use johnhenry\accessibilityaudit\helpers\UrlSafety;

// ---------------------------------------------------------------------------
// Fetching only what was checked.
//
// Validating a host and then handing the URL to an HTTP client leaves a gap:
// the client resolves the name again when it connects, so a name under
// someone else's control can answer with a public address for the check and a
// private one for the connection. Re-checking the name does not close that,
// because the check and the connection are two separate lookups. The addresses
// the guard validated have to be the addresses curl connects to.
// ---------------------------------------------------------------------------

describe('UrlSafety::fetch', function() {
    it('refuses a private address outright', function() {
        expect(fn() => UrlSafety::fetch('http://127.0.0.1/'))
            ->toThrow(UnsafeUrlException::class);
    });

    it('refuses the cloud metadata endpoint', function() {
        expect(fn() => UrlSafety::fetch('http://169.254.169.254/latest/meta-data/'))
            ->toThrow(UnsafeUrlException::class);
    });

    it('refuses a scheme that is not http or https', function() {
        expect(fn() => UrlSafety::fetch('file:///etc/passwd'))
            ->toThrow(UnsafeUrlException::class);
    });

    it('fetches this install through the guard', function() {
        // The site's own host is exempt from the private-range check (a local
        // install legitimately resolves to one), which makes it the one URL a
        // test can actually fetch. Proves the loop returns a response rather
        // than throwing on a perfectly good URL.
        $base = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');

        if ($base === '') {
            $this->markTestSkipped('The primary site has no base URL.');
        }

        $response = UrlSafety::fetch($base . '/', ['timeout' => 15, 'verify' => false]);

        expect($response->getStatusCode())->toBeLessThan(400);
    });
});

describe('UrlSafety::publicAddressesFor', function() {
    it('hands back the addresses it validated', function() {
        $ips = UrlSafety::publicAddressesFor('example.com');

        expect($ips)->not->toBeEmpty();

        foreach ($ips as $ip) {
            expect(filter_var($ip, FILTER_VALIDATE_IP))->not->toBeFalse()
                ->and(UrlSafety::isPrivateIp($ip))->toBeFalse();
        }
    });

    it('returns an IP literal unchanged so it can be pinned too', function() {
        expect(UrlSafety::publicAddressesFor('93.184.215.14'))->toBe(['93.184.215.14']);
    });

    it('refuses a host that resolves privately', function() {
        expect(fn() => UrlSafety::publicAddressesFor('localhost'))
            ->toThrow(UnsafeUrlException::class);
    });
});
