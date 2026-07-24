<?php

use johnhenry\accessibilityaudit\services\AuditService;

// ---------------------------------------------------------------------------
// Every listing of resolved issues has to answer the same question the same
// way: is a potential issue that stopped appearing a fix?
//
// The answer is only yes when somebody first confirmed it was a failure.
// Dismissing one as "not an issue" resolves nothing, because nothing was ever
// established to be wrong. Counting dismissals as remediation flatters the
// numbers in exactly the direction that makes them useless.
//
// The dashboard card and the per-element list did not filter, while the
// Resolved tab and the remediation chart did, so the dashboard reported a
// resolved rule that the Resolved tab showed as nothing at all.
// ---------------------------------------------------------------------------

it('applies the same potential-issue rule to every resolved listing', function() {
    $source = file_get_contents(
        dirname(__DIR__, 2) . '/src/services/AuditService.php',
    );

    $methods = [
        'getResolvedIssues',
        'getResolvedIssuesByImpact',
        'getResolvedTrend',
        'getResolvedIssuesForElement',
    ];

    $missing = [];

    foreach ($methods as $method) {
        // The method body, up to the start of the next one.
        $start = strpos($source, "public function {$method}(");
        expect($start)->not->toBeFalse("{$method}() is gone: update this test with its replacement.");

        $next = strpos($source, 'public function ', $start + 20);
        $body = substr($source, $start, $next !== false ? $next - $start : null);

        if (!str_contains($body, 'definiteCondition')) {
            $missing[] = $method;
        }
    }

    expect($missing)->toBe([], sprintf(
        "These count unconfirmed potential issues as resolved, so they disagree with the rest:\n  - %s",
        implode("\n  - ", $missing),
    ));
});

it('keeps confirmed potentials but drops dismissed and unreviewed ones', function() {
    // The rule itself, stated once: a non-potential rule always counts, and a
    // potential one counts only with a confirmed verdict.
    $condition = AccessibilityAudit()->audit->definiteCondition('i');

    $encoded = json_encode($condition);

    expect($encoded)->toContain('not like')
        ->and($encoded)->toContain('potential:%')
        ->and($encoded)->toContain('confirmed')
        // "dismissed" must never appear: a dismissal is not a qualifying verdict.
        ->and($encoded)->not->toContain('dismissed');
});

function AccessibilityAudit(): johnhenry\accessibilityaudit\AccessibilityAudit
{
    return johnhenry\accessibilityaudit\AccessibilityAudit::getInstance();
}
