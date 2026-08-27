<?php

use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Creates a real element (a User) so the scans table's elementId foreign key is
 * satisfied, and returns its element ID.
 */
function makeElementId(): int
{
    return (int) UserFactory::factory()->create()->id;
}

/**
 * Inserts a bare scan row for the given element/site so the edition-limit
 * counters have something to count.
 */
function insertScanRow(int $elementId, int $siteId = 1, ?DateTime $scannedAt = null): void
{
    $now = Db::prepareDateForDb(new DateTime());
    $scanned = Db::prepareDateForDb($scannedAt ?? new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId,
        'elementType' => User::class,
        'siteId' => $siteId,
        'score' => 100,
        'scoreA' => 100,
        'scoreAA' => 100,
        'scoreAAA' => 100,
        'errorCount' => 0,
        'warningCount' => 0,
        'noticeCount' => 0,
        'dateScanned' => $scanned,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();
}

/**
 * Fills the distinct-element scan count up to (and past) the Standard cap with
 * fresh throwaway elements, so a subsequent new element is genuinely over it.
 */
function fillToScanCap(AuditService $service): void
{
    $needed = 100 - $service->getScannedElementCount();
    for ($i = 0; $i < max(0, $needed); $i++) {
        insertScanRow(makeElementId(), 1);
    }
}

// ---------------------------------------------------------------------------
// getScannedElementCount
// ---------------------------------------------------------------------------

describe('AuditService::getScannedElementCount', function() {
    it('counts distinct elements, not scan rows', function() {
        $service = new AuditService();
        $before = $service->getScannedElementCount();

        $a = makeElementId();
        $b = makeElementId();

        // Two scans for element A, one for element B: two new distinct elements.
        insertScanRow($a);
        insertScanRow($a);
        insertScanRow($b);

        expect($service->getScannedElementCount())->toBe($before + 2);
    });

    it('stops counting a page once the site deletes it', function() {
        // Craft soft-deletes, so the elements row stays with a dateDeleted set
        // and the cascade on the scans table never fires: the scan outlives the
        // page. Counted regardless, a site that has scanned and then deleted
        // its way past the Standard limit is refused new scans over pages that
        // no longer exist, which is a paid-for feature withheld on the strength
        // of stale rows.
        $service = new AuditService();
        $before = $service->getScannedElementCount();

        $kept = makeElementId();
        $deleted = makeElementId();

        insertScanRow($kept);
        insertScanRow($deleted);

        expect($service->getScannedElementCount())->toBe($before + 2);

        Craft::$app->getDb()->createCommand()
            ->update(
                '{{%elements}}',
                ['dateDeleted' => Db::prepareDateForDb(new DateTime())],
                ['id' => $deleted],
            )
            ->execute();

        expect($service->getScannedElementCount())->toBe($before + 1);
    });
});

// ---------------------------------------------------------------------------
// canScanNewElement
// ---------------------------------------------------------------------------

describe('AuditService::canScanNewElement', function() {
    it('always allows re-scanning an element that already has a scan row', function() {
        $service = new AuditService();

        $id = makeElementId();
        insertScanRow($id, 1);

        // An already-scanned element is allowed regardless of the cap.
        expect($service->canScanNewElement($id, 1))->toBeTrue();
    });

    it('refuses a genuinely new element once the Standard cap is reached', function() {
        $service = new AuditService();

        // Pro edition never caps, so this branch only applies on Standard.
        if (AccessibilityAudit::getInstance()->is(AccessibilityAudit::EDITION_PRO)) {
            expect($service->canScanNewElement(makeElementId(), 1))->toBeTrue();
            return;
        }

        // Fill the distinct-element count up to (and past) the cap of 100.
        $needed = 100 - $service->getScannedElementCount();
        for ($i = 0; $i < max(0, $needed); $i++) {
            insertScanRow(makeElementId(), 1);
        }

        expect($service->getScannedElementCount())->toBeGreaterThanOrEqual(100);

        // A brand-new, never-scanned element is now refused.
        $newId = makeElementId();
        expect($service->canScanNewElement($newId, 1))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// ensureScan — the scan-write path the overlay uses, so the cap holds there too
// (scanElement enforces the same gate and returns ['limitReached' => true]).
// Forced to Standard so the cap is genuinely exercised regardless of the env's
// installed edition.
// ---------------------------------------------------------------------------

describe('AuditService::ensureScan (edition cap)', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
    });

    it('writes nothing and returns 0 for a new element once the cap is reached', function() {
        $service = new AuditService();
        fillToScanCap($service);

        $newId = makeElementId();
        // A scannable (native) type, so the 0 comes from the cap gate rather
        // than the element-type filter, which is what this test is about.
        $scanId = $service->ensureScan($newId, Entry::class, 1);

        expect($scanId)->toBe(0)
            ->and((int) (new Query())
                ->from('{{%accessibilityaudit_scans}}')
                ->where(['elementId' => $newId])
                ->count())->toBe(0);
    });

    it('still scans an already-scanned element at the cap (re-scan is never capped)', function() {
        $service = new AuditService();

        // Scanned two days ago, so ensureScan won't just return the recent row
        // and instead re-runs the cap gate, which must allow it.
        $existingId = makeElementId();
        insertScanRow($existingId, 1, (new DateTime())->modify('-2 days'));

        fillToScanCap($service);
        expect($service->getScannedElementCount())->toBeGreaterThanOrEqual(100);

        $scanId = $service->ensureScan($existingId, Entry::class, 1);

        expect($scanId)->toBeGreaterThan(0);
    });
});
