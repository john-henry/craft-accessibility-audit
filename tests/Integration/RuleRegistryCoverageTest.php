<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\services\ContentScanner;
use johnhenry\accessibilityaudit\services\PotentialScanner;
use johnhenry\accessibilityaudit\services\RuleRegistry;

// ---------------------------------------------------------------------------
// Every rule a scanner emits has metadata behind it.
//
// RuleRegistry is what the dashboard, the filters and the exports read to say
// how hard a rule is to fix, whose job it is, and who it affects. A rule added
// to a scanner and not added here still scans and still reports; it just turns
// up in the listings with blanks where the filters expect values, and the
// filters silently stop matching it. Adding a rule is two edits and this is
// what remembers the second one.
// ---------------------------------------------------------------------------

/**
 * Rule IDs the two PHP scanners can emit, read from the source.
 *
 * @return string[]
 */
function emittedRuleIds(): array
{
    $ids = [];

    foreach ([ContentScanner::class, PotentialScanner::class] as $class) {
        $source = (string)file_get_contents((new ReflectionClass($class))->getFileName());

        // IssueModel::make(ruleId: 'x', …) and the positional form, plus the
        // check map keys in ContentScanner::scan().
        preg_match_all("/ruleId:\s*'([a-z0-9:-]+)'/", $source, $named);
        preg_match_all("/IssueModel::make\(\s*'([a-z0-9:-]+)'/", $source, $positional);
        preg_match_all("/'([a-z0-9:-]+)'\s*=>\s*fn\(\)/", $source, $checks);

        $ids = [...$ids, ...$named[1], ...$positional[1], ...$checks[1]];
    }

    return array_values(array_unique($ids));
}

it('has registry metadata for every rule the scanners emit', function() {
    $emitted = emittedRuleIds();

    // A floor, so a broken reader cannot pass by finding nothing.
    expect(count($emitted))->toBeGreaterThan(25);

    $missing = array_values(array_filter(
        $emitted,
        static fn(string $ruleId): bool => RuleRegistry::get($ruleId) === null,
    ));

    expect($missing)->toBe([], sprintf(
        "These rules are emitted by a scanner but have no RuleRegistry entry, so they list "
        . "with blank difficulty, responsibility and element type:\n  - %s",
        implode("\n  - ", $missing),
    ));
});

it('gives every registry entry a value from the documented sets', function() {
    // The filters on the Issues page are built from these, so a typo takes a
    // rule out of its own filter rather than erroring.
    $difficulties = ['beginner', 'intermediate', 'advanced'];
    $responsibilities = ['content', 'design', 'development', 'technical'];
    $abilities = ['vision', 'cognition', 'motor', 'hearing'];

    $bad = [];

    foreach (emittedRuleIds() as $ruleId) {
        $meta = RuleRegistry::get($ruleId);

        if ($meta === null) {
            continue;
        }

        if (!in_array($meta['difficulty'] ?? '', $difficulties, true)) {
            $bad[] = "$ruleId: difficulty '" . ($meta['difficulty'] ?? '') . "'";
        }

        if (!in_array($meta['responsibility'] ?? '', $responsibilities, true)) {
            $bad[] = "$ruleId: responsibility '" . ($meta['responsibility'] ?? '') . "'";
        }

        if (trim((string)($meta['elementType'] ?? '')) === '') {
            $bad[] = "$ruleId: no elementType";
        }

        foreach ((array)($meta['abilities'] ?? []) as $ability) {
            if (!in_array($ability, $abilities, true)) {
                $bad[] = "$ruleId: ability '$ability'";
            }
        }
    }

    expect($bad)->toBe([], "Registry values outside the documented sets:\n  - " . implode("\n  - ", $bad));
});
