<?php

/**
 * Integration tests require a running Craft context.
 * Run from the parent Craft project:
 *
 *   vendor/bin/pest plugins/craft-accessibility-audit/tests \
 *     --test-directory=plugins/craft-accessibility-audit/tests
 */

// Apply TestCase + RefreshesDatabase to every Integration test.
// RefreshesDatabase wraps each test in a DB transaction that rolls back on
// teardown, keeping the database clean between tests.
use craft\elements\Entry;
use craft\elements\User;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\OrganisationMetaModel;
use johnhenry\accessibilityaudit\models\VpatMetaModel;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\User as UserFactory;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;

uses(
    TestCase::class,
    RefreshesDatabase::class,
)->in('Integration');

// Craft's Sites service memoises editable site ids for the whole process, and
// RefreshesDatabase only rolls back the database, not in-memory service memos.
// Without this, one file computing editable sites under a given identity leaves
// a stale (often empty) memo that survives into the next file, so a later
// actingAs() is ignored and per-site permission checks wrongly refuse. In
// production every request is a fresh process, so this only bites the shared
// test process; refreshing before each test recomputes against its identity.
uses()->beforeEach(function() {
    // RefreshesDatabase wraps each test in a transaction it rolls back on
    // teardown; that binding only engages when the suite is run through
    // `composer test:aa` (which passes --test-directory). If it ever stops
    // binding, no transaction is open here and every test would COMMIT to the
    // dev database, so fail loudly before this test can write.
    if (Craft::$app->getDb()->getTransaction() === null) {
        throw new RuntimeException(
            'No open database transaction: RefreshesDatabase did not bind, so tests would '
            . 'commit to the dev database. Run the suite via `composer test:aa`.'
        );
    }

    // The plugin, its settings model and its edition are process-level
    // singletons, and RefreshesDatabase rolls back the database and nothing
    // else. Without this, a test that changes a setting or drops the edition to
    // Standard changes it for every test collected after it, in whatever order
    // the files happen to land. The model is snapshotted on the first test,
    // while it is still pristine, and put back before each one after that.
    $plugin = AccessibilityAudit::getInstance();
    $settings = $plugin->getSettings();

    if (pristinePluginState() === null) {
        pristinePluginState([
            'edition' => $plugin->edition,
            'settings' => $settings->getAttributes(),
        ]);
    }

    $pristine = pristinePluginState();
    $plugin->edition = $pristine['edition'];
    $settings->setAttributes($pristine['settings'], false);

    // Two deliberate normalisations on top of the restore, because the dev
    // project config is not necessarily the state a test should assume: no
    // excluded volumes, and every native element type in scope.
    $settings->excludedVolumes = [];
    $settings->scannedElementTypes = null;

    // Craft works out which sites are editable once and holds the answer for
    // the rest of the request, reading it off whoever is logged in at the time.
    // A suite is one request, so the first test to ask fixes the answer for
    // every test after it, whatever user those act as. Anything gated on
    // editable sites then passes or fails on test order.
    Craft::$app->getSites()->refreshSites();
})->in('Integration');

/**
 * The plugin's edition and settings as they were before any test touched them.
 *
 * Held in a static rather than a global so nothing can overwrite it by
 * accident: passing a value stores it once and only once, and later calls read
 * it back.
 *
 * @param array{edition: string, settings: array<string, mixed>}|null $capture
 *        The snapshot to store, on the first call only.
 * @return array{edition: string, settings: array<string, mixed>}|null
 */
function pristinePluginState(?array $capture = null): ?array
{
    static $state = null;

    if ($capture !== null && $state === null) {
        $state = $capture;
    }

    return $state;
}

/**
 * Creates an entry in the dedicated `a11yFixture` section.
 *
 * A bare Entry::factory()->create() makes craft-pest auto-provision a section on
 * the fly, inside the RefreshesDatabase transaction. Section creation auto
 * -commits that transaction and craft-pest can only roll back an auto-committed
 * field, not a section, so the teardown afterwards throws an orphaned-model
 * error. That is what made the entry-creating tests fail intermittently. The
 * `a11yFixture` channel section lives in project config (created before the
 * tests run and enabled on every site), so nothing is provisioned inside the
 * transaction and the orphan path is never hit.
 *
 * @param string|null $title An optional entry title.
 * @return Entry
 */
