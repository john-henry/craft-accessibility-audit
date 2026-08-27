<?php

use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\ScanTarget;
use johnhenry\accessibilityaudit\services\VerdictService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Scans of pages with no element behind them.
//
// Every URL scan carries a null elementId, which is exactly what the listings
// used to group and count on. Grouped on that alone the whole lot folds into
// one row, and counted on that alone they vanish from the totals, so these
// cover the shape of the listing rather than any one page's markup.
//
// Helpers are uniquely named: Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** Inserts a scan of a URL with one issue row, and returns its scan id. */
function urlScanRow(string $url, string $title, int $siteId, string $ruleId = 'img-alt'): int
{
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => null,
        'elementType' => null,
        'url' => $url,
        'title' => $title,
        'siteId' => $siteId,
        'score' => 70,
        'scoreA' => 70,
        'scoreAA' => 70,
        'scoreAAA' => 70,
        'errorCount' => 1,
        'warningCount' => 0,
        'noticeCount' => 0,
        'dateScanned' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    $scanId = (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}');

    $db->createCommand()->insert('{{%accessibilityaudit_issues}}', [
        'scanId' => $scanId,
        'elementId' => null,
        'elementType' => null,
        'siteId' => $siteId,
        'ruleId' => $ruleId,
        'severity' => 'error',
        'message' => 'Image has no alt text.',
        'context' => '<img src="/a.jpg">',
        'source' => 'php',
        'isResolved' => false,
        'firstDetected' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return $scanId;
}

beforeEach(function() {
    // getSiteSummary and every listing average across whatever is stored, so
    // the tables start empty rather than carrying the dev site's own scans.
    $db = Craft::$app->getDb();
    $db->createCommand()->delete('{{%accessibilityaudit_issues}}')->execute();
    $db->createCommand()->delete('{{%accessibilityaudit_scans}}')->execute();
    $db->createCommand()->delete('{{%accessibilityaudit_verdicts}}')->execute();
});

describe('URL scans in the page listings', function() {
    it('lists every URL separately rather than folding them into one row', function() {
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        urlScanRow('https://example.test/news?page=2', 'News', $siteId);
        urlScanRow('https://example.test/news?page=3', 'News', $siteId);
        urlScanRow('https://example.test/search?q=craft', 'Search results', $siteId);

        $result = AccessibilityAudit::getInstance()->audit->getScannedElements($siteId, 1, 50);

        expect($result['total'])->toBe(3)
            ->and($result['entries'])->toHaveCount(3);
    });

    it('survives a title sort, which used to drop it on an inner join', function() {
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        urlScanRow('https://example.test/search?q=craft', 'Search results', $siteId);

        $result = AccessibilityAudit::getInstance()->audit
            ->getScannedElements($siteId, 1, 50, '', 'title', SORT_ASC);

        expect($result['total'])->toBe(1);
    });

    it('finds a URL scan by the title captured at scan time', function() {
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        urlScanRow('https://example.test/search?q=craft', 'Search results', $siteId);
        urlScanRow('https://example.test/news?page=2', 'News', $siteId);

        $result = AccessibilityAudit::getInstance()->audit
            ->getScannedElements($siteId, 1, 50, 'Search');

        expect($result['total'])->toBe(1);
    });

    it('counts URL pages in a rule\'s page count', function() {
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        urlScanRow('https://example.test/news?page=2', 'News', $siteId);
        urlScanRow('https://example.test/news?page=3', 'News', $siteId);

        $summary = AccessibilityAudit::getInstance()->audit->getIssueRuleSummary('img-alt', $siteId);

        expect((int) $summary['pageCount'])->toBe(2)
            ->and((int) $summary['occurrences'])->toBe(2);
    });

    it('lists both URL pages against the rule that found them', function() {
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        urlScanRow('https://example.test/news?page=2', 'News', $siteId);
        urlScanRow('https://example.test/news?page=3', 'News', $siteId);

        $result = AccessibilityAudit::getInstance()->audit->getPagesForRule('img-alt', $siteId);

        expect($result['total'])->toBe(2)
            ->and($result['entries'])->toHaveCount(2)
            ->and($result['entries'][0]['url'])->toContain('/news?page=');
    });
});

describe('ScanTarget', function() {
    it('names a URL scan by its captured title', function() {
        $scan = ['id' => 7, 'elementId' => null, 'url' => 'https://example.test/search?q=craft', 'title' => 'Search results'];

        expect(ScanTarget::isUrl($scan))->toBeTrue()
            ->and(ScanTarget::label($scan))->toBe('Search results')
            ->and(ScanTarget::url($scan))->toBe('https://example.test/search?q=craft');
    });

    it('falls back to the path when the page had no title', function() {
        $scan = ['id' => 7, 'elementId' => null, 'url' => 'https://example.test/search?q=craft', 'title' => null];

        expect(ScanTarget::label($scan))->toBe('/search?q=craft');
    });

    it('addresses a URL scan by scan id and an element scan by element id', function() {
        $urlScan = ['id' => 7, 'elementId' => null, 'url' => 'https://example.test/a', 'title' => 'A'];
        $elementScan = ['id' => 8, 'elementId' => 42, 'url' => null, 'title' => null];

        expect(ScanTarget::reportParams($urlScan, 'default'))->toBe(['scanId' => 7, 'site' => 'default'])
            ->and(ScanTarget::reportParams($elementScan, 'default'))->toBe(['elementId' => 42, 'site' => 'default']);
    });
});

describe('the page report for a URL scan', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
    });

    it('will not read a scan across the site fence', function() {
        $otherSiteId = 0;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (!$site->primary) {
                $otherSiteId = (int) $site->id;
                break;
            }
        }

        if ($otherSiteId === 0) {
            $this->markTestSkipped('Needs a second site.');
        }

        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $scanId = urlScanRow('https://example.test/secret', 'Secret', $otherSiteId);
        $audit = AccessibilityAudit::getInstance()->audit;

        // Scoped to the requested site, so a scan id from elsewhere is a miss
        // rather than a way across the fence.
        expect($audit->getScan($scanId, $siteId))->toBeNull()
            ->and($audit->getScan($scanId, $otherSiteId))->not->toBeNull();
    });

    it('redirects an element scan addressed by scanId to its element', function() {
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = (int) UserFactory::factory()->create()->id;
        $now = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
            'elementId' => $elementId,
            'elementType' => \craft\elements\User::class,
            'siteId' => $siteId,
            'score' => 90,
            'scoreA' => 90,
            'scoreAA' => 90,
            'scoreAAA' => 90,
            'errorCount' => 0,
            'warningCount' => 0,
            'noticeCount' => 0,
            'dateScanned' => $now,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        $scanId = (int) Craft::$app->getDb()->getLastInsertID('{{%accessibilityaudit_scans}}');

        $response = $this->http('get', 'actions/accessibility-audit/dashboard/page-report?scanId=' . $scanId)->send();

        $response->assertRedirect();
        expect($response->getHeaders()->get('Location'))->toContain('elementId=' . $elementId);
    });
});

