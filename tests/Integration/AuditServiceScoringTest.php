<?php

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * A page that passes every scanner rule, with a slot for issue-bearing
 * markup, so score assertions only ever count the issues planted on purpose.
 */
function auditScorePage(string $body = ''): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Score fixture</title>
    <meta name="description" content="A score fixture page.">
</head>
<body>
    <a href="#main">Skip to main content</a>
    <header><nav><a href="/about">About us</a></nav></header>
    <main id="main">
        <h1>Page heading</h1>
        {$body}
    </main>
    <footer><p>Footer text</p></footer>
</body>
</html>
HTML;
}

/**
 * Empties the plugin's scan and issue tables for the primary site, so a
 * site-wide summary assertion starts from a known-empty slate. The test
 * database is a snapshot of a real install, so its own scan data would
 * otherwise leak into any site-wide aggregate. Uses DELETE so it stays inside
 * RefreshesDatabase's transaction and is rolled back with everything else.
 */
function auditClearSiteScans(): void
{
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $db = Craft::$app->getDb();
    $db->createCommand()->delete('{{%accessibilityaudit_issues}}', ['siteId' => $siteId])->execute();
    $db->createCommand()->delete('{{%accessibilityaudit_scans}}', ['siteId' => $siteId])->execute();
}

/**
 * Scans the fixture against a fresh element (a user row satisfies the
 * elements FK) and returns [scanId, score, elementId].
 *
 * @return array{0: int, 1: int, 2: int}
 */
function auditScanFixture(string $body = ''): array
{
    $elementId = UserFactory::factory()->create()->id;
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    $result = AccessibilityAudit::getInstance()->audit->scanHtml(
        auditScorePage($body),
        $elementId,
        User::class,
        $siteId,
    );

    return [(int)$result['scanId'], (int)$result['score'], $elementId];
}

/**
 * A minimal axe violation the storage pipeline accepts.
 */
function auditAxeViolation(string $id, string $impact = 'serious'): array
{
    return [
        'id' => $id,
        'impact' => $impact,
        'description' => "Test violation {$id}",
        'tags' => ['wcag2a', 'wcag412'],
        'nodes' => [['html' => '<div class="offender">']],
    ];
}

/**
 * The stored score for a scan, read back the same way the CP does.
 */
function auditStoredScore(int $scanId): int
{
    return (int) AccessibilityAudit::getInstance()->audit->getScanSummary($scanId)['score'];
}

beforeEach(function() {
    // Pro lifts the Standard scan cap so fixtures never collide with real
    // scans already in the dev database; notification triggers are switched
    // off so scanning can't attempt delivery mid-test. All singleton state,
    // hence reset here every time.
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

    $settings = AccessibilityAudit::getInstance()->getSettings();
    $settings->ignoreRules = [];
    $settings->pruneResolvedIssues = true;
    $settings->notifyEmailEnabled = false;
    $settings->notifySlackEnabled = false;
    $settings->notifyOnNewError = false;
    $settings->notifyOnScoreDrop = false;
});

// ---------------------------------------------------------------------------
// Score arithmetic (calculateScore / calculateScoreByLevel via scanHtml)
// ---------------------------------------------------------------------------

