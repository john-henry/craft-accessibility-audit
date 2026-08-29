<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\services\AuditService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// A tab named for pages with issues.
//
// It listed every page that had been scanned, so a clean site showed hundreds
// of rows with a dash where the issue counts belong, under a heading counting
// them as pages with issues. The number in the tab was the size of the site.
//
// The other half is the empty state. A site nobody has scanned and a site with
// nothing wrong both produce an empty list, and telling someone to run a scan
// when they have just run one and passed reads as though it did not work.
// ---------------------------------------------------------------------------

/** A scan on its own element, with the severity counts given. */
function scanWithCounts(int $siteId, int $errors, int $warnings, int $notices): int
{
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => (int) UserFactory::factory()->create()->id,
        'elementType' => User::class,
        'siteId' => $siteId,
        'score' => $errors + $warnings > 0 ? 60 : 100,
        'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
        'errorCount' => $errors,
        'warningCount' => $warnings,
        'noticeCount' => $notices,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $this->audit = new AuditService();
});

/** The filtered and unfiltered totals, right now. */
function issueTotals(AuditService $audit, int $siteId): array
{
    return [
        'withIssues' => $audit->getScannedElements($siteId, 1, 1, withIssuesOnly: true)['total'],
        'all' => $audit->getScannedElements($siteId, 1, 1)['total'],
    ];
}

it('lists only pages that actually have something on them', function() {
    // Counted as a change rather than an absolute, so a dev database with its
    // own history behind it does not decide the result.
    $before = issueTotals($this->audit, $this->siteId);

    scanWithCounts($this->siteId, 0, 0, 0);
    scanWithCounts($this->siteId, 0, 0, 0);
    scanWithCounts($this->siteId, 2, 0, 0);

    $after = issueTotals($this->audit, $this->siteId);

    expect($after['withIssues'] - $before['withIssues'])->toBe(1)
        ->and($after['all'] - $before['all'])->toBe(3);
});

it('counts a page whose only findings are notices', function() {
    // Matched to the row's own count, which shows a dash only when all three
    // are zero. A page with notices has something to show, so it belongs.
    $before = issueTotals($this->audit, $this->siteId);

    scanWithCounts($this->siteId, 0, 0, 4);

    expect(issueTotals($this->audit, $this->siteId)['withIssues'] - $before['withIssues'])->toBe(1);
});

it('pages the filtered list, not the whole set', function() {
    // The total drives the tab's number and the pager. Filtering the rows and
    // not the total gives a pager promising pages that come back empty.
    $result = $this->audit->getScannedElements($this->siteId, 1, 500, withIssuesOnly: true);

    expect($result['entries'])->toHaveCount($result['total']);

    foreach ($result['entries'] as $row) {
        $scan = $row['scan'];
        $sum = (int) $scan['errorCount'] + (int) $scan['warningCount'] + (int) $scan['noticeCount'];

        expect($sum)->toBeGreaterThan(0);
    }
});

it('tells a clean site apart from an unscanned one', function() {
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/issues.twig');

    expect($twig)->toContain('{% if scannedCount %}')
        ->and($twig)->toContain('Nothing failing across {n} scanned pages.');

    $controller = (string) file_get_contents(
        dirname(__DIR__, 2) . '/src/controllers/DashboardController.php',
    );

    expect($controller)->toContain('withIssuesOnly: true')
        ->and($controller)->toContain("'scannedCount' => \$scannedCount,");
});
