<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\VpatService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Each scan records which keyboard walk questions its browser pass measured.
//
// The VPAT claims the 2.4.7 and 2.4.11 checks only for pages whose latest scan
// records them, so the record has to follow the walk exactly: set by a walk
// that ran, cleared by a walk skipped because no question applies, untouched
// by a walk that failed, and carried onto a PHP re-scan with the walk's rows.
// ---------------------------------------------------------------------------

function focusRecordScan(int $elementId, bool $visible = false, bool $obscured = false): int
{
    $now = Db::prepareDateForDb(new DateTime('-1 hour'));

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId, 'elementType' => User::class,
        'siteId' => (int) Craft::$app->getSites()->getPrimarySite()->id,
        'score' => 100, 'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
        'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 0,
        'focusVisibleChecked' => $visible, 'focusObscuredChecked' => $obscured,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) Craft::$app->getDb()->getLastInsertID('{{%accessibilityaudit_scans}}');
}

/** @return array{visible: bool, obscured: bool} */
function focusRecordFlags(int $scanId): array
{
    $row = (new Query())
        ->select(['focusVisibleChecked', 'focusObscuredChecked'])
        ->from('{{%accessibilityaudit_scans}}')
        ->where(['id' => $scanId])
        ->one();

    return ['visible' => (bool) $row['focusVisibleChecked'], 'obscured' => (bool) $row['focusObscuredChecked']];
}

function focusRecordWalk(array $overrides = []): array
{
    return array_merge([
        'ran' => true,
        'focusVisible' => true,
        'total' => 4,
        'limit' => 150,
        'notVisible' => [['html' => '<a href="/a" id="plain">']],
        'obscured' => [],
    ], $overrides);
}

beforeEach(function() {
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $settings->ignoreRules = [];
    $settings->wcagLevel = 'AA';

    $this->audit = AccessibilityAudit::getInstance()->getAudit();
    $this->elementId = (int) UserFactory::factory()->create()->id;
});

describe('storing the walk', function() {
    it('records both questions for a walk that ran with keyboard focus confirmed', function() {
        $scanId = focusRecordScan($this->elementId);

        $this->audit->storeFocusWalkIssues($scanId, focusRecordWalk());

        expect(focusRecordFlags($scanId))->toBe(['visible' => true, 'obscured' => true]);
    });

    it('records only 2.4.11 where the page did not confirm it treated the focus as keyboard focus', function() {
        $refused = focusRecordScan($this->elementId, true, false);
        $unknown = focusRecordScan((int) UserFactory::factory()->create()->id, true, false);

        $this->audit->storeFocusWalkIssues($refused, focusRecordWalk(['focusVisible' => false]));
        $this->audit->storeFocusWalkIssues($unknown, focusRecordWalk(['focusVisible' => null]));

        expect(focusRecordFlags($refused))->toBe(['visible' => false, 'obscured' => true])
            ->and(focusRecordFlags($unknown))->toBe(['visible' => false, 'obscured' => true]);
    });

    it('leaves the record alone for a walk that did not run', function() {
        $scanId = focusRecordScan($this->elementId, true, true);
        $unwalked = focusRecordScan((int) UserFactory::factory()->create()->id);

        $this->audit->storeFocusWalkIssues($scanId, focusRecordWalk(['ran' => false]));
        $this->audit->storeFocusWalkIssues($scanId, null);
        $this->audit->storeFocusWalkIssues($unwalked, focusRecordWalk(['ran' => false]));

        expect(focusRecordFlags($scanId))->toBe(['visible' => true, 'obscured' => true])
            ->and(focusRecordFlags($unwalked))->toBe(['visible' => false, 'obscured' => false]);
    });

    it('records neither question when the walk is skipped because none applies', function() {
        $scanId = focusRecordScan($this->elementId, true, true);

        $this->audit->clearFocusWalkIssues($scanId);

        expect(focusRecordFlags($scanId))->toBe(['visible' => false, 'obscured' => false]);
    });

    it('records only the question that applies when the other is ignored', function() {
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $first = focusRecordScan($this->elementId);
        $second = focusRecordScan((int) UserFactory::factory()->create()->id);

        $settings->ignoreRules = [AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE];
        $this->audit->storeFocusWalkIssues($first, focusRecordWalk());

        $settings->ignoreRules = [AuditService::RULE_POTENTIAL_FOCUS_OBSCURED];
        $this->audit->storeFocusWalkIssues($second, focusRecordWalk());

        expect(focusRecordFlags($first))->toBe(['visible' => false, 'obscured' => true])
            ->and(focusRecordFlags($second))->toBe(['visible' => true, 'obscured' => false]);
    });
});

