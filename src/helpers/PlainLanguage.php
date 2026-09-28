<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

/**
 * Finds the plain-language habits the Readability preview marks in a sentence:
 * adverbs, the passive voice, and words or phrases with a plainer alternative.
 *
 * The checks are simple pattern matches, and they work for English only. They point at places worth a second look rather
 * than at mistakes: plenty of adverbs earn their place, and the passive voice
 * is sometimes the right choice.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 *
 * @phpstan-type PlainSegment array{text: string, mark: string|null, alternative: string|null}
 * @phpstan-type PlainSentence array{text: string, words: int, level: int, segments: array<int, PlainSegment>, difficulty: string|null}
 */
class PlainLanguage
{
    // Const Properties
    // =========================================================================

    /**
     * @var string A word ending in "ly" used as an adverb.
     */
    public const MARK_ADVERB = 'adverb';

    /**
     * @var string A form of "to be" followed by a past participle.
     */
    public const MARK_PASSIVE = 'passive';

    /**
     * @var string A word or phrase with a plainer alternative.
     */
    public const MARK_COMPLEX = 'complex';

    /**
     * @var string[] Words ending in "ly" that are not adverbs, or are not worth
     *      marking as one.
     */
    private const NOT_ADVERBS = [
        'ally', 'anomaly', 'apply', 'assembly', 'belly', 'bully', 'butterfly', 'chilly', 'comply', 'costly',
        'curly', 'daily', 'deadly', 'early', 'elderly', 'family', 'friendly', 'hilly', 'holy', 'homely',
        'hourly', 'imply', 'italy', 'jelly', 'jolly', 'july', 'likely', 'lively', 'lonely', 'lovely',
        'melancholy', 'monopoly', 'monthly', 'multiply', 'only', 'orderly', 'rally', 'rely', 'reply',
        'silly', 'supply', 'tally', 'timely', 'ugly', 'unfriendly', 'unlikely', 'weekly', 'yearly',
    ];

    /**
     * @var string[] Words ending in "ed" that are not past participles, so a
     *      form of "to be" before them is not the passive voice.
     */
    private const NOT_PARTICIPLES = [
        'bed', 'bleed', 'breed', 'creed', 'exceed', 'feed', 'greed', 'hundred', 'indeed', 'kindred',
        'naked', 'need', 'proceed', 'red', 'sacred', 'seed', 'shed', 'speed', 'succeed', 'weed', 'wicked',
    ];

    /**
     * @var string[] Irregular past participles, which follow a form of "to be"
     *      in the passive voice without ending in "ed".
     */
    private const IRREGULAR_PARTICIPLES = [
        'begun', 'born', 'bought', 'brought', 'built', 'caught', 'chosen', 'done', 'drawn', 'driven',
        'eaten', 'fallen', 'felt', 'forgotten', 'found', 'given', 'grown', 'heard', 'held', 'hidden',
        'hung', 'kept', 'known', 'laid', 'led', 'left', 'lent', 'lost', 'made', 'meant', 'met', 'paid',
        'said', 'seen', 'sent', 'shown', 'sold', 'spent', 'spoken', 'stolen', 'struck', 'taken', 'taught',
        'thought', 'thrown', 'told', 'understood', 'woken', 'won', 'worn', 'written',
    ];

