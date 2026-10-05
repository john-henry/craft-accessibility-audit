<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;
use yii\web\BadRequestHttpException;

// ---------------------------------------------------------------------------
// The store endpoints write only onto elements the user may view.
//
// run-scans is install-wide, so it says nothing about which entries a person
// may see. Scanning an entry already checks canView(); writing the browser's
// findings onto that entry's scan has to check the same, or someone barred
// from a section can still put findings, and questions, on its pages. The
// element comes from the scan itself where one is named, so a harmless posted
// elementId can't stand in for it.
//
// Users stand in for entries: a user who lacks the users permissions may view
// their own account and no other, which is the boundary under test.
// ---------------------------------------------------------------------------

/** Inserts a scan row for a user element on the primary site. */
function sefScanId(int $elementId): int
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId,
        'elementType' => \craft\elements\User::class,
        'siteId' => (int) Craft::$app->getSites()->getPrimarySite()->id,
        'score' => 100, 'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
        'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 0,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) Craft::$app->getDb()->getLastInsertID('{{%accessibilityaudit_scans}}');
}

/** Count of stored issue rows for a scan. */
function sefIssueCount(int $scanId): int
{
    return (int) (new Query())->from('{{%accessibilityaudit_issues}}')->where(['scanId' => $scanId])->count();
}

/** A minimal axe violation payload. */
function sefViolations(): string
{
    return json_encode([[
        'id' => 'image-alt',
        'impact' => 'critical',
        'tags' => ['wcag2a', 'wcag111'],
        'description' => 'Images must have alternate text',
        'help' => 'Images must have alternate text',
        'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.9/image-alt',
        'nodes' => [['html' => '<img src="/x.jpg">', 'target' => ['img']]],
    ]]);
}

/** A stylesheet focus-outline finding, as the Inspect report posts it. */
function sefFocusRules(): string
{
    return json_encode([['selector' => 'a:focus', 'html' => '<a href="/x">', 'count' => 1]]);
}

beforeEach(function() {
    // Standard edition: the primary site is the only one, so the site fence
    // passes and the element fence is the only thing deciding.
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

    $this->scanner = UserFactory::factory()->create();
    Craft::$app->getUserPermissions()->saveUserPermissions((int) $this->scanner->id, [
        'accesscp',
        'accessplugin-accessibility-audit',
        'accessibility-audit:run-scans',
    ]);
    $this->actingAs($this->scanner);

    $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
});

it('refuses axe findings for the scan of an element the user cannot view', function() {
    $scanId = sefScanId((int) UserFactory::factory()->create()->id);

    $json = $this->postJson('actions/accessibility-audit/audit/store-axe-results', [
        'scanId' => $scanId,
        'siteId' => $this->siteId,
        // The posted element is one they can view; the scan's is not.
        'elementId' => (int) $this->scanner->id,
        'violations' => sefViolations(),
    ])->getJsonContent();

    expect($json['success'])->toBeFalse()
        ->and(sefIssueCount($scanId))->toBe(0);
});

it('refuses contrast and focus findings for the scan of an element the user cannot view', function() {
    $scanId = sefScanId((int) UserFactory::factory()->create()->id);

    $json = $this->postJson('actions/accessibility-audit/audit/store-contrast-results', [
        'scanId' => $scanId,
        'siteId' => $this->siteId,
        'occurrences' => json_encode([]),
        'focusRules' => sefFocusRules(),
    ])->getJsonContent();

    expect($json['success'])->toBeFalse()
        ->and(sefIssueCount($scanId))->toBe(0);
});

it('stores findings for the scan of an element the user can view', function() {
    $scanId = sefScanId((int) $this->scanner->id);

    $json = $this->postJson('actions/accessibility-audit/audit/store-contrast-results', [
        'scanId' => $scanId,
        'siteId' => $this->siteId,
        'occurrences' => json_encode([]),
        'focusRules' => sefFocusRules(),
    ])->getJsonContent();

    expect($json['success'])->toBeTrue()
        ->and(sefIssueCount($scanId))->toBe(1);
});

it('answers only requests that accept JSON', function() {
    $scanId = sefScanId((int) $this->scanner->id);

    expect(fn() => $this->post('actions/accessibility-audit/audit/store-contrast-results', [
        'scanId' => $scanId,
        'siteId' => $this->siteId,
        'occurrences' => json_encode([]),
    ]))->toThrow(BadRequestHttpException::class);

    expect(sefIssueCount($scanId))->toBe(0);
});
