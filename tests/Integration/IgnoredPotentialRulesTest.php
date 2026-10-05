<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\jobs\HeadlessScanJob;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\HeadlessScanner;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Ignored Rules covers the questions as well as the failures.
//
// A potential rule on the ignore list is muted twice over: a scan does not
// store it, and rows stored before it was ignored drop out of the review
// queues that read through pendingPotentialCondition().
// ---------------------------------------------------------------------------

function ignoredPotentialScan(int $elementId, int $siteId): int
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

function ignoredPotentialIssue(int $scanId, int $elementId, int $siteId, string $ruleId): void
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_issues}}', [
        'scanId' => $scanId, 'elementId' => $elementId, 'elementType' => User::class,
        'siteId' => $siteId, 'ruleId' => $ruleId,
        'wcagCriterion' => '1.3.1', 'wcagLevel' => 'A',
        'severity' => 'notice', 'message' => 'q', 'context' => '<p class="q">', 'source' => 'php',
        'isResolved' => false, 'firstDetected' => $now,
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
    ])->execute();
}

function setIgnoredPotentialRules(array $ruleIds): void
{
    AccessibilityAudit::getInstance()->getSettings()->ignoreRules = $ruleIds;
}

/** Stores one row for each keyboard walk question on a scan. */
function ignoredPotentialWalk(AuditService $audit, int $scanId): void
{
    $audit->storeFocusWalkIssues($scanId, [
        'ran' => true,
        'focusVisible' => true,
        'total' => 4,
        'limit' => 150,
        'checked' => 4,
        'notVisible' => [['html' => '<a href="/a">']],
        'obscured' => [['html' => '<div class="bar">', 'position' => 'fixed', 'count' => 1]],
    ]);
}

/** @return string[] The keyboard walk rules stored on a scan. */
function ignoredPotentialWalkRules(int $scanId): array
{
    return (new Query())->select(['ruleId'])->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId, 'ruleId' => AuditService::FOCUS_WALK_RULES])
        ->orderBy(['ruleId' => SORT_ASC])
        ->column();
}

beforeEach(function() {
    $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $this->elementId = (int) UserFactory::factory()->create()->id;
    $this->scanId = ignoredPotentialScan($this->elementId, $this->siteId);
    $this->audit = AccessibilityAudit::getInstance()->getAudit();

    ignoredPotentialIssue($this->scanId, $this->elementId, $this->siteId, 'potential:possible-heading');
    ignoredPotentialIssue($this->scanId, $this->elementId, $this->siteId, 'potential:table-layout');
});

it('drops an ignored question off the page report', function() {
    expect(array_column($this->audit->getPendingPotentialForScan($this->scanId), 'ruleId'))
        ->toContain('potential:possible-heading');

    setIgnoredPotentialRules(['potential:possible-heading']);

    $rules = array_column($this->audit->getPendingPotentialForScan($this->scanId), 'ruleId');

    expect($rules)->not->toContain('potential:possible-heading')
        ->and($rules)->toContain('potential:table-layout');
});

it('drops an ignored question off the Potential page', function() {
    expect(array_column($this->audit->getPotentialIssues($this->siteId), 'ruleId'))
        ->toContain('potential:possible-heading');

    setIgnoredPotentialRules(['potential:possible-heading']);

    $rules = array_column($this->audit->getPotentialIssues($this->siteId), 'ruleId');

    expect($rules)->not->toContain('potential:possible-heading')
        ->and($rules)->toContain('potential:table-layout');
});

it('leaves the review queue alone when nothing is ignored', function() {
    setIgnoredPotentialRules([]);

    expect($this->audit->getPendingPotentialForScan($this->scanId))->toHaveCount(2);
});

