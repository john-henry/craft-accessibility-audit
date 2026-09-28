<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\base\ElementInterface;
use craft\elements\Entry;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\jobs\AnalyseReadability;
use johnhenry\accessibilityaudit\jobs\RecordReadability;
use johnhenry\accessibilityaudit\services\ReadabilityService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Keeping the Readability page's Page results table filled: Analyse every
// page, a background run over the pages the scanner covers, and the result
// recorded each time an entry is saved.
//
// The analysis itself is stubbed. Each fixture entry has no text fields, so
// the real service would fall back to fetching the page over the network,
// which is neither what these tests are about nor something a test should do.
//
// Helpers are prefixed ra: Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** A readability service that records what it was asked to analyse. */
function raSpyService(): ReadabilityService
{
    return new class() extends ReadabilityService {
        /** @var int[] */
        public array $analysed = [];

        public function analyseElement(ElementInterface $element, bool $withClaude = false): array
        {
            $this->analysed[] = (int)$element->id;

            return $this->analyseText(str_repeat('The cat ran off to the den. ', 15));
        }
    };
}

/** How many jobs of a class are waiting in the queue. */
function raQueued(string $class): int
{
    return (int)(new craft\db\Query())
        ->from('{{%queue}}')
        ->where(['like', 'job', $class])
        ->count();
}

/** Runs one item through the job as the batch runner would. */
function raProcess(Entry $entry): void
{
    $job = new AnalyseReadability(['siteId' => (int)$entry->siteId]);
    $method = (new ReflectionClass(AnalyseReadability::class))->getMethod('processItem');
    $method->setAccessible(true);
    $method->invoke($job, ['elementId' => $entry->id, 'elementType' => Entry::class]);
}

beforeEach(function() {
    $plugin = AccessibilityAudit::getInstance();

    $this->raOriginal = [
        'edition' => $plugin->edition,
        'excludedUriPatterns' => $plugin->getSettings()->excludedUriPatterns,
        'readability' => $plugin->getReadability(),
    ];

    $plugin->edition = AccessibilityAudit::EDITION_PRO;
    $this->raSpy = raSpyService();
    $plugin->set('readability', $this->raSpy);
});

afterEach(function() {
    $plugin = AccessibilityAudit::getInstance();

    // The cache is not rolled back with the database, and a flag left behind
    // would tell the real Readability page a run is under way.
    Craft::$app->getCache()->delete(AnalyseReadability::runningKey((int)Craft::$app->getSites()->getPrimarySite()->id));
    $plugin->edition = $this->raOriginal['edition'];
    $plugin->getSettings()->excludedUriPatterns = $this->raOriginal['excludedUriPatterns'];
    $plugin->set('readability', $this->raOriginal['readability']);
});

describe('The job', function() {
    it('analyses a page and stores the result against its entry', function() {
        $entry = scannableEntry('Readability sweep fixture');

        raProcess($entry);

        $stored = $this->raSpy->getResults(1, (int)$entry->id, (int)$entry->siteId)[0] ?? null;

        expect($this->raSpy->analysed)->toBe([(int)$entry->id])
            ->and($stored)->not->toBeNull()
            ->and($stored['title'])->toBe('Readability sweep fixture')
            ->and($stored['url'])->toBe($entry->getUrl());
    });

    it('leaves out a page matched by Excluded Pages', function() {
        $entry = scannableEntry();
        AccessibilityAudit::getInstance()->getSettings()->excludedUriPatterns = [
            ['uriPattern' => '^' . preg_quote((string)$entry->uri) . '$'],
        ];

        raProcess($entry);

        expect($this->raSpy->analysed)->toBe([]);
    });

    it('stores nothing for a page it cannot score', function() {
        $service = new class() extends ReadabilityService {
            public function analyseElement(ElementInterface $element, bool $withClaude = false): array
            {
                return ['error' => 'Not enough text content to analyse.'];
            }
        };
        $entry = scannableEntry();

        $service->analyseAndStoreElement($entry);

        expect($service->getResults(1, (int)$entry->id, (int)$entry->siteId))->toBe([]);
    });

    it('walks the pages the scanner covers', function() {
        $entry = scannableEntry();
        $job = new AnalyseReadability(['siteId' => (int)$entry->siteId]);
        $loadData = (new ReflectionClass(AnalyseReadability::class))->getMethod('loadData');
        $loadData->setAccessible(true);

        expect(array_column($loadData->invoke($job)->getSlice(0, 100000), 'elementId'))
            ->toContain($entry->id);
    });
});

