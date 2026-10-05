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
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Where the focus checks keep their answers.
//
// Three questions, two owners. The keyboard walk's rows sit under the axe
// source and the stylesheet check's under the contrast source, because the
// source column is a fixed list. Each store that rebuilds a source therefore
// has to step around the focus rows it does not own, or an Inspect visit
// (which runs axe and contrast but never the walk) wipes them.
// ---------------------------------------------------------------------------

function focusStoreScan(int $elementId): int
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId, 'elementType' => User::class,
        'siteId' => (int) Craft::$app->getSites()->getPrimarySite()->id,
        'score' => 100, 'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
        'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 0,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) Craft::$app->getDb()->getLastInsertID('{{%accessibilityaudit_scans}}');
}

/** @return array<int, array<string, mixed>> */
function focusStoreRows(int $scanId, ?string $ruleId = null): array
{
    $query = (new Query())
        ->select(['ruleId', 'wcagCriterion', 'wcagLevel', 'severity', 'source', 'viewport', 'context', 'message', 'verdict'])
        ->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId])
        ->orderBy(['id' => SORT_ASC]);

    if ($ruleId !== null) {
        $query->andWhere(['ruleId' => $ruleId]);
    }

    return $query->all();
}

/** A walk that ran and found one of each. */
function focusStoreWalk(array $overrides = []): array
{
    return array_merge([
        'ran' => true,
        'focusVisible' => true,
        'total' => 12,
        'limit' => 150,
        'notVisible' => [
            ['html' => '<a href="/about" class="nav-link">About us</a>'],
        ],
        'obscured' => [
            [
                'html' => '<header class="site-header">',
                'position' => 'fixed',
                'count' => 3,
                'examples' => ['a.skip', 'a.card-link', 'button.more'],
            ],
        ],
    ], $overrides);
}

function focusStoreContrastIncomplete(): array
{
    return [[
        'id' => 'color-contrast',
        'nodes' => [[
            'html' => '<span class="badge">Sale</span>',
            'target' => ['span'],
            'any' => [['data' => ['messageKey' => 'bgImage', 'expectedContrastRatio' => '4.5:1']]],
        ]],
    ]];
}

function focusStoreViolation(): array
{
    return [
        'id' => 'target-size',
        'impact' => 'serious',
        'tags' => ['wcag22aa', 'wcag258'],
        'help' => 'All touch targets must be 24px large',
        'helpUrl' => 'https://example.com',
        'nodes' => [['html' => '<a href="/x" class="tiny">x</a>', 'target' => ['a.tiny']]],
    ];
}

beforeEach(function() {
    $this->elementId = (int) UserFactory::factory()->create()->id;
    $this->scanId = focusStoreScan($this->elementId);
    $this->audit = AccessibilityAudit::getInstance()->getAudit();
});

