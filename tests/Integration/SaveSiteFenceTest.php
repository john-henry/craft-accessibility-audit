<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\StatementProfiles;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Finding 3 (statement/VPAT writes unfenced to editable sites)
//
// Both save actions read a raw siteId and wrote to it with no per-site check.
// The manageStatement / manageVpat permissions are install-wide, so a Pro
// multi-site user could write a site outside their permissions, and a Standard
// user could write a non-primary site.
//
// Statement is available on every edition, so its fence is exercised on the
// Standard edition (non-primary is disallowed). VPAT is Pro-only, so its fence
// is exercised on Pro against a site id that is not editable (a non-existent id
// stands in for "not in the user's editable sites").
//
// Helper is uniquely named: Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** The first non-primary site id, or 0 on a single-site install. */
function sfNonPrimarySiteId(): int
{
    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        if (!$site->primary) {
            return (int) $site->id;
        }
    }

    return 0;
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

describe('StatementController::actionSaveMeta site fence', function() {
    beforeEach(function() {
        // Statement is available on every edition; Standard pins it to the
        // primary site, so a non-primary site is the disallowed target here.
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
    });

    it('refuses a save that targets a disallowed site', function() {
        $otherSiteId = sfNonPrimarySiteId();
        if ($otherSiteId === 0) {
            $this->markTestSkipped('Needs a second site.');
        }

        resetStatementRecord($otherSiteId);

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $otherSiteId,
            'productName' => 'Acme Council',
            'profile' => StatementProfiles::PROFILE_GENERIC,
        ]);

        // Nothing was written to the disallowed site, and the refusal is flashed.
        expect(AccessibilityAudit::getInstance()->statement->getRecord($otherSiteId)['meta']['productName'] ?? '')
            ->toBe('')
            ->and(Craft::$app->getSession()->getFlash('error'))->toContain('permission');
    });

    it('still saves to the allowed primary site', function() {
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;
        resetStatementRecord($primaryId);

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $primaryId,
            'productName' => 'Acme Council',
            'profile' => StatementProfiles::PROFILE_GENERIC,
        ]);

        expect(AccessibilityAudit::getInstance()->statement->getRecord($primaryId)['meta']['productName'])
            ->toBe('Acme Council');
    });
});

describe('VpatController::actionSaveMeta site fence', function() {
    beforeEach(function() {
        // VPAT is Pro-only; on Pro an admin can edit every real site, so a
        // non-existent id stands in for "outside the user's editable sites".
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    });

    it('refuses a save that targets a non-editable site', function() {
        $json = $this->postJson('actions/accessibility-audit/vpat/save-meta', [
            'siteId' => 999999,
            'productName' => 'Acme Council',
            'contactEmail' => 'access@example.com',
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['error'])->toContain('permission');
    });

    it('accepts a save that targets the allowed primary site', function() {
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;
        resetVpat($primaryId);

        $json = $this->postJson('actions/accessibility-audit/vpat/save-meta', [
            'siteId' => $primaryId,
            'productName' => 'Acme Council',
            'contactEmail' => 'access@example.com',
        ])->getJsonContent();

        expect($json['success'])->toBeTrue();
    });
});