describe('Re-analysing selected results', function() {
    it('walks only the selected results in the site', function() {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $entry = scannableEntry('Selected fixture');
        $result = $this->raSpy->analyseText(str_repeat('The cat ran off to the den. ', 15));
        $this->raSpy->storeResult($result, (int)$entry->id, $siteId, (string)$entry->getUrl(), 'Selected fixture');
        $this->raSpy->storeResult($result, null, null, 'https://example.com/not-selected', 'Not selected');
        $selectedId = (int)(new craft\db\Query())->select('id')->from('{{%accessibilityaudit_readability}}')
            ->where(['elementId' => $entry->id])->scalar();

        $job = new AnalyseReadability(['siteId' => $siteId, 'resultIds' => [$selectedId]]);
        $loadData = (new ReflectionClass(AnalyseReadability::class))->getMethod('loadData');
        $loadData->setAccessible(true);

        expect($loadData->invoke($job)->getSlice(0, 100))->toBe([
            ['id' => $selectedId, 'elementId' => $entry->id, 'url' => $entry->getUrl(), 'title' => 'Selected fixture'],
        ]);
    });

    it('removes a selected result whose page is gone', function() {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $entry = scannableEntry();
        $this->raSpy->storeResult($this->raSpy->analyseText(str_repeat('The cat ran off to the den. ', 15)), (int)$entry->id, $siteId, (string)$entry->getUrl());
        $id = (int)(new craft\db\Query())->select('id')->from('{{%accessibilityaudit_readability}}')->where(['elementId' => $entry->id])->scalar();
        Craft::$app->getElements()->deleteElement($entry);

        $job = new AnalyseReadability(['siteId' => $siteId, 'resultIds' => [$id]]);
        $method = (new ReflectionClass(AnalyseReadability::class))->getMethod('processItem');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => $id, 'elementId' => $entry->id, 'url' => '', 'title' => '']);

        expect((new craft\db\Query())->from('{{%accessibilityaudit_readability}}')->where(['id' => $id])->exists())->toBeFalse();
    });

    it('fetches a result with no element again from its URL', function() {
        $service = new class() extends ReadabilityService {
            public function analyseUrl(string $url, bool $withClaude = false): array
            {
                return $this->analyseText(str_repeat('The cat ran off to the den. ', 15));
            }
        };
        AccessibilityAudit::getInstance()->set('readability', $service);

        $job = new AnalyseReadability(['siteId' => 1, 'resultIds' => [1]]);
        $method = (new ReflectionClass(AnalyseReadability::class))->getMethod('processItem');
        $method->setAccessible(true);
        $method->invoke($job, ['elementId' => null, 'url' => 'https://example.com/loose-page', 'title' => 'Loose page']);

        $stored = $service->getResults(url: 'https://example.com/loose-page')[0] ?? null;

        expect($stored)->not->toBeNull()
            ->and($stored['title'])->toBe('Loose page');
    });

    it('queues the selected results, counting only those it will analyse', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $entry = scannableEntry();
        $this->raSpy->storeResult($this->raSpy->analyseText(str_repeat('The cat ran off to the den. ', 15)), (int)$entry->id, (int)$entry->siteId, (string)$entry->getUrl());
        $id = (int)(new craft\db\Query())->select('id')->from('{{%accessibilityaudit_readability}}')->where(['elementId' => $entry->id])->scalar();

        $this->postJson('actions/accessibility-audit/readability/analyse-selected', ['ids' => [$id, 999999999]])
            ->assertOk()
            ->assertJson(['success' => true, 'queued' => 1]);
    });

    it('is refused without the Run scans permission', function() {
        $this->actingAs(UserFactory::factory()->create());

        expect(fn() => $this->postJson('actions/accessibility-audit/readability/analyse-selected', ['ids' => [1]]))
            ->toThrow(yii\web\ForbiddenHttpException::class);
    });

    it('is refused on the Standard edition', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        $this->postJson('actions/accessibility-audit/readability/analyse-selected', ['ids' => [1]])
            ->assertOk()
            ->assertJson(['success' => false, 'proRequired' => true]);
    });

    it('says so when nothing is selected', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $this->postJson('actions/accessibility-audit/readability/analyse-selected', ['ids' => []])
            ->assertOk()
            ->assertJson(['success' => false]);
    });
});

describe('The Page results table', function() {
    it('links an element page to its edit screen', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $entry = scannableEntry('Edit link fixture');
        $controller = new johnhenry\accessibilityaudit\controllers\DashboardController('dashboard', AccessibilityAudit::getInstance());
        $method = (new ReflectionClass($controller))->getMethod('_readabilityTableData');
        $method->setAccessible(true);

        $rows = $method->invoke($controller, [
            ['id' => 1, 'elementId' => $entry->id, 'siteId' => $entry->siteId, 'url' => $entry->getUrl(), 'title' => 'Edit link fixture',
                'readingEase' => 70, 'readingEaseLabel' => 'Fairly easy', 'gradeLevel' => 7, 'readingAge' => 12,
                'wordCount' => 100, 'wcag315Pass' => 1, 'dateAnalysed' => '2026-09-17 10:00:00'],
            ['id' => 2, 'elementId' => null, 'siteId' => null, 'url' => 'https://example.com/loose', 'title' => 'Loose',
                'readingEase' => 70, 'readingEaseLabel' => 'Fairly easy', 'gradeLevel' => 7, 'readingAge' => 12,
                'wordCount' => 100, 'wcag315Pass' => 1, 'dateAnalysed' => '2026-09-17 10:00:00'],
        ]);

        expect($rows[0]['page']['editUrl'])->toBe($entry->getCpEditUrl())
            ->and($rows[1]['page']['editUrl'])->toBeNull();
    });
});