it('does not raise an ignored question in the PHP scan', function() {
    $html = '<!DOCTYPE html><html lang="en"><body><main><img src="/a.jpg" alt="ab"></main></body></html>';
    $scanner = AccessibilityAudit::getInstance()->getPotential();

    expect(array_map(static fn($i) => $i->ruleId, $scanner->scan($html)))->toContain('potential:short-alt')
        ->and(array_map(static fn($i) => $i->ruleId, $scanner->scan($html, ['potential:short-alt'])))
        ->not->toContain('potential:short-alt');
});

it('hands the ignore list to the PHP scan on both scan paths', function() {
    $source = (string) file_get_contents((new ReflectionClass(AuditService::class))->getFileName());

    expect($source)->toContain('$plugin->getPotential()->scan($html, $this->_ignoredRuleIds());')
        ->and($source)->toContain('getPotential()->scan($html, $ignoreRules);');
});

it('does not carry an ignored browser question onto the next scan', function() {
    ignoredPotentialWalk($this->audit, $this->scanId);
    setIgnoredPotentialRules([AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE]);

    $createScan = new ReflectionMethod($this->audit, '_createScan');
    $createScan->setAccessible(true);
    $newScanId = (int) $createScan->invoke($this->audit, $this->elementId, User::class, $this->siteId, [], []);

    expect(ignoredPotentialWalkRules($newScanId))->toBe([AuditService::RULE_POTENTIAL_FOCUS_OBSCURED]);
});

describe('a browser pass that skips the keyboard walk', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

        // Stands in for Chrome: a desktop pass with nothing to report, and no
        // walk, as when no walk rule applies.
        AccessibilityAudit::getInstance()->set('headless', new class() extends HeadlessScanner {
            public function isAvailable(): bool
            {
                return true;
            }

            public function scanUrlViewports(string $url, array $viewports): array
            {
                return [AuditService::VIEWPORT_DESKTOP => ['violations' => [], 'incomplete' => []]];
            }
        });

        ignoredPotentialWalk($this->audit, $this->scanId);
    });

    afterEach(function() {
        AccessibilityAudit::getInstance()->set('headless', HeadlessScanner::class);
    });

    it('clears the walk questions when both walk rules are ignored', function() {
        setIgnoredPotentialRules(AuditService::FOCUS_WALK_RULES);

        (new HeadlessScanJob(['scanId' => $this->scanId, 'url' => 'https://example.test/walk']))
            ->execute(Craft::$app->getQueue());

        expect(ignoredPotentialWalkRules($this->scanId))->toBe([]);
    });

    it('clears the walk questions on a site targeting Level A', function() {
        AccessibilityAudit::getInstance()->getSettings()->wcagLevel = 'A';

        (new HeadlessScanJob(['scanId' => $this->scanId, 'url' => 'https://example.test/walk']))
            ->execute(Craft::$app->getQueue());

        expect(ignoredPotentialWalkRules($this->scanId))->toBe([]);
    });

    it('keeps them when a walk rule still applies and the walk failed', function() {
        (new HeadlessScanJob(['scanId' => $this->scanId, 'url' => 'https://example.test/walk']))
            ->execute(Craft::$app->getQueue());

        expect(ignoredPotentialWalkRules($this->scanId))->toHaveCount(2);
    });
});

it('does not store an ignored contrast question from the browser pass', function() {
    $incomplete = [[
        'id' => 'color-contrast',
        'nodes' => [[
            'html' => '<span class="badge">Sale</span>',
            'target' => ['span'],
            'any' => [['data' => ['messageKey' => 'bgImage', 'expectedContrastRatio' => '4.5:1']]],
        ]],
    ]];

    setIgnoredPotentialRules([AuditService::RULE_POTENTIAL_CONTRAST]);
    $this->audit->storeAxeIssues($this->scanId, [], AuditService::VIEWPORT_DESKTOP, $incomplete);

    $stored = (new Query())->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $this->scanId, 'ruleId' => AuditService::RULE_POTENTIAL_CONTRAST])
        ->count();

    expect((int) $stored)->toBe(0);
});
