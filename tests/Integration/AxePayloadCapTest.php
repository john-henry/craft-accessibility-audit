<?php

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\elements\User;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\HeadlessScanner;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// What a store request is allowed to write.
//
// Every engine caps what it sends and is handed the caps to use, but a store
// endpoint takes whatever arrives at it: a browser pass is a POST, and the
// caps live in JavaScript anybody can edit. Without a limit on this side, one
// request writes a row per node, in the request, and a page reporting tens of
// thousands of nodes is a database asked to do tens of thousands of inserts
// before it answers.
// ---------------------------------------------------------------------------

/**
 * A scan row to store findings against, and its id.
 *
 * Inserted rather than asked for through ensureScan(), which refuses an
 * element type that carries no public URI and would hand back 0.
 */
function cappedScan(int $siteId, int $elementId): int
{
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId, 'elementType' => User::class, 'siteId' => $siteId,
        'score' => 100, 'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
        'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 0,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');
}

/** How many issues are stored against a scan. */
function storedIssues(int $scanId): int
{
    return (int) (new Query())
        ->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId])
        ->count();
}

describe('the axe payload cap', function() {
    beforeEach(function() {
        // Standard caps how many distinct elements may be scanned, and
        // ensureScan() answers 0 once that is reached, which would leave every
        // assertion below passing against an empty table.
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

        $this->capSiteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $this->capElementId = (int) UserFactory::factory()->create()->id;
        $this->capScanId = cappedScan($this->capSiteId, $this->capElementId);

        // Guards the assertions below: a cap test that stores nothing passes
        // every "no more than" check without exercising anything.
        expect($this->capScanId)->toBeGreaterThan(0);
    });

    it('stores no more contrast nodes than the cap the engines are given', function() {
        $scanId = $this->capScanId;

        // Twice the cap, each node distinct so none is collapsed as a repeat.
        $nodes = [];
        for ($i = 0; $i < HeadlessScanner::MAX_NODES_PER_VIOLATION * 2; $i++) {
            $nodes[] = [
                'html' => '<p class="c' . $i . '">Text ' . $i . '</p>',
                'target' => ['.c' . $i],
                'any' => [['data' => [
                    'contrastRatio' => 2.1,
                    'fgColor' => '#777777',
                    'bgColor' => '#888888',
                    'expectedContrastRatio' => '4.5:1',
                ]]],
            ];
        }

        AccessibilityAudit::getInstance()->getAudit()->storeAxeIssues($scanId, [[
            'id' => 'color-contrast',
            'impact' => 'serious',
            'description' => 'Elements must have sufficient colour contrast',
            'nodes' => $nodes,
        ]]);

        expect(storedIssues($scanId))->toBeLessThanOrEqual(HeadlessScanner::MAX_NODES_PER_VIOLATION);
    });

    it('stores no more violations than the cap, however many are posted', function() {
        $scanId = $this->capScanId;

        // Distinct rule ids so each would be its own row were they all stored.
        $violations = [];
        for ($i = 0; $i < 400; $i++) {
            $violations[] = [
                'id' => 'made-up-rule-' . $i,
                'impact' => 'moderate',
                'help' => 'Rule ' . $i . ' failed',
                'nodes' => [['html' => '<div>' . $i . '</div>', 'target' => ['div']]],
            ];
        }

        AccessibilityAudit::getInstance()->getAudit()->storeAxeIssues($scanId, $violations);

        // One row per violation on this path, so the row count is the cap.
        expect(storedIssues($scanId))->toBeLessThanOrEqual(200);
    });

    it('still stores an ordinary page in full', function() {
        // The cap must not be so tight that real findings are lost: a handful
        // of violations with a handful of nodes each goes in untouched.
        $scanId = $this->capScanId;

        $violations = [];
        for ($i = 0; $i < 5; $i++) {
            $violations[] = [
                'id' => 'ordinary-rule-' . $i,
                'impact' => 'moderate',
                'help' => 'Rule ' . $i . ' failed',
                'nodes' => [['html' => '<div>' . $i . '</div>', 'target' => ['div']]],
            ];
        }

        AccessibilityAudit::getInstance()->getAudit()->storeAxeIssues($scanId, $violations);

        expect(storedIssues($scanId))->toBe(5);
    });
});
