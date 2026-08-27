<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

// ---------------------------------------------------------------------------
// An answer has to outlive the scan that asked the question.
//
// Issue rows are rebuilt on every scan, which is why verdicts live in their own
// table. The element scan carries them onto its fresh rows; the browser pass
// rebuilds the contrast questions separately and has to do the same, or a
// question dismissed in the morning is back after the next scan with nothing
// to say why. That is the fastest way to teach somebody the review queue is
// not worth working through.
// ---------------------------------------------------------------------------

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

it('keeps a dismissed contrast question dismissed after the browser pass runs again', function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

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

    $html = '<span class="badge">Sale</span>';
    $incomplete = [[
        'id' => 'color-contrast',
        'nodes' => [[
            'html' => $html,
            'target' => ['span'],
            'any' => [['data' => ['messageKey' => 'bgImage', 'expectedContrastRatio' => '4.5:1']]],
        ]],
    ]];

    $audit = AccessibilityAudit::getInstance()->audit;

    // First browser pass raises the question.
    $audit->storeAxeIssues($scanId, [], 'desktop', $incomplete);
    expect($audit->getPendingPotentialForScan($scanId))->toHaveCount(1);

    // The author answers it.
    AccessibilityAudit::getInstance()->verdicts->setVerdict(
        $siteId, $elementId, 'potential:contrast-unmeasurable', $html, 'dismissed',
    );
    expect($audit->getPendingPotentialForScan($scanId))->toHaveCount(0);

    // The browser pass runs again, rebuilding these rows from scratch. The
    // answer has to come with them, or the scan undoes the reader's work.
    $audit->storeAxeIssues($scanId, [], 'desktop', $incomplete);

    $stored = (new Query())->select(['verdict'])->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId, 'ruleId' => 'potential:contrast-unmeasurable'])->scalar();

    expect($stored)->toBe('dismissed')
        ->and($audit->getPendingPotentialForScan($scanId))->toHaveCount(0);
});