    /**
     * @var array<string, string> Words and phrases with a plainer alternative,
     *      keyed by the wording to look for.
     */
    private const PLAINER = [
        'a large number of' => 'many',
        'a number of' => 'some',
        'accordingly' => 'so',
        'acquire' => 'get',
        'additional' => 'more',
        'amongst' => 'among',
        'approximately' => 'about',
        'are able to' => 'can',
        'ascertain' => 'find out',
        'assistance' => 'help',
        'at the present time' => 'now',
        'at this point in time' => 'now',
        'beneficial' => 'helpful',
        'commence' => 'start',
        'component' => 'part',
        'consequently' => 'so',
        'demonstrate' => 'show',
        'due to the fact that' => 'because',
        'endeavour' => 'try',
        'endeavor' => 'try',
        'facilitate' => 'help',
        'for the purpose of' => 'for',
        'has the ability to' => 'can',
        'have the ability to' => 'can',
        'in addition' => 'also',
        'in close proximity to' => 'near',
        'in order to' => 'to',
        'in relation to' => 'about',
        'in spite of the fact that' => 'although',
        'in the event that' => 'if',
        'in the vicinity of' => 'near',
        'individuals' => 'people',
        'initiate' => 'start',
        'is able to' => 'can',
        'locate' => 'find',
        'modify' => 'change',
        'notwithstanding' => 'despite',
        'numerous' => 'many',
        'obtain' => 'get',
        'on a daily basis' => 'daily',
        'optimal' => 'best',
        'participate' => 'take part',
        'prior to' => 'before',
        'purchase' => 'buy',
        'remainder' => 'rest',
        'require' => 'need',
        'reside' => 'live',
        'subsequently' => 'later',
        'sufficient' => 'enough',
        'terminate' => 'end',
        'the majority of' => 'most',
        'transmit' => 'send',
        'utilisation' => 'use',
        'utilise' => 'use',
        'utilize' => 'use',
        'whilst' => 'while',
        'with regard to' => 'about',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Splits a sentence into segments, each plain text or a marked adverb,
     * passive construction or complex wording. Joined back together, the
     * segments' text is the sentence, unchanged.
     *
     * Where marks would overlap, complex wording wins over the passive voice,
     * and the passive voice over an adverb. Longer phrases are looked for
     * before shorter ones, so "a large number of" is not read as "a number of".
     *
     * @param string $sentence The sentence.
     * @return array<int, PlainSegment> The sentence in marked-up pieces.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function segments(string $sentence): array
    {
        $found = [];

        foreach (self::_phrasesLongestFirst() as $phrase => $alternative) {
            if (preg_match_all('/\b' . preg_quote($phrase, '/') . '\b/i', $sentence, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$text, $offset]) {
                    $found[] = [$offset, strlen($text), self::MARK_COMPLEX, $alternative];
                }
            }
        }

        $notParticiple = '(?!(?:' . implode('|', self::NOT_PARTICIPLES) . ')\b)';
        $participle = '(?:[a-z]+ed|' . implode('|', self::IRREGULAR_PARTICIPLES) . ')';
        $passive = '/\b(?:am|is|are|was|were|be|been|being)\s+' . $notParticiple . $participle . '\b/i';

        if (preg_match_all($passive, $sentence, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$text, $offset]) {
                $found[] = [$offset, strlen($text), self::MARK_PASSIVE, null];
            }
        }

        if (preg_match_all('/\b[a-z]{2,}ly\b/i', $sentence, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$text, $offset]) {
                if (!in_array(strtolower($text), self::NOT_ADVERBS, true)) {
                    $found[] = [$offset, strlen($text), self::MARK_ADVERB, null];
                }
            }
        }

        // The first mark found keeps its place, and complex wording was
        // looked for first.
        $accepted = [];

        foreach ($found as $mark) {
            foreach ($accepted as $taken) {
                if ($mark[0] < $taken[0] + $taken[1] && $taken[0] < $mark[0] + $mark[1]) {
                    continue 2;
                }
            }

            $accepted[] = $mark;
        }

        usort($accepted, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $segments = [];
        $position = 0;

        foreach ($accepted as [$offset, $length, $mark, $alternative]) {
            if ($offset > $position) {
                $segments[] = ['text' => substr($sentence, $position, $offset - $position), 'mark' => null, 'alternative' => null];
            }

            $segments[] = ['text' => substr($sentence, $offset, $length), 'mark' => $mark, 'alternative' => $alternative];
            $position = $offset + $length;
        }

        if ($position < strlen($sentence)) {
            $segments[] = ['text' => substr($sentence, $position), 'mark' => null, 'alternative' => null];
        }

        return $segments;
    }

    /**
     * How many adverbs, passive constructions and complex words or phrases the
     * sentences of a readability preview hold.
     *
     * @param array<int, array<int, PlainSentence>> $blocks Paragraphs of
     *        sentences, as ReadabilityService::previewBlocks() returns them.
     * @return array{adverb: int, passive: int, complex: int}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function counts(array $blocks): array
    {
        $counts = [self::MARK_ADVERB => 0, self::MARK_PASSIVE => 0, self::MARK_COMPLEX => 0];

        foreach ($blocks as $sentences) {
            foreach ($sentences as $sentence) {
                foreach ($sentence['segments'] as $segment) {
                    if ($segment['mark'] !== null && isset($counts[$segment['mark']])) {
                        $counts[$segment['mark']]++;
                    }
                }
            }
        }

        return $counts;
    }

    // Private Methods
    // =========================================================================

    /**
     * The plainer-wording phrases, longest first, so a longer phrase is matched
     * before a shorter one inside it. Sorted once per request.
     *
     * @return array<string, string>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private static function _phrasesLongestFirst(): array
    {
        static $sorted = null;

        if ($sorted === null) {
            $sorted = self::PLAINER;
            uksort($sorted, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        }

        return $sorted;
    }
}
