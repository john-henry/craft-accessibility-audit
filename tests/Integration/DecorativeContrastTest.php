<?php

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// Contrast findings on markup marked as decoration.
//
// WCAG 1.4.3 exempts text that is pure decoration, and aria-hidden="true" is
// the author saying so. This plugin's own contrast pass has always skipped
// those subtrees; axe measures them, so the same page got a question from one
// engine about a node the other had deliberately passed over.
//
// Helpers are uniquely named: Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** A scan row to hang axe results off, returning its id. */
function decorativeContrastScan(): int
{
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => null,
        'elementType' => null,
        'url' => 'https://example.test/decorative',
        'title' => 'Decorative',
        'siteId' => (int) Craft::$app->getSites()->getPrimarySite()->id,
        'score' => 100,
        'scoreA' => 100,
        'scoreAA' => 100,
        'scoreAAA' => 100,
        'errorCount' => 0,
        'warningCount' => 0,
        'noticeCount' => 0,
        'dateScanned' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');
}

/** One axe node, shaped the way axe reports a contrast result. */
function decorativeContrastNode(string $html): array
{
    return [
        'html' => $html,
        'target' => ['span'],
        'any' => [['data' => [
            'contrastRatio' => 2.1,
            'fgColor' => '#777777',
            'bgColor' => '#ffffff',
            'expectedContrastRatio' => '4.5:1',
            'messageKey' => 'bgImage',
        ]]],
    ];
}

/** The contrast issues stored against a scan, by rule. */
function decorativeContrastIssues(int $scanId): array
{
    return (new Query())
        ->select(['ruleId', 'context'])
        ->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId])
        ->andWhere(['like', 'ruleId', 'contrast'])
        ->all();
}

describe('contrast findings on decorative markup', function() {
    it('does not ask about an aria-hidden node it cannot measure', function() {
        // The real case: a decorative arrow after link text.
        $scanId = decorativeContrastScan();

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [], 'desktop', [[
            'id' => 'color-contrast',
            'nodes' => [decorativeContrastNode('<span aria-hidden="true"> →</span>')],
        ]]);

        expect(decorativeContrastIssues($scanId))->toBeEmpty();
    });

    it('does not report an aria-hidden node as a contrast failure either', function() {
        $scanId = decorativeContrastScan();

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [[
            'id' => 'color-contrast',
            'impact' => 'serious',
            'nodes' => [decorativeContrastNode('<span aria-hidden="true"> →</span>')],
        ]]);

        expect(decorativeContrastIssues($scanId))->toBeEmpty();
    });

    it('still asks about text that is actually announced', function() {
        $scanId = decorativeContrastScan();

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [], 'desktop', [[
            'id' => 'color-contrast',
            'nodes' => [decorativeContrastNode('<p class="lead">Read this bit</p>')],
        ]]);

        expect(decorativeContrastIssues($scanId))->toHaveCount(1);
    });

    it('is not fooled by aria-hidden="false"', function() {
        // Explicitly not hidden, so it is announced and the question stands.
        $scanId = decorativeContrastScan();

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [], 'desktop', [[
            'id' => 'color-contrast',
            'nodes' => [decorativeContrastNode('<span aria-hidden="false">Sale</span>')],
        ]]);

        expect(decorativeContrastIssues($scanId))->toHaveCount(1);
    });

    it('reads single-quoted markup the same as double-quoted', function() {
        $scanId = decorativeContrastScan();

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [], 'desktop', [[
            'id' => 'color-contrast',
            'nodes' => [decorativeContrastNode("<span aria-hidden='true'> x</span>")],
        ]]);

        expect(decorativeContrastIssues($scanId))->toBeEmpty();
    });
});