describe('a PHP re-scan', function() {
    it('carries the record onto a re-scanned element', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $scanId = focusRecordScan($this->elementId, true, false);

        $createScan = new ReflectionMethod($this->audit, '_createScan');
        $newScanId = (int) $createScan->invoke($this->audit, $this->elementId, User::class, $siteId, [], []);

        expect($newScanId)->not->toBe($scanId)
            ->and(focusRecordFlags($newScanId))->toBe(['visible' => true, 'obscured' => false]);
    });

    it('carries the record onto a re-scanned URL', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $url = 'https://example.test/walk?q=' . StringHelper::randomString(6);

        $createUrlScan = new ReflectionMethod($this->audit, '_createUrlScan');
        $scanId = (int) $createUrlScan->invoke($this->audit, $url, $siteId, 'Walk', [], []);

        $this->audit->storeFocusWalkIssues($scanId, focusRecordWalk(['focusVisible' => false]));
        $newScanId = (int) $createUrlScan->invoke($this->audit, $url, $siteId, 'Walk', [], []);

        expect($newScanId)->not->toBe($scanId)
            ->and(focusRecordFlags($newScanId))->toBe(['visible' => false, 'obscured' => true]);
    });

    it('starts a page scanned for the first time with nothing recorded', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        $createScan = new ReflectionMethod($this->audit, '_createScan');
        $createUrlScan = new ReflectionMethod($this->audit, '_createUrlScan');

        $elementScan = (int) $createScan->invoke($this->audit, $this->elementId, User::class, $siteId, [], []);
        $urlScan = (int) $createUrlScan->invoke($this->audit, 'https://example.test/new?q=' . StringHelper::randomString(6), $siteId, 'New', [], []);

        expect(focusRecordFlags($elementScan))->toBe(['visible' => false, 'obscured' => false])
            ->and(focusRecordFlags($urlScan))->toBe(['visible' => false, 'obscured' => false]);
    });
});

// ---------------------------------------------------------------------------
// The VPAT claims the walk only for the pages it covered.
//
// The latest scan of each page decides. Evidence that names a check is read as
// coverage, so a site whose walk never ran, or ran once on a page since
// re-scanned without it, gets no claim at all.
// ---------------------------------------------------------------------------

describe('the VPAT evidence', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        // Any file that exists stands in for Chrome: availability checks the
        // path, not what is at the end of it.
        AccessibilityAudit::getInstance()->getSettings()->chromePath = '/bin/sh';

        $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $this->vpat = AccessibilityAudit::getInstance()->getVpat();
        $this->otherId = (int) UserFactory::factory()->create()->id;

        Craft::$app->getDb()->createCommand()
            ->delete('{{%accessibilityaudit_scans}}', ['siteId' => $this->siteId])
            ->execute();
    });

    it('makes no claim where no page has been walked', function() {
        focusRecordScan($this->elementId);
        focusRecordScan($this->otherId);

        $evidence = $this->vpat->getEvidence($this->siteId);

        expect($evidence['2.4.7']['checks'])->toBeNull()
            ->and($evidence['2.4.7']['cannot'])->toBeNull()
            ->and($evidence['2.4.11']['checks'])->toBeNull()
            ->and($evidence['2.4.11']['cannot'])->toBeNull();
    });

    it('says how many of the pages scanned the walk covered', function() {
        focusRecordScan($this->elementId, true, true);
        focusRecordScan($this->otherId);

        $evidence = $this->vpat->getEvidence($this->siteId);

        expect($evidence['2.4.7']['checks'])->toContain('keyboard focus')
            ->and($evidence['2.4.7']['checks'])->toEndWith(', on 1 of the 2 pages scanned')
            ->and($evidence['2.4.7']['cannot'])->not->toBeNull()
            ->and($evidence['2.4.11']['checks'])->toEndWith(', on 1 of the 2 pages scanned')
            ->and($evidence['2.4.7']['pages'])->toBe(2);
    });

    it('counts each question by its own record', function() {
        focusRecordScan($this->elementId, false, true);
        focusRecordScan($this->otherId, false, true);

        $evidence = $this->vpat->getEvidence($this->siteId);

        expect($evidence['2.4.7']['checks'])->toBeNull()
            ->and($evidence['2.4.11']['checks'])->toEndWith(', on all 2 pages scanned');
    });

    it('does not count a walk on a scan that has since been superseded', function() {
        focusRecordScan($this->elementId, true, true);
        focusRecordScan($this->elementId);
        focusRecordScan($this->otherId);

        $evidence = $this->vpat->getEvidence($this->siteId);

        expect($evidence['2.4.7']['checks'])->toBeNull()
            ->and($evidence['2.4.11']['checks'])->toBeNull();
    });

    it('keeps the settings gate on top of the record', function() {
        focusRecordScan($this->elementId, true, true);

        AccessibilityAudit::getInstance()->getSettings()->wcagLevel = 'A';
        $levelA = $this->vpat->getEvidence($this->siteId);

        AccessibilityAudit::getInstance()->getSettings()->wcagLevel = 'AA';
        AccessibilityAudit::getInstance()->getSettings()->chromePath = '';
        AccessibilityAudit::getInstance()->getSettings()->chromeWsEndpoint = '';
        $withoutBrowser = $this->vpat->getEvidence($this->siteId);

        expect($levelA['2.4.7']['checks'])->toBeNull()
            ->and($levelA['2.4.11']['checks'])->toBeNull()
            ->and($withoutBrowser['2.4.7']['checks'])->toBeNull()
            ->and($withoutBrowser['2.4.11']['checks'])->toBeNull();
    });

});

