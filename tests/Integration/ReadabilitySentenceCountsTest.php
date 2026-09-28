<?php

use craft\db\Query;
use craft\helpers\UrlHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\jobs\AnalyseReadability;
use johnhenry\accessibilityaudit\migrations\m260926_000004_drop_siteless_readability_results;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Text with one very hard sentence, one hard one and a few plain ones, at the
 * default target. A sentence's level is 4.71 × letters per word + 0.5 × words
 * − 21.43: twenty 12-letter words come to 45, sixteen 5-letter words to 10.
 */
function readabilityMixedText(): string
{
    $veryHard = ucfirst(implode(' ', array_fill(0, 20, 'organisation'))) . '.';
    $hard = ucfirst(implode(' ', array_fill(0, 16, 'words'))) . '.';
    $plain = 'The cat sat on the mat. We went home.';

    return $plain . ' ' . $hard . "\n\n" . $veryHard . ' ' . $plain;
}

/**
 * Stores a URL-only result for the primary site, with the given scores.
 */
function readabilityStoreRow(string $path, float $grade, bool $pass, ?int $hard = null, ?int $veryHard = null): void
{
    $result = [
        'readingEase' => 60.0,
        'readingEaseLabel' => 'Standard',
        'gradeLevel' => $grade,
        'readingAge' => (int)round($grade + 5),
        'wordCount' => 200,
        'sentenceCount' => 10,
        'avgWordsPerSentence' => 20.0,
        'wcag315Pass' => $pass,
    ];

    if ($hard !== null) {
        $result['hardSentences'] = $hard;
        $result['veryHardSentences'] = $veryHard;
    }

    AccessibilityAudit::getInstance()->getReadability()->storeResult(
        $result,
        null,
        (int)Craft::$app->getSites()->getPrimarySite()->id,
        UrlHelper::siteUrl($path),
        $path,
    );
}

// ---------------------------------------------------------------------------
// Counting hard sentences
// ---------------------------------------------------------------------------

describe('hard sentence counts', function() {
    it('counts the same sentences the Readability preview marks', function() {
        $service = AccessibilityAudit::getInstance()->getReadability();
        $text = readabilityMixedText();
        $target = AccessibilityAudit::getInstance()->getSettings()->readabilityTarget;

        $expected = ['hard' => 0, 'veryHard' => 0];
        foreach ($service->previewBlocks($text, $target) as $sentences) {
            foreach ($sentences as $sentence) {
                if ($sentence['difficulty'] !== null) {
                    $expected[$sentence['difficulty']]++;
                }
            }
        }

        $result = $service->analyseText($text);

        expect($expected)->toBe(['hard' => 1, 'veryHard' => 1])
            ->and($result['hardSentences'])->toBe($expected['hard'])
            ->and($result['veryHardSentences'])->toBe($expected['veryHard']);
    });
});

// ---------------------------------------------------------------------------
// Stored results
// ---------------------------------------------------------------------------

describe('stored results', function() {
    it('keeps the counts, and leaves them empty on a result that never had them', function() {
        readabilityStoreRow('counted', 12.0, false, 3, 1);
        readabilityStoreRow('uncounted', 12.0, false);

        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $rows = AccessibilityAudit::getInstance()->getReadability()->getResultsPaged($siteId, 1, 100, 'counted')['results'];
        $counted = array_values(array_filter($rows, fn($r) => $r['title'] === 'counted'))[0];
        $uncounted = array_values(array_filter($rows, fn($r) => $r['title'] === 'uncounted'))[0];

        expect($counted['hardSentences'])->toBe(3)
            ->and($counted['veryHardSentences'])->toBe(1)
            ->and($uncounted['hardSentences'])->toBeNull()
            ->and($uncounted['veryHardSentences'])->toBeNull();
    });

    it('lists failing pages first, hardest to read at the top, by default', function() {
        readabilityStoreRow('sort-pass', 5.0, true);
        readabilityStoreRow('sort-fail-mild', 11.0, false);
        readabilityStoreRow('sort-fail-worst', 16.0, false);

        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $rows = AccessibilityAudit::getInstance()->getReadability()->getResultsPaged($siteId, 1, 100, 'sort-')['results'];

        expect(array_column($rows, 'title'))->toBe(['sort-fail-worst', 'sort-fail-mild', 'sort-pass']);
    });

    it('sorts on the hard sentence counts', function() {
        readabilityStoreRow('hard-few', 11.0, false, 1, 0);
        readabilityStoreRow('hard-many', 11.0, false, 7, 2);

        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $rows = AccessibilityAudit::getInstance()->getReadability()->getResultsPaged($siteId, 1, 100, 'hard-', 'hardSentences', SORT_DESC)['results'];

        expect(array_column($rows, 'title'))->toBe(['hard-many', 'hard-few']);
    });
});

