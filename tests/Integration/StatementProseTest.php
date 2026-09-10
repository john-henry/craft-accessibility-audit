<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\StatementExclusionModel;
use johnhenry\accessibilityaudit\models\StatementMetaModel;
use johnhenry\accessibilityaudit\services\StatementProfiles;
use johnhenry\accessibilityaudit\variables\AccessibilityVariable;

// ---------------------------------------------------------------------------
// The sentences in the published statement, as a reader sees them.
//
// This is a legal document carrying the site owner's name, so a stray mark in
// it is a defect in their copy, not in ours. The rest of the statement tests
// assert structure and data; these assert the prose.
// ---------------------------------------------------------------------------

function renderProseStatement(int $siteId): string
{
    return (string)(new AccessibilityVariable())->accessibilityStatementHtml($siteId);
}

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    AccessibilityAudit::getInstance()->getSettings()->statementTemplate = '';

    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    resetVpat($siteId);
    resetScanData($siteId);
    saveVpatMetaFlat($siteId, ['productName' => 'Acme Council']);
});

describe('the commitment sentence', function() {
    it('closes cleanly on a profile that names no legislation', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $meta = new StatementMetaModel();
        $meta->profile = StatementProfiles::PROFILE_GENERIC;
        AccessibilityAudit::getInstance()->statement->saveMeta($siteId, $meta);

        expect(renderProseStatement($siteId))
            ->toContain('committed to making this website accessible.')
            ->not->toContain('accessible,.');
    });

    it('names the legislation where the profile carries one', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $meta = new StatementMetaModel();
        $meta->profile = StatementProfiles::PROFILE_EU;
        AccessibilityAudit::getInstance()->statement->saveMeta($siteId, $meta);

        expect(renderProseStatement($siteId))
            ->toContain('accessible, in accordance with');
    });
});

describe('an exclusion the author ended with a full stop', function() {
    beforeEach(function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $meta = new StatementMetaModel();
        $meta->profile = StatementProfiles::PROFILE_EU;
        AccessibilityAudit::getInstance()->statement->saveMeta($siteId, $meta);

        AccessibilityAudit::getInstance()->statement->saveExclusions($siteId, [
            StatementExclusionModel::fromArray([
                'category' => StatementExclusionModel::CATEGORY_OUT_OF_SCOPE,
                'content' => 'Third party booking software on the admissions pages.',
                'reason' => 'The supplier publishes its own conformance report.',
            ]),
            StatementExclusionModel::fromArray([
                'category' => StatementExclusionModel::CATEGORY_BURDEN,
                'content' => 'The lecture archive published before 2020.',
                'reason' => 'Captioning the whole of it is disproportionate.',
            ]),
        ]);
    });

    it('does not double the full stop before the reason', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        expect(renderProseStatement($siteId))
            ->not->toContain('..')
            ->toContain('admissions pages. The supplier');
    });

    it('still separates the two where the author left the full stop off', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        AccessibilityAudit::getInstance()->statement->saveExclusions($siteId, [
            StatementExclusionModel::fromArray([
                'category' => StatementExclusionModel::CATEGORY_OUT_OF_SCOPE,
                'content' => 'Third party booking software on the admissions pages',
                'reason' => 'The supplier publishes its own conformance report.',
            ]),
        ]);

        expect(renderProseStatement($siteId))
            ->toContain('admissions pages. The supplier');
    });

    it('keeps the criterion between the content and the reason', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        AccessibilityAudit::getInstance()->statement->saveExclusions($siteId, [
            StatementExclusionModel::fromArray([
                'category' => StatementExclusionModel::CATEGORY_BURDEN,
                'content' => 'The lecture archive published before 2020.',
                'criterion' => '1.2.2',
                'reason' => 'Captioning the whole of it is disproportionate.',
            ]),
        ]);

        expect(renderProseStatement($siteId))
            ->toContain('(WCAG 1.2.2). Captioning');
    });
});
