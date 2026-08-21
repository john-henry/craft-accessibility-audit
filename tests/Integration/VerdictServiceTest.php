<?php

use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\VerdictService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** A real element id, so the scans/verdicts foreign keys are satisfied. */
function verdictElementId(): int
{
    return (int) UserFactory::factory()->create()->id;
}

/** A scan row to hang issues off, starting from a clean 100. */
function verdictScanId(int $elementId, int $siteId = 1): int
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId,
        'elementType' => \craft\elements\User::class,
        'siteId' => $siteId,
        'score' => 100, 'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
        'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 0,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) Craft::$app->getDb()->getLastInsertID('{{%accessibilityaudit_scans}}');
}

/** Inserts one issue row and hands back its id. */
function verdictIssue(int $scanId, int $elementId, string $ruleId, string $context, string $severity = 'error', int $siteId = 1): int
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_issues}}', [
        'scanId' => $scanId,
        'elementId' => $elementId,
        'elementType' => \craft\elements\User::class,
        'siteId' => $siteId,
        'ruleId' => $ruleId,
        'severity' => $severity,
        'message' => 'Test finding',
        'context' => $context,
        'source' => 'php',
        'isResolved' => false,
        'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) Craft::$app->getDb()->getLastInsertID('{{%accessibilityaudit_issues}}');
}

/** The stored score for a scan. */
function verdictScore(int $scanId): int
{
    return (int) (new craft\db\Query())
        ->select('score')->from('{{%accessibilityaudit_scans}}')->where(['id' => $scanId])->scalar();
}

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
});

// ---------------------------------------------------------------------------
// Reviewing a potential issue
// ---------------------------------------------------------------------------

