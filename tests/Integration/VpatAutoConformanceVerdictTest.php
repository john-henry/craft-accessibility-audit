<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\VerdictService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The statement has to be able to show its working.
//
// Conformance levels were read straight off the issues table with no filtering
// at all: no isResolved, no verdict. So a potential issue the author had
// answered still counted, and a fixed one still counted, and the criterion
// showed as Partially Supports on the statement with nothing behind it on the
// Issues screen. On a real site the statement said three failing criteria while
// Issues listed one rule, and the other two were a heading question and four
// identical-link questions, all dismissed weeks earlier. Nothing on either
// screen could have told you that.
//
// This is the only read of that table that was not going through
// definiteCondition(). A criterion the author has ruled on is answered, and a
// statement is a legal claim: it does not get to count evidence the rest of the
// plugin agrees is spent.
// ---------------------------------------------------------------------------

function vpatScan(int $elementId, int $siteId): int
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId, 'elementType' => User::class, 'siteId' => $siteId,
        'score' => 100, 'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
        'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 0,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) Craft::$app->getDb()->getLastInsertID('{{%accessibilityaudit_scans}}');
}

function vpatIssue(int $scanId, int $elementId, int $siteId, array $overrides = []): void
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_issues}}', array_merge([
        'scanId' => $scanId, 'elementId' => $elementId, 'elementType' => User::class,
        'siteId' => $siteId, 'ruleId' => 'potential:possible-heading',
        'wcagCriterion' => '1.3.1', 'wcagLevel' => 'A',
        'severity' => 'notice', 'message' => 'q', 'context' => '<p>', 'source' => 'php',
        'isResolved' => false, 'firstDetected' => $now,
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
    ], $overrides))->execute();
}

beforeEach(function() {
    $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $this->elementId = (int) UserFactory::factory()->create()->id;
    $this->scanId = vpatScan($this->elementId, $this->siteId);
    $this->vpat = AccessibilityAudit::getInstance()->vpat;

    // The development database this runs against carries real findings, and
    // 1.3.1 is the busiest criterion in the set, so the row under test would be
    // judging a verdict it did not write. Rolled back with everything else.
    Craft::$app->getDb()->createCommand()
        ->delete('{{%accessibilityaudit_issues}}', ['wcagCriterion' => '1.3.1'])
        ->execute();
});

it('does not fail a criterion on a question the author has dismissed', function() {
    vpatIssue($this->scanId, $this->elementId, $this->siteId, [
        'verdict' => VerdictService::VERDICT_DISMISSED,
    ]);

    expect($this->vpat->getAutoConformance($this->siteId)['1.3.1']['level'] ?? 'Supports')
        ->not->toBe('Partially Supports');
});

it('does fail a criterion on a question the author has confirmed', function() {
    vpatIssue($this->scanId, $this->elementId, $this->siteId, [
        'verdict' => VerdictService::VERDICT_CONFIRMED,
    ]);

    expect($this->vpat->getAutoConformance($this->siteId)['1.3.1']['level'])
        ->toBe('Partially Supports');
});

it('does not fail a criterion on a question nobody has answered yet', function() {
    // An unanswered question is not evidence of a failure, which is why it
    // does not move the score either.
    vpatIssue($this->scanId, $this->elementId, $this->siteId);

    expect($this->vpat->getAutoConformance($this->siteId)['1.3.1']['level'] ?? 'Supports')
        ->not->toBe('Partially Supports');
});

it('does not fail a criterion on an issue that has been fixed', function() {
    vpatIssue($this->scanId, $this->elementId, $this->siteId, [
        'ruleId' => 'empty-heading', 'severity' => 'error', 'isResolved' => true,
    ]);

    expect($this->vpat->getAutoConformance($this->siteId)['1.3.1']['level'] ?? 'Supports')
        ->not->toBe('Does Not Support');
});

it('still fails a criterion on a live definite issue', function() {
    vpatIssue($this->scanId, $this->elementId, $this->siteId, [
        'ruleId' => 'empty-heading', 'severity' => 'error',
    ]);

    expect($this->vpat->getAutoConformance($this->siteId)['1.3.1']['level'])
        ->toBe('Does Not Support');
});

it('keeps every URL-scanned page rather than folding them into one', function() {
    // Every URL scan carries a null elementId, so grouping the latest scans on
    // that alone leaves one row and throws away the findings of every other
    // page scanned by address. getLatestScanIds already learned this.
    $source = (string) file_get_contents(
        (new ReflectionClass(\johnhenry\accessibilityaudit\services\VpatService::class))->getFileName(),
    );

    $start = (int) strpos($source, 'public function getAutoConformance(');
    $body = substr($source, $start, 1600);

    expect($body)->toContain("->groupBy(['elementId', 'url'])")
        ->and($body)->toContain("'isResolved' => false")
        ->and($body)->toContain('definiteCondition()');
});
