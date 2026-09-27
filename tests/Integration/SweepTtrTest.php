<?php

use johnhenry\accessibilityaudit\helpers\UrlSafety;
use johnhenry\accessibilityaudit\jobs\AnalyseReadability;
use johnhenry\accessibilityaudit\jobs\ScanElements;

// ---------------------------------------------------------------------------
// How long a sweep step says it needs
//
// Craft refreshes a batched job's reservation every time it moves to the next
// item, so the time to run only has to cover one page, and it also decides
// when Craft splits a batch before running out of time. A time to run sized
// for a whole batch switches that splitting off, and on hosts that kill a job
// at a fixed limit (Craft Cloud's fifteen minutes) a slow batch is killed and
// restarted from the same page.
//
// Helper names carry a `ttr` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** Every batched job that fetches a page per item. */
function ttrFetchingJobs(): array
{
    return [ScanElements::class, AnalyseReadability::class];
}

it('keeps the queue\'s own time to run, so Craft can still split a batch', function() {
    $queueDefault = (int) Craft::$app->getQueue()->ttr;

    foreach (ttrFetchingJobs() as $class) {
        expect((int) (new $class())->ttr)->toBe($queueDefault, $class . ' sets its own time to run');
    }
});

it('leaves room for one page to time out', function() {
    foreach (ttrFetchingJobs() as $class) {
        expect((int) (new $class())->ttr)->toBeGreaterThan(UrlSafety::FETCH_TIMEOUT);
    }
});

it('leaves a time to run it was handed alone', function() {
    foreach (ttrFetchingJobs() as $class) {
        expect((new $class(['ttr' => 4321]))->ttr)->toBe(4321);
    }
});
