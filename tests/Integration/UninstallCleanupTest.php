<?php

use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\jobs\AnalyseReadability;
use johnhenry\accessibilityaudit\jobs\AuditAssets;
use johnhenry\accessibilityaudit\jobs\GenerateAltTextJob;
use johnhenry\accessibilityaudit\jobs\HeadlessScanJob;
use johnhenry\accessibilityaudit\jobs\RecordReadability;
use johnhenry\accessibilityaudit\jobs\ScanElementJob;
use johnhenry\accessibilityaudit\jobs\ScanElements;
use johnhenry\accessibilityaudit\widgets\AccessibilityScoreWidget;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// What uninstalling leaves behind
//
// Dropping the plugin's tables does not reach two things. A dashboard tile
// names the class that draws it, and Craft answers a class it cannot load with
// its Missing Widget box: an error on the dashboard of everyone who added the
// score widget, saying nothing they can act on and removable only by hand. And
// a queued scan, asset sweep or alt-text draft runs after the plugin has gone
// and fails on a class that is not there any more.
//
// Neither is reachable from a test directly: uninstalling the plugin inside the
// suite would take the tables the rest of it runs against. What is asserted is
// that the hook exists, that it looks for the right things, and that the
// namespace it searches the queue for is the one the jobs are actually under.
//
// Helper names carry an `uninstall` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** The source of the plugin's lifecycle trait. */
function uninstallTraitSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/src/base/PluginTrait.php');
}

it('clears the dashboard tiles the uninstall would otherwise strand', function() {
    $source = uninstallTraitSource();

    preg_match('/protected function beforeUninstall\(\).*?\n    \}/s', $source, $m);
    $body = $m[0] ?? '';

    expect($body)->not->toBeEmpty()
        ->and($body)->toContain('Table::WIDGETS')
        ->and($body)->toContain('AccessibilityScoreWidget::class');
});

it('takes its own queued jobs out on the way', function() {
    $source = uninstallTraitSource();

    preg_match('/protected function beforeUninstall\(\).*?\n    \}/s', $source, $m);

    expect($m[0] ?? '')->toContain('_releaseQueuedJobs()');
});

it('cannot stop an uninstall, whatever goes wrong in it', function() {
    // Craft runs this inside the transaction that removes the plugin, so a
    // throw is a plugin that cannot be uninstalled at all.
    $source = uninstallTraitSource();

    preg_match('/protected function beforeUninstall\(\).*?\n    \}/s', $source, $m);
    $body = $m[0] ?? '';

    $tryAt = strpos($body, 'try {');
    $workAt = strpos($body, 'Db::delete(');

    expect($tryAt)->not->toBeFalse()
        ->and($tryAt)->toBeLessThan($workAt)
        ->and($body)->toContain('catch (Throwable');
});

it('searches the queue for a namespace every one of its jobs is under', function() {
    // Read through the plugin class: PHP will not hand back a trait constant
    // directly, it has to come from the class that uses the trait.
    foreach ([
        AnalyseReadability::class,
        AuditAssets::class,
        GenerateAltTextJob::class,
        HeadlessScanJob::class,
        RecordReadability::class,
        ScanElementJob::class,
        ScanElements::class,
    ] as $job) {
        expect($job)->toStartWith(AccessibilityAudit::JOB_NAMESPACE);
    }
});

it('finds that namespace in the bytes a queued job is actually stored as', function() {
    // A match against the source proves nothing about what lands in the row.
    expect(serialize(new ScanElements(['siteId' => 1])))
        ->toContain(AccessibilityAudit::JOB_NAMESPACE);
});

it('does not match a job belonging to something else', function() {
    expect(serialize(new stdClass()))->not->toContain(AccessibilityAudit::JOB_NAMESPACE);
});

it('would find a widget row of its own type', function() {
    // The delete is keyed on the stored type string, so the two have to agree.
    $this->actingAs(UserFactory::factory()->admin(true)->create());

    Craft::$app->getDb()->createCommand()->insert(Table::WIDGETS, [
        'userId' => Craft::$app->getUser()->getId(),
        'type' => AccessibilityScoreWidget::class,
        'sortOrder' => 99,
        'colspan' => 1,
        'settings' => '{}',
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();

    expect((new Query())->from(Table::WIDGETS)
        ->where(['type' => AccessibilityScoreWidget::class])
        ->count())->toBeGreaterThan(0);
});
