<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\VerdictService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Dismissing a whole group at once.
//
// A group can hold every occurrence on a page, and every occurrence in it
// shares one scan. Working the score out per ruling means computing the same
// number once for each, with the reader waiting on all of them.
//
// The other half is what happens when something does go wrong part way. These
// are separate writes, not one transaction, so the rulings already made are
// real. Reporting that honestly beats a blank error page that leaves somebody
// guessing whether any of it landed.
// ---------------------------------------------------------------------------

/** A scan carrying one potential issue per context, and its id. */
function bulkScanWith(int $siteId, int $elementId, array $contexts): int
{
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId, 'elementType' => User::class, 'siteId' => $siteId,
        'score' => 90, 'scoreA' => 90, 'scoreAA' => 90, 'scoreAAA' => 90,
        'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => count($contexts),
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    $scanId = (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');

    foreach ($contexts as $context) {
        $db->createCommand()->insert('{{%accessibilityaudit_issues}}', [
            'scanId' => $scanId, 'elementId' => $elementId, 'elementType' => User::class,
            'siteId' => $siteId, 'ruleId' => 'potential:contrast-unmeasurable',
            'severity' => 'notice', 'message' => 'q', 'context' => $context, 'source' => 'axe',
            'isResolved' => false, 'firstDetected' => $now,
            'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
        ])->execute();
    }

    return $scanId;
}

/** Counts score recalculations so a per-ruling loop cannot creep back in. */
class CountingAuditService extends AuditService
{
    public static int $calls = 0;

    public function recalculateScoreForScan(int $scanId): void
    {
        self::$calls++;
        parent::recalculateScoreForScan($scanId);
    }
}

/** Fails part way through a group, to stand in for a write that goes wrong. */
class FailingVerdictService extends VerdictService
{
    public static int $calls = 0;

    public static int $failAfter = 0;

    public function setVerdict(
        int $siteId,
        ?int $elementId,
        string $ruleId,
        ?string $context,
        ?string $verdict,
        ?string $note = null,
        ?string $url = null,
        bool $deferScoring = false,
    ): array {
        if (self::$calls >= self::$failAfter) {
            throw new RuntimeException('bulk verdict blew up');
        }

        self::$calls++;

        return parent::setVerdict($siteId, $elementId, $ruleId, $context, $verdict, $note, $url, $deferScoring);
    }
}

describe('dismissing a group', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    });

    afterEach(function() {
        // The stubs below are set on the live plugin instance, which outlives
        // the test, so the real services go back whether one was swapped or not.
        $plugin = AccessibilityAudit::getInstance();
        $plugin->set('audit', AuditService::class);
        $plugin->set('verdicts', VerdictService::class);
    });

    it('applies every ruling and can be repeated without complaint', function() {
        // Pressing it twice, or the page being reloaded and pressed again, is
        // ordinary. The second pass updates rather than inserting, and the
        // unique index means it cannot quietly double up.
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = (int) UserFactory::factory()->create()->id;

        $contexts = [];
        for ($i = 0; $i < 40; $i++) {
            $contexts[] = '<td class="px-4 py-2.5 align-top">Cell ' . $i . '</td>';
        }

        bulkScanWith($siteId, $elementId, $contexts);

        $items = array_map(
            static fn(string $c): array => ['ruleId' => 'potential:contrast-unmeasurable', 'context' => $c],
            $contexts,
        );

        foreach ([1, 2, 3] as $round) {
            $json = $this->postJson('actions/accessibility-audit/audit/set-verdicts-bulk', [
                'elementId' => $elementId,
                'siteId' => $siteId,
                'verdict' => 'dismissed',
                'items' => json_encode($items),
            ])->json();

            $decoded = is_string($json) ? json_decode($json, true) : $json;

            expect($decoded['success'] ?? false)->toBeTrue("round {$round} did not succeed")
                ->and($decoded['applied'] ?? 0)->toBe(40);
        }

        $rows = (new Query())->from('{{%accessibilityaudit_verdicts}}')
            ->where([
                'siteId' => $siteId,
                'elementId' => $elementId,
                'ruleId' => 'potential:contrast-unmeasurable',
            ])->count();

        expect((int) $rows)->toBe(40);
    });

    it('works the score out once for the whole group, not once per ruling', function() {
        // Every occurrence in a group shares one scan, so the recalculation is
        // the same number computed over and over. On a page with fifty of them
        // that is the difference between a click and a wait. The end state is
        // identical either way, so the count is what has to be pinned.
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = (int) UserFactory::factory()->create()->id;

        $contexts = [];
        for ($i = 0; $i < 40; $i++) {
            $contexts[] = '<td>Cell ' . $i . '</td>';
        }

        bulkScanWith($siteId, $elementId, $contexts);

        CountingAuditService::$calls = 0;
        AccessibilityAudit::getInstance()->set('audit', CountingAuditService::class);

        $this->postJson('actions/accessibility-audit/audit/set-verdicts-bulk', [
            'elementId' => $elementId,
            'siteId' => $siteId,
            'verdict' => 'dismissed',
            'items' => json_encode(array_map(
                static fn(string $c): array => ['ruleId' => 'potential:contrast-unmeasurable', 'context' => $c],
                $contexts,
            )),
        ]);

        expect(CountingAuditService::$calls)->toBe(1);
    });

    it('answers with a sentence rather than a blank error page when it breaks', function() {
        // These are separate writes, not one transaction, so whatever landed
        // before a failure is real and the count says so.
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = (int) UserFactory::factory()->create()->id;

        $contexts = ['<td>a</td>', '<td>b</td>', '<td>c</td>', '<td>d</td>'];
        bulkScanWith($siteId, $elementId, $contexts);

        FailingVerdictService::$calls = 0;
        FailingVerdictService::$failAfter = 2;
        AccessibilityAudit::getInstance()->set('verdicts', FailingVerdictService::class);

        $json = $this->postJson('actions/accessibility-audit/audit/set-verdicts-bulk', [
            'elementId' => $elementId,
            'siteId' => $siteId,
            'verdict' => 'dismissed',
            'items' => json_encode(array_map(
                static fn(string $c): array => ['ruleId' => 'potential:contrast-unmeasurable', 'context' => $c],
                $contexts,
            )),
        ])->json();

        $decoded = is_string($json) ? json_decode($json, true) : $json;

        expect($decoded['success'] ?? true)->toBeFalse()
            ->and($decoded['applied'] ?? null)->toBe(2)
            ->and($decoded['error'] ?? '')->not->toBeEmpty();
    });

    it('leaves the rulings it did make in place after a failure', function() {
        // Not one transaction, so the rulings written before the failure are
        // real and stay written. A retry is then a decision rather than a guess
        // about what landed.
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = (int) UserFactory::factory()->create()->id;

        $contexts = ['<td>a</td>', '<td>b</td>', '<td>c</td>', '<td>d</td>'];
        bulkScanWith($siteId, $elementId, $contexts);

        FailingVerdictService::$calls = 0;
        FailingVerdictService::$failAfter = 2;
        AccessibilityAudit::getInstance()->set('verdicts', FailingVerdictService::class);

        $this->postJson('actions/accessibility-audit/audit/set-verdicts-bulk', [
            'elementId' => $elementId,
            'siteId' => $siteId,
            'verdict' => 'dismissed',
            'items' => json_encode(array_map(
                static fn(string $c): array => ['ruleId' => 'potential:contrast-unmeasurable', 'context' => $c],
                $contexts,
            )),
        ]);

        $rows = (new Query())->from('{{%accessibilityaudit_verdicts}}')
            ->where([
                'siteId' => $siteId,
                'elementId' => $elementId,
                'ruleId' => 'potential:contrast-unmeasurable',
            ])->count();

        expect((int) $rows)->toBe(2);
    });
});
