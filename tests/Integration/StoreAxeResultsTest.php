<?php

use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers (uniquely named: Pest loads every test file into one process, so
// helper names must not collide with other Integration tests)
// ---------------------------------------------------------------------------

/** Creates a real element (a User) so the scans table's FK is satisfied. */
function axeTestElementId(): int
{
    return (int) UserFactory::factory()->create()->id;
}

/** Inserts a scan row for the element and returns its scan ID. */
function axeTestScanId(int $elementId, int $siteId = 1): int
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

/** A realistic axe violation payload, overridable per test. */
function axeTestViolation(array $overrides = []): array
{
    return array_merge([
        'id' => 'target-size',
        'impact' => 'serious',
        'tags' => ['cat.sensory-and-visual-cues', 'wcag22aa', 'wcag258'],
        'description' => 'Ensure touch targets have sufficient size and space',
        'help' => 'All touch targets must be 24px large, or leave sufficient space',
        'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.9/target-size',
        'nodes' => [
            ['html' => '<a href="/x" class="tiny">x</a>', 'target' => ['a.tiny']],
        ],
    ], $overrides);
}

/** All axe-source issue rows for a scan. */
function axeTestRows(int $scanId): array
{
    return (new craft\db\Query())
        ->select(['ruleId', 'wcagCriterion', 'wcagLevel', 'severity', 'viewport'])
        ->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId, 'source' => 'axe'])
        ->all();
}

/** Inserts a PHP-scanner issue row on the scan, for the dedup tests. */
function axeTestPhpIssue(int $scanId, string $ruleId): void
{
    $scan = (new craft\db\Query())
        ->select(['elementId', 'elementType', 'siteId'])
        ->from('{{%accessibilityaudit_scans}}')
        ->where(['id' => $scanId])
        ->one();

    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_issues}}', [
        'scanId' => $scanId,
        'elementId' => $scan['elementId'],
        'elementType' => $scan['elementType'],
        'siteId' => $scan['siteId'],
        'ruleId' => $ruleId,
        'severity' => 'warning',
        'message' => 'Test finding for ' . $ruleId,
        'source' => 'php',
        'isResolved' => false,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();
}

// ---------------------------------------------------------------------------
// Controller: overlay POSTs violations as a JSON string
// ---------------------------------------------------------------------------

describe('AuditController::actionStoreAxeResults', function() {
    it('stores violations posted as a JSON string (the overlay payload format)', function() {
        // Regression: the overlay sends violations via FormData as
        // JSON.stringify(...), a string. The controller previously passed it
        // straight into storeAxeIssues(array), throwing a TypeError, so no axe
        // result was ever stored.
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $elementId = axeTestElementId();
        $scanId = axeTestScanId($elementId);

        $json = $this->postJson('actions/accessibility-audit/audit/store-axe-results', [
            'scanId' => $scanId,
            'elementId' => $elementId,
            'elementType' => \craft\elements\User::class,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'violations' => json_encode([axeTestViolation()]),
        ])->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and($json['scanId'])->toBe($scanId);

        $rows = axeTestRows($scanId);
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['ruleId'])->toBe('axe:target-size')
            ->and($rows[0]['wcagCriterion'])->toBe('2.5.8')
            ->and($rows[0]['wcagLevel'])->toBe('AA')
            ->and($rows[0]['severity'])->toBe('error');
    });

    it('stores nothing (without erroring) when the violations payload is not valid JSON', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $elementId = axeTestElementId();
        $scanId = axeTestScanId($elementId);

        $json = $this->postJson('actions/accessibility-audit/audit/store-axe-results', [
            'scanId' => $scanId,
            'elementId' => $elementId,
            'elementType' => \craft\elements\User::class,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'violations' => 'not-json-at-all',
        ])->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and(axeTestRows($scanId))->toHaveCount(0);
    });

    it('buckets overlay results as mobile from a narrow viewportWidth', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $elementId = axeTestElementId();
        $scanId = axeTestScanId($elementId);

        $json = $this->postJson('actions/accessibility-audit/audit/store-axe-results', [
            'scanId' => $scanId,
            'elementId' => $elementId,
            'elementType' => \craft\elements\User::class,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'viewportWidth' => 375,
            'violations' => json_encode([axeTestViolation()]),
        ])->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and(axeTestRows($scanId)[0]['viewport'])->toBe(AuditService::VIEWPORT_MOBILE);
    });

    it('lets an explicit viewport bucket win over viewportWidth', function() {
        // The Inspect preview posts its bucket explicitly: its logical width
        // is fixed by the switch, not by the admin's window.
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $elementId = axeTestElementId();
        $scanId = axeTestScanId($elementId);

        $json = $this->postJson('actions/accessibility-audit/audit/store-axe-results', [
            'scanId' => $scanId,
            'elementId' => $elementId,
            'elementType' => \craft\elements\User::class,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'viewport' => AuditService::VIEWPORT_MOBILE,
            'viewportWidth' => 1920,
            'violations' => json_encode([axeTestViolation()]),
        ])->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and(axeTestRows($scanId)[0]['viewport'])->toBe(AuditService::VIEWPORT_MOBILE);
    });
});

