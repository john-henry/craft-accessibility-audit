<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\StatementExclusionModel;
use johnhenry\accessibilityaudit\models\StatementMetaModel;
use johnhenry\accessibilityaudit\services\StatementProfiles;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function statementService(): \johnhenry\accessibilityaudit\services\StatementService
{
    return AccessibilityAudit::getInstance()->statement;
}

function primarySiteId(): int
{
    return Craft::$app->getSites()->getPrimarySite()->id;
}

/**
 * Clears any stored statement for a site.
 *
 * A real install has a statement the moment somebody opens the page, so a test
 * asserting "no statement yet" has to make that true rather than assume it.
 * RefreshesDatabase rolls this back, so nobody's actual data is at risk.
 */
function resetStatement(int $siteId): void
{
    Craft::$app->getDb()->createCommand()
        ->delete('{{%accessibilityaudit_statement}}', ['siteId' => $siteId])
        ->execute();
}

/**
 * Marks every manual VPAT criterion as Supports, the way an editor signing off
 * the report would. This is the only route to a full compliance claim.
 */
function confirmAllManualCriteria(int $siteId): void
{
    $vpat = AccessibilityAudit::getInstance()->vpat;
    $report = $vpat->getFullReport($siteId);

    foreach (array_merge($report['levelA'], $report['levelAA']) as $number => $row) {
        $vpat->saveOverride($siteId, (string) $number, 'Supports', 'Verified manually.');
    }
}

// ---------------------------------------------------------------------------
// Storage
// ---------------------------------------------------------------------------

