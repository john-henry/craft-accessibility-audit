<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\migrations\m260830_090000_restable_verdict_keys;
use johnhenry\accessibilityaudit\services\VerdictService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Rescuing rulings made before ids left the key.
//
// A ruling keyed on markup carrying a regenerated id can never be found again:
// the key was built from a string nobody has a copy of. Taking ids out of the
// key fixes every ruling from here on and none of the ones already stored, so
// anybody upgrading would find their dismissals quietly dead.
//
// The occurrences still hold the markup, which is what makes the rescue
// possible at all.
// ---------------------------------------------------------------------------

/** A contact-form textarea as one render of the page produces it. */
function formieTextarea(string $token): string
{
    return '<textarea id="fui-contactForm-' . $token . '-fields-message" class="fui-input" '
        . 'name="fields[message]" placeholder="Max. 500 characters"></textarea>';
}

/** A scan carrying one potential issue, and the scan's id. */
function rekeyScanWith(int $elementId, int $siteId, string $context): int
{
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId, 'elementType' => User::class, 'siteId' => $siteId,
        'score' => 100, 'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
        'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 1,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    $scanId = (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');

    $db->createCommand()->insert('{{%accessibilityaudit_issues}}', [
        'scanId' => $scanId, 'elementId' => $elementId, 'elementType' => User::class,
        'siteId' => $siteId, 'ruleId' => 'potential:contrast-unmeasurable',
        'severity' => 'notice', 'message' => 'q', 'context' => $context, 'source' => 'axe',
        'isResolved' => false, 'firstDetected' => $now,
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
    ])->execute();

    return $scanId;
}

/** A ruling stored the old way, keyed on the raw markup. */
function storeRawVerdict(int $elementId, int $siteId, string $context): string
{
    $verdicts = new VerdictService();
    $hash = $verdicts->contextHash($context);
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_verdicts}}', [
        'targetHash' => $verdicts->targetHash($elementId),
        'elementId' => $elementId, 'siteId' => $siteId,
        'ruleId' => 'potential:contrast-unmeasurable',
        'contextHash' => $hash, 'verdict' => VerdictService::VERDICT_DISMISSED,
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
    ])->execute();

    return $hash;
}

/** The hashes recorded against one element. */
function verdictHashes(int $elementId): array
{
    return (new Query())->select(['contextHash'])
        ->from('{{%accessibilityaudit_verdicts}}')
        ->where(['elementId' => $elementId, 'ruleId' => 'potential:contrast-unmeasurable'])
        ->column();
}

beforeEach(function() {
    $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $this->elementId = (int) UserFactory::factory()->create()->id;
    $this->verdicts = new VerdictService();
});

it('moves a ruling onto the key the scanner now uses', function() {
    $context = formieTextarea('npsmjc');

    rekeyScanWith($this->elementId, $this->siteId, $context);
    $raw = storeRawVerdict($this->elementId, $this->siteId, $context);

    expect(verdictHashes($this->elementId))->toBe([$raw]);

    (new m260830_090000_restable_verdict_keys())->safeUp();

    expect(verdictHashes($this->elementId))->toBe([$this->verdicts->stableContextHash($context)]);
});

it('makes the rescued ruling match a later render of the same field', function() {
    // The whole point. The field comes back with a different token every scan,
    // and the ruling has to survive that.
    $context = formieTextarea('npsmjc');

    rekeyScanWith($this->elementId, $this->siteId, $context);
    storeRawVerdict($this->elementId, $this->siteId, $context);
    (new m260830_090000_restable_verdict_keys())->safeUp();

    $map = $this->verdicts->mapForElement($this->elementId, $this->siteId);

    expect($this->verdicts->lookup($map, 'potential:contrast-unmeasurable', formieTextarea('hzebiw')))
        ->toBe(VerdictService::VERDICT_DISMISSED);
});

it('merges an old row into a newer one rather than colliding with it', function() {
    // Somebody who dismissed again after upgrading has both keys stored. The
    // unique index would refuse the move, and the newer answer is the current
    // one, so the stale row goes.
    $context = formieTextarea('npsmjc');

    rekeyScanWith($this->elementId, $this->siteId, $context);
    storeRawVerdict($this->elementId, $this->siteId, $context);

    $now = Db::prepareDateForDb(new DateTime());
    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_verdicts}}', [
        'targetHash' => $this->verdicts->targetHash($this->elementId),
        'elementId' => $this->elementId, 'siteId' => $this->siteId,
        'ruleId' => 'potential:contrast-unmeasurable',
        'contextHash' => $this->verdicts->stableContextHash($context),
        'verdict' => VerdictService::VERDICT_DISMISSED,
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
    ])->execute();

    expect(verdictHashes($this->elementId))->toHaveCount(2);

    (new m260830_090000_restable_verdict_keys())->safeUp();

    expect(verdictHashes($this->elementId))->toBe([$this->verdicts->stableContextHash($context)]);
});

it('leaves markup with no id in it alone', function() {
    // Most of a site. Both hashes agree, so there is nothing to move and no
    // reason to touch the row.
    $context = '<p class="mt-3 text-center">Plain markup, no id anywhere</p>';

    rekeyScanWith($this->elementId, $this->siteId, $context);
    $raw = storeRawVerdict($this->elementId, $this->siteId, $context);

    (new m260830_090000_restable_verdict_keys())->safeUp();

    expect(verdictHashes($this->elementId))->toBe([$raw]);
});

it('can be run twice without undoing itself', function() {
    // Migrations get re-run on other environments, and a second pass must find
    // nothing left to do rather than move the row somewhere else.
    $context = formieTextarea('npsmjc');

    rekeyScanWith($this->elementId, $this->siteId, $context);
    storeRawVerdict($this->elementId, $this->siteId, $context);

    (new m260830_090000_restable_verdict_keys())->safeUp();
    $after = verdictHashes($this->elementId);
    (new m260830_090000_restable_verdict_keys())->safeUp();

    expect(verdictHashes($this->elementId))->toBe($after);
});