describe('rulings on a page scanned by URL', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
    });

    it('keeps two URL pages\' answers apart', function() {
        // The reason this needed its own column. Every URL scan has a null
        // elementId, so a verdict keyed on that would have one dismissal on one
        // page silently answer the same question on every other URL page.
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        urlScanRow('https://example.test/news?page=2', 'News', $siteId, 'potential:identical-links');
        urlScanRow('https://example.test/news?page=3', 'News', $siteId, 'potential:identical-links');

        $verdicts = AccessibilityAudit::getInstance()->verdicts;
        $context = '<img src="/a.jpg">';

        $verdicts->setVerdict(
            $siteId, null, 'potential:identical-links', $context,
            VerdictService::VERDICT_DISMISSED, null, 'https://example.test/news?page=2',
        );

        $onPage2 = $verdicts->mapForElement(null, $siteId, 'https://example.test/news?page=2');
        $onPage3 = $verdicts->mapForElement(null, $siteId, 'https://example.test/news?page=3');

        expect($verdicts->lookup($onPage2, 'potential:identical-links', $context))
            ->toBe(VerdictService::VERDICT_DISMISSED)
            ->and($verdicts->lookup($onPage3, 'potential:identical-links', $context))
            ->toBeNull();
    });

    it('keeps a URL answer apart from an element answer', function() {
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = (int) UserFactory::factory()->create()->id;
        $verdicts = AccessibilityAudit::getInstance()->verdicts;
        $context = '<img src="/a.jpg">';

        $verdicts->setVerdict($siteId, $elementId, 'potential:long-alt', $context, VerdictService::VERDICT_CONFIRMED);
        $verdicts->setVerdict(
            $siteId, null, 'potential:long-alt', $context,
            VerdictService::VERDICT_DISMISSED, null, 'https://example.test/a',
        );

        expect($verdicts->lookup($verdicts->mapForElement($elementId, $siteId), 'potential:long-alt', $context))
            ->toBe(VerdictService::VERDICT_CONFIRMED)
            ->and($verdicts->lookup($verdicts->mapForElement(null, $siteId, 'https://example.test/a'), 'potential:long-alt', $context))
            ->toBe(VerdictService::VERDICT_DISMISSED);
    });

    it('stamps the ruling onto the URL page\'s stored issue rows', function() {
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        urlScanRow('https://example.test/news?page=2', 'News', $siteId, 'potential:identical-links');

        AccessibilityAudit::getInstance()->verdicts->setVerdict(
            $siteId, null, 'potential:identical-links', '<img src="/a.jpg">',
            VerdictService::VERDICT_DISMISSED, null, 'https://example.test/news?page=2',
        );

        $stored = (new craft\db\Query())
            ->select(['verdict'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['ruleId' => 'potential:identical-links'])
            ->scalar();

        expect($stored)->toBe(VerdictService::VERDICT_DISMISSED);
    });

    it('answers only once when the same question is answered twice', function() {
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $verdicts = AccessibilityAudit::getInstance()->verdicts;

        $verdicts->setVerdict($siteId, null, 'potential:long-alt', 'x', VerdictService::VERDICT_DISMISSED, null, 'https://example.test/a');
        $verdicts->setVerdict($siteId, null, 'potential:long-alt', 'x', VerdictService::VERDICT_CONFIRMED, null, 'https://example.test/a');

        // Scoped to the one page: the unique index is what stops the same
        // question on the same page holding two answers.
        $rows = (new craft\db\Query())
            ->from('{{%accessibilityaudit_verdicts}}')
            ->where([
                'siteId' => $siteId,
                'ruleId' => 'potential:long-alt',
                'targetHash' => $verdicts->targetHash(null, 'https://example.test/a'),
            ])
            ->count();

        expect((int) $rows)->toBe(1);
    });
});