describe('StatementService storage', function() {
    it('creates a record on first read with the generic profile', function() {
        resetStatement(primarySiteId());

        $record = statementService()->getRecord(primarySiteId());

        expect($record['profile'])->toBe(StatementProfiles::PROFILE_GENERIC)
            ->and($record['exclusions'])->toBe([])
            ->and($record['sourceScanDate'])->toBeNull();
    });

    it('merges the shared organisation metadata underneath its own', function() {
        $siteId = primarySiteId();

        saveVpatMetaFlat($siteId, [
            'productName' => 'Acme Council',
            'contactEmail' => 'access@example.com',
        ]);

        $meta = new StatementMetaModel();
        $meta->profile = StatementProfiles::PROFILE_EU;
        $meta->enforcementBody = 'Ombudsman';
        $meta->feedbackResponseTime = 'within 5 working days';
        statementService()->saveMeta($siteId, $meta);

        $record = statementService()->getRecord($siteId);

        // One flat array: the statement's own fields and the shared ones the
        // VPAT also reads, with no duplicate entry anywhere.
        expect($record['profile'])->toBe(StatementProfiles::PROFILE_EU)
            ->and($record['meta']['productName'])->toBe('Acme Council')
            ->and($record['meta']['contactEmail'])->toBe('access@example.com')
            ->and($record['meta']['enforcementBody'])->toBe('Ombudsman')
            ->and($record['meta']['feedbackResponseTime'])->toBe('within 5 working days');
    });

    it('keeps the profile in its own column, not the blob', function() {
        $siteId = primarySiteId();

        $meta = new StatementMetaModel();
        $meta->profile = StatementProfiles::PROFILE_UK;
        $meta->enforcementBody = 'EHRC';
        statementService()->saveMeta($siteId, $meta);

        $stored = (new craft\db\Query())
            ->select(['profile', 'meta'])
            ->from('{{%accessibilityaudit_statement}}')
            ->where(['siteId' => $siteId])
            ->one();

        expect($stored['profile'])->toBe(StatementProfiles::PROFILE_UK)
            ->and(craft\helpers\Json::decode($stored['meta']))->not->toHaveKey('profile');
    });

    it('round-trips exclusion entries and stamps the scan date', function() {
        $siteId = primarySiteId();

        statementService()->saveExclusions($siteId, [
            StatementExclusionModel::fromArray([
                'category' => StatementExclusionModel::CATEGORY_BURDEN,
                'content' => 'Legacy video archive',
                'reason' => 'Recaptioning 4,000 hours is disproportionate.',
                'criterion' => '1.2.2',
            ]),
        ]);

        $record = statementService()->getRecord($siteId);

        expect($record['exclusions'])->toHaveCount(1)
            ->and($record['exclusions'][0]['content'])->toBe('Legacy video archive')
            ->and($record['exclusions'][0]['category'])->toBe(StatementExclusionModel::CATEGORY_BURDEN)
            ->and($record['sourceScanDate'])->not->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// The compliance gate: the part with legal consequences
// ---------------------------------------------------------------------------

describe('StatementService::deriveComplianceStatus', function() {
    it('never claims full compliance from scan data alone', function() {
        // The heart of it. A site with no recorded failures still has dozens of
        // criteria no scanner can test. Automated silence is not evidence.
        // Clear both records so nothing is pre-confirmed: the unconfirmed
        // manual criteria are the whole point of the assertion.
        resetStatement(primarySiteId());
        resetVpat(primarySiteId());

        $derivation = statementService()->deriveComplianceStatus(primarySiteId());

        expect($derivation['status'])->not->toBe(StatementMetaModel::STATUS_FULL)
            ->and($derivation['canClaimFull'])->toBeFalse()
            ->and($derivation['unconfirmedCriteria'])->not->toBeEmpty();
    });

    it('allows a full claim once every manual criterion is signed off', function() {
        $siteId = primarySiteId();
        // A full claim is gated on the site actually having been scanned, so
        // seed a scan before signing everything off.
        seedComplianceScan($siteId);
        confirmAllManualCriteria($siteId);

        $derivation = statementService()->deriveComplianceStatus($siteId);

        expect($derivation['canClaimFull'])->toBeTrue()
            ->and($derivation['status'])->toBe(StatementMetaModel::STATUS_FULL)
            ->and($derivation['failingCriteria'])->toBeEmpty()
            ->and($derivation['unconfirmedCriteria'])->toBeEmpty();
    });

    it('lets a blanket attestation stand in for per-criterion VPAT sign-off', function() {
        // The VPAT is a Pro feature and the statement is not, so a Standard site
        // needs some route to a full claim. The principle held is unchanged: a
        // person must say they checked, the machine never says it for them.
        $siteId = primarySiteId();
        // Nothing confirmed to start (so criteria read as unconfirmed), but a
        // scan on record so the claim becomes reachable once attested below.
        resetStatement($siteId);
        resetVpat($siteId);
        seedComplianceScan($siteId);

        expect(statementService()->deriveComplianceStatus($siteId)['unconfirmedCriteria'])->not->toBeEmpty();

        $meta = new StatementMetaModel();
        $meta->manualReviewConfirmed = true;
        statementService()->saveMeta($siteId, $meta);

        $derivation = statementService()->deriveComplianceStatus($siteId);

        // The attestation clears the unexamined criteria. Anything the scanner
        // actually found still stands in its way, which the next test covers.
        expect($derivation['unconfirmedCriteria'])->toBeEmpty();

        // With the failures cleared too, the claim is now reachable without the
        // VPAT, which is the whole point on a Standard install.
        foreach ($derivation['failingCriteria'] as $number) {
            AccessibilityAudit::getInstance()->vpat->saveOverride($siteId, $number, 'Supports', 'Fixed.');
        }

        expect(statementService()->deriveComplianceStatus($siteId)['canClaimFull'])->toBeTrue();
    });

    it('still refuses a full claim when something is actually failing, attested or not', function() {
        // Attesting to a manual review says nothing about the failures a scanner
        // did find. Those have to be fixed or written into the statement.
        $siteId = primarySiteId();

        $meta = new StatementMetaModel();
        $meta->manualReviewConfirmed = true;
        statementService()->saveMeta($siteId, $meta);

        AccessibilityAudit::getInstance()->vpat->saveOverride($siteId, '1.1.1', 'Does Not Support', 'Images lack alt text.');

        expect(statementService()->deriveComplianceStatus($siteId)['canClaimFull'])->toBeFalse();
    });

    it('drops back below full when a single criterion is marked as failing', function() {
        $siteId = primarySiteId();
        confirmAllManualCriteria($siteId);
        AccessibilityAudit::getInstance()->vpat->saveOverride($siteId, '1.1.1', 'Does Not Support', 'Images lack alt text.');

        $derivation = statementService()->deriveComplianceStatus($siteId);

        expect($derivation['canClaimFull'])->toBeFalse()
            ->and($derivation['failingCriteria'])->toContain('1.1.1');
    });

    it('reports not compliant when most criteria are failing', function() {
        $siteId = primarySiteId();
        $vpat = AccessibilityAudit::getInstance()->vpat;
        $report = $vpat->getFullReport($siteId);
        $all = array_keys(array_merge($report['levelA'], $report['levelAA']));

        // Fail three quarters: past the halfway mark, "partially compliant"
        // would be flattering the site to the public.
        foreach (array_slice($all, 0, (int) ceil(count($all) * 0.75)) as $number) {
            $vpat->saveOverride($siteId, (string) $number, 'Does Not Support', 'Fails.');
        }

        expect(statementService()->deriveComplianceStatus($siteId)['status'])
            ->toBe(StatementMetaModel::STATUS_NONE);
    });
});

// ---------------------------------------------------------------------------
// Overrides: an editor may narrow a claim, never inflate it
// ---------------------------------------------------------------------------

describe('StatementService::resolveComplianceStatus', function() {
    it('refuses an override up to full while criteria are unexamined', function() {
        $siteId = primarySiteId();
        // Nothing signed off, so the full claim is genuinely unearned.
        resetStatement($siteId);
        resetVpat($siteId);

        $meta = new StatementMetaModel();
        $meta->statusOverride = StatementMetaModel::STATUS_FULL;
        statementService()->saveMeta($siteId, $meta);

        $resolved = statementService()->resolveComplianceStatus($siteId);

        expect($resolved['status'])->not->toBe(StatementMetaModel::STATUS_FULL)
            ->and($resolved['refusedOverride'])->toBeTrue()
            ->and($resolved['isOverridden'])->toBeFalse();
    });

    it('accepts an override downwards', function() {
        $siteId = primarySiteId();

        // A person may well know something the scanner does not. Narrowing a
        // claim is always theirs to do.
        $meta = new StatementMetaModel();
        $meta->statusOverride = StatementMetaModel::STATUS_NONE;
        statementService()->saveMeta($siteId, $meta);

        $resolved = statementService()->resolveComplianceStatus($siteId);

        expect($resolved['status'])->toBe(StatementMetaModel::STATUS_NONE)
            ->and($resolved['isOverridden'])->toBeTrue()
            ->and($resolved['refusedOverride'])->toBeFalse();
    });

    it('accepts an override up to full once it is actually earned', function() {
        $siteId = primarySiteId();
        seedComplianceScan($siteId);
        confirmAllManualCriteria($siteId);

        $meta = new StatementMetaModel();
        $meta->statusOverride = StatementMetaModel::STATUS_FULL;
        statementService()->saveMeta($siteId, $meta);

        expect(statementService()->resolveComplianceStatus($siteId)['status'])
            ->toBe(StatementMetaModel::STATUS_FULL);
    });
});

// ---------------------------------------------------------------------------
// Suggestions and staleness
// ---------------------------------------------------------------------------

describe('StatementService suggestions', function() {
    it('drops a suggestion once the statement covers that criterion', function() {
        $siteId = primarySiteId();
        $before = statementService()->deriveSuggestions($siteId);

        if (empty($before)) {
            expect(statementService()->isStale($siteId))->toBeFalse();

            return;
        }

        $criterion = $before[0]['criterion'];

        statementService()->saveExclusions($siteId, [
            StatementExclusionModel::fromArray([
                'content' => 'Covered in the statement',
                'criterion' => $criterion,
            ]),
        ]);

        $after = array_column(statementService()->deriveSuggestions($siteId), 'criterion');

        expect($after)->not->toContain($criterion);
    });

    it('describes suggestions by WCAG criterion, not by rule ID', function() {
        // A statement is read by the public and speaks in terms of the standard.
        // "img-alt" means nothing to them; "Non-text Content (1.1.1)" does.
        $suggestions = statementService()->deriveSuggestions(primarySiteId());

        expect($suggestions)->toBeArray();

        foreach ($suggestions as $suggestion) {
            expect($suggestion['criterion'])->toMatch('/^\d+\.\d+\.\d+$/')
                ->and($suggestion['name'])->not->toBeEmpty();
        }
    });
});

// ---------------------------------------------------------------------------
// Assembled statement
// ---------------------------------------------------------------------------

describe('StatementService data shape', function() {
    // Both of these were real bugs. The CP editor cannot be rendered under test
    // (its layout needs request-scoped globals), so the contract it depends on
    // is asserted directly, which is the better test anyway.
    it('returns every metadata key even on a record that was never saved', function() {
        // A template reading a missing key throws under strict_variables, so an
        // untouched statement would have taken the editor down on first load.
        $meta = statementService()->getRecord(primarySiteId())['meta'];

        foreach (StatementMetaModel::storageKeys() as $key) {
            expect($meta)->toHaveKey($key);
        }

        foreach (johnhenry\accessibilityaudit\models\OrganisationMetaModel::storageKeys() as $key) {
            expect($meta)->toHaveKey($key);
        }
    });

    it('exposes entries flat for the editor and grouped for the document', function() {
        $siteId = primarySiteId();

        statementService()->saveExclusions($siteId, [
            StatementExclusionModel::fromArray(['content' => 'A flat entry']),
        ]);

        $statement = statementService()->getFullStatement($siteId);

        // The editor edits one ordered list; the published document renders each
        // legal category under its own heading. Handing either the other shape
        // silently renders nothing.
        expect($statement['entries'])->toBeArray()
            ->and($statement['entries'][0]['content'])->toBe('A flat entry')
            ->and(array_keys($statement['exclusions']))->toBe(StatementExclusionModel::categories());
    });
});

describe('StatementService::getFullStatement', function() {
    it('flags a target level that cannot support the profile claim', function() {
        $siteId = primarySiteId();
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $original = $settings->wcagLevel;

        $meta = new StatementMetaModel();
        $meta->profile = StatementProfiles::PROFILE_EU;
        $meta->enforcementBody = 'Ombudsman';
        statementService()->saveMeta($siteId, $meta);

        $settings->wcagLevel = 'A';
        $statement = statementService()->getFullStatement($siteId);
        $settings->wcagLevel = $original;

        // EN 301 549 needs AA. Publishing an EU statement off a Level A target
        // would claim a standard the site was never measured against.
        expect($statement['targetLevelSatisfies'])->toBeFalse();
    });

    it('carries the profile legislation through for the commitment section', function() {
        $siteId = primarySiteId();

        $meta = new StatementMetaModel();
        $meta->profile = StatementProfiles::PROFILE_EU;
        $meta->enforcementBody = 'Ombudsman';
        statementService()->saveMeta($siteId, $meta);

        $statement = statementService()->getFullStatement($siteId);

        expect($statement['legislation'])->toContain('2016/2102')
            ->and($statement['standard'])->toContain('EN 301 549');
    });

    it('groups exclusions under the three legal categories', function() {
        $siteId = primarySiteId();

        statementService()->saveExclusions($siteId, [
            StatementExclusionModel::fromArray(['category' => StatementExclusionModel::CATEGORY_NON_COMPLIANCE, 'content' => 'A']),
            StatementExclusionModel::fromArray(['category' => StatementExclusionModel::CATEGORY_BURDEN, 'content' => 'B']),
        ]);

        $grouped = statementService()->getFullStatement($siteId)['exclusions'];

        expect(array_keys($grouped))->toBe(StatementExclusionModel::categories())
            ->and($grouped[StatementExclusionModel::CATEGORY_NON_COMPLIANCE])->toHaveCount(1)
            ->and($grouped[StatementExclusionModel::CATEGORY_BURDEN])->toHaveCount(1)
            ->and($grouped[StatementExclusionModel::CATEGORY_OUT_OF_SCOPE])->toBeEmpty();
    });
});
