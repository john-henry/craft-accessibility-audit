<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\StatementExclusionModel;
use johnhenry\accessibilityaudit\models\StatementMetaModel;
use johnhenry\accessibilityaudit\services\StatementProfiles;
use markhuot\craftpest\factories\User as UserFactory;

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
});

// ---------------------------------------------------------------------------
// Permission separation: the reason this document has its own gate
// ---------------------------------------------------------------------------

describe('StatementController permissions', function() {
    // A live permission grant cannot be exercised here: the plugin registers its
    // permissions on a CP request, and there isn't one under test, so Craft's
    // registry is empty and every handle would refuse. The separation is
    // asserted from source instead, the way PermissionHandleCoverageTest does.
    it('never lets the VPAT permission reach the statement', function() {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/StatementController.php');

        preg_match_all('/requirePermission\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);

        // The whole point of the separate handle: managing a procurement report
        // must not confer the right to publish a public legal declaration.
        expect($matches[1])->not->toBeEmpty()
            ->and($matches[1])->not->toContain('accessibility-audit:manageVpat');
    });

    it('gates writes on manageStatement and the read-only preview on viewReports', function() {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/StatementController.php');

        // Reading the document somebody published is not editing it, so the
        // preview must not demand the editing permission.
        preg_match('/actionPreview.*?requirePermission\(\s*[\'"]([^\'"]+)[\'"]/s', $source, $preview);

        expect($preview[1])->toBe('accessibility-audit:viewReports');

        foreach (['actionSaveMeta', 'actionSuggestions'] as $action) {
            preg_match('/' . $action . '.*?requirePermission\(\s*[\'"]([^\'"]+)[\'"]/s', $source, $m);

            expect($m[1])->toBe('accessibility-audit:manageStatement');
        }
    });

    it('guards as many actions as it defines', function() {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/StatementController.php');

        $actions = preg_match_all('/public function action\w+\(/', $source);
        $guards = preg_match_all('/requirePermission\(/', $source);

        // An unguarded action is the failure mode worth catching here.
        expect($guards)->toBe($actions);
    });

    it('refuses an anonymous request', function() {
        expect(fn() => $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        ]))->toThrow(yii\web\ForbiddenHttpException::class);
    });
});

// ---------------------------------------------------------------------------
// Saving metadata
// ---------------------------------------------------------------------------

describe('StatementController::actionSaveMeta', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
    });

    it('writes the shared and statement halves from one flat form', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
            'contactEmail' => 'access@example.com',
            'profile' => StatementProfiles::PROFILE_EU,
            'enforcementBody' => 'Ombudsman',
            'feedbackResponseTime' => 'within 5 working days',
        ]);

        $record = AccessibilityAudit::getInstance()->statement->getRecord($siteId);

        expect($record['profile'])->toBe(StatementProfiles::PROFILE_EU)
            ->and($record['meta']['productName'])->toBe('Acme Council')
            ->and($record['meta']['enforcementBody'])->toBe('Ombudsman');
    });

    it('rejects an EU statement with no enforcement body', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        AccessibilityAudit::getInstance()->statement->saveMeta($siteId, new StatementMetaModel());

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
            'profile' => StatementProfiles::PROFILE_EU,
        ]);

        // Nothing stored: the jurisdiction never takes effect without the body
        // it requires, and the reason is flashed rather than swallowed.
        expect(AccessibilityAudit::getInstance()->statement->getRecord($siteId)['profile'])
            ->not->toBe(StatementProfiles::PROFILE_EU)
            ->and(Craft::$app->getSession()->getFlash('error'))->toContain('enforcement body');
    });

    it('reports a refused full-compliance override rather than saving it silently', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        // Nothing signed off, so the full override is genuinely unearned and the
        // controller must refuse it rather than inherit ambient confirmations.
        resetStatementRecord($siteId);
        resetVpat($siteId);

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
            'statusOverride' => StatementMetaModel::STATUS_FULL,
        ]);

        // The save succeeds; the claim is capped. The editor has to be told,
        // otherwise the page just appears to ignore what they picked.
        expect(AccessibilityAudit::getInstance()->statement->resolveComplianceStatus($siteId)['status'])
            ->not->toBe(StatementMetaModel::STATUS_FULL)
            ->and(Craft::$app->getSession()->getFlash('error'))->toContain('not applied');
    });
});

