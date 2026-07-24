<?php

use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use johnhenry\accessibilityaudit\helpers\UrlSafety;

// ---------------------------------------------------------------------------
// isPrivateIp: private / reserved ranges must be rejected
// ---------------------------------------------------------------------------

describe('UrlSafety::isPrivateIp rejects private and reserved IPs', function() {
    it('rejects the cloud metadata endpoint', function() {
        expect(UrlSafety::isPrivateIp('169.254.169.254'))->toBeTrue();
    });

    it('rejects loopback', function() {
        expect(UrlSafety::isPrivateIp('127.0.0.1'))->toBeTrue();
    });

    it('rejects the 10.0.0.0/8 range', function() {
        expect(UrlSafety::isPrivateIp('10.1.2.3'))->toBeTrue();
    });

    it('rejects the 172.16.0.0/12 range', function() {
        expect(UrlSafety::isPrivateIp('172.16.5.5'))->toBeTrue();
    });

    it('rejects the 192.168.0.0/16 range', function() {
        expect(UrlSafety::isPrivateIp('192.168.1.1'))->toBeTrue();
    });

    it('rejects link-local 169.254.0.0/16', function() {
        expect(UrlSafety::isPrivateIp('169.254.10.20'))->toBeTrue();
    });

    it('rejects 0.0.0.0', function() {
        expect(UrlSafety::isPrivateIp('0.0.0.0'))->toBeTrue();
    });

    it('rejects IPv6 loopback ::1', function() {
        expect(UrlSafety::isPrivateIp('::1'))->toBeTrue();
    });

    it('rejects IPv6 unique-local fc00::/7', function() {
        expect(UrlSafety::isPrivateIp('fc00::1'))->toBeTrue()
            ->and(UrlSafety::isPrivateIp('fd12:3456::1'))->toBeTrue();
    });

    it('rejects IPv6 link-local fe80::/10', function() {
        expect(UrlSafety::isPrivateIp('fe80::1'))->toBeTrue();
    });

    it('rejects an IPv4-mapped IPv6 metadata address', function() {
        expect(UrlSafety::isPrivateIp('::ffff:169.254.169.254'))->toBeTrue();
    });

    it('rejects a non-IP string', function() {
        expect(UrlSafety::isPrivateIp('not-an-ip'))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// isPrivateIp: genuine public IPs must be accepted
// ---------------------------------------------------------------------------

describe('UrlSafety::isPrivateIp accepts public IPs', function() {
    it('accepts a public IPv4 address', function() {
        expect(UrlSafety::isPrivateIp('93.184.216.34'))->toBeFalse();
    });

    it('accepts a public DNS resolver IPv4', function() {
        expect(UrlSafety::isPrivateIp('8.8.8.8'))->toBeFalse();
    });

    it('accepts a public IPv6 address', function() {
        expect(UrlSafety::isPrivateIp('2606:2800:220:1:248:1893:25c8:1946'))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// assertSafeUrl: scheme restriction
// ---------------------------------------------------------------------------

describe('UrlSafety::assertSafeUrl scheme handling', function() {
    it('rejects a non-http(s) scheme', function() {
        UrlSafety::assertSafeUrl('ftp://example.com/file');
    })->throws(UnsafeUrlException::class);

    it('rejects the file scheme', function() {
        UrlSafety::assertSafeUrl('file:///etc/passwd');
    })->throws(UnsafeUrlException::class);

    it('rejects a malformed URL', function() {
        UrlSafety::assertSafeUrl('not a url at all');
    })->throws(UnsafeUrlException::class);

    it('rejects a URL with no host', function() {
        UrlSafety::assertSafeUrl('http://');
    })->throws(UnsafeUrlException::class);
});

// ---------------------------------------------------------------------------
// assertSafeUrl: IP-literal hosts (no DNS needed)
// ---------------------------------------------------------------------------

describe('UrlSafety::assertSafeUrl IP-literal hosts', function() {
    it('rejects a URL pointing straight at the metadata IP', function() {
        UrlSafety::assertSafeUrl('http://169.254.169.254/latest/meta-data/');
    })->throws(UnsafeUrlException::class);

    it('rejects a URL pointing at loopback', function() {
        UrlSafety::assertSafeUrl('http://127.0.0.1:8080/admin');
    })->throws(UnsafeUrlException::class);

    it('rejects a URL pointing at an RFC1918 address', function() {
        UrlSafety::assertSafeUrl('https://10.0.0.5/internal');
    })->throws(UnsafeUrlException::class);

    it('rejects a bracketed IPv6 loopback literal', function() {
        UrlSafety::assertSafeUrl('http://[::1]/');
    })->throws(UnsafeUrlException::class);

    it('accepts a URL pointing at a public IP literal', function() {
        UrlSafety::assertSafeUrl('https://93.184.216.34/');

        // No exception thrown === safe.
        expect(true)->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// assertHostIsPublic: hostname resolution
// ---------------------------------------------------------------------------

describe('UrlSafety::assertHostIsPublic', function() {
    it('rejects localhost (resolves to loopback)', function() {
        UrlSafety::assertHostIsPublic('localhost');
    })->throws(UnsafeUrlException::class);

    it('rejects an unresolvable host', function() {
        UrlSafety::assertHostIsPublic('this-host-does-not-exist.invalid');
    })->throws(UnsafeUrlException::class);
});
