<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\HeadlessScanner;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// A finding has to say what the work is.
//
// Every axe violation was stored with the rule's own statement, which is the
// same sentence on every occurrence of that rule anywhere on the site. The
// reason this element failed sits on the node, and the browser pass was
// throwing it away before it left Chrome.
//
// target-size shows why that matters. "All touch targets must be 24px large, or
// leave sufficient space" is one sentence covering three unrelated jobs: the
// target is too small, the target is fine but sits too close to its neighbours,
// or something else is covering part of it. A 44px-tall link reported under
// that sentence reads as a contradiction, and there was nothing stored anywhere
// that could settle it.
// ---------------------------------------------------------------------------

it('keeps the reason the element failed, not just the rule it failed', function() {
    $message = AuditService::axeMessage(
        'All touch targets must be 24px large, or leave sufficient space',
        ['failureSummary' => "Fix any of the following:\n  Element has insufficient size (20px by 20px, should be at least 24px by 24px)"],
    );

    expect($message)->toContain('20px by 20px')
        ->and($message)->toContain('All touch targets must be 24px large');
});

it('tells the three target-size failures apart', function() {
    $help = 'All touch targets must be 24px large, or leave sufficient space';

    $tooSmall = AuditService::axeMessage($help, [
        'failureSummary' => "Fix any of the following:\n  Element has insufficient size (20px by 20px, should be at least 24px by 24px)",
    ]);
    $covered = AuditService::axeMessage($help, [
        'failureSummary' => "Fix any of the following:\n  Element has insufficient size because another element obscures it",
    ]);
    $crowded = AuditService::axeMessage($help, [
        'failureSummary' => "Fix any of the following:\n  Element has insufficient space to its closest neighbors",
    ]);

    expect($tooSmall)->not->toBe($covered)
        ->and($covered)->not->toBe($crowded)
        ->and($covered)->toContain('obscures it')
        ->and($crowded)->toContain('closest neighbors');
});

it('drops the list scaffolding axe writes around its reasons', function() {
    $message = AuditService::axeMessage('Rule statement', [
        'failureSummary' => "Fix all of the following:\n  First reason\n  Second reason",
    ]);

    expect($message)->toBe('Rule statement. First reason. Second reason.')
        ->and($message)->not->toContain('Fix all of the following');
});

it('falls back to the rule statement when axe gives no reason', function() {
    expect(AuditService::axeMessage('Rule statement', []))->toBe('Rule statement')
        ->and(AuditService::axeMessage('Rule statement', ['failureSummary' => '']))->toBe('Rule statement')
        // A summary that is nothing but scaffolding leaves nothing to add.
        ->and(AuditService::axeMessage('Rule statement', ['failureSummary' => 'Fix any of the following:']))
        ->toBe('Rule statement');
});

it('carries the reason out of the browser in the first place', function() {
    // The slimming step decides what survives the page. A reason dropped here
    // cannot be recovered later, which is how it went missing.
    expect(HeadlessScanner::class)->toBeString();

    $source = (string) file_get_contents((new ReflectionClass(HeadlessScanner::class))->getFileName());

    expect($source)->toContain('failureSummary: (n.failureSummary || \'\')');
});

it('stores the reason against the issue', function() {
    $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $elementId = (int) UserFactory::factory()->create()->id;
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId, 'elementType' => User::class, 'siteId' => $siteId,
        'score' => 100, 'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
        'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 0,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    $scanId = (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');

    AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [[
        'id' => 'target-size',
        'impact' => 'serious',
        'help' => 'All touch targets must be 24px large, or leave sufficient space',
        'nodes' => [[
            'html' => '<a href="/x" class="min-h-11">',
            'target' => ['a'],
            'failureSummary' => "Fix any of the following:\n  Element has insufficient size because another element obscures it",
        ]],
    ]], 'desktop');

    $stored = (new Query())->select(['message'])->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId, 'ruleId' => 'axe:target-size'])->scalar();

    expect($stored)->toContain('obscures it');
});
