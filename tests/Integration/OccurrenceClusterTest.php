<?php

use johnhenry\accessibilityaudit\helpers\OccurrenceCluster;

// ---------------------------------------------------------------------------
// Gathering repeated occurrences of one question.
//
// A rule that fires per element fires once per element. A reference table of
// nineteen rows whose cells cannot be measured for contrast produces dozens of
// questions, all the same question, every one showing the same truncated
// <td class="px-4 py-2.5 …"> because that is what the markup says. Grouping is
// what makes that answerable instead of scrollable.
// ---------------------------------------------------------------------------

/** Shorthand for a row the way the report holds one. */
function clusterRow(string $context, string $ruleId = 'potential:contrast-unmeasurable'): array
{
    return ['ruleId' => $ruleId, 'context' => $context, 'message' => 'q'];
}

describe('OccurrenceCluster::signature', function() {
    it('gives repeated markup the same signature', function() {
        $a = OccurrenceCluster::signature('<td class="px-4 py-2.5 align-top">Total log entries</td>');
        $b = OccurrenceCluster::signature('<td class="px-4 py-2.5 align-top">Average events per order</td>');

        expect($a)->toBe($b)->and($a)->not->toBe('');
    });

    it('does not care what order the classes were written in', function() {
        expect(OccurrenceCluster::signature('<td class="px-4 align-top py-2.5">a</td>'))
            ->toBe(OccurrenceCluster::signature('<td class="align-top py-2.5 px-4">b</td>'));
    });

    it('keeps different components apart', function() {
        expect(OccurrenceCluster::signature('<td class="px-4">a</td>'))
            ->not->toBe(OccurrenceCluster::signature('<th class="px-4">a</th>'))
            ->and(OccurrenceCluster::signature('<td class="px-4">a</td>'))
            ->not->toBe(OccurrenceCluster::signature('<td class="p-2">a</td>'));
    });

    it('has no signature for a context that is not markup', function() {
        // Some rules store a plain sentence, which cannot be grouped this way.
        expect(OccurrenceCluster::signature('"Visit Website" → https://example.test/'))->toBe('');
    });
});

describe('OccurrenceCluster::group', function() {
    it('gathers a repeated cell into one thing to answer', function() {
        $rows = [];
        foreach (['Total logs', 'Unique orders', 'Average events', 'Conversion rate'] as $text) {
            $rows[] = clusterRow('<td class="px-4 py-2.5 align-top">' . $text . '</td>');
        }

        $clusters = OccurrenceCluster::group($rows);

        expect($clusters)->toHaveCount(1)
            ->and($clusters[0]['count'])->toBe(4)
            ->and($clusters[0]['occurrences'])->toHaveCount(4)
            ->and($clusters[0]['label'])->toContain('4 ×')
            ->and($clusters[0]['label'])->toContain('<td>');
    });

    it('leaves a couple of occurrences as they were', function() {
        // Below the threshold a cluster is more chrome than the cards it
        // replaces, so two of a kind stay two plain cards.
        $clusters = OccurrenceCluster::group([
            clusterRow('<td class="px-4">a</td>'),
            clusterRow('<td class="px-4">b</td>'),
        ]);

        expect($clusters)->toHaveCount(2)
            ->and($clusters[0]['count'])->toBe(1)
            ->and($clusters[1]['count'])->toBe(1);
    });

    it('keeps every occurrence, so nothing is answered by accident', function() {
        $rows = [
            clusterRow('<td class="px-4">a</td>'),
            clusterRow('<td class="px-4">b</td>'),
            clusterRow('<td class="px-4">c</td>'),
            clusterRow('<a class="btn">Read more</a>'),
            clusterRow('plain sentence context'),
        ];

        $clusters = OccurrenceCluster::group($rows);
        $total = array_sum(array_map(static fn(array $c): int => count($c['occurrences']), $clusters));

        // Three cells clustered, the link and the sentence on their own.
        expect($total)->toBe(5)
            ->and($clusters)->toHaveCount(3);
    });

    it('never clusters contexts that are not markup, however many there are', function() {
        $rows = array_fill(0, 6, clusterRow('"Visit Website" goes to two places'));

        $clusters = OccurrenceCluster::group($rows);

        expect($clusters)->toHaveCount(6);
    });

    it('holds each occurrence\'s own context, since a verdict is keyed to it', function() {
        $clusters = OccurrenceCluster::group([
            clusterRow('<td class="px-4">a</td>'),
            clusterRow('<td class="px-4">b</td>'),
            clusterRow('<td class="px-4">c</td>'),
        ]);

        $contexts = array_column($clusters[0]['occurrences'], 'context');

        expect($contexts)->toHaveCount(3)
            ->and(array_unique($contexts))->toHaveCount(3);
    });
});