// ---------------------------------------------------------------------------
// Service: WCAG criterion/level extraction from axe tags
// ---------------------------------------------------------------------------

describe('AuditService::storeAxeIssues WCAG tag parsing', function() {
    it('parses multi-digit criteria correctly (wcag258 → 2.5.8, wcag1410 → 1.4.10)', function() {
        // Regression: wcag258 used to parse as "2.58", which never matched the
        // VPAT criteria keys, so axe findings never fed auto-conformance.
        $service = new AuditService();
        $scanId = axeTestScanId(axeTestElementId());

        $service->storeAxeIssues($scanId, [
            axeTestViolation(),
            axeTestViolation([
                'id' => 'scrollable-region-focusable',
                'tags' => ['wcag2aa', 'wcag1410'],
            ]),
        ]);

        $criteria = array_column(axeTestRows($scanId), 'wcagCriterion', 'ruleId');
        expect($criteria['axe:target-size'])->toBe('2.5.8')
            ->and($criteria['axe:scrollable-region-focusable'])->toBe('1.4.10');
    });

    it('derives the level from the version tag and leaves best-practice rules unmapped', function() {
        $service = new AuditService();
        $scanId = axeTestScanId(axeTestElementId());

        $service->storeAxeIssues($scanId, [
            axeTestViolation(['id' => 'level-a', 'tags' => ['wcag2a', 'wcag111']]),
            axeTestViolation(['id' => 'level-aaa', 'tags' => ['wcag2aaa', 'wcag146']]),
            axeTestViolation(['id' => 'heading-order', 'tags' => ['cat.semantics', 'best-practice']]),
        ]);

        $rows = axeTestRows($scanId);
        $byRule = [];
        foreach ($rows as $row) {
            $byRule[$row['ruleId']] = $row;
        }

        expect($byRule['axe:level-a']['wcagLevel'])->toBe('A')
            ->and($byRule['axe:level-aaa']['wcagLevel'])->toBe('AAA')
            ->and($byRule['axe:heading-order']['wcagCriterion'])->toBeNull()
            ->and($byRule['axe:heading-order']['wcagLevel'])->toBeNull();
    });

    it('replaces the previous run\'s axe results instead of accumulating them', function() {
        $service = new AuditService();
        $scanId = axeTestScanId(axeTestElementId());

        $service->storeAxeIssues($scanId, [axeTestViolation()]);
        $service->storeAxeIssues($scanId, [axeTestViolation()]);

        expect(axeTestRows($scanId))->toHaveCount(1);
    });
});

// ---------------------------------------------------------------------------
// Service: per-viewport buckets
// ---------------------------------------------------------------------------