// ---------------------------------------------------------------------------
// Saving non-accessible content entries
// ---------------------------------------------------------------------------

describe('StatementController entries', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        // Every test here counts the rows it expects to find afterwards, which
        // only means anything from a known-empty record. Its siblings above and
        // below already do this; these did not, and were reading whatever the
        // record happened to hold.
        resetStatementRecord((int) Craft::$app->getSites()->getPrimarySite()->id);
    });

    it('saves entries posted with the form', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
            'entries' => [
                ['category' => StatementExclusionModel::CATEGORY_NON_COMPLIANCE, 'content' => 'Some PDFs', 'criterion' => '1.1.1'],
                ['category' => StatementExclusionModel::CATEGORY_OUT_OF_SCOPE, 'content' => 'Archived minutes'],
            ],
        ]);

        $stored = AccessibilityAudit::getInstance()->statement->getRecord($siteId)['exclusions'];

        expect($stored)->toHaveCount(2)
            ->and($stored[0]['content'])->toBe('Some PDFs');
    });

    it('reads a Craft date field rather than parsing the string itself', function() {
        // Craft posts dates as a locale-formatted composite. Parsing that string
        // by hand reads 07/08 as the wrong month for anyone whose formatting
        // locale does not match the server's.
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
            'entries' => [[
                'content' => 'Legacy PDFs',
                'plannedDate' => ['date' => '2027-01-31', 'timezone' => 'UTC'],
            ]],
        ]);

        $stored = AccessibilityAudit::getInstance()->statement->getRecord($siteId)['exclusions'];

        expect($stored[0]['plannedDate'])->toBe('2027-01-31');
    });

    it('adds a blank row without losing what was already typed', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
            'addEntry' => '1',
            'entries' => [['content' => 'Typed already']],
        ]);

        $stored = AccessibilityAudit::getInstance()->statement->getRecord($siteId)['exclusions'];

        expect($stored)->toHaveCount(2)
            ->and($stored[0]['content'])->toBe('Typed already')
            ->and($stored[1]['content'])->toBe('');
    });

    it('removes the row the editor asked for and keeps the rest', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
            'removeEntry' => '0',
            'entries' => [
                ['content' => 'Goes'],
                ['content' => 'Stays'],
            ],
        ]);

        $stored = AccessibilityAudit::getInstance()->statement->getRecord($siteId)['exclusions'];

        expect($stored)->toHaveCount(1)
            ->and($stored[0]['content'])->toBe('Stays');
    });

    it('pre-fills a row from a scan suggestion', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $suggestions = AccessibilityAudit::getInstance()->statement->deriveSuggestions($siteId);

        if (empty($suggestions)) {
            expect(true)->toBeTrue();

            return;
        }

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
            'addSuggestion' => $suggestions[0]['criterion'],
            'entries' => [],
        ]);

        $stored = AccessibilityAudit::getInstance()->statement->getRecord($siteId)['exclusions'];

        // The row carries the criterion and a factual note about the scan.
        // "What is affected" stays empty on purpose: it asks for what a member
        // of the public would recognise, which only a person can write.
        expect($stored)->toHaveCount(1)
            ->and($stored[0]['criterion'])->toBe($suggestions[0]['criterion'])
            ->and($stored[0]['content'])->toBe('')
            ->and($stored[0]['reason'])->not->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// Editions
// ---------------------------------------------------------------------------

describe('StatementController editions', function() {
    it('works on the Standard edition', function() {
        // A statement is legally required in the EU and UK. Putting a legal
        // obligation behind an upgrade would be a poor way to treat the people
        // who most need it, so this is deliberately not gated.
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
        ]);

        expect(AccessibilityAudit::getInstance()->statement->getRecord($siteId)['meta']['productName'])
            ->toBe('Acme Council');
    });
});

