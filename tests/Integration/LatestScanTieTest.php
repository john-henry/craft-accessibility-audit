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
// Which scan the report is looking at.
//
// dateScanned is stored to the second, and a re-scan writes its own row while
// the queued browser pass is still working on the same page, so two scans of
// one page land in the same second as a matter of course. Ordered on the date
// alone the database may hand back either, and the older one predates anything
// answered since: a question already settled reappears, and the page looks
// like it is ignoring the answer.
//
// Fixed rows in one second, so a run that picks by chance fails here rather
// than in front of somebody.
// ---------------------------------------------------------------------------

/** Several scans of one page, all stamped the same second. Newest id last. */
function scansInOneSecond(int $elementId, int $siteId, int $count): array
{
    $stamp = Db::prepareDateForDb(new DateTime('2026-08-30 09:15:00'));
    $db = Craft::$app->getDb();
    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
            'elementId' => $elementId,
            'elementType' => User::class,
            'siteId' => $siteId,
            'score' => 50 + $i,
            'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
            'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 0,
            'dateScanned' => $stamp,
            'dateCreated' => $stamp,
            'dateUpdated' => $stamp,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $ids[] = (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');
    }

    return $ids;
}

beforeEach(function() {
    $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $this->elementId = (int) UserFactory::factory()->create()->id;
    $this->audit = new AuditService();
});

it('takes the newest of several scans sharing a second', function() {
    $ids = scansInOneSecond($this->elementId, $this->siteId, 4);

    $scan = $this->audit->getLatestScan($this->elementId, $this->siteId);

    expect($scan)->not->toBeNull()
        ->and((int) $scan['id'])->toBe(end($ids));
});

it('gives the same answer every time it is asked', function() {
    // The failure this guards is intermittent by nature: without a tiebreak
    // the database may return a different row on each run.
    scansInOneSecond($this->elementId, $this->siteId, 5);

    $seen = [];

    foreach (range(1, 8) as $ignored) {
        $seen[] = (int) $this->audit->getLatestScan($this->elementId, $this->siteId)['id'];
    }

    expect(array_unique($seen))->toHaveCount(1);
});

it('still prefers a later second over a higher id', function() {
    // The date leads and the id only settles a tie. A scan from a minute ago
    // does not beat one from just now because its row went in first.
    $ids = scansInOneSecond($this->elementId, $this->siteId, 3);

    $later = Db::prepareDateForDb(new DateTime('2026-08-30 09:16:00'));
    Craft::$app->getDb()->createCommand()->update(
        '{{%accessibilityaudit_scans}}',
        ['dateScanned' => $later],
        ['id' => $ids[0]],
    )->execute();

    expect((int) $this->audit->getLatestScan($this->elementId, $this->siteId)['id'])->toBe($ids[0]);
});

it('breaks the tie everywhere the latest scan for a page is read', function() {
    // One place left without it would put the inconsistency back, and it would
    // show up as a page whose report and score disagree.
    $source = (string) file_get_contents((new ReflectionClass(AuditService::class))->getFileName());

    $withTiebreak = substr_count($source, "orderBy(['dateScanned' => SORT_DESC, 'id' => SORT_DESC])");
    $without = substr_count($source, "orderBy(['dateScanned' => SORT_DESC])");

    expect($withTiebreak)->toBe(5)
        // The one left alone reads a date across a whole site rather than
        // picking a row, so a tie has nothing to decide.
        ->and($without)->toBe(1);
});
