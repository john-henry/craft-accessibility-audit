<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\StatementMetaModel;
use johnhenry\accessibilityaudit\services\StatementProfiles;
use johnhenry\accessibilityaudit\variables\AccessibilityVariable;

// ---------------------------------------------------------------------------
// When the statement is due to be looked at again.
//
// A reader cannot tell a statement that is being maintained from one nobody has
// touched since it went up. This is the only date on the statement that belongs
// in the future.
// ---------------------------------------------------------------------------

/**
 * The statement formats its dates for the site's locale, so the expected text
 * is built the same way rather than written out in one locale's convention.
 */
function longDate(string $date): string
{
    return Craft::$app->getFormatter()->asDate($date, 'long');
}

function nextReviewMeta(array $attributes): StatementMetaModel
{
    $meta = new StatementMetaModel();
    $meta->profile = StatementProfiles::PROFILE_GENERIC;

    foreach ($attributes as $name => $value) {
        $meta->$name = $value;
    }

    return $meta;
}

describe('the next review date', function() {
    it('accepts a date in the future', function() {
        $meta = nextReviewMeta(['nextReviewDate' => (new DateTime('+1 year'))->format('Y-m-d')]);

        expect($meta->validate())->toBeTrue();
    });

    it('accepts one already past, because an overdue review is a true thing to say', function() {
        $meta = nextReviewMeta(['nextReviewDate' => '2026-01-01']);

        expect($meta->validate())->toBeTrue();
    });

    it('refuses one falling before the last review', function() {
        $meta = nextReviewMeta([
            'reviewDate' => '2026-09-04',
            'nextReviewDate' => '2026-08-01',
        ]);

        expect($meta->validate())->toBeFalse()
            ->and($meta->getErrors('nextReviewDate'))->not->toBeEmpty();
    });

    it('refuses one falling on the same day as the last review', function() {
        $meta = nextReviewMeta([
            'reviewDate' => '2026-09-04',
            'nextReviewDate' => '2026-09-04',
        ]);

        expect($meta->validate())->toBeFalse();
    });

    it('accepts one after the last review', function() {
        $meta = nextReviewMeta([
            'reviewDate' => '2026-09-04',
            'nextReviewDate' => '2027-09-04',
        ]);

        expect($meta->validate())->toBeTrue();
    });

    it('still accepts being left empty', function() {
        expect(nextReviewMeta([])->validate())->toBeTrue();
    });

    it('survives a round trip through storage', function() {
        $meta = nextReviewMeta(['nextReviewDate' => '2027-09-04']);

        expect($meta->toStorageArray())->toHaveKey('nextReviewDate')
            ->and($meta->toStorageArray()['nextReviewDate'])->toBe('2027-09-04')
            ->and(StatementMetaModel::storageKeys())->toContain('nextReviewDate');
    });
});

describe('the published statement', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        AccessibilityAudit::getInstance()->getSettings()->statementTemplate = '';

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        resetVpat($siteId);
        resetScanData($siteId);
        saveVpatMetaFlat($siteId, ['productName' => 'Acme Council']);
    });

    it('says when it is due to be looked at again', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $meta = new StatementMetaModel();
        $meta->profile = StatementProfiles::PROFILE_GENERIC;
        $meta->reviewDate = '2026-09-04';
        $meta->nextReviewDate = '2027-09-04';
        AccessibilityAudit::getInstance()->statement->saveMeta($siteId, $meta);

        $html = (string)(new AccessibilityVariable())->accessibilityStatementHtml($siteId);

        expect($html)->toContain('last reviewed on ' . longDate('2026-09-04'))
            ->and($html)->toContain('due to be reviewed again by ' . longDate('2027-09-04'));
    });

    it('says nothing about it when it was never set', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $meta = new StatementMetaModel();
        $meta->profile = StatementProfiles::PROFILE_GENERIC;
        $meta->reviewDate = '2026-09-04';
        AccessibilityAudit::getInstance()->statement->saveMeta($siteId, $meta);

        $html = (string)(new AccessibilityVariable())->accessibilityStatementHtml($siteId);

        expect($html)->toContain('last reviewed on ' . longDate('2026-09-04'))
            ->and($html)->not->toContain('due to be reviewed again');
    });

    it('stands on its own when only the next review is known', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $meta = new StatementMetaModel();
        $meta->profile = StatementProfiles::PROFILE_GENERIC;
        $meta->nextReviewDate = '2027-09-04';
        AccessibilityAudit::getInstance()->statement->saveMeta($siteId, $meta);

        $html = (string)(new AccessibilityVariable())->accessibilityStatementHtml($siteId);

        expect($html)->toContain('due to be reviewed again by ' . longDate('2027-09-04'))
            ->and($html)->not->toContain('last reviewed on');
    });
});
