<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\VerdictService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Most of a VPAT is signed off by a person, and the screen was not helping.
//
// Automated testing reaches a fraction of WCAG, so the great majority of the
// criteria come down to a human decision. Each of those rows offered a dropdown
// and an empty box, which means the person answering has to go and work out for
// themselves what has already been tested before they can answer anything. On a
// real site that is 47 rows of it.
//
// The plugin already knows the answer. Both halves have to be said: what was
// covered, so the work is not repeated, and what was not, so the person knows
// where to actually go looking. And a criterion no scanner contributes to at
// all needs saying too, because "nothing here is automated" is itself the
// fastest thing you can tell somebody about that row.
// ---------------------------------------------------------------------------

function evidenceScan(int $elementId, int $siteId): int
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

function evidenceIssue(int $scanId, int $elementId, int $siteId, array $overrides = []): void
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_issues}}', array_merge([
        'scanId' => $scanId, 'elementId' => $elementId, 'elementType' => User::class,
        'siteId' => $siteId, 'ruleId' => 'empty-heading',
        'wcagCriterion' => '1.3.1', 'wcagLevel' => 'A',
        'severity' => 'error', 'message' => 'x', 'context' => '<h2>', 'source' => 'php',
        'isResolved' => false, 'firstDetected' => $now,
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
    ], $overrides))->execute();
}

beforeEach(function() {
    $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $this->elementId = (int) UserFactory::factory()->create()->id;
    $this->scanId = evidenceScan($this->elementId, $this->siteId);
    $this->vpat = AccessibilityAudit::getInstance()->vpat;

    // The development database carries real findings, and 1.3.1 is the busiest
    // criterion in the set. Rolled back with everything else.
    Craft::$app->getDb()->createCommand()
        ->delete('{{%accessibilityaudit_issues}}', ['wcagCriterion' => '1.3.1'])
        ->execute();
});

describe('VpatService::getEvidence', function() {
    it('covers every criterion, including the ones no scanner reaches', function() {
        $evidence = $this->vpat->getEvidence($this->siteId);

        expect($evidence)->toHaveCount(count($this->vpat->getCriteria()));

        foreach ($this->vpat->getCriteria() as $num => $_) {
            expect($evidence)->toHaveKey((string) $num);
        }
    });

    it('says both what was checked and what is left for a person', function() {
        $row = $this->vpat->getEvidence($this->siteId)['1.1.1'];

        expect($row['checks'])->toContain('alt attribute')
            ->and($row['cannot'])->toContain('describes the image');
    });

    it('never claims coverage without also naming the gap', function() {
        // Half the sentence is worse than none of it: "we checked this" with
        // no limit stated reads as "this criterion is done".
        foreach ($this->vpat->getEvidence($this->siteId) as $num => $row) {
            if ($row['checks'] !== null) {
                expect($row['cannot'])->not->toBeNull("criterion {$num} claims coverage with no gap stated");
            }
        }
    });

    it('leaves a criterion no scanner contributes to blank rather than inventing coverage', function() {
        // 2.4.7 Focus Visible: a static pass cannot exercise focus states, so
        // the plugin deliberately makes no claim about it.
        $row = $this->vpat->getEvidence($this->siteId)['2.4.7'];

        expect($row['checks'])->toBeNull()
            ->and($row['cannot'])->toBeNull();
    });

    it('counts the findings still open against a criterion', function() {
        evidenceIssue($this->scanId, $this->elementId, $this->siteId);

        expect($this->vpat->getEvidence($this->siteId)['1.3.1']['findings'])->toBe(1);
    });

    it('does not count a question the author has answered', function() {
        evidenceIssue($this->scanId, $this->elementId, $this->siteId, [
            'ruleId' => 'potential:possible-heading', 'severity' => 'notice',
            'verdict' => VerdictService::VERDICT_DISMISSED,
        ]);

        expect($this->vpat->getEvidence($this->siteId)['1.3.1']['findings'])->toBe(0);
    });

    it('does not count an issue that has been fixed', function() {
        evidenceIssue($this->scanId, $this->elementId, $this->siteId, ['isResolved' => true]);

        expect($this->vpat->getEvidence($this->siteId)['1.3.1']['findings'])->toBe(0);
    });

    it('reports how many pages the evidence came from', function() {
        expect($this->vpat->getEvidence($this->siteId)['1.3.1']['pages'])->toBeGreaterThan(0);
    });
});

it('hands the evidence to the report so the screen can show it', function() {
    $report = AccessibilityAudit::getInstance()->vpat->getFullReport($this->siteId);

    expect($report['levelA']['1.1.1']['evidence']['checks'])->not->toBeNull();
});

it('renders the evidence without a Twig error', function() {
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();

    try {
        $view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);
        $node = $view->getTwig()->parse(
            $view->getTwig()->tokenize($view->getTwig()->getLoader()->getSourceContext('accessibility-audit/vpat')),
        );

        expect($node)->toBeInstanceOf(\Twig\Node\ModuleNode::class);
    } finally {
        $view->setTemplateMode($mode);
    }
});

it('does not disturb the field names the autosave posts', function() {
    // The VPAT form saves as you type, keyed on these. The evidence is read
    // only and sits beside them; renaming anything here breaks saving.
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/vpat.twig');

    expect($twig)->toContain('class="accessibility-audit-vpat-select" data-criterion="{{ num }}"')
        ->and($twig)->toContain('data-criterion="{{ num }}" data-auto-level=');
});