describe('AuditService::storeAxeIssues viewport buckets', function() {
    it('tags stored rows with the desktop bucket by default', function() {
        $service = new AuditService();
        $scanId = axeTestScanId(axeTestElementId());

        $service->storeAxeIssues($scanId, [axeTestViolation()]);

        expect(axeTestRows($scanId)[0]['viewport'])->toBe(AuditService::VIEWPORT_DESKTOP);
    });

    it('unions viewports instead of last-writer-wins', function() {
        // Regression: with a single bucket, whichever browser pass ran last
        // (desktop or mobile) overwrote the other's findings, and the score
        // flip-flopped between the two.
        $service = new AuditService();
        $scanId = axeTestScanId(axeTestElementId());

        $service->storeAxeIssues($scanId, [axeTestViolation()], AuditService::VIEWPORT_DESKTOP);
        $service->storeAxeIssues($scanId, [axeTestViolation()], AuditService::VIEWPORT_MOBILE);

        $viewports = array_column(axeTestRows($scanId), 'viewport');
        sort($viewports);
        expect($viewports)->toBe([AuditService::VIEWPORT_DESKTOP, AuditService::VIEWPORT_MOBILE]);
    });

    it('sweeps untagged legacy rows when the desktop bucket is written', function() {
        // Regression: rows stored before viewport tagging existed carry
        // viewport = null. No bucket's replace matched them, so carry-forward
        // duplicated the same finding onto every new scan forever (seen live
        // as axe:landmark-unique counted twice). The desktop bucket owns them.
        $service = new AuditService();
        $scanId = axeTestScanId(axeTestElementId());
        $scan = (new craft\db\Query())
            ->select(['elementId', 'elementType', 'siteId'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['id' => $scanId])
            ->one();

        // A legacy row: axe source, no viewport tag.
        $now = Db::prepareDateForDb(new DateTime());
        Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_issues}}', [
            'scanId' => $scanId,
            'elementId' => $scan['elementId'],
            'elementType' => $scan['elementType'],
            'siteId' => $scan['siteId'],
            'ruleId' => 'axe:landmark-unique',
            'severity' => 'warning',
            'message' => 'Legacy untagged finding',
            'source' => 'axe',
            'viewport' => null,
            'isResolved' => false,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $service->storeAxeIssues($scanId, [
            axeTestViolation(['id' => 'landmark-unique', 'tags' => ['cat.semantics', 'best-practice']]),
        ], AuditService::VIEWPORT_DESKTOP);

        // One desktop row, no lingering null duplicate.
        $rows = axeTestRows($scanId);
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['viewport'])->toBe(AuditService::VIEWPORT_DESKTOP);
    });

    it('leaves untagged legacy rows alone when the mobile bucket is written', function() {
        $service = new AuditService();
        $scanId = axeTestScanId(axeTestElementId());
        $scan = (new craft\db\Query())
            ->select(['elementId', 'elementType', 'siteId'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['id' => $scanId])
            ->one();

        $now = Db::prepareDateForDb(new DateTime());
        Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_issues}}', [
            'scanId' => $scanId,
            'elementId' => $scan['elementId'],
            'elementType' => $scan['elementType'],
            'siteId' => $scan['siteId'],
            'ruleId' => 'axe:landmark-unique',
            'severity' => 'warning',
            'message' => 'Legacy untagged finding',
            'source' => 'axe',
            'viewport' => null,
            'isResolved' => false,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        // A mobile pass must not claim desktop-era findings.
        $service->storeAxeIssues($scanId, [], AuditService::VIEWPORT_MOBILE);

        $rows = axeTestRows($scanId);
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['viewport'])->toBeNull();
    });

    it('only replaces its own bucket on a re-run', function() {
        $service = new AuditService();
        $scanId = axeTestScanId(axeTestElementId());

        $service->storeAxeIssues($scanId, [axeTestViolation()], AuditService::VIEWPORT_DESKTOP);
        $service->storeAxeIssues($scanId, [axeTestViolation()], AuditService::VIEWPORT_MOBILE);

        // A clean desktop re-run clears the desktop bucket; mobile stands.
        $service->storeAxeIssues($scanId, [], AuditService::VIEWPORT_DESKTOP);

        $rows = axeTestRows($scanId);
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['viewport'])->toBe(AuditService::VIEWPORT_MOBILE);
    });
});

describe('AuditService::viewportForWidth', function() {
    it('buckets narrow windows as mobile and everything else as desktop', function() {
        expect(AuditService::viewportForWidth(375))->toBe(AuditService::VIEWPORT_MOBILE)
            ->and(AuditService::viewportForWidth(AuditService::MOBILE_MAX_WIDTH))->toBe(AuditService::VIEWPORT_MOBILE)
            ->and(AuditService::viewportForWidth(AuditService::MOBILE_MAX_WIDTH + 1))->toBe(AuditService::VIEWPORT_DESKTOP)
            ->and(AuditService::viewportForWidth(1280))->toBe(AuditService::VIEWPORT_DESKTOP)
            // Unknown width (overlay predates the param, or JS failed) must
            // fall back to desktop, matching the pre-multi-viewport behaviour.
            ->and(AuditService::viewportForWidth(0))->toBe(AuditService::VIEWPORT_DESKTOP);
    });
});

// ---------------------------------------------------------------------------
// Service: cross-engine dedup (axe vs PHP scanner)
// ---------------------------------------------------------------------------

