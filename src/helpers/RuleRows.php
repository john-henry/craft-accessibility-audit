<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

/**
 * Filters and orders the one-row-per-rule listing of open issues.
 *
 * The listing is bounded by the number of rules, and its points column is
 * worked out after the query, so it is filtered and sorted in PHP rather
 * than in SQL.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class RuleRows
{
    // Const Properties
    // =========================================================================

    /**
     * @var array<string, string> The columns the table may sort on, by the
     * name the table sends, mapped to the row key holding the value. Anything
     * else falls back to the default order.
     */
    public const SORT_FIELDS = [
        'count' => 'occurrences',
        'points' => 'pointsToGain',
    ];

    /**
     * @var array<string, int> Severity rank for the default order, worst first.
     */
    private const SEVERITY_RANK = ['error' => 0, 'warning' => 1, 'notice' => 2];

    // Public Methods
    // =========================================================================

    /**
     * Rule rows folded to one per rule.
     *
     * The issue listings split a rule by criterion, level and severity, so one
     * rule can come back as several rows. Folded, each rule keeps the row with
     * its worst severity, with the occurrences and points to gain of every row
     * summed into it.
     *
     * @param array<int, array<string, mixed>> $rows The rule rows.
     * @return array<int, array<string, mixed>> One row per rule, in the order
     *         each rule first appears.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function foldByRule(array $rows): array
    {
        $rules = [];

        foreach ($rows as $row) {
            $ruleId = (string)$row['ruleId'];
            $existing = $rules[$ruleId] ?? null;

            if ($existing === null) {
                $rules[$ruleId] = $row;
                continue;
            }

            $worse = (self::SEVERITY_RANK[$row['severity'] ?? ''] ?? 3) < (self::SEVERITY_RANK[$existing['severity'] ?? ''] ?? 3);
            $folded = $worse ? $row : $existing;
            $folded['occurrences'] = (int)$existing['occurrences'] + (int)$row['occurrences'];
            $folded['pointsToGain'] = round((float)($existing['pointsToGain'] ?? 0) + (float)($row['pointsToGain'] ?? 0), 2);
            $rules[$ruleId] = $folded;
        }

        return array_values($rules);
    }

    /**
     * Rows matching a search and an element type.
     *
     * @param array<int, array<string, mixed>> $rows The rule rows.
     * @param string $search Matched against the title and the rule ID, ignoring
     *                       case. Empty matches everything.
     * @param string $elementType The element type a row must have. Empty
     *                            matches everything.
     * @return array<int, array<string, mixed>> The matching rows, re-indexed.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function filter(array $rows, string $search = '', string $elementType = ''): array
    {
        $search = mb_strtolower(trim($search));

        return array_values(array_filter($rows, static function(array $row) use ($search, $elementType): bool {
            if ($elementType !== '' && (string)($row['elementType'] ?? '') !== $elementType) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = mb_strtolower((string)($row['title'] ?? '') . ' ' . (string)($row['ruleId'] ?? ''));

            return str_contains($haystack, $search);
        }));
    }

    /**
     * Rows in the order the table asked for.
     *
     * With no recognised sort field the rows go worst severity first, then by
     * points to gain, which is how the listing reads before anyone sorts it.
     *
     * @param array<int, array<string, mixed>> $rows The rule rows.
     * @param string|null $field The sort field the table sent.
     * @param bool $ascending Whether to sort smallest first.
     * @return array<int, array<string, mixed>> The rows, re-indexed.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function sort(array $rows, ?string $field, bool $ascending): array
    {
        $key = self::SORT_FIELDS[(string)$field] ?? null;

        usort($rows, static function(array $a, array $b) use ($key, $ascending): int {
            if ($key !== null) {
                $cmp = (float)$a[$key] <=> (float)$b[$key];

                if ($cmp !== 0) {
                    return $ascending ? $cmp : -$cmp;
                }
            }

            return (self::SEVERITY_RANK[$a['severity'] ?? ''] ?? 3) <=> (self::SEVERITY_RANK[$b['severity'] ?? ''] ?? 3)
                ?: (float)$b['pointsToGain'] <=> (float)$a['pointsToGain']
                ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });

        return $rows;
    }
}
