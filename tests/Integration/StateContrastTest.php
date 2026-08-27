<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\RuleRegistry;

// ---------------------------------------------------------------------------
// Contrast in states the page is never in while it is being scanned.
//
// Hover, focus and selection colours never appear in a rendered page, so no
// engine that reads the DOM can see them: axe measures what is on screen, and
// what is on screen is the resting state. They are read from the stylesheet in
// the browser instead (accessibility-audit-shared.js) and arrive here carrying
// the state they were found in.
//
// The reading is JavaScript and has no harness here. What is covered is the
// storage: that a state occurrence becomes its own rule, says which state in
// its message, and keeps the state in its context for the report to read.
// ---------------------------------------------------------------------------

/** A scan to store occurrences against. */
function stateContrastScanId(): int
{
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => null,
        'elementType' => null,
        'url' => 'https://example.test/state',
        'title' => 'State',
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

/** One occurrence in the shape the browser sends. */
function stateOccurrence(?string $state, float $ratio = 4.25): array
{
    return array_filter([
        'state' => $state,
        'fg' => '#767676',
        'bg' => '#FFFFFF',
        'ratio' => $ratio,
        'expected' => '4.5:1',
        'html' => '<a class="btn">Hover me</a>',
        'selector' => 'a.btn',
    ], static fn($v): bool => $v !== null);
}

/** The stored rows for a scan, as ruleId => [message, context]. */
function storedContrastRows(int $scanId): array
{
    $rows = (new Query())
        ->select(['ruleId', 'message', 'context'])
        ->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId, 'source' => 'contrast'])
        ->all();

    return array_combine(
        array_column($rows, 'ruleId'),
        array_map(static fn(array $r): array => [$r['message'], $r['context']], $rows),
    );
}

describe('state contrast storage', function() {
    it('gives each state its own rule', function() {
        $scanId = stateContrastScanId();

        AccessibilityAudit::getInstance()->audit->storeContrastIssues($scanId, [
            stateOccurrence('hover'),
            stateOccurrence('focus', 2.66),
            stateOccurrence('selection', 1.29),
        ]);

        expect(array_keys(storedContrastRows($scanId)))
            ->toEqualCanonicalizing(['contrast-hover', 'contrast-focus', 'contrast-selection']);
    });

    it('says which state the finding is about', function() {
        // "Contrast 4.25:1" alone reads as a resting-state failure the reader
        // will go looking for and never find.
        $scanId = stateContrastScanId();

        AccessibilityAudit::getInstance()->audit->storeContrastIssues($scanId, [stateOccurrence('hover')]);

        [$message] = storedContrastRows($scanId)['contrast-hover'];

        expect($message)->toContain('on hover')
            ->and($message)->toContain('4.25');
    });

    it('keeps the state in the context so the report can read it back', function() {
        $scanId = stateContrastScanId();

        AccessibilityAudit::getInstance()->audit->storeContrastIssues($scanId, [stateOccurrence('selection')]);

        [, $context] = storedContrastRows($scanId)['contrast-selection'];

        expect(Json::decode($context)['state'])->toBe('selection');
    });

    it('leaves a resting-state occurrence exactly as it was', function() {
        // The states are an addition. An occurrence with no state must still
        // store as color-contrast, with no state in its context.
        $scanId = stateContrastScanId();

        AccessibilityAudit::getInstance()->audit->storeContrastIssues($scanId, [stateOccurrence(null)]);

        $rows = storedContrastRows($scanId);

        expect(array_keys($rows))->toBe(['color-contrast'])
            ->and($rows['color-contrast'][0])->not->toContain('on hover')
            ->and(Json::decode($rows['color-contrast'][1])['state'])->toBeNull();
    });

    it('ignores a state it does not know', function() {
        // The state arrives from the browser, so it is not taken on trust: an
        // unknown one falls back to the resting-state rule rather than
        // inventing a rule id nothing is registered for.
        $scanId = stateContrastScanId();

        AccessibilityAudit::getInstance()->audit->storeContrastIssues($scanId, [stateOccurrence('active')]);

        expect(array_keys(storedContrastRows($scanId)))->toBe(['color-contrast']);
    });

    it('registers every state rule it can emit', function() {
        foreach (array_keys(AuditService::CONTRAST_STATES) as $state) {
            expect(RuleRegistry::get('contrast-' . $state))->not->toBeNull();
        }
    });
});
