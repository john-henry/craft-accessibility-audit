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

// The setting is in-memory only; make sure no test leaks it into the next.
afterEach(function() {
    headlessSetChromePath('');
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

    it('unlocks browser-verified VPAT auto-conformance only when available', function() {
        // Target Size (2.5.8) and Non-text Contrast (1.4.11) auto-mark
        // "Supports" only when a fleet-wide browser pass backs the claim.
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
        expect($off)->not->toHaveKey('1.4.11')
            ->and($off)->not->toHaveKey('2.5.8');

        headlessSetChromePath('/bin/sh');
        $on = AccessibilityAudit::getInstance()->vpat->getAutoConformance(1);
        expect($on['1.4.11']['level'] ?? null)->toBe('Supports')
            // Target Size is mobile-critical; it only auto-claims because the
            // headless sweep now renders every page at the mobile viewport as
            // well as desktop, so a clean pass covers the touch-sized layout.
            ->and($on['2.5.8']['level'] ?? null)->toBe('Supports')
            // Focus Visible must never be auto-claimed, headless or not.
            ->and($on)->not->toHaveKey('2.4.7');
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
            $violations = AccessibilityAudit::getInstance()->headless->scanUrl('https://craft-5-boilerplate.ddev.site/');
        } finally {
            $general->devMode = $previousDevMode;
        }

        expect($violations)->toBeArray();

        // Every violation must arrive in the overlay payload shape that
        // storeAxeIssues() consumes.
        foreach ($violations as $violation) {
            expect($violation)->toHaveKeys(['id', 'impact', 'tags', 'description', 'help', 'helpUrl', 'nodes']);
        }
    })->skip(fn() => !file_exists('/usr/bin/chromium'), 'chromium is not installed in this environment');
});