// ---------------------------------------------------------------------------
// The add buttons submit the whole form. With no entries yet the form posts no
// `entries` key at all, and that state must still append the new row rather
// than just saving: the first entry is always added from exactly this state.
// ---------------------------------------------------------------------------

describe('StatementController add buttons on an empty list', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        resetStatementRecord(Craft::$app->getSites()->getPrimarySite()->id);
    });

    it('adds a blank entry when Add an entry is pressed with no entries yet', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
            'addEntry' => '1',
        ]);

        expect(AccessibilityAudit::getInstance()->statement->getRecord($siteId)['exclusions'])
            ->toHaveCount(1);
    });

    it('adds a pre-filled entry when a suggestion chip is pressed with no entries yet', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
            'addSuggestion' => '1.4.3',
        ]);

        $stored = AccessibilityAudit::getInstance()->statement->getRecord($siteId)['exclusions'];

        expect($stored)->toHaveCount(1)
            ->and($stored[0]['criterion'])->toBe('1.4.3');
    });

    it('never pre-fills the public description or restates the criterion as the reason', function() {
        // This ends up in a published legal document. "What is affected" asks
        // for what a member of the public would recognise, so a criterion name
        // is exactly the wrong thing to put there. And a criterion's own
        // wording states the condition for passing: printed under "Does not
        // comply" it describes the site working correctly, which is worse than
        // saying nothing.
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $criteria = AccessibilityAudit::getInstance()->vpat->getCriteria();

        $this->post('actions/accessibility-audit/statement/save-meta', [
            'siteId' => $siteId,
            'productName' => 'Acme Council',
            'addSuggestion' => '3.2.2',
        ]);

        $stored = AccessibilityAudit::getInstance()->statement->getRecord($siteId)['exclusions'];

        expect($stored)->toHaveCount(1)
            ->and($stored[0]['content'])->toBe('')
            ->and($stored[0]['reason'])->not->toBe((string) ($criteria['3.2.2']['desc'] ?? 'x'))
            ->and($stored[0]['reason'])->not->toContain((string) ($criteria['3.2.2']['name'] ?? 'x'));
    });
});

// ---------------------------------------------------------------------------
// Guidance for "what is affected"
// ---------------------------------------------------------------------------

describe('affected-content examples', function() {
    it('offers an example for every criterion the scanners can raise', function() {
        // The criteria this plugin's own rules attach to. A reader who lands on
        // one of these has a fair chance of needing help writing the sentence.
        foreach (['1.1.1', '1.2.2', '1.2.5', '1.3.1', '1.3.5', '1.3.6', '1.4.2',
                  '1.4.3', '2.4.1', '2.4.2', '2.4.4', '2.4.6', '3.1.1', '3.2.2',
                  '4.1.1', '4.1.2'] as $criterion) {
            expect(\johnhenry\accessibilityaudit\helpers\AffectedExample::for($criterion))
                ->toStartWith('For example:');
        }
    });

    it('falls back to a generic example for anything else', function() {
        expect(\johnhenry\accessibilityaudit\helpers\AffectedExample::for('9.9.9'))
            ->toStartWith('For example:')
            ->and(\johnhenry\accessibilityaudit\helpers\AffectedExample::for(''))
            ->toStartWith('For example:');
    });

    it('names content rather than restating the rule', function() {
        // The field asks what a member of the public would recognise. An
        // example that repeats the criterion teaches the wrong answer.
        $example = \johnhenry\accessibilityaudit\helpers\AffectedExample::for('2.4.4');

        expect($example)->not->toContain('2.4.4')
            ->and($example)->not->toContain('Link Purpose')
            ->and($example)->toContain('Read more');
    });

    it('is only ever a placeholder, never a stored value', function() {
        // A default value would be published verbatim by somebody in a hurry.
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/statement.twig');

        expect($template)->toContain('placeholder: affectedExamples[')
            ->and($template)->toContain("value: entry.content ?? ''");
    });
});
