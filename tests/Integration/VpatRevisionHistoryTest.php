<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\helpers\Db;
use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// The record of what changed between issues of the report.
//
// A conformance report read on its own says nothing about whether the position
// is improving. The history is what turns it from a scorecard into a record,
// and it is only worth anything if it counts decisions rather than exports.
// ---------------------------------------------------------------------------

function clearRevisions(int $siteId): void
{
    Craft::$app->getDb()->createCommand()
        ->delete('{{%accessibilityaudit_vpat_revisions}}', ['siteId' => $siteId])
        ->execute();
}

function ageRevisions(int $siteId, int $days): void
{
    // Ordering is by date, so snapshots written in the same second within one
    // test would otherwise be indistinguishable.
    Craft::$app->getDb()->createCommand()
        ->update(
            '{{%accessibilityaudit_vpat_revisions}}',
            ['dateCreated' => Db::prepareDateForDb(new DateTime("-{$days} days"))],
            ['siteId' => $siteId],
        )
        ->execute();
}

beforeEach(function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    resetVpat($siteId);
    clearRevisions($siteId);
});

describe('VpatService::recordRevision', function() {
    it('writes a snapshot the first time', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        AccessibilityAudit::getInstance()->vpat->saveOverride($siteId, '1.1.1', 'Supports', '');

        expect(AccessibilityAudit::getInstance()->vpat->recordRevision($siteId))->toBeTrue();
    });

    it('does not write one when nothing was answered in between', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $vpat = AccessibilityAudit::getInstance()->vpat;

        $vpat->saveOverride($siteId, '1.1.1', 'Supports', '');
        $vpat->recordRevision($siteId);

        expect($vpat->recordRevision($siteId))->toBeFalse();
    });

    it('writes one when an answer changes', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $vpat = AccessibilityAudit::getInstance()->vpat;

        $vpat->saveOverride($siteId, '1.1.1', 'Supports', '');
        $vpat->recordRevision($siteId);
        $vpat->saveOverride($siteId, '1.1.1', 'Partially Supports', '');

        expect($vpat->recordRevision($siteId))->toBeTrue();
    });

    it('ignores the bookkeeping saved beside a remark', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $vpat = AccessibilityAudit::getInstance()->vpat;

        // Saving the same answer again rewrites remarkSavedAt. That is not a
        // decision and must not open a new version of the report.
        $vpat->saveOverride($siteId, '1.4.3', 'Supports', 'Meets 4.5:1 throughout.');
        $vpat->recordRevision($siteId);
        $vpat->saveOverride($siteId, '1.4.3', 'Supports', 'Meets 4.5:1 throughout.');

        expect($vpat->recordRevision($siteId))->toBeFalse();
    });
});

describe('VpatService::getRevisionHistory', function() {
    it('says nothing about a first issue', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $vpat = AccessibilityAudit::getInstance()->vpat;

        $vpat->saveOverride($siteId, '1.1.1', 'Supports', '');
        $vpat->recordRevision($siteId);

        expect($vpat->getRevisionHistory($siteId))->toBe([]);
    });

    it('names the criterion that moved, and where it moved from', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $vpat = AccessibilityAudit::getInstance()->vpat;

        $vpat->saveOverride($siteId, '1.1.1', 'Does Not Support', 'Images carry no alt text.');
        $vpat->recordRevision($siteId);
        ageRevisions($siteId, 30);

        $vpat->saveOverride($siteId, '1.1.1', 'Supports', 'Alt text added across the library.');
        $vpat->recordRevision($siteId);

        $history = $vpat->getRevisionHistory($siteId);

        expect($history)->toHaveCount(1)
            ->and($history[0]['changes'])->toHaveCount(1)
            ->and($history[0]['changes'][0]['criterion'])->toBe('1.1.1')
            ->and($history[0]['changes'][0]['name'])->toBe('Non-text Content')
            ->and($history[0]['changes'][0]['from'])->toBe('Does Not Support')
            ->and($history[0]['changes'][0]['to'])->toBe('Supports');
    });

    it('counts a reworded remark rather than listing it', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $vpat = AccessibilityAudit::getInstance()->vpat;

        $vpat->saveOverride($siteId, '1.4.3', 'Partially Supports', 'Two colours fail.');
        $vpat->recordRevision($siteId);
        ageRevisions($siteId, 30);

        $vpat->saveOverride($siteId, '1.4.3', 'Partially Supports', 'The caption style fails at 3.9:1.');
        $vpat->recordRevision($siteId);

        $history = $vpat->getRevisionHistory($siteId);

        expect($history[0]['changes'])->toBe([])
            ->and($history[0]['remarkEdits'])->toBe(1);
    });

    it('reports a newly answered criterion as coming from not evaluated', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $vpat = AccessibilityAudit::getInstance()->vpat;

        $vpat->saveOverride($siteId, '1.1.1', 'Supports', '');
        $vpat->recordRevision($siteId);
        ageRevisions($siteId, 30);

        $vpat->saveOverride($siteId, '2.4.7', 'Partially Supports', 'Focus is lost on the accordions.');
        $vpat->recordRevision($siteId);

        $history = $vpat->getRevisionHistory($siteId);

        expect($history[0]['changes'][0]['criterion'])->toBe('2.4.7')
            ->and($history[0]['changes'][0]['from'])->toBe('Not evaluated');
    });
});
