<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\StatementExclusionModel;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The statement's entries are stored as a JSON list, so nothing stands between
// the posted row and the published document. The category matters most: an
// unrecognised one is grouped under non-compliance when the statement renders,
// which publishes an admission of failure where the editor claimed an
// exemption. The rules on the model were never run before, so none of this was
// caught.
//
// A blank row is the other half of it. The form adds rows server-side, so one
// rides along with every save from the moment Add is pressed; holding it to the
// required rule would turn Add into an error.
// ---------------------------------------------------------------------------

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    $this->actingAs(UserFactory::factory()->admin(true)->create());

    // The record ships seeded, and a flash outlives the request that set it, so
    // both are cleared or a rejected save cannot be told from a stale one.
    resetStatementRecord(Craft::$app->getSites()->getPrimarySite()->id);
    Craft::$app->getSession()->removeFlash('error');
});

/** Posts one entry row with the statement form and hands back the stored list. */
function postEntry(array $row): array
{
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    test()->post('actions/accessibility-audit/statement/save-meta', [
        'siteId' => $siteId,
        'productName' => 'Acme Council',
        'entries' => [$row],
    ]);

    return AccessibilityAudit::getInstance()->getStatement()->getRecord($siteId)['exclusions'] ?? [];
}

it('refuses a category that is not one of the three legal ones', function() {
    $stored = postEntry([
        'category' => 'notARealCategory',
        'content' => 'PDF menus published before 2023',
    ]);

    expect($stored)->toBe([])
        ->and(Craft::$app->getSession()->getFlash('error'))->not->toBeEmpty();
});

it('stores an entry in each of the three categories', function() {
    foreach (StatementExclusionModel::categories() as $category) {
        $stored = postEntry(['category' => $category, 'content' => 'Archived minutes', 'reason' => 'Rebuilding the archive would cost more than the site takes in a year.']);

        expect($stored[0]['category'])->toBe($category);
    }
});

it('refuses a sentence typed into the criterion box', function() {
    $stored = postEntry([
        'category' => StatementExclusionModel::CATEGORY_NON_COMPLIANCE,
        'content' => 'Archived minutes',
        'criterion' => 'something about contrast',
    ]);

    expect($stored)->toBe([])
        ->and(Craft::$app->getSession()->getFlash('error'))->toContain('1.4.3');
});

it('accepts a criterion number', function() {
    $stored = postEntry([
        'category' => StatementExclusionModel::CATEGORY_NON_COMPLIANCE,
        'content' => 'Archived minutes',
        'criterion' => '1.4.3',
    ]);

    expect($stored[0]['criterion'])->toBe('1.4.3');
});

it('refuses content longer than the stated limit', function() {
    $stored = postEntry([
        'category' => StatementExclusionModel::CATEGORY_NON_COMPLIANCE,
        'content' => str_repeat('a', 501),
    ]);

    expect($stored)->toBe([]);
});

it('names which entry is at fault, since the form holds several', function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    $this->post('actions/accessibility-audit/statement/save-meta', [
        'siteId' => $siteId,
        'productName' => 'Acme Council',
        'entries' => [
            ['category' => StatementExclusionModel::CATEGORY_NON_COMPLIANCE, 'content' => 'Fine'],
            ['category' => 'notARealCategory', 'content' => 'Broken'],
        ],
    ]);

    expect(Craft::$app->getSession()->getFlash('error'))->toContain('2');
});

it('still lets a blank row be added without reporting it as an error', function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    $this->post('actions/accessibility-audit/statement/save-meta', [
        'siteId' => $siteId,
        'productName' => 'Acme Council',
        'addEntry' => '1',
    ]);

    $stored = AccessibilityAudit::getInstance()->getStatement()->getRecord($siteId)['exclusions'] ?? [];

    expect($stored)->toHaveCount(1)
        ->and($stored[0]['content'])->toBe('')
        ->and(Craft::$app->getSession()->getFlash('error'))->toBeEmpty();
});

it('never publishes an empty bullet for a row nobody filled in', function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $plugin = AccessibilityAudit::getInstance();

    // One real entry and one the editor added and walked away from.
    $plugin->getStatement()->saveExclusions($siteId, [
        StatementExclusionModel::fromArray([
            'category' => StatementExclusionModel::CATEGORY_NON_COMPLIANCE,
            'content' => 'Archived minutes published before 2023',
        ]),
        new StatementExclusionModel(),
    ]);

    $html = $plugin->getStatement()->render($siteId);

    expect($html)->toContain('Archived minutes published before 2023')
        ->and(substr_count($html, '<li>'))->toBe(substr_count($html, '</li>'))
        ->and($html)->not->toMatch('/<li>\s*<\/li>/');
});
