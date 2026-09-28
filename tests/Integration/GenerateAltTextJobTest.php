<?php

use johnhenry\accessibilityaudit\helpers\ImageSource;
use johnhenry\accessibilityaudit\jobs\GenerateAltTextJob;

// ---------------------------------------------------------------------------
// GenerateAltTextJob
//
// The job's network call can't be exercised without a live Anthropic key, but
// its gatekeeping can: which images it will hand to a third-party API by URL
// (a data-exposure decision) and its no-op guards. ImageSource::isLocalUrl()
// decides whether an image URL is public enough to send to Anthropic as a
// link, or must be base64'd instead; a wrong answer either leaks an internal
// URL to a third party or needlessly fails. The queued draft and the
// on-demand one both come through it, so this covers the pair.
// ---------------------------------------------------------------------------

/** Whether an address is one the plugin keeps in rather than hand over. */
function jobUrlIsLocal(string $url): bool
{
    return ImageSource::isLocalUrl($url);
}

describe('ImageSource::isLocalUrl', function() {
    it('treats a public https URL as sendable', function(string $url) {
        expect(jobUrlIsLocal($url))->toBeFalse();
    })->with([
        'plain host'   => 'https://example.com/uploads/photo.jpg',
        'subdomain'    => 'https://cdn.johnhenry.ie/img/a.png',
        'co.uk'        => 'https://images.example.co.uk/x.webp',
    ]);

    it('treats non-public URLs as local, so they are base64 not linked', function(string $url) {
        expect(jobUrlIsLocal($url))->toBeTrue();
    })->with([
        'not https'    => 'http://example.com/photo.jpg', // http:// can't be trusted public
        'localhost'    => 'https://localhost/photo.jpg',
        'loopback ip'  => 'https://127.0.0.1/photo.jpg',
        'ipv6 loop'    => 'https://[::1]/photo.jpg',
        'dot local'    => 'https://mysite.local/photo.jpg',
        'dot test'     => 'https://mysite.test/photo.jpg',
        'ddev'         => 'https://craft-5-boilerplate.ddev.site/photo.jpg',
        'lando'        => 'https://mysite.lndo.site/photo.jpg',
    ]);
});

describe('GenerateAltTextJob::execute guards', function() {
    it('no-ops without erroring when the asset id resolves to nothing', function() {
        // A queued job whose asset was deleted before it ran must return
        // quietly, never throw into the queue worker.
        $job = new GenerateAltTextJob(['assetId' => 0]);

        expect(fn() => $job->execute(Craft::$app->getQueue()))->not->toThrow(\Throwable::class);
    });
});
