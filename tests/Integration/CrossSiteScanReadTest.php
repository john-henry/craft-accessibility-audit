<?php

use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Finding 5 (cross-site read of issue detail via scanId)
//
// page-issues / page-rule-occurrences take a raw scanId gated only on the
// install-wide viewReports permission, which does not scope by site. A crafted
// scanId for another site's scan must not expose that site's findings. On the
// Standard edition only the primary site is allowed, so a non-primary scan is
// the disallowed case.
//
// Helpers are uniquely named: Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** The first non-primary site id, or 0 on a single-site install. */
function xsrNonPrimarySiteId(): int
{
    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        if (!$site->primary) {
            return (int) $site->id;
        }
    }

    return 0;
}

/** Inserts a scan row on the given site (with one issue row) and returns its id. */
function xsrScanWithIssue(int $elementId, int $siteId): int
{
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId,
        'elementType' => \craft\elements\User::class,
        'siteId' => $siteId,
        'score' => 50,
        'scoreA' => 50,
        'scoreAA' => 50,
        'scoreAAA' => 50,
        'errorCount' => 1,
        'warningCount' => 0,
        'noticeCount' => 0,
        'dateScanned' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    $scanId = (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');

    $db->createCommand()->insert('{{%accessibilityaudit_issues}}', [
        'scanId' => $scanId,
        'elementId' => $elementId,
        'elementType' => \craft\elements\User::class,
        'siteId' => $siteId,
        'ruleId' => 'image-alt',
        'severity' => 'error',
        'message' => 'Images must have alternate text',
        'source' => 'axe',
        'isResolved' => false,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return $scanId;
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
});

describe('DashboardController read actions: cross-site scanId fence', function() {
    it('refuses page-issues for a scanId on a disallowed site', function() {
        $otherSiteId = xsrNonPrimarySiteId();
        if ($otherSiteId === 0) {
            $this->markTestSkipped('Needs a second site.');
        }

        $elementId = (int) UserFactory::factory()->create()->id;
        $scanId = xsrScanWithIssue($elementId, $otherSiteId);

        $json = $this->http('get', 'actions/accessibility-audit/dashboard/page-issues?scanId=' . $scanId)
            ->addHeader('Accept', 'application/json')
            ->send()
            ->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['error'])->toContain('permission');
    });

    it('refuses page-rule-occurrences for a scanId on a disallowed site', function() {
        $otherSiteId = xsrNonPrimarySiteId();
        if ($otherSiteId === 0) {
            $this->markTestSkipped('Needs a second site.');
        }

        $elementId = (int) UserFactory::factory()->create()->id;
        $scanId = xsrScanWithIssue($elementId, $otherSiteId);

        $json = $this->http('get', 'actions/accessibility-audit/dashboard/page-rule-occurrences?scanId=' . $scanId . '&ruleId=image-alt')
            ->addHeader('Accept', 'application/json')
            ->send()
            ->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['error'])->toContain('permission');
    });

    it('still returns page-issues for a scanId on the allowed primary site', function() {
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = (int) UserFactory::factory()->create()->id;
        $scanId = xsrScanWithIssue($elementId, $primaryId);

        $json = $this->http('get', 'actions/accessibility-audit/dashboard/page-issues?scanId=' . $scanId)
            ->addHeader('Accept', 'application/json')
            ->send()
            ->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and($json['issues'])->toBeArray();
    });
});
