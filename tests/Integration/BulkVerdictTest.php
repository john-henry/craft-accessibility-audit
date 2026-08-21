<?php

use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\VerdictService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Bulk verdicts: one judgment repeated across a page's review queue lands in
// one request. Guards mirror the single action: potential rules only, known
// verdicts only, permission-gated.
//
// Helpers reuse VerdictServiceTest's (verdictScanId, verdictIssue): Pest
// loads every file into one process.
// ---------------------------------------------------------------------------

function bulkVerdictOf(int $scanId, string $ruleId): ?string
{
    return (new Query())
        ->select(['verdict'])
        ->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId, 'ruleId' => $ruleId])
        ->scalar() ?: null;
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
});

describe('AuditController::actionSetVerdictsBulk', function() {
    it('dismisses several potential issues in one request', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();
        $scanId = verdictScanId($elementId, $siteId);

        verdictIssue($scanId, $elementId, 'potential:contrast-unmeasurable', '<a href="/about">About</a>', 'error', $siteId);
        verdictIssue($scanId, $elementId, 'potential:contrast-unmeasurable', '<a href="/blog">Blog</a>', 'error', $siteId);
        verdictIssue($scanId, $elementId, 'potential:decorative-image', '<img src="/pic.jpg" alt="">', 'error', $siteId);

        $json = $this->postJson('actions/accessibility-audit/audit/set-verdicts-bulk', [
            'elementId' => $elementId,
            'siteId' => $siteId,
            'verdict' => VerdictService::VERDICT_DISMISSED,
            'items' => json_encode([
                ['ruleId' => 'potential:contrast-unmeasurable', 'context' => '<a href="/about">About</a>'],
                ['ruleId' => 'potential:contrast-unmeasurable', 'context' => '<a href="/blog">Blog</a>'],
                ['ruleId' => 'potential:decorative-image', 'context' => '<img src="/pic.jpg" alt="">'],
            ]),
        ])->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and($json['applied'])->toBe(3);

        // Every targeted row carries the ruling; nothing was left behind.
        $dismissed = (new Query())
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId, 'verdict' => VerdictService::VERDICT_DISMISSED])
            ->count();
        expect((int)$dismissed)->toBe(3);
    });

    it('skips non-potential rules whatever the request asks for', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();
        $scanId = verdictScanId($elementId, $siteId);

        verdictIssue($scanId, $elementId, 'img-alt', '<img src="/x.jpg">', 'error', $siteId);

        $json = $this->postJson('actions/accessibility-audit/audit/set-verdicts-bulk', [
            'elementId' => $elementId,
            'siteId' => $siteId,
            'verdict' => VerdictService::VERDICT_DISMISSED,
            'items' => json_encode([
                ['ruleId' => 'img-alt', 'context' => '<img src="/x.jpg">'],
            ]),
        ])->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and($json['applied'])->toBe(0)
            ->and(bulkVerdictOf($scanId, 'img-alt'))->toBeNull();
    });

    it('refuses an unknown verdict', function() {
        $json = $this->postJson('actions/accessibility-audit/audit/set-verdicts-bulk', [
            'elementId' => verdictElementId(),
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'verdict' => 'shredded',
            'items' => json_encode([]),
        ])->getJsonContent();

        expect($json['success'])->toBeFalse();
    });

    it('refuses to clear rulings in bulk (that is what Restore is for)', function() {
        $json = $this->postJson('actions/accessibility-audit/audit/set-verdicts-bulk', [
            'elementId' => verdictElementId(),
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'verdict' => '',
            'items' => json_encode([['ruleId' => 'potential:decorative-image', 'context' => 'x']]),
        ])->getJsonContent();

        expect($json['success'])->toBeFalse();
    });
});
