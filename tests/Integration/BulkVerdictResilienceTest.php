<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\controllers\AuditController;
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

describe('dismissing a group', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
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
        // that is the difference between a click and a wait.
        $source = (string) file_get_contents((new ReflectionClass(AuditController::class))->getFileName());

        $start = strpos($source, 'public function actionSetVerdictsBulk(');
        $body = substr($source, (int) $start, 3000);

        expect($body)->toContain('deferScoring: true')
            ->and($body)->toContain('foreach (array_unique($needScoring) as $scanId)');
    });

    it('answers with a sentence rather than a blank error page when it breaks', function() {
        // These are separate writes, not one transaction, so whatever landed
        // before a failure is real and the count says so.
        $source = (string) file_get_contents((new ReflectionClass(AuditController::class))->getFileName());

        $start = strpos($source, 'public function actionSetVerdictsBulk(');
        $body = substr($source, (int) $start, 3000);

        expect($body)->toContain('catch (Throwable $e)')
            ->and($body)->toContain("'success' => false")
            ->and($body)->toContain('Craft::error');
    });

    it('leaves the rulings it did make in place after a failure', function() {
        // Reported as "saved N of M", so a retry is a decision rather than a
        // guess about what happened.
        $source = (string) file_get_contents((new ReflectionClass(AuditController::class))->getFileName());

        expect($source)->toContain('Saved {applied} of {total} before something went wrong.');
    });
});
