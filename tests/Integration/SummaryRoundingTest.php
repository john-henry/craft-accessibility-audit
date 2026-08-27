<?php

use craft\elements\Entry;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The Overview's Level A / AA figures are page scores averaged across the site.
// On a large site a few failing pages move that mean by a fraction of a point,
// and ordinary rounding lands it on 100 while the card underneath still reads
// "3 criteria failing".
//
// A tool that publishes accessibility statements cannot show a green 100 on
// evidence its own statement page would refuse to call compliant, so the
// average rounds down onto 99 rather than up onto 100.
// ---------------------------------------------------------------------------

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());

    // The summary averages every scan on the site, so the fixture database's
    // own scans would swamp the handful each test writes. Cleared inside the
    // test transaction, so it rolls back with everything else.
    Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_issues}}')->execute();
    Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_scans}}')->execute();
});

/** Writes a scan row with the given level scores and returns its id. */
function scanWithScores(Entry $entry, int $score, int $scoreA, int $scoreAA): int
{
    $audit = AccessibilityAudit::getInstance()->getAudit();
    $scanId = $audit->ensureScan((int)$entry->id, Entry::class, (int)$entry->siteId);

    Craft::$app->getDb()->createCommand()->update(
        '{{%accessibilityaudit_scans}}',
        ['score' => $score, 'scoreA' => $scoreA, 'scoreAA' => $scoreAA],
        ['id' => $scanId],
    )->execute();

    return $scanId;
}

it('does not round a shade under full marks up to 100', function() {
    // One page a point short, many perfect: the true mean is just under 100.
    $siteId = null;

    for ($i = 0; $i < 40; $i++) {
        $entry = scannableEntry();
        $siteId ??= (int)$entry->siteId;
        scanWithScores($entry, 100, 100, 100);
    }

    $short = scannableEntry();
    scanWithScores($short, 96, 96, 96);

    $summary = AccessibilityAudit::getInstance()->getAudit()->getSiteSummary($siteId);

    expect($summary['avgScore'])->toBe(99)
        ->and($summary['avgScoreA'])->toBe(99)
        ->and($summary['avgScoreAA'])->toBe(99);
});

it('still shows 100 when every page really is perfect', function() {
    // The clamp must not cost an honest full score.
    $siteId = null;

    for ($i = 0; $i < 5; $i++) {
        $entry = scannableEntry();
        $siteId ??= (int)$entry->siteId;
        scanWithScores($entry, 100, 100, 100);
    }

    $summary = AccessibilityAudit::getInstance()->getAudit()->getSiteSummary($siteId);

    expect($summary['avgScore'])->toBe(100)
        ->and($summary['avgScoreA'])->toBe(100)
        ->and($summary['avgScoreAA'])->toBe(100);
});

it('leaves rounding alone everywhere below the full-marks boundary', function() {
    // Only the 100 boundary is special: 89.6 still rounds to 90 as before.
    $siteId = null;

    foreach ([88, 90, 92] as $score) {
        $entry = scannableEntry();
        $siteId ??= (int)$entry->siteId;
        scanWithScores($entry, $score, $score, $score);
    }

    $summary = AccessibilityAudit::getInstance()->getAudit()->getSiteSummary($siteId);

    expect($summary['avgScore'])->toBe(90);
});