describe('the keyboard walk', function() {
    it('stores both questions as potential issues against the right criteria', function() {
        expect($this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk()))->toBe(2);

        $notVisible = focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE);
        $obscured = focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_OBSCURED);

        expect($notVisible)->toHaveCount(1)
            ->and($notVisible[0]['wcagCriterion'])->toBe('2.4.7')
            ->and($notVisible[0]['wcagLevel'])->toBe('AA')
            ->and($notVisible[0]['severity'])->toBe('notice')
            ->and($notVisible[0]['source'])->toBe('axe')
            ->and($notVisible[0]['viewport'])->toBe('desktop')
            // The opening tag alone: the report highlights by it and keys the
            // answer to its hash.
            ->and($notVisible[0]['context'])->toBe('<a href="/about" class="nav-link">')
            ->and($obscured)->toHaveCount(1)
            ->and($obscured[0]['wcagCriterion'])->toBe('2.4.11')
            ->and($obscured[0]['severity'])->toBe('warning')
            ->and($obscured[0]['context'])->toBe('<header class="site-header">')
            ->and($obscured[0]['message'])->toContain('This fixed element completely covered 3 controls')
            ->and($obscured[0]['message'])->toContain('a.skip, a.card-link, button.more');
    });

    it('asks rather than fails, so the score does not move', function() {
        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk());

        expect((int) $this->audit->getScanSummary($this->scanId)['score'])->toBe(100)
            ->and($this->audit->getPendingPotentialForScan($this->scanId))->toHaveCount(2);
    });

    it('survives the axe pass that Inspect and the overlay run without it', function() {
        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk());
        $this->audit->storeAxeIssues($this->scanId, [focusStoreViolation()], AuditService::VIEWPORT_DESKTOP);

        $rules = array_column(focusStoreRows($this->scanId), 'ruleId');

        expect($rules)->toContain(AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE)
            ->and($rules)->toContain(AuditService::RULE_POTENTIAL_FOCUS_OBSCURED)
            ->and($rules)->toContain('axe:target-size');
    });

    it('leaves the axe rows and the contrast questions alone', function() {
        $this->audit->storeAxeIssues(
            $this->scanId,
            [focusStoreViolation()],
            AuditService::VIEWPORT_DESKTOP,
            focusStoreContrastIncomplete(),
        );
        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk());
        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk(['notVisible' => [], 'obscured' => []]));

        $rules = array_column(focusStoreRows($this->scanId), 'ruleId');

        expect($rules)->toContain('axe:target-size')
            ->and($rules)->toContain(AuditService::RULE_POTENTIAL_CONTRAST)
            ->and($rules)->not->toContain(AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE);
    });

    it('keeps the earlier rows when there is no walk, and clears them on an empty one', function() {
        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk());

        $this->audit->storeFocusWalkIssues($this->scanId, null);
        expect(focusStoreRows($this->scanId))->toHaveCount(2);

        // A walk the page refused measured nothing either.
        $this->audit->storeFocusWalkIssues($this->scanId, ['ran' => false]);
        expect(focusStoreRows($this->scanId))->toHaveCount(2);

        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk(['notVisible' => [], 'obscured' => []]));
        expect(focusStoreRows($this->scanId))->toHaveCount(0);
    });

    it('does not rebuild the 2.4.7 rows from a walk the browser never treated as keyboard focus', function() {
        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk());

        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk([
            'focusVisible' => false,
            'notVisible' => [['html' => '<a href="/other">']],
            'obscured' => [],
        ]));

        $notVisible = focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE);

        expect($notVisible)->toHaveCount(1)
            ->and($notVisible[0]['context'])->toBe('<a href="/about" class="nav-link">')
            ->and(focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_OBSCURED))->toHaveCount(0);
    });

    it('carries an answer onto the rebuilt rows', function() {
        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk());

        AccessibilityAudit::getInstance()->getVerdicts()->setVerdict(
            (int) Craft::$app->getSites()->getPrimarySite()->id,
            $this->elementId,
            AuditService::RULE_POTENTIAL_FOCUS_OBSCURED,
            '<header class="site-header">',
            'dismissed',
        );

        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk());

        expect(focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_OBSCURED)[0]['verdict'])
            ->toBe('dismissed')
            ->and($this->audit->getPendingPotentialForScan($this->scanId))->toHaveCount(1);
    });

    it('caps what one page can store', function() {
        $notVisible = [];
        for ($i = 0; $i < 60; $i++) {
            $notVisible[] = ['html' => "<a href=\"/p{$i}\">"];
        }

        $obscured = [];
        for ($i = 0; $i < 12; $i++) {
            $obscured[] = ['html' => "<div class=\"bar-{$i}\">", 'position' => 'sticky', 'count' => $i === 0 ? 999999 : 1];
        }

        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk([
            'notVisible' => $notVisible,
            'obscured' => $obscured,
        ]));

        $covered = focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_OBSCURED);

        expect(focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE))->toHaveCount(50)
            ->and($covered)->toHaveCount(10)
            ->and($covered[0]['message'])->toContain('covered 10,000 controls');
    });

    it('stores one row per element, and nothing that is not markup', function() {
        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk([
            'notVisible' => [
                ['html' => '<a href="/x">One</a>'],
                ['html' => '<a href="/x">Two</a>'],
                ['html' => 'not markup'],
                'not even an array',
            ],
            'obscured' => [],
        ]));

        expect(focusStoreRows($this->scanId))->toHaveCount(1);
    });

    it('says when the walk stopped at its cap', function() {
        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk(['total' => 400, 'limit' => 150, 'checked' => 150]));

        expect(focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE)[0]['message'])
            ->toContain('Only the first 150 of 400 focusable elements on this page were checked.');
    });

    it('says when the walk stopped before its cap, and why', function(string $stopped, string $why) {
        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk([
            'total' => 400, 'limit' => 150, 'checked' => 12, 'stopped' => $stopped,
        ]));

        foreach (focusStoreRows($this->scanId) as $row) {
            expect($row['message'])
                ->toContain("The check stopped after 12 of 400 focusable elements on this page{$why}.")
                ->not->toContain('Only the first');
        }
    })->with([
        'out of time' => ['budget', ', when it ran out of time'],
        'a dialog' => ['dialog', ', when a dialog opened'],
        'focus refused' => ['refused', ', because most of them would not take focus'],
        'an address change' => ['navigation', ', when the page changed its address'],
        'a reason it does not know' => ['something-new', ''],
    ]);

    it('says nothing about coverage when the walk reached every control', function() {
        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk(['total' => 12, 'checked' => 12]));

        expect(focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE)[0]['message'])
            ->not->toContain('checked')
            ->not->toContain('stopped');
    });

    it('stores nothing for an ignored rule', function() {
        AccessibilityAudit::getInstance()->getSettings()->ignoreRules = [AuditService::RULE_POTENTIAL_FOCUS_OBSCURED];

        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk());

        expect(array_column(focusStoreRows($this->scanId), 'ruleId'))
            ->toBe([AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE]);
    });

    it('stores nothing for a site targeting Level A', function() {
        AccessibilityAudit::getInstance()->getSettings()->wcagLevel = 'A';

        $this->audit->storeFocusWalkIssues($this->scanId, focusStoreWalk());

        expect(focusStoreRows($this->scanId))->toHaveCount(0);
    });
});