function scannableEntry(?string $title = null): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('a11yFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The a11yFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $factory = EntryFactory::factory()->section($section);

    if ($title !== null) {
        $factory = $factory->title($title);
    }

    return $factory->create();
}

/**
 * Saves a flat set of VPAT metadata the way the editor posts it.
 *
 * Storage is split in two: the shared organisation half (product name, contact,
 * evaluation method) and the VPAT's own half. Tests care about the flat shape
 * the export and the editor see, so this mirrors what VpatController does and
 * keeps that split out of every test that just needs some metadata saved.
 *
 * @param int $siteId The site to save against.
 * @param array<string, string|string[]> $values Flat metadata, keyed by field name.
 * @return void
 */
function saveVpatMetaFlat(int $siteId, array $values): void
{
    $shared = new OrganisationMetaModel();
    $vpat = new VpatMetaModel();

    foreach ($values as $attr => $value) {
        $target = in_array($attr, OrganisationMetaModel::storageKeys(), true) ? $shared : $vpat;
        $target->$attr = $value;
    }

    $plugin = AccessibilityAudit::getInstance();
    $plugin->organisation->saveMeta($siteId, $shared);
    $plugin->vpat->saveMeta($siteId, $vpat);
}

/**
 * Clears a site's VPAT record (its per-criterion overrides and its meta).
 *
 * The compliance gate reads VPAT overrides to decide what has been signed off.
 * A test asserting "nothing is confirmed yet" has to make that true rather than
 * inherit whatever overrides happen to be in the database, or it rides on
 * ambient state. RefreshesDatabase rolls this back per test.
 *
 * @param int $siteId The site to clear.
 * @return void
 */
function resetVpat(int $siteId): void
{
    Craft::$app->getDb()->createCommand()
        ->delete('{{%accessibilityaudit_vpat}}', ['siteId' => $siteId])
        ->execute();
}

/**
 * Clears a site's stored accessibility statement record.
 *
 * A statement test that asserts a save, or a refused save, has to start from a
 * known-empty record rather than inherit whatever a prior test left. It lives
 * here rather than in one test file so any test can use it without depending on
 * that file being collected first.
 *
 * @param int $siteId The site to clear.
 * @return void
 */
function resetStatementRecord(int $siteId): void
{
    Craft::$app->getDb()->createCommand()
        ->delete('{{%accessibilityaudit_statement}}', ['siteId' => $siteId])
        ->execute();
}

/**
 * Clears a site's scans and their issues.
 *
 * Auto-conformance (and so `hasScanData`, and so which criteria read as failing)
 * is derived from scan issues. A compliance-status assertion that must not be
 * swayed by whatever scans exist clears them first. Rolled back per test.
 *
 * @param int $siteId The site to clear.
 * @return void
 */
function resetScanData(int $siteId): void
{
    $db = Craft::$app->getDb();
    $db->createCommand()->delete('{{%accessibilityaudit_issues}}', ['siteId' => $siteId])->execute();
    $db->createCommand()->delete('{{%accessibilityaudit_scans}}', ['siteId' => $siteId])->execute();
}

/**
 * Records one scan (with issues that carry WCAG criteria) for a site, so
 * VpatService::getAutoConformance returns data and `hasScanData` is true.
 *
 * A full compliance claim is gated on the site having actually been scanned, so
 * a test that expects the claim to be reachable has to seed that scan rather
 * than assume one is lying around. A user row satisfies the elements foreign
 * key cheaply. Rolled back per test.
 *
 * @param int $siteId The site to scan against.
 * @return void
 */
function seedComplianceScan(int $siteId): void
{
    $elementId = UserFactory::factory()->create()->id;

    AccessibilityAudit::getInstance()->audit->scanHtml(
        '<html><body><img src="a.jpg"><a href="#"></a></body></html>',
        $elementId,
        User::class,
        $siteId,
    );
}
