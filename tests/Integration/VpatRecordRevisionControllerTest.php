<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// Recording an issue is its own action, not a side effect of exporting.
//
// Opening the export to see how a remark reads is a preview. Only the author
// knows when a version of the document was actually given to somebody, so the
// history counts decisions rather than times the preview was opened.
// ---------------------------------------------------------------------------

function revisionCount(int $siteId): int
{
    return (int)(new craft\db\Query())
        ->from('{{%accessibilityaudit_vpat_revisions}}')
        ->where(['siteId' => $siteId])
        ->count();
}

beforeEach(function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    resetVpat($siteId);
    Craft::$app->getDb()->createCommand()
        ->delete('{{%accessibilityaudit_vpat_revisions}}', ['siteId' => $siteId])
        ->execute();
});

describe('exporting the report', function() {
    it('records nothing', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $vpat = AccessibilityAudit::getInstance()->vpat;

        $vpat->saveOverride($siteId, '1.1.1', 'Supports', '');
        $vpat->getFullReport($siteId);

        expect(revisionCount($siteId))->toBe(0);
    });

    it('still reports the history it already has', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $vpat = AccessibilityAudit::getInstance()->vpat;

        $vpat->saveOverride($siteId, '1.1.1', 'Supports', '');
        $vpat->recordRevision($siteId);

        expect($vpat->getFullReport($siteId))->toHaveKey('revisions')
            ->and(revisionCount($siteId))->toBe(1);
    });
});

describe('recording an issue', function() {
    it('adds one', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $vpat = AccessibilityAudit::getInstance()->vpat;

        $vpat->saveOverride($siteId, '1.1.1', 'Supports', '');

        expect($vpat->recordRevision($siteId))->toBeTrue()
            ->and(revisionCount($siteId))->toBe(1);
    });

    it('does not add a second for the same answers', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $vpat = AccessibilityAudit::getInstance()->vpat;

        $vpat->saveOverride($siteId, '1.1.1', 'Supports', '');
        $vpat->recordRevision($siteId);
        $vpat->recordRevision($siteId);

        expect(revisionCount($siteId))->toBe(1);
    });

    it('carries the site the report describes', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $report = AccessibilityAudit::getInstance()->vpat->getFullReport($siteId);

        // The exported document posts this back, so a multi-site install
        // records against the site it is showing rather than the request's
        // default.
        expect($report['siteId'])->toBe($siteId);
    });
});
