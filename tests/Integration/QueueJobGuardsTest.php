<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\jobs\HeadlessScanJob;

// ---------------------------------------------------------------------------
// A queue job that throws fails the job, and a failed job is a red banner in
// every admin's control panel until somebody clears it. These jobs run long
// after they were queued, by which time the scan may be pruned, the settings
// changed, or the edition downgraded, so every one of those has to end the job
// quietly rather than loudly.
//
// The work itself needs a browser and is covered by HeadlessScannerTest. What
// is covered here is everything the job decides before it gets there.
// ---------------------------------------------------------------------------

/** A scan row to point a job at, returning its id. */
function guardScanId(): int
{
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => null,
        'elementType' => null,
        'url' => 'https://example.test/guard',
        'title' => 'Guard',
        'siteId' => (int) Craft::$app->getSites()->getPrimarySite()->id,
        'score' => 100,
        'scoreA' => 100,
        'scoreAA' => 100,
        'scoreAAA' => 100,
        'errorCount' => 0,
        'warningCount' => 0,
        'noticeCount' => 0,
        'dateScanned' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');
}

describe('HeadlessScanJob guards', function() {
    it('ends quietly when it was queued with nothing to do', function() {
        $job = new HeadlessScanJob(['scanId' => 0, 'url' => '']);

        expect(fn() => $job->execute(Craft::$app->getQueue()))->not->toThrow(Throwable::class);
    });

    it('ends quietly when it has a URL but no scan to store against', function() {
        $job = new HeadlessScanJob(['scanId' => 0, 'url' => 'https://example.test/']);

        expect(fn() => $job->execute(Craft::$app->getQueue()))->not->toThrow(Throwable::class);
    });

    it('ends quietly when the scan was pruned after it was queued', function() {
        // A long queue and a short retention period is an ordinary thing to
        // have, not an error to report.
        $scanId = guardScanId();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%accessibilityaudit_scans}}', ['id' => $scanId])
            ->execute();

        $job = new HeadlessScanJob(['scanId' => $scanId, 'url' => 'https://example.test/guard']);

        expect(fn() => $job->execute(Craft::$app->getQueue()))->not->toThrow(Throwable::class);
    });

    it('writes nothing when it cannot run', function() {
        // Whatever stops it, it must not leave a half-written scan behind: the
        // PHP findings already stored are the ones that stand.
        $scanId = guardScanId();
        $before = (int) (new Query())
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId])
            ->count();

        (new HeadlessScanJob(['scanId' => $scanId, 'url' => 'https://example.test/guard']))
            ->execute(Craft::$app->getQueue());

        $after = (int) (new Query())
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId])
            ->count();

        expect($after)->toBe($before);
    });

    it('describes itself for the queue listing', function() {
        // A job with no description shows as a bare class name in the CP.
        $job = new HeadlessScanJob(['scanId' => 1, 'url' => 'https://example.test/']);

        expect(trim((string) $job->getDescription()))->not->toBeEmpty();
    });
});
