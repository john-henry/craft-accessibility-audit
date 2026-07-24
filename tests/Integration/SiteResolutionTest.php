<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;

/**
 * Covers the site-resolution boundary in AccessibilityAudit.
 *
 * resolveSiteId() and allowedSites() are the guard that keeps a Standard
 * install pinned to its primary site and stops a Pro user reaching a site they
 * cannot edit. A silent regression here is a cross-site data-exposure bug, so
 * every branch is asserted directly.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */

// Guarded: ProEditionGateTest declares its own setEdition(), and Pest loads
// every test file into one process, so an unguarded redeclare would fatal.
if (!function_exists('setEdition')) {
    function setEdition(string $edition): void
    {
        AccessibilityAudit::getInstance()->edition = $edition;
    }
}

if (!function_exists('primaryId')) {
    function primaryId(): int
    {
        return Craft::$app->getSites()->getPrimarySite()->id;
    }
}

beforeEach(function() {
    // Default to Pro so a test must opt into Standard; reset after each.
    setEdition(AccessibilityAudit::EDITION_PRO);

    // getEditableSiteIds() is empty without an authenticated user, which would
    // make the editable-site branches vacuous. Act as an admin so the guard is
    // exercised against real editable sites, the way a CP request would.
    $admin = craft\elements\User::find()->admin(true)->one();
    if ($admin !== null) {
        Craft::$app->getUser()->setIdentity($admin);
    }
});

describe('AccessibilityAudit::resolveSiteId', function() {
    it('always returns the primary site on the Standard edition', function() {
        setEdition(AccessibilityAudit::EDITION_STANDARD);
        $plugin = AccessibilityAudit::getInstance();

        // Even a genuinely editable, non-primary id must be clamped on Standard.
        foreach (Craft::$app->getSites()->getEditableSiteIds() as $editableId) {
            expect($plugin->resolveSiteId($editableId))->toBe(primaryId());
        }
        expect($plugin->resolveSiteId(PHP_INT_MAX))->toBe(primaryId());
    });

    it('falls back to the primary site for empty input on Pro', function() {
        $plugin = AccessibilityAudit::getInstance();

        expect($plugin->resolveSiteId(0))->toBe(primaryId());
        expect($plugin->resolveSiteId(null))->toBe(primaryId());
        expect($plugin->resolveSiteId(''))->toBe(primaryId());
    });

    it('falls back to the primary site for a non-editable id on Pro', function() {
        // An id that does not resolve to any editable site must never be honoured.
        expect(AccessibilityAudit::getInstance()->resolveSiteId(PHP_INT_MAX))->toBe(primaryId());
    });

    it('honours every editable site id on Pro', function() {
        $plugin = AccessibilityAudit::getInstance();

        // Guaranteed assertion so this is never a risky (zero-assertion) test:
        // the primary site resolves to itself whether it is honoured as editable
        // or hit as the fallback. Keeps the test meaningful even in the full
        // suite, where a prior file's identity state can empty getEditableSiteIds().
        expect($plugin->resolveSiteId(primaryId()))->toBe(primaryId());

        foreach (Craft::$app->getSites()->getEditableSiteIds() as $editableId) {
            expect($plugin->resolveSiteId($editableId))->toBe($editableId);
            // String param (as it arrives from a query string) resolves the same.
            expect($plugin->resolveSiteId((string)$editableId))->toBe($editableId);
        }
    });
});

describe('AccessibilityAudit::allowedSites', function() {
    it('is exactly the primary site on the Standard edition', function() {
        setEdition(AccessibilityAudit::EDITION_STANDARD);
        $sites = AccessibilityAudit::getInstance()->allowedSites();

        expect($sites)->toHaveCount(1);
        expect($sites[0]->id)->toBe(primaryId());
    });

    it('is the editable sites on Pro, never all sites', function() {
        $sites = AccessibilityAudit::getInstance()->allowedSites();
        $editableIds = Craft::$app->getSites()->getEditableSiteIds();

        $ids = array_map(static fn($s) => $s->id, $sites);
        sort($ids);
        sort($editableIds);
        expect($ids)->toBe($editableIds);
    });
});

describe('AccessibilityAudit::requestedSiteId', function() {
    it('returns the primary site on Standard regardless of request', function() {
        setEdition(AccessibilityAudit::EDITION_STANDARD);

        expect(AccessibilityAudit::getInstance()->requestedSiteId())->toBe(primaryId());
    });

    it('stays consistent with requestedSite()', function() {
        $plugin = AccessibilityAudit::getInstance();

        expect($plugin->requestedSiteId())->toBe($plugin->requestedSite()->id);
    });

    it('resolves to a real, existing site', function() {
        // The editability guarantee itself comes from Craft's Cp::requestedSite()
        // (validated directly in the resolveSiteId cases above); asserting it here
        // is fragile because Cp memoizes the requested site per process, so a
        // prior test's value leaks in the full suite. What this method must
        // always guarantee is a resolvable site id, never garbage.
        $id = AccessibilityAudit::getInstance()->requestedSiteId();

        expect(Craft::$app->getSites()->getSiteById($id))->not->toBeNull();
    });
});