describe('AuditService::storeAxeIssues cross-engine dedup', function() {
    it('skips an axe violation whose equivalent PHP rule already flagged this scan', function() {
        // Both engines detect heading-order; without dedup the same problem is
        // counted (and score-penalised) twice.
        $service = new AuditService();
        $scanId = axeTestScanId(axeTestElementId());

        axeTestPhpIssue($scanId, 'heading-order');

        $service->storeAxeIssues($scanId, [
            axeTestViolation(['id' => 'heading-order', 'tags' => ['cat.semantics', 'best-practice']]),
            // No PHP equivalent exists for target-size: it must still store.
            axeTestViolation(),
        ]);

        $ruleIds = array_column(axeTestRows($scanId), 'ruleId');
        expect($ruleIds)->toBe(['axe:target-size']);
    });

    it('stores the axe violation when the PHP scanner did not flag the equivalent rule', function() {
        $service = new AuditService();
        $scanId = axeTestScanId(axeTestElementId());

        // A PHP issue for an unrelated rule must not suppress heading-order.
        axeTestPhpIssue($scanId, 'link-new-window');

        $service->storeAxeIssues($scanId, [
            axeTestViolation(['id' => 'heading-order', 'tags' => ['cat.semantics', 'best-practice']]),
        ]);

        $ruleIds = array_column(axeTestRows($scanId), 'ruleId');
        expect($ruleIds)->toBe(['axe:heading-order']);
    });
});

// ---------------------------------------------------------------------------
// Service: client-side findings carried onto fresh PHP scans
// ---------------------------------------------------------------------------

describe('AuditService client-issue carry-forward on PHP re-scan', function() {
    // scanHtml() enforces the Standard-edition scan cap, and earlier tests in
    // the suite may leave the in-memory edition on Standard with the dev DB
    // already at the cap — force Pro so these tests exercise carry-forward,
    // not the cap.
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    });

    // A minimal clean page: no heading skips, so the php heading-order rule
    // stays quiet and the carried axe findings are unambiguous.
    $cleanHtml = '<html lang="en"><head><title>Test</title><meta name="description" content="d"></head>'
        . '<body><main><h1>Hi</h1><p>Text</p></main></body></html>';

    it('carries axe findings onto the next PHP scan instead of dropping or "resolving" them', function() use ($cleanHtml) {
        $service = new AuditService();
        $elementId = axeTestElementId();

        // PHP scan, then the overlay stores an axe-only finding on it.
        $first = $service->scanHtml($cleanHtml, $elementId, \craft\elements\User::class, 1);
        $service->storeAxeIssues($first['scanId'], [axeTestViolation()]);

        // A second PHP scan creates a new scan row.
        $second = $service->scanHtml($cleanHtml, $elementId, \craft\elements\User::class, 1);
        expect($second['scanId'])->not->toBe($first['scanId']);

        // The axe finding rides along onto the new scan…
        $carried = array_column(axeTestRows($second['scanId']), 'ruleId');
        expect($carried)->toContain('axe:target-size');

        // …counts toward the new scan's stored score…
        $summary = $service->getScanSummary($second['scanId']);
        expect((int) $summary['errorCount'])->toBeGreaterThanOrEqual(1);

        // …and the original row is NOT falsely marked resolved.
        $resolved = (new craft\db\Query())
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $first['scanId'], 'ruleId' => 'axe:target-size', 'isResolved' => true])
            ->exists();
        expect($resolved)->toBeFalse();
    });

    it('does not carry an axe finding the fresh PHP scan flags itself', function() use ($cleanHtml) {
        $service = new AuditService();
        $elementId = axeTestElementId();

        // First scan is clean, so the axe heading-order finding stores.
        $first = $service->scanHtml($cleanHtml, $elementId, \craft\elements\User::class, 1);
        $service->storeAxeIssues($first['scanId'], [
            axeTestViolation(['id' => 'heading-order', 'tags' => ['cat.semantics', 'best-practice']]),
        ]);
        expect(array_column(axeTestRows($first['scanId']), 'ruleId'))->toContain('axe:heading-order');

        // The second scan's markup skips a heading level, so the PHP scanner
        // flags heading-order itself: the carried axe duplicate must be skipped.
        $skippedHeadingHtml = '<html lang="en"><head><title>Test</title><meta name="description" content="d"></head>'
            . '<body><main><h1>Hi</h1><h3>Skipped</h3></main></body></html>';
        $second = $service->scanHtml($skippedHeadingHtml, $elementId, \craft\elements\User::class, 1);

        expect(array_column(axeTestRows($second['scanId']), 'ruleId'))->not->toContain('axe:heading-order');
    });

    it('preserves each carried finding\'s viewport bucket', function() use ($cleanHtml) {
        $service = new AuditService();
        $elementId = axeTestElementId();

        $first = $service->scanHtml($cleanHtml, $elementId, \craft\elements\User::class, 1);
        $service->storeAxeIssues($first['scanId'], [axeTestViolation()], AuditService::VIEWPORT_MOBILE);

        $second = $service->scanHtml($cleanHtml, $elementId, \craft\elements\User::class, 1);

        // The mobile finding must still be a mobile finding on the new scan,
        // or the next mobile browser pass couldn't replace it and the next
        // desktop pass would wrongly wipe it.
        $rows = axeTestRows($second['scanId']);
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['ruleId'])->toBe('axe:target-size')
            ->and($rows[0]['viewport'])->toBe(AuditService::VIEWPORT_MOBILE);
    });
});

