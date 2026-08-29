<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\controllers\DashboardController;
use johnhenry\accessibilityaudit\services\AuditService;

// ---------------------------------------------------------------------------
// Saying which width a question came from.
//
// The page is measured at two widths and the preview shows one at a time. A
// decoration that clears the text column on a wide screen can sit behind it on
// a narrow one, so a question can be perfectly real and still be invisible in
// the preview the reader is looking at. Without the width on the row there is
// no way to tell that from a bug, and the reader is left hunting for something
// that is not on the screen.
// ---------------------------------------------------------------------------

/** The label built for a set of viewports. */
function viewportLabel(array $viewports): ?string
{
    $method = new ReflectionMethod(DashboardController::class, '_viewportLabel');
    $method->setAccessible(true);

    return $method->invoke(
        new DashboardController('dashboard', \johnhenry\accessibilityaudit\AccessibilityAudit::getInstance()),
        $viewports,
    );
}

it('names the width when a question came from only one', function() {
    expect(viewportLabel([AuditService::VIEWPORT_MOBILE]))->toBe('Mobile only')
        ->and(viewportLabel([AuditService::VIEWPORT_DESKTOP]))->toBe('Desktop only')
        ->and(viewportLabel([AuditService::VIEWPORT_MOBILE, AuditService::VIEWPORT_MOBILE]))->toBe('Mobile only');
});

it('says nothing when the question came from both', function() {
    // A label on every row is a label nobody reads.
    expect(viewportLabel([AuditService::VIEWPORT_DESKTOP, AuditService::VIEWPORT_MOBILE]))->toBeNull();
});

it('says nothing when the scan recorded no width', function() {
    // Scans predating per-viewport storage, and the PHP rules, which read the
    // markup rather than a rendered page and so belong to no width.
    expect(viewportLabel([]))->toBeNull()
        ->and(viewportLabel(['']))->toBeNull()
        ->and(viewportLabel(['something-else']))->toBeNull();
});

it('reads the width out of the issues table', function() {
    $method = new ReflectionMethod(AuditService::class, 'getPendingPotentialForScan');

    expect($method->isPublic())->toBeTrue();

    $source = (string) file_get_contents((new ReflectionClass(AuditService::class))->getFileName());
    $start = strpos($source, 'public function getPendingPotentialForScan(');

    expect(substr($source, (int) $start, 400))->toContain("'viewport'");
});

it('folds a question found at both widths into one row', function() {
    // Otherwise the same question is asked twice over identical markup, and
    // answering one leaves its twin sitting there looking unanswered.
    $source = (string) file_get_contents((new ReflectionClass(DashboardController::class))->getFileName());

    expect($source)->toContain("\$potential[\$ruleId]['occurrences'][\$at]['viewports'][] =")
        ->and($source)->toContain("\$seen[\$ruleId][\$key] = count(\$potential[\$ruleId]['occurrences']);");
});

it('shows the width on the row', function() {
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/page-report.twig');
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/src/resources/css/accessibility-audit.css');

    expect(substr_count($twig, '{{ row.viewportLabel }}'))->toBe(2)
        ->and($css)->toContain('.accessibility-audit-pr-review__vp {');
});
