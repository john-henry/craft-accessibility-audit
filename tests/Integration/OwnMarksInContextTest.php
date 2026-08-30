<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\migrations\m260830_110000_strip_own_marks_from_contexts;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\VerdictService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The plugin must not read back its own fingerprints.
//
// The report marks an element in its preview to highlight it, and the browser
// pass then reads that same preview. An element that had been highlighted came
// back carrying the mark:
//
//     <span class="token scalar string" data-accessibility-audit-hl="first">
//
// A different string, so a different key, so a second occurrence. Clicking
// "Show on page" and re-scanning therefore turned a question already answered
// into a fresh one. Taken from real data: 26 rows for one span stored clean
// and 3 stored marked.
// ---------------------------------------------------------------------------

/** The same element, clean and as the report left it after a highlight. */
function markedForms(): array
{
    return [
        'clean' => '<span class="token scalar string">',
        'marked' => '<span class="token scalar string" data-accessibility-audit-hl="first">',
    ];
}

beforeEach(function() {
    $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $this->elementId = (int) UserFactory::factory()->create()->id;
    $this->verdicts = new VerdictService();
});

describe('an element carrying the plugin s own mark', function() {
    it('is the same occurrence as the element without it', function() {
        ['clean' => $clean, 'marked' => $marked] = markedForms();

        expect(AuditService::openingTagOf($marked))->toBe($clean);
    });

    it('strips every mark the plugin stamps, not just the highlight one', function() {
        foreach (['hl', 'cluster', 'review', 'restore', 'scan-entry'] as $mark) {
            $markup = '<a href="/x" data-accessibility-audit-' . $mark . '="1">';

            expect(AuditService::openingTagOf($markup))->toBe('<a href="/x">');
        }
    });

    it('strips a valueless mark', function() {
        expect(AuditService::openingTagOf('<div class="a" data-accessibility-audit-panel>'))
            ->toBe('<div class="a">');
    });

    it('leaves the page own data attributes alone', function() {
        // Only the plugin's own prefix goes. Everything else is the page's,
        // and stripping it would merge elements that genuinely differ.
        $markup = '<span data-fui-id="contactForm-message" data-max-chars="500">';

        expect(AuditService::openingTagOf($markup))->toBe($markup);
    });

    it('still tells two genuinely different elements apart', function() {
        expect(AuditService::openingTagOf('<span class="token key" data-accessibility-audit-hl="first">'))
            ->not->toBe(AuditService::openingTagOf('<span class="token string" data-accessibility-audit-hl="first">'));
    });
});

it('clears marks already stored and moves the ruling with them', function() {
    ['clean' => $clean, 'marked' => $marked] = markedForms();

    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $this->elementId, 'elementType' => User::class, 'siteId' => $this->siteId,
        'score' => 100, 'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
        'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 1,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    $scanId = (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');

    $db->createCommand()->insert('{{%accessibilityaudit_issues}}', [
        'scanId' => $scanId, 'elementId' => $this->elementId, 'elementType' => User::class,
        'siteId' => $this->siteId, 'ruleId' => 'potential:contrast-unmeasurable',
        'severity' => 'notice', 'message' => 'q', 'context' => $marked, 'source' => 'axe',
        'isResolved' => false, 'firstDetected' => $now,
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
    ])->execute();

    $db->createCommand()->insert('{{%accessibilityaudit_verdicts}}', [
        'targetHash' => $this->verdicts->targetHash($this->elementId),
        'elementId' => $this->elementId, 'siteId' => $this->siteId,
        'ruleId' => 'potential:contrast-unmeasurable',
        'contextHash' => $this->verdicts->stableContextHash($marked),
        'verdict' => VerdictService::VERDICT_DISMISSED,
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
    ])->execute();

    (new m260830_110000_strip_own_marks_from_contexts())->safeUp();

    expect((new Query())->select(['context'])->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId])->scalar())->toBe($clean);

    $map = $this->verdicts->mapForElement($this->elementId, $this->siteId);

    expect($this->verdicts->lookup($map, 'potential:contrast-unmeasurable', $clean))
        ->toBe(VerdictService::VERDICT_DISMISSED);
});
