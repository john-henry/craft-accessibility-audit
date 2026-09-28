<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\variables\AccessibilityVariable;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The scan set is every element type that has URIs, so a Commerce product, a
// category and a user are scanned and stored exactly as an entry is, and the
// scans table carries the element type alongside the id to prove it. The Twig
// API took an Entry and nothing else, so a store front could not ask for a
// product's score from a template even though the plugin held one.
//
// A User stands in for "not an entry" here because it needs no section, group
// or Commerce install to create.
// ---------------------------------------------------------------------------

/** Seeds a scan row against any element and returns its id. */
function seedScanFor(int $elementId, string $elementType, int $siteId): int
{
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId, 'elementType' => $elementType, 'siteId' => $siteId,
        'score' => 73, 'scoreA' => 73, 'scoreAA' => 73, 'scoreAAA' => 73,
        'errorCount' => 2, 'warningCount' => 1, 'noticeCount' => 0,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int)$db->getLastInsertID('{{%accessibilityaudit_scans}}');
}

it('reads a scan for an element that is not an entry', function() {
    $user = UserFactory::factory()->create();
    seedScanFor((int)$user->id, User::class, (int)$user->siteId);

    $scan = (new AccessibilityVariable())->scan($user);

    expect($scan)->not->toBeNull()
        ->and((int)$scan['score'])->toBe(73)
        ->and((int)$scan['elementId'])->toBe((int)$user->id);
});

it('reads the issues for an element that is not an entry', function() {
    $user = UserFactory::factory()->create();
    $scanId = seedScanFor((int)$user->id, User::class, (int)$user->siteId);

    AccessibilityAudit::getInstance()->getAudit()->storeAxeIssues($scanId, [[
        'id' => 'image-alt',
        'impact' => 'critical',
        'help' => 'Images must have alternate text',
        'nodes' => [['html' => '<img src="/x.png">', 'target' => ['img']]],
    ]]);

    $issues = (new AccessibilityVariable())->issues($user);

    expect($issues)->not->toBeEmpty()
        ->and($issues[0]['ruleId'])->toBe('axe:image-alt');
});

it('answers for an element that has never been scanned', function() {
    $user = UserFactory::factory()->create();

    expect((new AccessibilityVariable())->scan($user))->toBeNull()
        ->and((new AccessibilityVariable())->issues($user))->toBe([]);
});
