<?php

use craft\db\Query;
use johnhenry\accessibilityaudit\jobs\GenerateAltTextJob;
use johnhenry\accessibilityaudit\jobs\ScanElementJob;

// ---------------------------------------------------------------------------
// A billing refusal from the API refuses every queued alt text job the same
// way, so the first one cancels the rest of its batch. The scoping is the
// whole point: it must release only waiting GenerateAltTextJob rows and leave
// every other job in the queue exactly where it was.
// ---------------------------------------------------------------------------

function queuedJobCount(string $needle): int
{
    return (int)(new Query())
        ->from('{{%queue}}')
        ->where(['like', 'job', $needle])
        ->count();
}

it('cancels waiting alt text jobs and nothing else', function() {
    $queue = Craft::$app->getQueue();
    $queue->push(new GenerateAltTextJob(['assetId' => 111]));
    $queue->push(new GenerateAltTextJob(['assetId' => 222]));
    $queue->push(new ScanElementJob(['elementId' => 333, 'elementType' => 'craft\\elements\\Entry', 'siteId' => 1]));

    expect(queuedJobCount('GenerateAltTextJob'))->toBe(2)
        ->and(queuedJobCount('ScanElementJob'))->toBe(1);

    $cancelled = GenerateAltTextJob::cancelPendingJobs();

    expect($cancelled)->toBe(2)
        ->and(queuedJobCount('GenerateAltTextJob'))->toBe(0)
        // The scan job is not this breaker's business.
        ->and(queuedJobCount('ScanElementJob'))->toBe(1);
});

it('returns zero with nothing queued', function() {
    expect(GenerateAltTextJob::cancelPendingJobs())->toBe(0);
});

it('leaves a job a worker has already reserved alone', function() {
    $queue = Craft::$app->getQueue();
    $queue->push(new GenerateAltTextJob(['assetId' => 444]));

    // Simulate a worker mid-run: reserved rows must be left to finish or fail
    // on their own rather than yanked out from under the worker.
    Craft::$app->getDb()->createCommand()->update(
        '{{%queue}}',
        ['dateReserved' => \craft\helpers\Db::prepareDateForDb(new DateTime())],
        ['like', 'job', 'GenerateAltTextJob'],
    )->execute();

    expect(GenerateAltTextJob::cancelPendingJobs())->toBe(0)
        ->and(queuedJobCount('GenerateAltTextJob'))->toBe(1);
});
