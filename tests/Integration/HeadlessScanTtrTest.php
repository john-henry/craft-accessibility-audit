<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\jobs\HeadlessScanJob;
use johnhenry\accessibilityaudit\services\HeadlessScanner;
use yii\queue\RetryableJobInterface;

// ---------------------------------------------------------------------------
// A job that outlives its reservation is not lost, it is restarted: the queue
// hands it to the next worker and it begins again from the top. For a browser
// pass that means Chrome launched for the same page over and over, for as long
// as the page stays slow, and the sweep never moves on.
//
// The batched sweeps were given honest reservations already. This one drives
// Chrome twice, once per viewport, and every call it makes is bounded, so the
// total it can take is arithmetic rather than a guess.
// ---------------------------------------------------------------------------

/**
 * The reservation the queue will actually give this job.
 *
 * Mirrors yii\queue\Queue::push(): a job is asked for its own ttr only when
 * it implements RetryableJobInterface, and otherwise gets the queue's. Read
 * this way so a test cannot pass on a getTtr() nothing would ever call.
 */
function effectiveTtr(object $job): int
{
    return $job instanceof RetryableJobInterface
        ? $job->getTtr()
        : (int)Craft::$app->getQueue()->ttr;
}

function headlessJob(): HeadlessScanJob
{
    return new HeadlessScanJob(['scanId' => 1, 'url' => 'https://example.com/']);
}

it('is reserved for as long as the scan can actually take', function() {
    expect(effectiveTtr(headlessJob()))
        ->toBeGreaterThanOrEqual(HeadlessScanner::worstCaseScanSeconds());
});

it('is reserved for longer than the queue would give it on its own', function() {
    // The whole point. Craft reserves for 300 seconds by default and a full
    // pass over two viewports cannot fit in that, so the job was guaranteed to
    // lapse on any page slow enough to need the time.
    expect(HeadlessScanner::worstCaseScanSeconds())->toBeGreaterThan(300)
        ->and(effectiveTtr(headlessJob()))->toBeGreaterThan(300);
});

it('is never reserved for less than the queue is configured to', function() {
    // An install that raised the queue's own ttr did it for a reason, and a
    // job handing back a smaller number would undo that.
    expect(effectiveTtr(headlessJob()))
        ->toBeGreaterThanOrEqual((int)Craft::$app->getQueue()->ttr);
});

it('does not retry, as it did not before', function() {
    // Implementing RetryableJobInterface takes the retry decision away from the
    // queue's attempts setting, so it has to answer the same way that did.
    expect(headlessJob()->canRetry(1, new Exception('boom')))->toBeFalse();
});

it('counts the browser bounds rather than restating a number', function() {
    // If a viewport is added, or a round trip, the reservation follows.
    $floor = count(HeadlessScanner::VIEWPORTS) * (int)(HeadlessScanner::MAX_SETTLE_MS / 1000);

    expect(HeadlessScanner::worstCaseScanSeconds())->toBeGreaterThan($floor);
});
