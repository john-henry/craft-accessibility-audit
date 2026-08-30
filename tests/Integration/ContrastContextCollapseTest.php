<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\migrations\m260830_100000_collapse_contrast_context_keys;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\VerdictService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// One element, one occurrence, whenever the check ran.
//
// axe reduces an element to its bare opening tag only once the full markup
// runs past its own limit. Which side of that limit an element falls on
// depends on how much of the page has rendered: a code block a highlighter
// expands to nine lines is under the limit before the highlighter finishes and
// over it after. So the same span arrived as two strings, hashed two ways, and
// became two occurrences. Answering one never reached the other, and the
// question came back on the next scan looking identical because it was the
// same element wearing a different key.
//
// Taken from real data: 22 rows stored at 34 characters and 3 at 70, all for
// one span on one page.
// ---------------------------------------------------------------------------

/** The two forms one engine or another returned for the same span. */
function collapseForms(): array
{
    return [
        'short' => '<span class="token scalar string">',
        'long' => '<span class="token scalar string">response=$(curl -s -H "Authorization")</span>',
    ];
}

/** A scan carrying one contrast occurrence with the given context. */
function collapseScanWith(int $elementId, int $siteId, string $context): int
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

/** A ruling filed under a context exactly as it stands. */
function collapseVerdict(int $elementId, int $siteId, string $context): void
{
    $verdicts = new VerdictService();
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_verdicts}}', [
        'targetHash' => $verdicts->targetHash($elementId),
        'elementId' => $elementId, 'siteId' => $siteId,
        'ruleId' => 'potential:contrast-unmeasurable',
        'contextHash' => $verdicts->stableContextHash($context),
        'verdict' => VerdictService::VERDICT_DISMISSED,
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
    ])->execute();
}

beforeEach(function() {
    $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $this->elementId = (int) UserFactory::factory()->create()->id;
    $this->verdicts = new VerdictService();
});

describe('reducing an occurrence to its opening tag', function() {
    it('gives both forms of one element the same key', function() {
        ['short' => $short, 'long' => $long] = collapseForms();

        expect(AuditService::openingTagOf($long))->toBe($short)
            ->and(AuditService::openingTagOf($short))->toBe($short);
    });

    it('leaves an element that has no content alone', function() {
        expect(AuditService::openingTagOf('<img src="/a.jpg" alt="">'))
            ->toBe('<img src="/a.jpg" alt="">');
    });

    it('leaves anything that is not an element alone', function() {
        // Contexts from the other rules are not markup, and must pass through.
        expect(AuditService::openingTagOf('"Services" → /a, /b'))->toBe('"Services" → /a, /b')
            ->and(AuditService::openingTagOf(''))->toBe('');
    });

    it('keeps a truncated opening tag rather than losing it', function() {
        // The 300-character cap can land inside the tag, leaving no closing
        // bracket. Cutting to a bracket that is not there would empty it.
        $cut = '<p class="mx-auto mt-10 max-w-xl text-center text-base text-';

        expect(AuditService::openingTagOf($cut))->toBe($cut);
    });

    it('still tells two different elements apart', function() {
        expect(AuditService::openingTagOf('<span class="token key">a</span>'))
            ->not->toBe(AuditService::openingTagOf('<span class="token string">a</span>'));
    });

    it('is what the scanner stores', function() {
        $source = (string) file_get_contents((new ReflectionClass(AuditService::class))->getFileName());

        expect(substr_count($source, 'self::openingTagOf('))->toBe(3);
    });
});

describe('rulings already filed under the longer form', function() {
    it('moves them onto the key the shorter form has', function() {
        ['short' => $short, 'long' => $long] = collapseForms();

        collapseScanWith($this->elementId, $this->siteId, $long);
        collapseVerdict($this->elementId, $this->siteId, $long);

        (new m260830_100000_collapse_contrast_context_keys())->safeUp();

        $map = $this->verdicts->mapForElement($this->elementId, $this->siteId);

        expect($this->verdicts->lookup($map, 'potential:contrast-unmeasurable', $short))
            ->toBe(VerdictService::VERDICT_DISMISSED);
    });

    it('shortens the stored occurrence too', function() {
        // Otherwise the report keeps showing the long form while the next scan
        // writes the short one, and they read as two findings.
        ['short' => $short, 'long' => $long] = collapseForms();

        $scanId = collapseScanWith($this->elementId, $this->siteId, $long);

        (new m260830_100000_collapse_contrast_context_keys())->safeUp();

        expect((new Query())->select(['context'])->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId])->scalar())->toBe($short);
    });

    it('keeps the later answer when both forms were ruled on', function() {
        ['short' => $short, 'long' => $long] = collapseForms();

        collapseScanWith($this->elementId, $this->siteId, $long);
        collapseVerdict($this->elementId, $this->siteId, $short);
        collapseVerdict($this->elementId, $this->siteId, $long);

        expect((new Query())->from('{{%accessibilityaudit_verdicts}}')
            ->where(['elementId' => $this->elementId])->count())->toBe('2');

        (new m260830_100000_collapse_contrast_context_keys())->safeUp();

        expect((new Query())->from('{{%accessibilityaudit_verdicts}}')
            ->where(['elementId' => $this->elementId])->count())->toBe('1');

        $map = $this->verdicts->mapForElement($this->elementId, $this->siteId);

        expect($this->verdicts->lookup($map, 'potential:contrast-unmeasurable', $short))
            ->toBe(VerdictService::VERDICT_DISMISSED);
    });

    it('leaves the client-side pass alone', function() {
        // That one stores JSON and is keyed on its own fields, so shortening
        // it would key it on a fragment of the JSON instead.
        $json = '{"html":"<p class=\"a\">Text</p>","fg":"#767676","bg":"#ffffff"}';
        $scanId = collapseScanWith($this->elementId, $this->siteId, $json);

        (new m260830_100000_collapse_contrast_context_keys())->safeUp();

        expect((new Query())->select(['context'])->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $scanId])->scalar())->toBe($json);
    });

    it('can be run twice without moving anything the second time', function() {
        ['long' => $long] = collapseForms();

        collapseScanWith($this->elementId, $this->siteId, $long);
        collapseVerdict($this->elementId, $this->siteId, $long);

        (new m260830_100000_collapse_contrast_context_keys())->safeUp();
        $after = (new Query())->select(['contextHash'])->from('{{%accessibilityaudit_verdicts}}')
            ->where(['elementId' => $this->elementId])->column();

        (new m260830_100000_collapse_contrast_context_keys())->safeUp();

        expect((new Query())->select(['contextHash'])->from('{{%accessibilityaudit_verdicts}}')
            ->where(['elementId' => $this->elementId])->column())->toBe($after);
    });
});