describe('Saving an entry', function() {
    it('records its readability from its own text', function() {
        if (!AccessibilityAudit::getInstance()->getSettings()->scanOnSave) {
            $this->markTestSkipped('Scan on Entry Save was off when the plugin booted, so no save listener is registered.');
        }

        $service = new class() extends ReadabilityService {
            public function analyseElementText(ElementInterface $element, bool $withClaude = false): array
            {
                return $this->analyseText(str_repeat('The cat ran off to the den. ', 15));
            }

            public function analyseElement(ElementInterface $element, bool $withClaude = false): array
            {
                throw new LogicException('Saving must not fetch the page.');
            }
        };
        AccessibilityAudit::getInstance()->set('readability', $service);
        $queuedBefore = raQueued('RecordReadability');

        $entry = scannableEntry('Saved readability fixture');

        // Queued rather than run during the save, which is in a transaction.
        expect(raQueued('RecordReadability'))->toBe($queuedBefore + 1);

        (new RecordReadability(['elementId' => (int)$entry->id]))->execute(Craft::$app->getQueue());
        $stored = $service->getResults(1, (int)$entry->id, (int)$entry->siteId)[0] ?? null;

        expect($stored)->not->toBeNull()
            ->and($stored['title'])->toBe('Saved readability fixture')
            ->and($stored['gradeLevel'])->toBe(0.0);
    });

    it('records nothing for a draft', function() {
        if (!AccessibilityAudit::getInstance()->getSettings()->scanOnSave) {
            $this->markTestSkipped('Scan on Entry Save was off when the plugin booted, so no save listener is registered.');
        }

        $entry = scannableEntry();
        $queuedBefore = raQueued('RecordReadability');

        Craft::$app->getDrafts()->createDraft($entry, $entry->authorId ?: 1, 'readability draft');

        expect(raQueued('RecordReadability'))->toBe($queuedBefore);
    });

    it('keeps one result per entry and site however often it is stored', function() {
        $entry = scannableEntry();
        $result = $this->raSpy->analyseText(str_repeat('The cat ran off to the den. ', 15));

        $this->raSpy->storeResult($result, (int)$entry->id, (int)$entry->siteId, (string)$entry->getUrl());
        $this->raSpy->storeResult($result, (int)$entry->id, (int)$entry->siteId, (string)$entry->getUrl());

        expect((int)(new craft\db\Query())->from('{{%accessibilityaudit_readability}}')->where(['elementId' => $entry->id])->count())->toBe(1);
    });

    it('keeps the stored result when the entry has too little text of its own', function() {
        $entry = scannableEntry();
        $service = new class() extends ReadabilityService {
            public function analyseElementText(ElementInterface $element, bool $withClaude = false): array
            {
                return ['error' => 'Not enough text.', 'charactersNeeded' => 60];
            }
        };

        expect($service->recordElementText($entry))->toBeFalse()
            ->and($service->getResults(1, (int)$entry->id, (int)$entry->siteId))->toBe([]);
    });

    it('records nothing on the Standard edition', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
        $entry = scannableEntry();

        expect($this->raSpy->recordElementText($entry))->toBeFalse()
            ->and($this->raSpy->getResults(1, (int)$entry->id, (int)$entry->siteId))->toBe([]);
    });
});

describe('The Analyse every page button', function() {
    it('queues the run and says how many pages it covers', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        scannableEntry();

        $this->postJson('actions/accessibility-audit/readability/analyse-all', ['siteId' => $siteId])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'queued' => (int)AccessibilityAudit::getInstance()->getAudit()->getUrlElementsQuery($siteId)->count(),
            ]);
    });

    it('does not queue a second run while one is under way', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        $this->postJson('actions/accessibility-audit/readability/analyse-all', ['siteId' => $siteId])
            ->assertJson(['success' => true]);
        $queued = raQueued('AnalyseReadability');

        $this->postJson('actions/accessibility-audit/readability/analyse-all', ['siteId' => $siteId])
            ->assertJson(['success' => false]);

        expect(raQueued('AnalyseReadability'))->toBe($queued);
    });

    it('is refused without the Run scans permission', function() {
        $this->actingAs(UserFactory::factory()->create());

        expect(fn() => $this->postJson('actions/accessibility-audit/readability/analyse-all', []))
            ->toThrow(yii\web\ForbiddenHttpException::class);
    });
});
