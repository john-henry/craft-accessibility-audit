<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\helpers\UrlSafety;
use johnhenry\accessibilityaudit\services\AuditService;

// ---------------------------------------------------------------------------
// Which addresses are worth auditing, and under what name.
//
// Two things to keep right. A page that is missing has to be told apart from
// a host that is down, which a blanket catch cannot do. And a redirect's
// content belongs to the address it ended on: filed under the one that was
// asked for, /old and /new sit in the listing as two pages with one set of
// findings between them.
// ---------------------------------------------------------------------------

/** The fetch result for a URL, as the scanner sees it. */
function fetchPage(string $url): array
{
    $method = new ReflectionMethod(AuditService::class, '_fetchPage');
    $method->setAccessible(true);

    return $method->invoke(new AuditService(), $url);
}

describe('a page that is not there', function() {
    it('reads the status rather than catching an exception for it', function() {
        // http_errors left on turns a 404 into a throwable indistinguishable
        // from a timeout, which is how both ended up with one message.
        $source = (string) file_get_contents((new ReflectionClass(AuditService::class))->getFileName());
        $start = strpos($source, 'private function _fetchPage(');
        $body = substr($source, (int) $start, 3200);

        expect($body)->toContain("'http_errors' => false")
            ->and($body)->toContain('if ($status < 200 || $status > 299) {');
    });

    it('names the status instead of blaming the network', function() {
        // Against the site running this suite, so the test does not depend on
        // somebody else's server being up.
        $base = rtrim((string) Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
        $result = fetchPage($base . '/a-page-that-is-not-here-' . bin2hex(random_bytes(6)));

        expect($result['html'])->toBeNull()
            ->and($result['error'])->toBeString()
            ->and($result['error'])->not->toBe('');

        // Either it reached the site and got a status, or it could not reach
        // it at all. Both are reasons; neither is the other's message.
        expect($result['error'])->toMatch('/\d{3}|could not be reached/i');
    });

    it('keeps a missing page out of the stored scans', function() {
        // Nothing is stored, so a 404 never reaches the listing, never counts
        // against the edition's page limit, and its error page's markup is
        // never reported against an address that has no page.
        $source = (string) file_get_contents((new ReflectionClass(AuditService::class))->getFileName());

        expect($source)->toContain("return ['scanId' => 0, 'score' => 0, 'url' => \$url, 'error' => \$page['error']];");
    });
});

describe('a page that redirects', function() {
    it('reports the address it landed on', function() {
        $final = null;
        $method = new ReflectionMethod(UrlSafety::class, 'fetch');

        expect($method->getNumberOfParameters())->toBe(3)
            ->and($method->getParameters()[2]->getName())->toBe('finalUrl')
            ->and($method->getParameters()[2]->isPassedByReference())->toBeTrue();
    });

    it('files the scan under where the content lives, not where it was asked for', function() {
        // Otherwise /old and /new are two rows carrying identical findings,
        // and answering a question on one leaves its twin asking.
        $source = (string) file_get_contents((new ReflectionClass(AuditService::class))->getFileName());

        expect($source)->toContain("\$url = \$page['url'];")
            ->and($source)->toContain('UrlSafety::fetch($url, $clientConfig, $landed)');
    });

    it('still refuses a hop that leaves the public internet', function() {
        // The redirect following is the same loop it always was; only the
        // address it reports back is new.
        expect(fn() => UrlSafety::assertSafeUrl('http://169.254.169.254/latest/meta-data/'))
            ->toThrow(\johnhenry\accessibilityaudit\exceptions\UnsafeUrlException::class);
    });
});

describe('a page nobody could read', function() {
    it('does not score it as clean', function() {
        // It answered 100 for an element whose page could not be fetched,
        // which reads as a verdict rather than as an absence of one, and the
        // console printed it in green.
        $source = (string) file_get_contents((new ReflectionClass(AuditService::class))->getFileName());
        $start = strpos($source, 'public function scanElement(');
        $body = substr($source, (int) $start, 1800);

        expect($body)->toContain("return ['scanId' => 0, 'score' => 0, 'issues' => [], 'error' => \$page['error']];")
            ->and($body)->not->toContain("return ['scanId' => 0, 'score' => 100, 'issues' => []];\n\n        \$result");
    });

    it('says so on the console instead of printing a score', function() {
        $console = (string) file_get_contents(dirname(__DIR__, 2) . '/src/console/controllers/AuditController.php');

        expect($console)->toContain("\$this->stdout('skipped: ' . \$result['error'] . PHP_EOL, BaseConsole::FG_YELLOW);");
    });
});

// ---------------------------------------------------------------------------
// Redirects that leave the site.
//
// Filing under the landed address is right until the address stops being ours.
// A page redirecting to somebody else's domain would otherwise put a foreign
// URL in the listing, count it against the edition's page limit, and report
// that site's markup as if it were this one's.
// ---------------------------------------------------------------------------

/** Whether a redirect from one address to another is treated as staying put. */
function staysOnSite(string $from, string $to): bool
{
    $method = new ReflectionMethod(AuditService::class, '_isSameSite');
    $method->setAccessible(true);

    return (bool) $method->invoke(new AuditService(), $from, $to);
}

describe('a redirect that leaves the site', function() {
    it('allows the ordinary ones', function() {
        // http to https, a trailing slash, a path move. Host is what counts,
        // so none of these read as leaving.
        expect(staysOnSite('http://example.test/a', 'https://example.test/a'))->toBeTrue()
            ->and(staysOnSite('https://example.test/a', 'https://example.test/a/'))->toBeTrue()
            ->and(staysOnSite('https://example.test/old', 'https://example.test/new'))->toBeTrue()
            ->and(staysOnSite('https://EXAMPLE.test/a', 'https://example.TEST/b'))->toBeTrue();
    });

    it('allows a hop to another site this install serves', function() {
        // An install whose sites sit on separate domains still owns both ends.
        $base = (string) Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
        $host = (string) parse_url($base, PHP_URL_HOST);

        expect($host)->not->toBe('');
        expect(staysOnSite('https://somewhere-else.test/a', 'https://' . $host . '/b'))->toBeTrue();
    });

    it('allows a hop down to a subdomain', function() {
        expect(staysOnSite('https://example.test/a', 'https://sub.example.test/a'))->toBeTrue()
            ->and(staysOnSite('https://example.test/a', 'https://a.b.example.test/x'))->toBeTrue()
            ->and(staysOnSite('https://example.co.uk/a', 'https://shop.example.co.uk/a'))->toBeTrue();
    });

    it('allows dropping a leading www, and nothing else upwards', function() {
        // The common pair, and the only hop upwards worth allowing. Going up
        // generally would let a site on foo.github.io claim github.io.
        expect(staysOnSite('https://www.example.test/a', 'https://example.test/a'))->toBeTrue()
            ->and(staysOnSite('https://foo.github.io/a', 'https://github.io/a'))->toBeFalse()
            ->and(staysOnSite('https://a.b.example.test/x', 'https://example.test/x'))->toBeFalse();
    });

    it('refuses a hop to a domain nothing here serves', function() {
        expect(staysOnSite('https://example.test/a', 'https://not-our-site.test/a'))->toBeFalse()
            // The one a suffix list is needed to get right, and the reason
            // nothing here derives a registrable domain from the string.
            ->and(staysOnSite('https://example.co.uk/a', 'https://somebody-else.co.uk/a'))->toBeFalse()
            ->and(staysOnSite('https://example.test/a', 'https://example.test.evil.test/a'))->toBeFalse()
            ->and(staysOnSite('https://example.test/a', 'https://notexample.test/a'))->toBeFalse();
    });

    it('does not invent a reason to refuse when there is no host to read', function() {
        expect(staysOnSite('https://example.test/a', '/relative'))->toBeTrue();
    });

    it('skips the scan rather than filing a foreign address', function() {
        $source = (string) file_get_contents((new ReflectionClass(AuditService::class))->getFileName());
        $start = strpos($source, 'private function _fetchPage(');
        $body = substr($source, (int) $start, 3200);

        expect($body)->toContain('if (!$this->_isSameSite($url, $landed)) {')
            // Reported under the address that was asked for: the off-site one
            // is not this site's to list.
            ->and($body)->toContain("'url' => \$url,");
    });
});
