<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Two different facts, and reading one as the other gets both wrong.
//
// The Overview reports on the pages it has scanned. Part-way through a sweep
// that is a handful of them, reported with exactly the confidence of a finished
// sweep: clear the history, start a scan, and three pages in the site reads 100
// out of 100 with nothing failing.
//
// The obvious guard is to compare pages scanned against pages scannable and
// call the difference "still to do". It is wrong, and on a real site it is
// wrong permanently. A finished sweep leaves pages unscanned by design: an
// address that redirects belongs to the page it lands on, an excluded page is
// meant to be missed, and one that 404s or times out has nothing to score. A
// real site sat at 342 of 389 with the queue empty, so an all-clear gated on
// that count could never appear again.
//
// So the job says when it starts and when it finishes, and the count is only
// ever shown as progress, never read as it.
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

function clearSweepFlag(int $siteId): void
{
    Craft::$app->getCache()->delete(AuditService::sweepKey($siteId));
}

beforeEach(function() {
    $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $this->audit = AccessibilityAudit::getInstance()->audit;

    clearSweepFlag($this->siteId);
});

afterEach(function() {
    clearSweepFlag($this->siteId);
});

describe('AuditService::getCoverage', function() {
    it('reports pages scanned, pages a sweep would consider, and whether one is running', function() {
        $coverage = $this->audit->getCoverage($this->siteId);

        expect($coverage)->toHaveKeys(['scanned', 'scannable', 'sweeping'])
            ->and($coverage['sweeping'])->toBeFalse();
    });

    it('counts each scanned page once however many times it has been scanned', function() {
        $before = $this->audit->getCoverage($this->siteId)['scanned'];
        $elementId = (int) UserFactory::factory()->create()->id;

        coverageScan($elementId, $this->siteId);
        coverageScan($elementId, $this->siteId);

        expect($this->audit->getCoverage($this->siteId)['scanned'])->toBe($before + 1);
    });

    it('includes the URLs configured by hand in what a sweep would consider', function() {
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

describe('AuditService::isSweepRunning', function() {
    it('is false when nothing is sweeping', function() {
        expect($this->audit->isSweepRunning($this->siteId))->toBeFalse();
    });

    it('is true while the flag the job sets is in place', function() {
        Craft::$app->getCache()->set(AuditService::sweepKey($this->siteId), true, 60);

        expect($this->audit->isSweepRunning($this->siteId))->toBeTrue();
    });

    it('answers per site, so one site sweeping does not mute another', function() {
        Craft::$app->getCache()->set(AuditService::sweepKey($this->siteId), true, 60);

        expect($this->audit->isSweepRunning($this->siteId + 1000))->toBeFalse();
    });
});

describe('the sweep job', function() {
    it('says when it starts and when it is finished', function() {
        $source = (string) file_get_contents(
            (new ReflectionClass(\johnhenry\accessibilityaudit\jobs\ScanElements::class))->getFileName(),
        );

        expect($source)->toContain('protected function before(): void')
            ->and($source)->toContain('protected function after(): void')
            ->and($source)->toContain('->set(AuditService::sweepKey($this->siteId), true, self::SWEEP_TTL)')
            ->and($source)->toContain('->delete(AuditService::sweepKey($this->siteId))');
    });

    it('lets the flag expire, so a sweep that dies does not wedge the Overview', function() {
        // A failed job or a restarted worker never reaches after().
        $source = (string) file_get_contents(
            (new ReflectionClass(\johnhenry\accessibilityaudit\jobs\ScanElements::class))->getFileName(),
        );

        expect($source)->toContain('private const SWEEP_TTL =');
    });
});

describe('the Overview', function() {
    it('says a scan is running rather than implying the remainder is pending', function() {
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

        expect($twig)->toContain('{% if sweeping %}')
            ->and($twig)->toContain('A scan is running. {n} of {total} pages so far.');
    });

    it('holds the all-clear back while a sweep is moving the figures', function() {
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

        expect($twig)->toContain(
            "{% set allClear = openTotal == 0 and (pendingPotential ?? 0) == 0 and summary.avgScore >= 100 and settled %}",
        );
    });

    it('never gates the all-clear on every page having a scan', function() {
        // A finished sweep leaves pages unscanned by design, so that gate would
        // never open again on a site with a redirect or an excluded page in it.
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

        expect($twig)->not->toContain('unscanned <= 0')
            ->and($twig)->toContain('{% set settled = (coverage.scanned ?? 0) > 0 and not sweeping %}');
    });

    it('does not call a site nobody has scanned settled', function() {
        // Nothing scanned and no sweep running is not an all-clear: it is a
        // site the plugin has never looked at.
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

        expect($twig)->toContain('(coverage.scanned ?? 0) > 0 and not sweeping');
    });

    it('hands the template the coverage it needs to decide', function() {
        $source = (string) file_get_contents(
            (new ReflectionClass(\johnhenry\accessibilityaudit\controllers\DashboardController::class))->getFileName(),
        );

        expect($source)->toContain("'coverage' => \$plugin->audit->getCoverage(\$siteId),");
    });
});
