<?php

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Finding 1 (cross-site write via scanId)
//
// store-axe-results / store-contrast-results fence the POSTED siteId, but the
// write targets the SCAN's own site. A user must not be able to post their own
// allowed siteId alongside another site's scanId and slip findings onto a site
// they can't edit. On the Standard edition only the primary site is allowed, so
// a scan on a non-primary site is the "not allowed" case.
//
// Helpers are uniquely named: Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** The first non-primary site id, or 0 on a single-site install. */
function xsNonPrimarySiteId(): int
{
    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        if (!$site->primary) {
            return (int) $site->id;
        }
    }

    return 0;
}

/** Inserts a scan row on the given site and returns its id. */
function xsScanId(int $elementId, int $siteId): int
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId,
        'elementType' => \craft\elements\User::class,
        'siteId' => $siteId,
        'score' => 100,
        'scoreA' => 100,
        'scoreAA' => 100,
        'scoreAAA' => 100,
        'errorCount' => 0,
        'warningCount' => 0,
        'noticeCount' => 0,
        'dateScanned' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) Craft::$app->getDb()->getLastInsertID('{{%accessibilityaudit_scans}}');
}

/** A minimal axe violation payload. */
function xsViolation(): array
{
    return [
        'id' => 'image-alt',
        'impact' => 'critical',
        'tags' => ['wcag2a', 'wcag111'],
        'description' => 'Images must have alternate text',
        'help' => 'Images must have alternate text',
        'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.9/image-alt',
        'nodes' => [
            ['html' => '<img src="/x.jpg">', 'target' => ['img']],
        ],
    ];
}

/** Count of stored issue rows for a scan. */
function xsIssueCount(int $scanId): int
{
    return (int) (new Query())
        ->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId])
        ->count();
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    // Standard edition: only the primary site is allowed, so a non-primary
    // site's scan is the disallowed target this fence must catch.
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
});

describe('AuditController store actions: cross-site scanId fence', function() {
    it('refuses an axe write whose scanId belongs to a disallowed site', function() {
        $otherSiteId = xsNonPrimarySiteId();
        if ($otherSiteId === 0) {
            $this->markTestSkipped('Needs a second site.');
        }

        $elementId = (int) UserFactory::factory()->create()->id;
        $scanId = xsScanId($elementId, $otherSiteId);

        // Posts the primary (allowed) siteId, but the scan is on the other site.
        $json = $this->postJson('actions/accessibility-audit/audit/store-axe-results', [
            'scanId' => $scanId,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'violations' => json_encode([xsViolation()]),
        ])->getJsonContent();

        expect($json['success'])->toBeFalse();
        // Nothing was written to the other site's scan.
        expect(xsIssueCount($scanId))->toBe(0);
    });

    it('refuses a contrast write whose scanId belongs to a disallowed site', function() {
        $otherSiteId = xsNonPrimarySiteId();
        if ($otherSiteId === 0) {
            $this->markTestSkipped('Needs a second site.');
        }

        $elementId = (int) UserFactory::factory()->create()->id;
        $scanId = xsScanId($elementId, $otherSiteId);

        $json = $this->postJson('actions/accessibility-audit/audit/store-contrast-results', [
            'scanId' => $scanId,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'occurrences' => json_encode([
                ['fg' => '#777', 'bg' => '#888', 'ratio' => 1.2, 'expected' => '4.5:1', 'html' => '<p>x</p>'],
            ]),
        ])->getJsonContent();

        expect($json['success'])->toBeFalse();
        expect(xsIssueCount($scanId))->toBe(0);
    });

    it('still stores an axe write whose scanId is on the allowed primary site', function() {
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = (int) UserFactory::factory()->create()->id;
        $scanId = xsScanId($elementId, $primaryId);

        $json = $this->postJson('actions/accessibility-audit/audit/store-axe-results', [
            'scanId' => $scanId,
            'siteId' => $primaryId,
            'violations' => json_encode([xsViolation()]),
        ])->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and($json['scanId'])->toBe($scanId);
        // The same-site path wrote the violation through.
        expect(xsIssueCount($scanId))->toBeGreaterThan(0);
    });
});
