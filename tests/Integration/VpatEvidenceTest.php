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

    it('does not call a criterion automated when the scan only checks half of it', function() {
        // Page Titled and Language of Page were marked fully automated, so a
        // clean scan set them to Supports with nobody asked. The scan
        // establishes that a title exists and that html carries a lang
        // attribute. The criteria ask for a title that describes the page and
        // a lang value naming the language actually written, and neither is
        // something a scanner can settle: a site titling every page with the
        // site name, or serving lang="en" on a page of Irish, passed both.
        $criteria = $this->vpat->getCriteria();

        expect($criteria['2.4.2']['auto'])->toBe('partial')
            ->and($criteria['3.1.1']['auto'])->toBe('partial')
            // Contrast is the most thorough pass in the plugin and still only
            // measures computed colour. Text drawn into an image has none.
            ->and($criteria['1.4.3']['auto'])->toBe('partial');
    });

    it('claims full automation only where the scan settles the whole criterion', function() {
        // No criterion currently qualifies, and the tier is kept rather than
        // removed: it is the mechanism for saying so when one does. A criterion
        // whose evidence names something a scanner cannot reach is not one of
        // them, which is what this guards.
        $evidence = $this->vpat->getEvidence($this->siteId);
        $overclaiming = [];

        foreach ($this->vpat->getCriteria() as $num => $criterion) {
            if (($criterion['auto'] ?? '') === 'automated' && $evidence[$num]['cannot'] !== null) {
                $overclaiming[] = (string) $num;
            }
        }

        expect($overclaiming)->toBe([]);
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

// ---------------------------------------------------------------------------
// Three screens read the findings, and they have to agree.
//
// The AI draft was the third place reading that table its own way. It filtered
// resolved issues but not verdicts, so it handed the model four dismissed
// identical-link questions as evidence, and wrote a remark saying the scanner
// had identified four instances into a row whose own evidence line, two inches
// to the left, said it found nothing. Both came from the same table.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// The drafting rules come from real reports, not from taste.
//
// A corpus of 37 published conformance reports is recorded in
// reference/vpat-remark-patterns.md. The findings below drive the prompt, and
// each is here because dropping it would put the plugin's drafts back among the
// weakest documents in that corpus.
// ---------------------------------------------------------------------------

it('tells the model to name the fault, its place and its scale together', function() {
    // The sharpest finding in the corpus: published reports name what fails or
    // count it, and almost never do both with a location as well. The plugin
    // holds a rule, a page and an occurrence count for every finding, so it can.
    $source = (string) file_get_contents(
        (new ReflectionClass(\johnhenry\accessibilityaudit\services\VpatService::class))->getFileName(),
    );

    expect($source)->toContain('Name what fails, say where, and say how much of it there is');
});

it('will not let a workaround stand in for a fix', function() {
    // One report in the corpus marks contrast failures and notes they are
    // mitigated by an accessibility overlay. A buyer reads that as the fault
    // standing and a script being asked to cover it.
    $source = (string) file_get_contents(
        (new ReflectionClass(\johnhenry\accessibilityaudit\services\VpatService::class))->getFileName(),
    );

    expect($source)->toContain('A fault that is worked around is still a fault');
});

it('keeps the conventions the whole corpus agrees on', function() {
    $source = (string) file_get_contents(
        (new ReflectionClass(\johnhenry\accessibilityaudit\services\VpatService::class))->getFileName(),
    );

    // Not one of the eleven restates the criterion or puts test method in a
    // remark. Method belongs to the report, not to a row.
    expect($source)->toContain('Do not restate the success criterion')
        ->and($source)->toContain('Do not describe testing tools or method');
});

it('bars the weak patterns the corpus is full of', function() {
    $source = (string) file_get_contents(
        (new ReflectionClass(\johnhenry\accessibilityaudit\services\VpatService::class))->getFileName(),
    );

    expect($source)
        // Hedging with nothing behind it is the commonest failure in the corpus.
        ->toContain('No hedging unless something concrete sits beside it')
        // Design intent is not testable behaviour.
        ->and($source)->toContain('not what it was designed or intended to do')
        // The trap a component vendor falls into: qualify everything by saying
        // it depends on the implementer, and the report has said nothing.
        ->and($source)->toContain('Do not push the problem onto whoever is implementing');
});

it('drafts remarks from the findings the rest of the plugin recognises', function() {
    $source = (string) file_get_contents(
        (new ReflectionClass(\johnhenry\accessibilityaudit\services\VpatService::class))->getFileName(),
    );

    $start = (int) strpos($source, 'public function draftRemark(');
    $body = substr($source, $start, 2600);

    expect($body)->toContain('definiteCondition()')
        ->and($body)->toContain("->groupBy(['elementId', 'url'])");
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
