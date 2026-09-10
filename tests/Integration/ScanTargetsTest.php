<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\ScanTargets;
use johnhenry\accessibilityaudit\jobs\ScanElements;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The run of pages a site-wide sweep covers.
//
// A sweep has to reach the Additional URLs as well as the elements, and the
// batch runner asks for the run in slices. The slice straddling the join
// between the two is what breaks: get the offset arithmetic wrong and either
// the last elements or the first URLs are dropped, silently, and only on
// sites big enough for the sweep to take more than one step.
//
// Helpers are prefixed st: Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** Walks a batchable the way Craft's batch runner does. */
function stWalk(ScanTargets $targets, int $batchSize): array
{
    $seen = [];

    for ($offset = 0; $offset < $targets->count(); $offset += $batchSize) {
        foreach ($targets->getSlice($offset, $batchSize) as $item) {
            $seen[] = $item;
        }
    }

    return $seen;
}

/** The sweep query for the primary site. */
function stElementQuery(): craft\db\Query
{
    return AccessibilityAudit::getInstance()->audit->getUrlElementsQuery(
        (int)Craft::$app->getSites()->getPrimarySite()->id,
    );
}

/** Sets the Additional URLs for the duration of a test. */
function stSetCustomUrls(string $value): void
{
    AccessibilityAudit::getInstance()->getSettings()->customUrls = $value;
}

describe('ScanTargets', function() {
    it('counts the elements and the URLs together', function() {
        foreach (range(1, 4) as $i) {
            scannableEntry("Scan targets fixture {$i}");
        }

        $elements = (int)stElementQuery()->count();
        $targets = new ScanTargets(stElementQuery(), ['/a', '/b', '/c']);

        expect($targets->count())->toBe($elements + 3);
    });

    it('puts the URLs last, after every element', function() {
        foreach (range(1, 4) as $i) {
            scannableEntry("Scan targets order fixture {$i}");
        }

        $seen = stWalk(new ScanTargets(stElementQuery(), ['/a', '/b']), 100);

        expect(array_slice($seen, -2))->toBe(['/a', '/b']);

        foreach (array_slice($seen, 0, -2) as $row) {
            expect($row)->toBeArray();
        }
    });

    it('drops nothing from the slice that straddles the join', function() {
        foreach (range(1, 7) as $i) {
            scannableEntry("Scan targets straddle fixture {$i}");
        }

        $urls = ['/a', '/b', '/c', '/d', '/e'];
        $elements = (int)stElementQuery()->count();

        // Every batch size from 1 up walks a different join boundary, so this
        // covers the slice that ends on the last element, the one that starts
        // on the first URL, and the one carrying both.
        foreach (range(1, $elements + count($urls) + 1) as $batchSize) {
            $seen = stWalk(new ScanTargets(stElementQuery(), $urls), $batchSize);
            $seenUrls = array_values(array_filter($seen, 'is_string'));
            $seenRows = array_values(array_filter($seen, 'is_array'));

            expect($seenUrls)->toBe($urls, "batch size {$batchSize}")
                ->and($seenRows)->toHaveCount($elements, "batch size {$batchSize}");
        }
    });

    it('reports nothing to do when there is nothing to do', function() {
        $targets = new ScanTargets(stElementQuery()->andWhere(['e.id' => 0]), []);

        expect($targets->count())->toBe(0)
            ->and(stWalk($targets, 10))->toBe([]);
    });
});

describe('The site-wide sweep', function() {
    it('loads the configured URLs alongside the elements', function() {
        scannableEntry('Sweep fixture');
        stSetCustomUrls("/search/results?q=craft\n# a comment\n/paginated/2");

        $job = new ScanElements(['siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id]);

        $loadData = (new ReflectionClass(ScanElements::class))->getMethod('loadData');
        $loadData->setAccessible(true);
        $targets = $loadData->invoke($job);

        $urls = array_values(array_filter(stWalk($targets, 100), 'is_string'));

        expect($urls)->toBe(['/search/results?q=craft', '/paginated/2']);
    });

    it('counts them in what the Scan All button reports as queued', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        scannableEntry('Queued count fixture');
        $elements = (int)stElementQuery()->count();
        stSetCustomUrls("/a\n/b");

        $this->post('actions/accessibility-audit/audit/scan-all', [
            'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
        ])->assertOk()->assertJson(['success' => true, 'queued' => $elements + 2]);
    });
});
