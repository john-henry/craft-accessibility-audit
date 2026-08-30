<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The Overview reports on the pages it has scanned, not on the site.
//
// Every figure there comes from the latest scan of each page that has one, so a
// site part-way through a sweep reports on the handful done so far with exactly
// the confidence of a finished sweep. Purge the history, start a scan, look at
// the Overview three pages in: 100 out of 100, nothing failing, all clear. Each
// of those numbers is true of the three pages and none of them is true of the
// site, and nothing on the screen said which it meant.
// ---------------------------------------------------------------------------

function coverageScan(int $elementId, int $siteId): void
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId, 'elementType' => User::class, 'siteId' => $siteId,
        'score' => 100, 'scoreA' => 100, 'scoreAA' => 100, 'scoreAAA' => 100,
        'errorCount' => 0, 'warningCount' => 0, 'noticeCount' => 0,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();
}

beforeEach(function() {
    $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $this->audit = AccessibilityAudit::getInstance()->audit;
});

describe('AuditService::getCoverage', function() {
    it('counts the pages a full sweep would cover, not just the ones done', function() {
        $coverage = $this->audit->getCoverage($this->siteId);

        expect($coverage)->toHaveKeys(['scanned', 'scannable'])
            ->and($coverage['scannable'])->toBeGreaterThanOrEqual($coverage['scanned']);
    });

    it('counts each scanned page once however many times it has been scanned', function() {
        $before = $this->audit->getCoverage($this->siteId)['scanned'];
        $elementId = (int) UserFactory::factory()->create()->id;

        coverageScan($elementId, $this->siteId);
        coverageScan($elementId, $this->siteId);

        expect($this->audit->getCoverage($this->siteId)['scanned'])->toBe($before + 1);
    });

    it('includes the URLs configured by hand in what a sweep covers', function() {
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $was = $settings->customUrls;

        try {
            $settings->customUrls = '';
            $bare = $this->audit->getCoverage($this->siteId)['scannable'];

            $settings->customUrls = "https://example.com/a\nhttps://example.com/b";

            expect($this->audit->getCoverage($this->siteId)['scannable'])->toBe($bare + 2);
        } finally {
            $settings->customUrls = $was;
        }
    });
});

describe('the Overview', function() {
    it('says how much of the site the figures cover while a sweep is short', function() {
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

        expect($twig)->toContain('{% if unscanned > 0 %}')
            ->and($twig)->toContain('{n} of {total} pages have been scanned.');
    });

    it('holds the all-clear back until every page has actually been scanned', function() {
        // Otherwise the one message on the screen that promises there is
        // nothing behind the score is the one making a claim about pages it
        // has never looked at.
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

        expect($twig)->toContain(
            "{% set allClear = openTotal == 0 and (pendingPotential ?? 0) == 0 and summary.avgScore >= 100 and fullyCovered %}",
        );
    });

    it('does not call an empty site fully covered', function() {
        // Nothing scanned and nothing scannable is zero of zero, which is not
        // an all-clear: it is a site the plugin has never looked at.
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

        expect($twig)->toContain('{% set fullyCovered = (coverage.scanned ?? 0) > 0 and unscanned <= 0 %}');
    });

    it('hands the template the coverage it needs to decide', function() {
        $source = (string) file_get_contents(
            (new ReflectionClass(\johnhenry\accessibilityaudit\controllers\DashboardController::class))->getFileName(),
        );

        expect($source)->toContain("'coverage' => \$plugin->audit->getCoverage(\$siteId),");
    });
});