// ---------------------------------------------------------------------------
// Per-level score recalculation after axe results land
// ---------------------------------------------------------------------------
// The PHP scanner writes scoreA/AA/AAA once, then axe findings arrive later.
// Those used to update only the overall score, so every AA failure axe found
// (contrast, target size) stayed invisible to Level AA and the three levels
// drifted into reporting the same number. The fixture scan starts at 100
// across the board, so each case below reads as a pure delta.

/** @return array{score: string, scoreA: string, scoreAA: string, scoreAAA: string} */
function axeTestScanScores(int $scanId): array
{
    return (new craft\db\Query())
        ->select(['score', 'scoreA', 'scoreAA', 'scoreAAA'])
        ->from('{{%accessibilityaudit_scans}}')
        ->where(['id' => $scanId])
        ->one();
}

describe('AuditService::storeAxeIssues level score recalculation', function() {
    beforeEach(function() {
        // The target level decides which issues survive the level filter, and
        // the settings model is shared across files: pin it.
        AccessibilityAudit::getInstance()->getSettings()->wcagLevel = 'AA';
    });

    it('drops AA and AAA but leaves Level A alone for an AA failure', function() {
        $scanId = axeTestScanId(axeTestElementId());

        // wcag22aa / wcag258, serious impact -> a Level AA error (10 points).
        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [axeTestViolation()]);

        $scores = axeTestScanScores($scanId);

        expect((int)$scores['scoreA'])->toBe(100)
            ->and((int)$scores['scoreAA'])->toBe(90)
            ->and((int)$scores['scoreAAA'])->toBe(90)
            ->and((int)$scores['score'])->toBe(90);
    });

    it('drops every level for a Level A failure, since AA and AAA include A', function() {
        $scanId = axeTestScanId(axeTestElementId());

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [
            axeTestViolation([
                'id' => 'link-name',
                'tags' => ['cat.name-role-value', 'wcag2a', 'wcag412'],
            ]),
        ]);

        $scores = axeTestScanScores($scanId);

        expect((int)$scores['scoreA'])->toBe(90)
            ->and((int)$scores['scoreAA'])->toBe(90)
            ->and((int)$scores['scoreAAA'])->toBe(90)
            ->and((int)$scores['score'])->toBe(90);
    });

    it('moves only the overall score for a best-practice finding with no WCAG level', function() {
        $scanId = axeTestScanId(axeTestElementId());

        // No wcag* tag, so the rule carries no level: a real finding, but not
        // a conformance failure. It must not be counted against Level A.
        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [
            axeTestViolation([
                'id' => 'region',
                'tags' => ['cat.keyboard', 'best-practice'],
            ]),
        ]);

        $scores = axeTestScanScores($scanId);

        expect((int)$scores['score'])->toBe(90)
            ->and((int)$scores['scoreA'])->toBe(100)
            ->and((int)$scores['scoreAA'])->toBe(100)
            ->and((int)$scores['scoreAAA'])->toBe(100);
    });
});

// ---------------------------------------------------------------------------
// Undecided contrast stored as needs review
// ---------------------------------------------------------------------------

