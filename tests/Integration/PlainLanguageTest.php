<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\PlainLanguage;

// ---------------------------------------------------------------------------
// The plain-language marks in the Readability preview: adverbs, the passive
// voice, and wording with a plainer alternative. They are pattern matches, so
// what matters is that they mark the common cases, leave the look-alikes
// alone, never overlap, and never change the text they mark.
//
// Helpers are uniquely named (pl*): Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/**
 * The marked segments of a sentence, as [mark, text, alternative].
 *
 * @return array<int, array{0: string, 1: string, 2: string|null}>
 */
function plMarks(string $sentence): array
{
    $marked = array_filter(
        PlainLanguage::segments($sentence),
        static fn(array $segment): bool => $segment['mark'] !== null,
    );

    return array_values(array_map(
        static fn(array $segment): array => [$segment['mark'], $segment['text'], $segment['alternative']],
        $marked,
    ));
}

describe('Marking a sentence', function() {
    it('marks the passive voice and adverbs', function() {
        expect(plMarks('The report was written quickly by the team.'))->toBe([
            ['passive', 'was written', null],
            ['adverb', 'quickly', null],
        ]);
    });

    it('offers a plainer alternative, trying the longest phrase first', function() {
        expect(plMarks('We utilise a large number of tools in order to help.'))->toBe([
            ['complex', 'utilise', 'use'],
            ['complex', 'a large number of', 'many'],
            ['complex', 'in order to', 'to'],
        ]);
    });

    it('leaves words that only look like adverbs or participles alone', function() {
        expect(plMarks('Our family is indeed only open early on a Friday.'))->toBe([]);
    });

    it('lets complex wording win where marks would overlap', function() {
        expect(plMarks('It costs approximately ten euro.'))->toBe([
            ['complex', 'approximately', 'about'],
        ]);
    });

    it('gives the sentence back unchanged when the segments are joined', function() {
        $sentence = 'Tickets were sold subsequently, and the hall was built prior to that.';

        expect(implode('', array_column(PlainLanguage::segments($sentence), 'text')))->toBe($sentence);
    });
});

describe('The preview', function() {
    it('carries the marks with each sentence, and counts them', function() {
        $blocks = AccessibilityAudit::getInstance()->getReadability()
            ->previewBlocks("The report was written quickly.\n\nWe utilise it daily.");

        expect(array_column($blocks[0][0]['segments'], 'mark'))->toBe([null, 'passive', null, 'adverb', null])
            ->and(PlainLanguage::counts($blocks))->toBe(['adverb' => 1, 'passive' => 1, 'complex' => 1]);
    });
});