describe('the stylesheet check', function() {
    it('stores a question against 2.4.7 under the contrast source', function() {
        $stored = $this->audit->storeFocusOutlineIssues($this->scanId, [
            ['selector' => '.btn:focus', 'html' => '<button class="btn">Go</button>', 'count' => 4],
        ], AuditService::VIEWPORT_DESKTOP);

        $rows = focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_OUTLINE);

        expect($stored)->toBe(1)
            ->and($rows[0]['wcagCriterion'])->toBe('2.4.7')
            ->and($rows[0]['wcagLevel'])->toBe('AA')
            ->and($rows[0]['severity'])->toBe('notice')
            ->and($rows[0]['source'])->toBe('contrast')
            ->and(AuditService::contextMarkup($rows[0]['context']))->toBe('<button class="btn">')
            ->and($rows[0]['message'])->toContain('".btn:focus"')
            ->and($rows[0]['message'])->toContain('4 focusable elements');
    });

    it('keeps two rules on the same element apart, so answering one leaves the other', function() {
        $rules = [
            ['selector' => '.btn:focus', 'html' => '<button class="btn">Go</button>', 'count' => 1],
            ['selector' => 'button:focus', 'html' => '<button class="btn">Go</button>', 'count' => 1],
        ];

        $this->audit->storeFocusOutlineIssues($this->scanId, $rules);
        $first = focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_OUTLINE)[0];

        AccessibilityAudit::getInstance()->getVerdicts()->setVerdict(
            (int) Craft::$app->getSites()->getPrimarySite()->id,
            $this->elementId,
            AuditService::RULE_POTENTIAL_FOCUS_OUTLINE,
            $first['context'],
            'dismissed',
        );

        // The next Inspect visit rebuilds the rows and carries the answer over.
        $this->audit->storeFocusOutlineIssues($this->scanId, $rules);
        $pending = $this->audit->getPendingPotentialForScan($this->scanId);

        expect($pending)->toHaveCount(1)
            ->and($pending[0]['message'])->toContain('"button:focus"');
    });

    it('survives the contrast store, and leaves the contrast rows alone', function() {
        $occurrence = ['fg' => '#777777', 'bg' => '#ffffff', 'ratio' => 4.48, 'expected' => '4.5:1', 'html' => '<p class="x">'];

        $this->audit->storeContrastIssues($this->scanId, [$occurrence]);
        $this->audit->storeFocusOutlineIssues($this->scanId, [
            ['selector' => 'a:focus', 'html' => '<a href="/">', 'count' => 1],
        ]);
        $this->audit->storeContrastIssues($this->scanId, [$occurrence]);

        $rules = array_column(focusStoreRows($this->scanId), 'ruleId');

        expect($rules)->toContain('color-contrast')
            ->and($rules)->toContain(AuditService::RULE_POTENTIAL_FOCUS_OUTLINE);
    });

    it('treats what the browser posts as untrusted', function() {
        $rules = [
            ['selector' => str_repeat('a', 500), 'html' => '<a href="/">', 'count' => 1],
            ['selector' => 'a:focus', 'html' => 'javascript:alert(1)', 'count' => 1],
            ['selector' => '', 'html' => '<a href="/two">', 'count' => 1],
            'rubbish',
            ['selector' => '.huge:focus', 'html' => '<a href="/huge">', 'count' => 999999],
        ];

        for ($i = 0; $i < 50; $i++) {
            $rules[] = ['selector' => ".r{$i}:focus", 'html' => "<a href=\"/r{$i}\">", 'count' => 1];
        }

        $this->audit->storeFocusOutlineIssues($this->scanId, $rules);
        $rows = focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_OUTLINE);

        // Forty read, three dropped, the long selector cut to 200.
        expect($rows)->toHaveCount(37)
            ->and($rows[0]['message'])->toContain('"' . str_repeat('a', 200) . '"')
            ->and($rows[0]['message'])->not->toContain(str_repeat('a', 201))
            ->and($rows[1]['message'])->toContain('10,000 focusable elements');
    });
});

