<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

/**
 * Gathers repeated occurrences of one question into a single thing to answer.
 *
 * A rule that fires per element fires once per element. A reference table of
 * nineteen rows and three columns whose cells cannot be measured for contrast
 * produces fifty-seven questions, all of them the same question, and every one
 * of them showing the same truncated `<td class="px-4 py-2.5 …">` because that
 * is genuinely what the markup says. Nobody reads fifty-seven of those. They
 * scroll past, which is the same failure as a false positive arrived at from
 * the other direction: the queue teaches you to ignore it.
 *
 * Occurrences are grouped by what the markup looks like, tag plus class, since
 * a component repeated down a page repeats its classes. That collapses the
 * table above into one question about `<td>` cells, with the individual
 * occurrences kept underneath for anyone who wants to answer them one at a
 * time.
 *
 * Grouping is presentational only. Each occurrence keeps its own context, and
 * a verdict is still recorded against each one separately, so answering a
 * cluster is the same as answering its members in turn.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
class OccurrenceCluster
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Occurrences sharing a signature are only gathered from this
     *          many up. Below it a cluster is more chrome than the two or
     *          three cards it would replace.
     */
    public const MIN_SIZE = 3;

    // Public Methods
    // =========================================================================

    /**
     * Groups one rule's occurrences by the markup they were found in.
     *
     * @param array<int, array<string, mixed>> $occurrences The rule's rows, in
     *                                                      the order they were
     *                                                      found.
     * @return array<int, array{signature: string, label: string, count: int, occurrences: array<int, array<string, mixed>>}>
     *         Clusters in first-seen order. A cluster of one is a plain
     *         occurrence and the template renders it as it always did.
     */
    public static function group(array $occurrences): array
    {
        $bySignature = [];

        foreach ($occurrences as $occurrence) {
            $signature = self::signature((string)($occurrence['context'] ?? ''));
            $bySignature[$signature][] = $occurrence;
        }

        $clusters = [];

        foreach ($bySignature as $signature => $members) {
            // Too few to be worth folding away: emitted one at a time so they
            // read exactly as they did before.
            if (count($members) < self::MIN_SIZE || $signature === '') {
                foreach ($members as $member) {
                    $clusters[] = [
                        'signature' => '',
                        'label' => '',
                        'count' => 1,
                        'occurrences' => [$member],
                    ];
                }

                continue;
            }

            $clusters[] = [
                'signature' => $signature,
                'label' => self::label($signature, count($members)),
                'count' => count($members),
                'occurrences' => $members,
            ];
        }

        return $clusters;
    }

    /**
     * What a piece of markup looks like, reduced to tag and class.
     *
     * Two occurrences of one component share these and differ in their text,
     * which is exactly the wrong way round for telling them apart and exactly
     * the right way round for gathering them together.
     *
     * @param string $context The stored context snippet.
     * @return string A signature, or an empty string when the context is not
     *                markup and so cannot be grouped this way.
     */
    public static function signature(string $context): string
    {
        if (preg_match('/^\s*<([a-z][a-z0-9]*)\b([^>]*)>/i', $context, $m) !== 1) {
            return '';
        }

        $tag = strtolower($m[1]);
        $class = '';

        if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is', $m[2], $classMatch) === 1) {
            // Whitespace-normalised and sorted, so the same classes written in
            // a different order still count as the same component.
            $classes = preg_split('/\s+/', trim($classMatch[2])) ?: [];
            sort($classes);
            $class = implode(' ', array_filter($classes));
        }

        return $tag . '|' . $class;
    }

    /**
     * A human-readable name for a cluster, for the card that stands in for it.
     *
     * @param string $signature The cluster's signature.
     * @param int $count How many occurrences it holds.
     * @return string
     */
    public static function label(string $signature, int $count): string
    {
        [$tag] = explode('|', $signature, 2);

        return $count . ' × <' . $tag . '>';
    }
}