/** An axe color-contrast result from the incomplete bucket. */
function axeTestIncompleteContrast(array $dataOverrides = [], string $html = '<p class="over-img">Hello</p>'): array
{
    return [
        'id' => 'color-contrast',
        'impact' => 'serious',
        'tags' => ['cat.color', 'wcag2aa', 'wcag143'],
        'description' => 'Ensure the contrast between foreground and background colors meets WCAG 2 AA thresholds',
        'help' => 'Elements must meet minimum color contrast ratio thresholds',
        'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.9/color-contrast',
        'nodes' => [
            [
                'html' => $html,
                'target' => ['.over-img'],
                'any' => [['data' => array_merge([
                    'contrastRatio' => 0,
                    'fontSize' => '12.0pt (16px)',
                    'fontWeight' => 'normal',
                    'messageKey' => 'bgOverlap',
                    'expectedContrastRatio' => '4.5:1',
                ], $dataOverrides)]],
            ],
        ],
    ];
}

/** Rows stored for the needs-review contrast rule on a scan. */
function axeTestContrastReviewRows(int $scanId): array
{
    return (new \craft\db\Query())
        ->select(['ruleId', 'severity', 'message', 'context', 'wcagCriterion', 'wcagLevel', 'viewport', 'source'])
        ->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId, 'ruleId' => AuditService::RULE_POTENTIAL_CONTRAST])
        ->all();
}

describe('AuditService::storeAxeIssues undecided contrast', function() {
    it('stores an incomplete contrast node as a needs-review notice', function() {
        $scanId = axeTestScanId(axeTestElementId());

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [], AuditService::VIEWPORT_DESKTOP, [
            axeTestIncompleteContrast(),
        ]);

        $rows = axeTestContrastReviewRows($scanId);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['severity'])->toBe('notice')
            ->and($rows[0]['wcagCriterion'])->toBe('1.4.3')
            ->and($rows[0]['wcagLevel'])->toBe('AA')
            ->and($rows[0]['source'])->toBe('axe')
            // Bare markup, not JSON: the report prints it and matches the
            // element by it, and the verdict is keyed to its hash.
            ->and($rows[0]['context'])->toBe('<p class="over-img">Hello</p>')
            ->and($rows[0]['message'])->toContain('another element sits over it');
    });

    it('keeps an undecided contrast node out of the score, unlike a real violation', function() {
        $scanId = axeTestScanId(axeTestElementId());

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [], AuditService::VIEWPORT_DESKTOP, [
            axeTestIncompleteContrast(),
        ]);

        $scores = axeTestScanScores($scanId);

        expect((int)$scores['score'])->toBe(100)
            ->and((int)$scores['scoreAA'])->toBe(100);
    });

    it('phrases the question from the reason axe gave', function() {
        $scanId = axeTestScanId(axeTestElementId());

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [], AuditService::VIEWPORT_DESKTOP, [
            axeTestIncompleteContrast(['messageKey' => 'bgImage']),
        ]);

        expect(axeTestContrastReviewRows($scanId)[0]['message'])->toContain('it sits on a background image');
    });

    it('ignores incomplete results for every rule other than contrast', function() {
        $scanId = axeTestScanId(axeTestElementId());

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [], AuditService::VIEWPORT_DESKTOP, [
            array_merge(axeTestIncompleteContrast(), ['id' => 'aria-hidden-focus']),
            array_merge(axeTestIncompleteContrast(), ['id' => 'nested-interactive']),
        ]);

        expect(axeTestContrastReviewRows($scanId))->toBeEmpty();
    });

    it('replaces only its own viewport bucket on a re-scan', function() {
        $scanId = axeTestScanId(axeTestElementId());
        $audit = AccessibilityAudit::getInstance()->audit;

        $audit->storeAxeIssues($scanId, [], AuditService::VIEWPORT_DESKTOP, [axeTestIncompleteContrast()]);
        $audit->storeAxeIssues($scanId, [], AuditService::VIEWPORT_MOBILE, [
            axeTestIncompleteContrast([], '<p class="narrow">Hi</p>'),
        ]);

        $rows = axeTestContrastReviewRows($scanId);
        $byViewport = array_column($rows, 'context', 'viewport');

        expect($rows)->toHaveCount(2)
            ->and($byViewport[AuditService::VIEWPORT_DESKTOP])->toBe('<p class="over-img">Hello</p>')
            ->and($byViewport[AuditService::VIEWPORT_MOBILE])->toBe('<p class="narrow">Hi</p>');
    });

    it('drops a node with no markup, since the report matches elements by it', function() {
        $scanId = axeTestScanId(axeTestElementId());

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [], AuditService::VIEWPORT_DESKTOP, [
            axeTestIncompleteContrast([], ''),
        ]);

        expect(axeTestContrastReviewRows($scanId))->toBeEmpty();
    });
});