// ---------------------------------------------------------------------------
// A drafted remark claims what the evidence row claims and no more. The
// Anthropic request is answered by a mock and kept for inspection.
// ---------------------------------------------------------------------------

describe('drafting a remark', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        AccessibilityAudit::getInstance()->getSettings()->chromePath = '/bin/sh';
        AccessibilityAudit::getInstance()->getSettings()->anthropicApiKey = 'test-key';

        $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        // A fresh service rather than the plugin component, which another
        // test file may have swapped for a stub that never drafts.
        $this->vpat = new VpatService();
        $this->otherId = (int) UserFactory::factory()->create()->id;
        $this->sent = [];

        Craft::$app->getDb()->createCommand()
            ->delete('{{%accessibilityaudit_scans}}', ['siteId' => $this->siteId])
            ->execute();

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode(['content' => [['text' => 'Drafted.']]])),
        ]));
        $stack->push(Middleware::history($this->sent));

        Craft::$container->set(Client::class, fn($container, array $params) => new Client(
            array_merge($params[0] ?? [], ['handler' => $stack]),
        ));
    });

    afterEach(function() {
        Craft::$container->clear(Client::class);
    });

    it('claims exactly the coverage the evidence row shows', function() {
        focusRecordScan($this->elementId, true, true);
        focusRecordScan($this->otherId);

        $result = $this->vpat->draftRemark($this->siteId, '2.4.7', '');
        $prompt = (string) json_decode((string) $this->sent[0]['request']->getBody(), true)['messages'][0]['content'];
        $checks = $this->vpat->getEvidence($this->siteId)['2.4.7']['checks'];

        expect($result['success'])->toBeTrue()
            ->and($checks)->toEndWith(', on 1 of the 2 pages scanned')
            ->and($prompt)->toContain('the scanner checked ' . $checks . ' and recorded no findings')
            ->and($prompt)->not->toContain('scanned page(s)');
    });

    it('will not draft from walk coverage no latest scan records', function() {
        focusRecordScan($this->elementId, true, true);
        focusRecordScan($this->elementId);

        $result = $this->vpat->draftRemark($this->siteId, '2.4.7', '');

        expect($result['success'])->toBeFalse()
            ->and($result['hint'] ?? false)->toBeTrue()
            ->and($this->sent)->toBe([]);
    });

    it('still states the page count for a check that covers every scanned page', function() {
        focusRecordScan($this->elementId);
        focusRecordScan($this->otherId);

        $this->vpat->draftRemark($this->siteId, '2.4.2', '');
        $prompt = (string) json_decode((string) $this->sent[0]['request']->getBody(), true)['messages'][0]['content'];

        expect($prompt)->toContain('across 2 scanned page(s) and recorded no findings');
    });
});
