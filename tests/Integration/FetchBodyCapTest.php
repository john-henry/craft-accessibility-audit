<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use johnhenry\accessibilityaudit\helpers\UrlSafety;

// ---------------------------------------------------------------------------
// How much of a response the guarded fetch will read.
//
// The body is parsed in memory and its size is decided by whatever is at the
// other end. Sweeps run unattended over pages that may be broken, generated,
// or hostile, so a page that never stops is a worker killed on memory rather
// than a page reported as unreadable.
//
// The site's own host is used because it is exempt from the private-address
// check, so these never leave the machine; the mock handler answers instead.
// ---------------------------------------------------------------------------

/** A fetch against the site's own host, answered by the given responses. */
function cappedFetch(Response ...$responses): string
{
    $url = (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

    $response = UrlSafety::fetch($url, [
        'handler' => HandlerStack::create(new MockHandler($responses)),
    ]);

    return (string)$response->getBody();
}

describe('the guarded fetch body cap', function() {
    it('refuses a body over the cap rather than reading it all in', function() {
        $oversized = str_repeat('a', UrlSafety::MAX_BODY_BYTES + 1024);

        expect(fn() => cappedFetch(new Response(200, [], Utils::streamFor($oversized))))
            ->toThrow(UnsafeUrlException::class);
    });

    it('returns an ordinary page whole, with nothing trimmed off it', function() {
        // Guards the test above: a cap that also truncated real pages would
        // pass it while quietly costing every scan its markup.
        $html = '<html lang="en"><body><h1>Grand</h1>' . str_repeat('<p>Text.</p>', 500) . '</body></html>';

        expect(cappedFetch(new Response(200, [], Utils::streamFor($html))))->toBe($html);
    });

    it('reads the body of the hop it lands on, not the redirect', function() {
        $landed = '<html lang="en"><body><h1>Landed</h1></body></html>';

        $body = cappedFetch(
            new Response(302, ['Location' => '/elsewhere'], Utils::streamFor('redirect body')),
            new Response(200, [], Utils::streamFor($landed)),
        );

        expect($body)->toBe($landed);
    });
});

describe('the guarded fetch transport', function() {
    it('keeps the request on cURL, so the validated addresses are still pinned', function() {
        // The cap must not be bought with the `stream` request option: Guzzle
        // routes a streaming request to its stream handler, which ignores cURL
        // options, and the CURLOPT_RESOLVE pinning is what closes the DNS
        // rebinding gap. The two look unrelated, so this pins them together.
        $seen = [];

        UrlSafety::fetch((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), [
            'handler' => static function(RequestInterface $request, array $options) use (&$seen) {
                $seen = $options;

                return Create::promiseFor(new Response(200, [], Utils::streamFor('<html lang="en"></html>')));
            },
        ]);

        expect($seen['stream'] ?? false)->toBeFalsy();

        if (extension_loaded('curl') && UrlSafety::publicAddressesFor((string)parse_url((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST)) !== []) {
            expect($seen['curl'][CURLOPT_RESOLVE] ?? null)->toBeArray();
        }
    });
});
