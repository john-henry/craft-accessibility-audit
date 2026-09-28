<?php

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\VerdictService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Bulk restore clears somebody's recorded decisions, several at a time, from a
// list of ids posted by a browser. The scoping is the whole safety net: the
// action must only ever touch dismissed potential issues belonging to the site
// it resolved, whatever ids the request supplies.
// ---------------------------------------------------------------------------

function dismissedFixture(int $siteId, string $ruleId = 'potential:identical-links'): array
{
    $entry = scannableEntry();
    $verdicts = AccessibilityAudit::getInstance()->getVerdicts();

    $scanId = AccessibilityAudit::getInstance()->getAudit()->ensureScan(
        $entry->id,
        get_class($entry),
        $siteId,
    );

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_issues}}', [
        'scanId' => $scanId,
        'elementId' => $entry->id,
        'elementType' => get_class($entry),
        'siteId' => $siteId,
        'ruleId' => $ruleId,
        'severity' => 'notice',
        'message' => 'Are these identical links going to different places?',
        'context' => '<a href="/a">Read more</a>',
        'isResolved' => false,
        'verdict' => VerdictService::VERDICT_DISMISSED,
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();

    $id = (int) Craft::$app->getDb()->getLastInsertID();

    return ['id' => $id, 'entry' => $entry];
}

function verdictCount(int $id): int
{
    return (int) (new Query())
        ->from('{{%accessibilityaudit_issues}}')
        ->where(['id' => $id, 'verdict' => VerdictService::VERDICT_DISMISSED])
        ->count();
}

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

it('clears the verdict on every id it is given', function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $a = dismissedFixture($siteId);
    $b = dismissedFixture($siteId);

    $json = $this->postJson('actions/accessibility-audit/audit/restore-verdicts', [
        'siteId' => $siteId,
        'ids' => [$a['id'], $b['id']],
    ])->getJsonContent();

    expect($json['success'])->toBeTrue()
        ->and($json['restored'])->toBe(2)
        ->and(verdictCount($a['id']))->toBe(0)
        ->and(verdictCount($b['id']))->toBe(0);
});

it('does nothing when given no ids', function() {
    $json = $this->postJson('actions/accessibility-audit/audit/restore-verdicts', [
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        'ids' => [],
    ])->getJsonContent();

    expect($json['success'])->toBeTrue()
        ->and($json['restored'])->toBe(0);
});

it('ignores ids that are not dismissed potential issues', function() {
    // A confirmed verdict, or an ordinary rule, is not this action's business
    // however the request is shaped.
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $ordinary = dismissedFixture($siteId, 'img-alt');

    $json = $this->postJson('actions/accessibility-audit/audit/restore-verdicts', [
        'siteId' => $siteId,
        'ids' => [$ordinary['id']],
    ])->getJsonContent();

    expect($json['restored'])->toBe(0)
        ->and(verdictCount($ordinary['id']))->toBe(1);
});

it('needs the run-scans permission, like dismissing does', function() {
    $source = file_get_contents(
        dirname(__DIR__, 2) . '/src/controllers/AuditController.php',
    );

    preg_match(
        '/actionRestoreVerdicts.*?requirePermission\(\s*[\'"]([^\'"]+)[\'"]/s',
        $source,
        $m,
    );

    expect($m[1] ?? null)->toBe('accessibility-audit:run-scans');
});

it('will not clear a ruling belonging to another site', function() {
    // The scoping is the authorisation, and the site half of it was the part
    // nothing covered. An id is posted by a browser, so the only thing keeping
    // one site's decisions out of another's reach is the where clause.
    $sites = Craft::$app->getSites()->getAllSites();

    if (count($sites) < 2) {
        $this->markTestSkipped('Needs a second site.');
    }

    $primary = Craft::$app->getSites()->getPrimarySite()->id;
    $other = null;

    foreach ($sites as $site) {
        if ((int) $site->id !== (int) $primary) {
            $other = (int) $site->id;
            break;
        }
    }

    $elsewhere = dismissedFixture($other);

    $json = $this->postJson('actions/accessibility-audit/audit/restore-verdicts', [
        'siteId' => $primary,
        'ids' => [$elsewhere['id']],
    ])->getJsonContent();

    expect($json['restored'])->toBe(0)
        ->and(verdictCount($elsewhere['id']))->toBe(1);
});

it('keeps the scoping with the operation, not with the surface that asked', function() {
    // Restoring used to build its own scoped query in the controller. Anything
    // else needing to restore a ruling would have had to write that where
    // clause again, and a copy that drifts is a cross-site write.
    $controller = (string) file_get_contents(
        dirname(__DIR__, 2) . '/src/controllers/AuditController.php',
    );

    expect($controller)->not->toContain('new Query()');
    expect(method_exists(VerdictService::class, 'restoreDismissedPotentials'))->toBeTrue();
});
