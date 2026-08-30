<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Sets the in-memory chromePath setting for the current test. */
function headlessSetChromePath(string $path): void
{
    AccessibilityAudit::getInstance()->getSettings()->chromePath = $path;
}

/** Sets the in-memory chromeWsEndpoint setting for the current test. */
function headlessSetWsEndpoint(string $uri): void
{
    AccessibilityAudit::getInstance()->getSettings()->chromeWsEndpoint = $uri;
}

// The settings are in-memory only; make sure no test leaks them into the next.
afterEach(function() {
    headlessSetChromePath('');
    headlessSetWsEndpoint('');
});

// ---------------------------------------------------------------------------
// Availability gating
// ---------------------------------------------------------------------------

describe('HeadlessScanner::isAvailable', function() {
    it('is unavailable on the Standard edition even with a valid binary path', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
        // Any file that certainly exists in the container stands in for Chrome:
        // isAvailable() checks existence, not that it really is a browser.
        headlessSetChromePath('/bin/sh');

        expect(AccessibilityAudit::getInstance()->headless->isAvailable())->toBeFalse();
    });

    it('is unavailable on Pro when no path is configured', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        headlessSetChromePath('');

        expect(AccessibilityAudit::getInstance()->headless->isAvailable())->toBeFalse();
    });

    it('is unavailable on Pro when the configured path does not exist', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        headlessSetChromePath('/definitely/not/a/browser');

        expect(AccessibilityAudit::getInstance()->headless->isAvailable())->toBeFalse();
    });

    it('is available on Pro when the configured path exists', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        headlessSetChromePath('/bin/sh');

        expect(AccessibilityAudit::getInstance()->headless->isAvailable())->toBeTrue();
    });

    it('is available on Pro with only a remote endpoint and no local binary', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        headlessSetChromePath('');
        headlessSetWsEndpoint('ws://chrome:3000');

        expect(AccessibilityAudit::getInstance()->headless->isAvailable())->toBeTrue();
    });

    it('is unavailable on the Standard edition even with a remote endpoint', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
        headlessSetWsEndpoint('ws://chrome:3000');

        expect(AccessibilityAudit::getInstance()->headless->isAvailable())->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Endpoint resolution
// ---------------------------------------------------------------------------

describe('HeadlessScanner::chromeWsEndpoint', function() {
    it('is empty when unset', function() {
        headlessSetWsEndpoint('');

        expect(AccessibilityAudit::getInstance()->headless->chromeWsEndpoint())->toBe('');
    });

    it('trims surrounding whitespace so a pasted URI still connects', function() {
        headlessSetWsEndpoint("  ws://chrome:3000\n");

        expect(AccessibilityAudit::getInstance()->headless->chromeWsEndpoint())->toBe('ws://chrome:3000/');
    });

    it('resolves an environment variable reference, which is how a tokenised endpoint is stored', function() {
        putenv('AA_TEST_WS_ENDPOINT=ws://chrome:3000/?token=secret');
        $_ENV['AA_TEST_WS_ENDPOINT'] = 'ws://chrome:3000/?token=secret';
        headlessSetWsEndpoint('$AA_TEST_WS_ENDPOINT');

        try {
            expect(AccessibilityAudit::getInstance()->headless->chromeWsEndpoint())
                ->toBe('ws://chrome:3000/?token=secret');
        } finally {
            putenv('AA_TEST_WS_ENDPOINT');
            unset($_ENV['AA_TEST_WS_ENDPOINT']);
        }
    });

    // The WebSocket client rejects a pathless URI outright ("Invalid path"),
    // and a pathless URI is exactly what browserless hands out, so the endpoint
    // has to grow a root path before it ever reaches the socket.
    it('adds a root path to a bare host, which is the form hosted services give you', function() {
        headlessSetWsEndpoint('wss://production-lon.browserless.io?token=secret');

        expect(AccessibilityAudit::getInstance()->headless->chromeWsEndpoint())
            ->toBe('wss://production-lon.browserless.io/?token=secret');
    });

    it('adds a root path when there is no query string either', function() {
        headlessSetWsEndpoint('ws://chrome:3000');

        expect(AccessibilityAudit::getInstance()->headless->chromeWsEndpoint())->toBe('ws://chrome:3000/');
    });

    it('leaves a URI that already carries a path alone', function() {
        headlessSetWsEndpoint('wss://host/chromium?token=secret');

        expect(AccessibilityAudit::getInstance()->headless->chromeWsEndpoint())
            ->toBe('wss://host/chromium?token=secret');
    });
});

// ---------------------------------------------------------------------------
// scanUrl guard
// ---------------------------------------------------------------------------

describe('HeadlessScanner::scanUrl', function() {
    it('returns null without launching anything when unavailable', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
        headlessSetChromePath('');

        expect(AccessibilityAudit::getInstance()->headless->scanUrl('https://example.com/'))->toBeNull();
    });

    it('never signs off a VPAT criterion on the strength of a clean sweep', function() {
        // Target Size (2.5.8) and Non-text Contrast (1.4.11) used to auto-mark
        // "Supports" whenever a fleet-wide browser pass came back clean. No
        // criterion is settled end to end by this plugin: both of these have
        // work a browser pass cannot reach, components that only appear part
        // way through an interaction, and a VPAT is a public claim. A clean
        // sweep is shown beside the row as evidence and the answer stays with
        // the person. Target Size makes the case on its own, since a real
        // violation sat on the Overview while the VPAT called it Supports.
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

        // Wipe scan data inside this test's transaction (rolled back on
        // teardown) so auto-conformance reflects a controlled, violation-free
        // fleet instead of whatever the dev database's latest scans hold. A
        // single clean scan row stays in: with zero scans, auto-conformance
        // honestly reports nothing at all.
        Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_issues}}')->execute();
        Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_scans}}')->execute();
        $element = \markhuot\craftpest\factories\User::factory()->create();
        Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
            'elementId' => (int) $element->id,
            'elementType' => \craft\elements\User::class,
            'siteId' => 1,
            'score' => 100,
            'scoreA' => 100,
            'scoreAA' => 100,
            'scoreAAA' => 100,
            'errorCount' => 0,
            'warningCount' => 0,
            'noticeCount' => 0,
            'dateScanned' => \craft\helpers\Db::prepareDateForDb(new DateTime()),
            'dateCreated' => \craft\helpers\Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => \craft\helpers\Db::prepareDateForDb(new DateTime()),
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        headlessSetChromePath('');
        $off = AccessibilityAudit::getInstance()->vpat->getAutoConformance(1);

        headlessSetChromePath('/bin/sh');
        $on = AccessibilityAudit::getInstance()->vpat->getAutoConformance(1);

        // Having a browser changes what gets measured, never what gets claimed.
        foreach (['1.4.11', '2.5.8', '2.4.7'] as $criterion) {
            expect($off)->not->toHaveKey($criterion)
                ->and($on)->not->toHaveKey($criterion);
        }

        // A clean sweep against a criterion says nothing at all here, so the
        // two answers are identical whether Chrome is there or not.
        expect($on)->toBe($off);
    });

    it('runs a real axe pass in headless Chrome when a browser is installed', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        headlessSetChromePath('/usr/bin/chromium');

        // scanUrl only relaxes TLS verification (self-signed dev certs) when
        // devMode is on. This test covers the scan pipeline, not the TLS
        // policy, and must pass against the DDEV cert even when the local
        // environment is switched to production mode, so force it in-memory.
        $general = Craft::$app->getConfig()->getGeneral();
        $previousDevMode = $general->devMode;
        $general->devMode = true;

        try {
            $findings = AccessibilityAudit::getInstance()->headless->scanUrl('https://craft-5-boilerplate.ddev.site/');
        } finally {
            $general->devMode = $previousDevMode;
        }

        expect($findings)->toBeArray()
            ->toHaveKeys(['violations', 'incomplete'])
            ->and($findings['violations'])->toBeArray()
            ->and($findings['incomplete'])->toBeArray();

        // Both buckets must arrive in the overlay payload shape that
        // storeAxeIssues() consumes.
        foreach ([...$findings['violations'], ...$findings['incomplete']] as $result) {
            expect($result)->toHaveKeys(['id', 'impact', 'tags', 'description', 'help', 'helpUrl', 'nodes']);
        }

        // Only contrast is carried over from the incomplete bucket: every other
        // rule's "can't tell" answer is dropped in the page, not here.
        foreach ($findings['incomplete'] as $result) {
            expect($result['id'])->toBe('color-contrast');
        }
    })->skip(fn() => !file_exists('/usr/bin/chromium'), 'chromium is not installed in this environment');

    it('uses the remote endpoint even when a working local binary is also configured', function() {
        // Precedence is documented in the setting's own instructions, so pin it
        // by behaviour: with an unreachable endpoint and a perfectly good binary
        // alongside it, a fall back to the binary would return findings.
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        headlessSetChromePath('/usr/bin/chromium');
        headlessSetWsEndpoint('ws://127.0.0.1:1/devtools/browser/nope');

        expect(AccessibilityAudit::getInstance()->headless->scanUrl('https://example.com/'))->toBeNull();
    })->skip(fn() => !file_exists('/usr/bin/chromium'), 'chromium is not installed in this environment');

    it('returns null instead of throwing when the remote endpoint is unreachable', function() {
        // isAvailable() takes an endpoint on trust, so an unreachable one has to
        // fail inside scanUrl. A broken browser pass must never fail the PHP
        // scan it accompanies.
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        headlessSetChromePath('');
        headlessSetWsEndpoint('ws://127.0.0.1:1/devtools/browser/nope');

        expect(AccessibilityAudit::getInstance()->headless->scanUrl('https://example.com/'))->toBeNull();
    });
});
