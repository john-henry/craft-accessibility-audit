<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\helpers\RemoteChromeClient;
use johnhenry\accessibilityaudit\jobs\AuditAssets;
use johnhenry\accessibilityaudit\twig\A11yTemplateNodeVisitor;
use johnhenry\accessibilityaudit\twig\A11yTwigExtension;

// ---------------------------------------------------------------------------
// The pieces around the edges: the template-marker Twig extension, the asset
// sweep job, and the remote-Chrome client.
//
// None of them can be exercised end to end here. The Twig extension only loads
// under devMode, the asset sweep walks the whole library, and the Chrome client
// needs a browser listening on a socket. What can be checked is that each one
// is wired up as the code around it assumes, and that the remote client fails
// by returning rather than by throwing, since it runs inside a queue job where
// an exception is a failed job and a banner in every admin's CP.
// ---------------------------------------------------------------------------

describe('A11yTwigExtension', function() {
    it('supplies the template-marker node visitor', function() {
        // The page report finds which template rendered an element by reading
        // the markers this visitor writes. An extension that returned nothing
        // would leave that quietly always-empty.
        $visitors = (new A11yTwigExtension())->getNodeVisitors();

        expect($visitors)->toHaveCount(1)
            ->and($visitors[0])->toBeInstanceOf(A11yTemplateNodeVisitor::class);
    });

    it('registers a visitor Twig will accept', function() {
        $visitor = (new A11yTwigExtension())->getNodeVisitors()[0];

        expect($visitor)->toBeInstanceOf(Twig\NodeVisitor\NodeVisitorInterface::class)
            ->and($visitor->getPriority())->toBeInt();
    });
});

describe('AuditAssets', function() {
    it('describes itself for the queue listing', function() {
        expect(trim((string) (new AuditAssets())->getDescription()))->not->toBeEmpty();
    });

    it('batches over a query rather than loading the library at once', function() {
        // A site with tens of thousands of images is the case this job exists
        // for, so loadData must stay batchable: hydrating them all is how it
        // runs out of memory.
        $method = new ReflectionMethod(AuditAssets::class, 'loadData');
        $method->setAccessible(true);

        expect($method->invoke(new AuditAssets()))
            ->toBeInstanceOf(craft\base\Batchable::class);
    });
});

describe('RemoteChromeClient', function() {
    it('reports a failed connection rather than throwing', function() {
        // Runs inside a queue job. A throw here fails the job and banners it in
        // every admin's CP; a false lets the scan fall back to the PHP results.
        // Port 1 is reserved and nothing listens on it.
        $client = new RemoteChromeClient('ws://127.0.0.1:1/devtools/browser/none', 'http://127.0.0.1');

        expect($client->connect())->toBeFalse();
    });

    it('reports a failed connection for an unresolvable host too', function() {
        $client = new RemoteChromeClient(
            'ws://accessibility-audit-nonexistent.invalid:9222/devtools/browser/none',
            'http://accessibility-audit-nonexistent.invalid',
        );

        expect($client->connect())->toBeFalse();
    });

    it('is only ever built inside a catch, since a bad endpoint throws', function() {
        // The endpoint is typed in by an admin, and the WebSocket library
        // rejects one with no path from the constructor, before any connect.
        // That is fine as long as nothing builds a client outside a Throwable
        // catch: in a queue job an escaped exception is a failed job and a
        // banner in every admin's CP.
        expect(fn() => new RemoteChromeClient('ws://127.0.0.1:9222', 'http://127.0.0.1'))
            ->toThrow(InvalidArgumentException::class);

        $scanner = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/services/HeadlessScanner.php',
        );

        $at = strpos($scanner, 'new RemoteChromeClient(');
        expect($at)->not->toBeFalse();

        // The nearest try before it, and the catch that closes it.
        $before = substr($scanner, 0, (int) $at);
        expect(strrpos($before, 'try {'))->not->toBeFalse()
            ->and(substr($scanner, (int) $at))->toContain('catch (Throwable $e)');
    });

    it('uses the socket subclass that tolerates a slow first frame', function() {
        // Chrome can leave the handshake response a moment; the stock socket
        // treats an empty first read as the end of the connection.
        $source = (string) file_get_contents(
            (new ReflectionClass(RemoteChromeClient::class))->getFileName(),
        );

        expect($source)->toContain("'socket_class' => RemoteChromeSocket::class");
    });
});