// ---------------------------------------------------------------------------
// Analyse a page: this install's own sites only
// ---------------------------------------------------------------------------

describe('which URLs belong to this install', function() {
    it('matches a page under a site\'s base URL', function() {
        $site = Craft::$app->getSites()->getPrimarySite();
        $base = rtrim((string)$site->getBaseUrl(), '/');

        expect(AccessibilityAudit::getInstance()->getReadability()->siteForUrl($base . '/some/page')?->id)->toBe($site->id)
            ->and(AccessibilityAudit::getInstance()->getReadability()->siteForUrl($base . '/')?->id)->toBe($site->id);
    });

    it('refuses a lookalike host, another scheme or port, and an outside site', function() {
        $base = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
        $parts = parse_url($base);
        $service = AccessibilityAudit::getInstance()->getReadability();
        $otherScheme = ($parts['scheme'] === 'https' ? 'http' : 'https') . '://' . $parts['host'] . '/page';

        expect($service->siteForUrl($parts['scheme'] . '://' . $parts['host'] . '.evil.test/page'))->toBeNull()
            ->and($service->siteForUrl($otherScheme))->toBeNull()
            ->and($service->siteForUrl($parts['scheme'] . '://' . $parts['host'] . ':8443/page'))->toBeNull()
            ->and($service->siteForUrl('https://example.com/page'))->toBeNull();
    });

    it('refuses to analyse a page on another site', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $json = $this->postJson('actions/accessibility-audit/readability/analyse', [
            'url' => 'https://example.com/page',
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['error'])->toContain('Only pages on this site');
    });
});

// ---------------------------------------------------------------------------
// Analyse every page: Additional URLs
// ---------------------------------------------------------------------------

describe('Analyse every page with Additional URLs', function() {
    beforeEach(function() {
        $this->settings = AccessibilityAudit::getInstance()->getSettings();
        $this->savedCustomUrls = $this->settings->customUrls;
        $this->siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
    });

    afterEach(function() {
        $this->settings->customUrls = $this->savedCustomUrls;
    });

    it('covers the Additional URLs after the pages with a URL', function() {
        $this->settings->customUrls = [
            ['url' => '/readability-extra', 'enabled' => true],
            ['url' => '/readability-off', 'enabled' => false],
        ];

        $job = new AnalyseReadability(['siteId' => $this->siteId]);
        $data = (new ReflectionMethod($job, 'loadData'))->invoke($job);
        $elements = (int)AccessibilityAudit::getInstance()->getAudit()->getUrlElementsQuery($this->siteId)->count();

        expect($data->count())->toBe($elements + 1);
    });

    it('passes over an Additional URL on another host without storing anything', function() {
        $job = new AnalyseReadability(['siteId' => $this->siteId]);
        (new ReflectionMethod($job, '_analyseConfiguredUrl'))->invoke($job, 'https://example.com/elsewhere');

        $stored = (new Query())
            ->from('{{%accessibilityaudit_readability}}')
            ->where(['like', 'url', 'example.com/elsewhere'])
            ->count();

        expect((int)$stored)->toBe(0);
    });
});


// ---------------------------------------------------------------------------
// Clean-up of results with no site
// ---------------------------------------------------------------------------

describe('m260926_000004_drop_siteless_readability_results', function() {
    it('deletes results with no element and no site, and keeps the rest', function() {
        $service = AccessibilityAudit::getInstance()->getReadability();
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $result = ['readingEase' => 60.0, 'gradeLevel' => 8.0, 'wcag315Pass' => true];

        $service->storeResult($result, null, null, 'https://example.com/siteless-page', 'Siteless');
        $service->storeResult($result, null, $siteId, UrlHelper::siteUrl('sited-page'), 'Sited');

        m260926_000004_drop_siteless_readability_results::apply();

        $titles = (new Query())
            ->select('title')
            ->from('{{%accessibilityaudit_readability}}')
            ->where(['title' => ['Siteless', 'Sited']])
            ->column();

        expect($titles)->toBe(['Sited']);
    });
});