describe('AuditService score arithmetic', function() {
    it('scores a clean page 100 at every level', function() {
        [$scanId, $score, $elementId] = auditScanFixture('');
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $latest = AccessibilityAudit::getInstance()->audit->getLatestScan($elementId, $siteId);

        expect($score)->toBe(100)
            ->and((int)$latest['scoreA'])->toBe(100)
            ->and((int)$latest['scoreAA'])->toBe(100)
            ->and((int)$latest['scoreAAA'])->toBe(100)
            ->and((int)$latest['errorCount'])->toBe(0);
    });

    it('deducts 10 per error and 4 per warning', function() {
        // One missing alt (error) and one generic link (warning): 100 - 14.
        [, $score] = auditScanFixture('<img src="/p.jpg"><a href="/x">Click here</a>');

        expect($score)->toBe(86);
    });

    it('penalises levels cumulatively: A issues count against AA and AAA', function() {
        // img-alt is a Level A error (10); a second h1 is a Level AA notice (1).
        [, , $elementId] = auditScanFixture('<img src="/p.jpg"><h1>Second heading</h1>');
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $latest = AccessibilityAudit::getInstance()->audit->getLatestScan($elementId, $siteId);

        expect((int)$latest['scoreA'])->toBe(90)
            ->and((int)$latest['scoreAA'])->toBe(89)
            ->and((int)$latest['scoreAAA'])->toBe(89)
            ->and((int)$latest['score'])->toBe(89);
    });

    it('floors the score at zero', function() {
        // Twelve missing alts: 120 points of penalty on a 100-point scale.
        [, $score] = auditScanFixture(str_repeat('<img src="/p.jpg">', 12));

        expect($score)->toBe(0);
    });

    it('counts distinct failing criteria per level, cumulatively', function() {
        // getSiteSummary aggregates the whole site, and the test database is a
        // snapshot of a real install with its own scan data, so clear the site's
        // scans first for a deterministic slate. DELETE, not TRUNCATE, so it
        // stays inside RefreshesDatabase's transaction and rolls back after.
        auditClearSiteScans();

        // img-alt is Level A (criterion 1.1.1); a second <h1> is a Level AA
        // finding. Two missing alts are still one failing criterion, so the
        // count is of distinct criteria, not occurrences. The summary feeds the
        // overview cards, where "N criteria failing" sits under each level.
        auditScanFixture('<img src="/a.jpg"><img src="/b.jpg"><h1>Second heading</h1>');
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $summary = AccessibilityAudit::getInstance()->audit->getSiteSummary($siteId);

        // A: just 1.1.1. AA cumulative: 1.1.1 plus the second-h1 criterion. No
        // AAA-only failures, so AAA equals AA.
        expect($summary['failingCriteriaA'])->toBe(1)
            ->and($summary['failingCriteriaAA'])->toBe(2)
            ->and($summary['failingCriteriaAAA'])->toBe(2);
    });

    it('reports zero failing criteria for a clean site', function() {
        auditClearSiteScans();
        auditScanFixture();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $summary = AccessibilityAudit::getInstance()->audit->getSiteSummary($siteId);

        expect($summary['failingCriteriaA'])->toBe(0)
            ->and($summary['failingCriteriaAA'])->toBe(0)
            ->and($summary['failingCriteriaAAA'])->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// The recalculation invariant the viewport flip-flop fix depends on:
// recalculating from stored rows must land on the fresh scan's score.
// ---------------------------------------------------------------------------

describe('AuditService recalculation invariant', function() {
    it('recalculates to exactly the fresh score for the same issue set', function() {
        [$scanId, $freshScore] = auditScanFixture(
            '<img src="/p.jpg"><img src="/q.jpg" alt="IMG_1.jpg"><a href="/x">Click here</a><h1>Again</h1>'
        );

        // Storing an empty axe result set changes no rows but forces the
        // stored-row recalculation path.
        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, []);

        expect(auditStoredScore($scanId))->toBe($freshScore);
    });

    it('replaces a viewport bucket on re-run instead of accumulating it', function() {
        [$scanId] = auditScanFixture('');
        $audit = AccessibilityAudit::getInstance()->audit;
        $violation = auditAxeViolation('aria-required-attr');

        $audit->storeAxeIssues($scanId, [$violation]);
        $afterFirst = auditStoredScore($scanId);

        $audit->storeAxeIssues($scanId, [$violation]);

        expect($afterFirst)->toBe(90)
            ->and(auditStoredScore($scanId))->toBe(90);
    });

    it('unions viewport buckets and keeps the score stable across repeated runs', function() {
        [$scanId] = auditScanFixture('');
        $audit = AccessibilityAudit::getInstance()->audit;
        $desktop = auditAxeViolation('aria-required-attr');
        $mobile = auditAxeViolation('target-size');

        $audit->storeAxeIssues($scanId, [$desktop], 'desktop');
        $audit->storeAxeIssues($scanId, [$mobile], 'mobile');
        $unionScore = auditStoredScore($scanId);

        // The flip-flop guard: re-running the desktop pass with the same
        // findings must not touch the mobile bucket or move the score.
        $audit->storeAxeIssues($scanId, [$desktop], 'desktop');

        expect($unionScore)->toBe(80)
            ->and(auditStoredScore($scanId))->toBe(80);
    });

    it('does not double-count an axe violation the PHP scanner already flagged', function() {
        // img-alt is already on the scan as a PHP error; axe's image-alt is
        // its cross-engine equivalent and must be skipped.
        [$scanId, $freshScore] = auditScanFixture('<img src="/p.jpg">');

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [auditAxeViolation('image-alt')]);

        expect($freshScore)->toBe(90)
            ->and(auditStoredScore($scanId))->toBe(90);
    });
});

// ---------------------------------------------------------------------------
// pruneScanResults, both retention modes
// ---------------------------------------------------------------------------

describe('AuditService::pruneScanResults', function() {
    it('deletes old scans outright when pruneResolvedIssues is on', function() {
        [$scanId] = auditScanFixture('<img src="/p.jpg">');

        Craft::$app->getDb()->createCommand()->update('{{%accessibilityaudit_scans}}', [
            'dateScanned' => Db::prepareDateForDb((new DateTime())->modify('-60 days')),
        ], ['id' => $scanId])->execute();

        $deleted = AccessibilityAudit::getInstance()->audit->pruneScanResults(30);

        $scanRows = (new Query())->from('{{%accessibilityaudit_scans}}')->where(['id' => $scanId])->count();
        $issueRows = (new Query())->from('{{%accessibilityaudit_issues}}')->where(['scanId' => $scanId])->count();

        expect($deleted)->toBeGreaterThanOrEqual(1)
            ->and((int)$scanRows)->toBe(0)
            ->and((int)$issueRows)->toBe(0);
    });

    it('keeps recently resolved issues (and their scan) when pruneResolvedIssues is off', function() {
        AccessibilityAudit::getInstance()->getSettings()->pruneResolvedIssues = false;

        // An old scan holding one unresolved issue and one freshly resolved one.
        [$scanId] = auditScanFixture('<img src="/p.jpg"><a href="/x">Click here</a>');
        $db = Craft::$app->getDb();

        $resolvedIssueId = (new Query())
            ->select(['id'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId, 'ruleId' => 'link-generic'])
            ->scalar();

        $db->createCommand()->update('{{%accessibilityaudit_issues}}', [
            'isResolved' => true,
            'dateResolved' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $resolvedIssueId])->execute();

        $db->createCommand()->update('{{%accessibilityaudit_scans}}', [
            'dateScanned' => Db::prepareDateForDb((new DateTime())->modify('-60 days')),
        ], ['id' => $scanId])->execute();

        AccessibilityAudit::getInstance()->audit->pruneScanResults(30);

        // The unresolved img-alt row goes with the old scan; the resolved row
        // lives on its own dateResolved clock, which keeps the scan alive too.
        $remainingRules = (new Query())
            ->select(['ruleId'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId, 'ruleId' => ['img-alt', 'link-generic']])
            ->column();
        $scanSurvives = (new Query())->from('{{%accessibilityaudit_scans}}')->where(['id' => $scanId])->exists();

        expect($remainingRules)->toBe(['link-generic'])
            ->and($scanSurvives)->toBeTrue();
    });

    it('prunes resolved issues on their own clock once dateResolved passes the cutoff', function() {
        AccessibilityAudit::getInstance()->getSettings()->pruneResolvedIssues = false;

        [$scanId] = auditScanFixture('<a href="/x">Click here</a>');
        $db = Craft::$app->getDb();

        // Resolved long ago, on a scan that's also past the cutoff.
        $db->createCommand()->update('{{%accessibilityaudit_issues}}', [
            'isResolved' => true,
            'dateResolved' => Db::prepareDateForDb((new DateTime())->modify('-60 days')),
        ], ['scanId' => $scanId, 'ruleId' => 'link-generic'])->execute();

        $db->createCommand()->update('{{%accessibilityaudit_scans}}', [
            'dateScanned' => Db::prepareDateForDb((new DateTime())->modify('-60 days')),
        ], ['id' => $scanId])->execute();

        AccessibilityAudit::getInstance()->audit->pruneScanResults(30);

        // Nothing references the scan any more, so it goes too.
        $issueCount = (new Query())->from('{{%accessibilityaudit_issues}}')->where(['scanId' => $scanId])->count();
        $scanSurvives = (new Query())->from('{{%accessibilityaudit_scans}}')->where(['id' => $scanId])->exists();

        expect((int)$issueCount)->toBe(0)
            ->and($scanSurvives)->toBeFalse();
    });
});