describe('how a stylesheet question reads', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $this->audit->storeFocusOutlineIssues($this->scanId, [
            ['selector' => '.btn:focus', 'html' => '<button class="btn">Go</button>', 'count' => 1],
        ]);
        $this->context = focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_OUTLINE)[0]['context'];
    });

    it('shows the element on the page report, and posts the whole context back', function() {
        // The report draws its review cards only for a page with an address.
        $now = Db::prepareDateForDb(new DateTime());
        Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
            'elementId' => null, 'elementType' => null, 'url' => 'https://example.test/focus-outline',
            'siteId' => $this->siteId,
            'score' => 100, 'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
            'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 0,
            'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        $urlScanId = (int) Craft::$app->getDb()->getLastInsertID('{{%accessibilityaudit_scans}}');

        $this->audit->storeFocusOutlineIssues($urlScanId, [
            ['selector' => '.btn:focus', 'html' => '<button class="btn">Go</button>', 'count' => 1],
        ]);

        $html = $this->get('admin/accessibility-audit/page-report?' . http_build_query([
            'scanId' => $urlScanId,
            'siteId' => $this->siteId,
        ]))->assertOk()->content;

        expect($html)->toContain('<p class="accessibility-audit-pr-review__ctx" title="&lt;button class=&quot;btn&quot;&gt;">')
            ->and($html)->toContain('data-context="' . htmlspecialchars($this->context, ENT_QUOTES) . '"');
    });

    it('shows the element in the dismissed listing, and restores by the whole context', function() {
        AccessibilityAudit::getInstance()->getVerdicts()->setVerdict(
            $this->siteId,
            $this->elementId,
            AuditService::RULE_POTENTIAL_FOCUS_OUTLINE,
            $this->context,
            'dismissed',
        );

        $data = $this->http('get', 'actions/accessibility-audit/dashboard/dismissed-table?' . http_build_query([
            'siteId' => $this->siteId,
        ]))->addHeader('Accept', 'application/json')->send()->getJsonContent();

        $row = array_values(array_filter(
            $data['data'],
            fn(array $r): bool => $r['rule'] === AuditService::RULE_POTENTIAL_FOCUS_OUTLINE,
        ))[0];

        expect($row['question']['context'])->toBe('<button class="btn">')
            ->and($row['restore']['context'])->toBe($this->context);
    });
});

describe('AuditController::actionStoreContrastResults', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $this->post = fn(array $extra) => $this->postJson('actions/accessibility-audit/audit/store-contrast-results', array_merge([
            'scanId' => $this->scanId,
            'elementId' => $this->elementId,
            'elementType' => User::class,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'viewport' => 'desktop',
            'occurrences' => json_encode([]),
        ], $extra))->getJsonContent();
    });

    it('stores the focus rules the report posts and counts them', function() {
        $json = ($this->post)([
            'focusRules' => json_encode([['selector' => '*:focus', 'html' => '<a href="/">', 'count' => 9]]),
        ]);

        expect($json['success'])->toBeTrue()
            ->and($json['stored'])->toBe(1)
            ->and(focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_OUTLINE))->toHaveCount(1);
    });

    it('clears them on an empty list and leaves them alone when the list is missing', function() {
        ($this->post)(['focusRules' => json_encode([['selector' => '*:focus', 'html' => '<a href="/">', 'count' => 9]])]);

        // A report page cached before the check existed posts no list at all.
        ($this->post)([]);
        expect(focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_OUTLINE))->toHaveCount(1);

        // The page skipped the check, or found nothing: the old rows go.
        ($this->post)(['focusRules' => json_encode([])]);
        expect(focusStoreRows($this->scanId, AuditService::RULE_POTENTIAL_FOCUS_OUTLINE))->toHaveCount(0);
    });
});