describe('VerdictService', function() {
    it('keeps a potential issue out of the score until it is confirmed', function() {
        $plugin = AccessibilityAudit::getInstance();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();
        $scanId = verdictScanId($elementId, $siteId);
        verdictIssue($scanId, $elementId, 'potential:decorative-image', '<img src="a.jpg">', 'error', $siteId);

        // Unreviewed: a question, not a failure.
        $plugin->audit->recalculateScoreForScan($scanId);
        expect(verdictScore($scanId))->toBe(100);

        // Confirmed: now it is a failure and costs the error weight.
        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:decorative-image', '<img src="a.jpg">', VerdictService::VERDICT_CONFIRMED);
        expect(verdictScore($scanId))->toBe(90);

        // Dismissed: back out of the score again.
        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:decorative-image', '<img src="a.jpg">', VerdictService::VERDICT_DISMISSED);
        expect(verdictScore($scanId))->toBe(100);
    });

    it('drops a reviewed issue out of the pending queue, either way', function() {
        $plugin = AccessibilityAudit::getInstance();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();
        $scanId = verdictScanId($elementId, $siteId);
        verdictIssue($scanId, $elementId, 'potential:decorative-image', '<img src="a.jpg">', 'error', $siteId);
        verdictIssue($scanId, $elementId, 'potential:long-alt', '<img src="b.jpg">', 'notice', $siteId);

        expect($plugin->audit->getPendingPotentialForScan($scanId))->toHaveCount(2);

        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:long-alt', '<img src="b.jpg">', VerdictService::VERDICT_DISMISSED);

        expect($plugin->audit->getPendingPotentialForScan($scanId))->toHaveCount(1);
    });

    it('rules on one occurrence without answering the others', function() {
        $plugin = AccessibilityAudit::getInstance();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();
        $scanId = verdictScanId($elementId, $siteId);

        // Same rule, two different images: two separate questions.
        verdictIssue($scanId, $elementId, 'potential:decorative-image', '<img src="a.jpg">', 'error', $siteId);
        verdictIssue($scanId, $elementId, 'potential:decorative-image', '<img src="b.jpg">', 'error', $siteId);

        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:decorative-image', '<img src="a.jpg">', VerdictService::VERDICT_DISMISSED);

        $pending = $plugin->audit->getPendingPotentialForScan($scanId);

        expect($pending)->toHaveCount(1)
            ->and($pending[0]['context'])->toBe('<img src="b.jpg">');
    });

    it('clears a ruling and puts the question back', function() {
        $plugin = AccessibilityAudit::getInstance();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();
        $scanId = verdictScanId($elementId, $siteId);
        verdictIssue($scanId, $elementId, 'potential:decorative-image', '<img src="a.jpg">', 'error', $siteId);

        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:decorative-image', '<img src="a.jpg">', VerdictService::VERDICT_CONFIRMED);
        expect($plugin->audit->getPendingPotentialForScan($scanId))->toBeEmpty()
            ->and(verdictScore($scanId))->toBe(90);

        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:decorative-image', '<img src="a.jpg">', null);

        expect($plugin->audit->getPendingPotentialForScan($scanId))->toHaveCount(1)
            ->and(verdictScore($scanId))->toBe(100);
    });

    it('expands a confirmed rule to the confirmed occurrence only', function() {
        $plugin = AccessibilityAudit::getInstance();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();
        $scanId = verdictScanId($elementId, $siteId);

        // Three of the same question; the author answers two of them.
        verdictIssue($scanId, $elementId, 'potential:possible-heading', 'Bold', 'notice', $siteId);
        verdictIssue($scanId, $elementId, 'potential:possible-heading', 'Table:', 'notice', $siteId);
        verdictIssue($scanId, $elementId, 'potential:possible-heading', 'Ordered List:', 'notice', $siteId);

        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:possible-heading', 'Bold', VerdictService::VERDICT_CONFIRMED);
        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:possible-heading', 'Table:', VerdictService::VERDICT_DISMISSED);

        $grouped = $plugin->audit->getIssuesGroupedByScan($scanId);
        $occurrences = $plugin->audit->getOccurrencesForRule($scanId, 'potential:possible-heading');

        // Confirming one occurrence used to promote the rule and then list
        // every occurrence under it, so the count and the list disagreed.
        expect($grouped)->toHaveCount(1)
            ->and((int)$grouped[0]['occurrences'])->toBe(1)
            ->and($occurrences)->toHaveCount(1)
            ->and($occurrences[0]['context'])->toBe('Bold');
    });

    it('keeps a dismissed issue out of the element issue list', function() {
        $plugin = AccessibilityAudit::getInstance();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();
        $scanId = verdictScanId($elementId, $siteId);
        verdictIssue($scanId, $elementId, 'potential:possible-heading', 'Bold', 'notice', $siteId);

        expect($plugin->audit->getIssues($scanId))->toHaveCount(1);

        // The entry sidebar and craft.a11y read this: a question the author has
        // answered must not reappear beside the entry.
        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:possible-heading', 'Bold', VerdictService::VERDICT_DISMISSED);

        expect($plugin->audit->getIssues($scanId))->toBeEmpty();
    });

    it('survives a re-scan, so the author is not asked twice', function() {
        $plugin = AccessibilityAudit::getInstance();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();

        // Answer the question on one scan.
        $firstScan = verdictScanId($elementId, $siteId);
        verdictIssue($firstScan, $elementId, 'potential:decorative-image', '<img src="a.jpg">', 'error', $siteId);
        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:decorative-image', '<img src="a.jpg">', VerdictService::VERDICT_DISMISSED);

        // A later scan finds the same thing again. The stored ruling is keyed to
        // the element, rule and markup, so the map still resolves it.
        $map = $plugin->verdicts->mapForElement($elementId, $siteId);

        expect($plugin->verdicts->lookup($map, 'potential:decorative-image', '<img src="a.jpg">'))
            ->toBe(VerdictService::VERDICT_DISMISSED)
            // Different markup is a different question, still unanswered.
            ->and($plugin->verdicts->lookup($map, 'potential:decorative-image', '<img src="b.jpg">'))
            ->toBeNull();
    });

    it('still matches a ruling made against the old 150-character context cap', function() {
        $plugin = AccessibilityAudit::getInstance();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();

        // The full snippet a post-upgrade scan stores (well past 150 chars)...
        $longContext = '<img class="absolute inset-0 mt-0" src="https://cdn.example.com/content/uploads/Listing-rectangles/Speaking_Engagement_Listing_Graphic_With_A_Long_Name.svg" loading="lazy">';
        // ...and the truncated form a pre-upgrade scan stored the ruling under.
        $legacyContext = mb_substr($longContext, 0, 150) . '…';

        expect(mb_strlen($longContext))->toBeGreaterThan(151);

        $scanId = verdictScanId($elementId, $siteId);
        verdictIssue($scanId, $elementId, 'potential:decorative-image', $legacyContext, 'error', $siteId);
        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:decorative-image', $legacyContext, VerdictService::VERDICT_DISMISSED);

        $map = $plugin->verdicts->mapForElement($elementId, $siteId);

        // The next scan presents the longer snippet; the old ruling still holds.
        expect($plugin->verdicts->lookup($map, 'potential:decorative-image', $longContext))
            ->toBe(VerdictService::VERDICT_DISMISSED);
    });

    it('matches a legacy ruling at the 151-character boundary', function() {
        $plugin = AccessibilityAudit::getInstance();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();

        // Exactly 151 characters: fits the new 300 cap untouched, but the old
        // builder truncated it to 150 plus the ellipsis — the one length where
        // an off-by-one in the guard silently loses the ruling.
        $longContext = '<img src="/uploads/' . str_repeat('a', 151 - 25) . '.jpg">';
        expect(mb_strlen($longContext))->toBe(151);
        $legacyContext = mb_substr($longContext, 0, 150) . '…';

        $scanId = verdictScanId($elementId, $siteId);
        verdictIssue($scanId, $elementId, 'potential:decorative-image', $legacyContext, 'error', $siteId);
        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:decorative-image', $legacyContext, VerdictService::VERDICT_DISMISSED);

        $map = $plugin->verdicts->mapForElement($elementId, $siteId);

        expect($plugin->verdicts->lookup($map, 'potential:decorative-image', $longContext))
            ->toBe(VerdictService::VERDICT_DISMISSED);
    });

    it('matches a ruling posted with CRLF line endings against LF-stored rows', function() {
        $plugin = AccessibilityAudit::getInstance();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = verdictElementId();

        // Multi-line snippet as the scanner stores it (LF)...
        $stored = "<text fill=\"rgba(0,0,0,0.1)\">\n    625 × 300\n</text>";
        // ...and as the browser posts it back: form encoding converts \n to \r\n.
        $posted = str_replace("\n", "\r\n", $stored);

        $scanId = verdictScanId($elementId, $siteId);
        verdictIssue($scanId, $elementId, 'potential:contrast-unmeasurable', $stored, 'error', $siteId);
        $plugin->verdicts->setVerdict($siteId, $elementId, 'potential:contrast-unmeasurable', $posted, VerdictService::VERDICT_DISMISSED);

        // The ruling must land on the stored row despite the line-ending change.
        $verdict = (new \craft\db\Query())
            ->select(['verdict'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId, 'ruleId' => 'potential:contrast-unmeasurable'])
            ->scalar();

        expect($verdict)->toBe(VerdictService::VERDICT_DISMISSED);

        // And the render-time lookup resolves it from the stored LF context.
        $map = $plugin->verdicts->mapForElement($elementId, $siteId);
        expect($plugin->verdicts->lookup($map, 'potential:contrast-unmeasurable', $stored))
            ->toBe(VerdictService::VERDICT_DISMISSED);
    });
});
