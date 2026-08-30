<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\web\View;
use johnhenry\accessibilityaudit\controllers\DashboardController;

// ---------------------------------------------------------------------------
// What a score of 100 is allowed to claim.
//
// Potential issues do not count against the score either way until somebody
// answers them, so a site can read 100 out of 100 with zero open issues while a
// hundred questions sit unanswered in another tab. Someone could screenshot
// that as evidence of conformance. The headline has to say when there is more
// to it, and the all-clear has to mean the whole of it.
// ---------------------------------------------------------------------------

it('says when questions are still waiting', function() {
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

    expect($twig)->toContain('{% if (pendingPotential ?? 0) > 0 %}')
        ->and($twig)->toContain('still waiting on a person');
});

it('holds the all-clear back until the questions are answered too', function() {
    // Three conditions, not one. Any of them missing and the message is a
    // claim the scan cannot support.
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

    expect($twig)->toContain(
        "{% set allClear = openTotal == 0 and (pendingPotential ?? 0) == 0 and summary.avgScore >= 100 %}",
    );
});

// ---------------------------------------------------------------------------
// A key that does not exist reads as empty, and Twig says nothing about it.
//
// The all-clear was gated on `summary.score`, which the summary has never had:
// the key is `avgScore`. `is defined` was false every time, so the panel could
// not appear on any site at any score. Nothing errored and nothing logged. The
// only symptom was an absence, which is the hardest kind to notice.
// ---------------------------------------------------------------------------

it('only reads summary keys the summary actually has', function() {
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');
    $summary = \johnhenry\accessibilityaudit\AccessibilityAudit::getInstance()->audit->getSiteSummary(
        (int) Craft::$app->getSites()->getPrimarySite()->id,
    );

    preg_match_all('/summary\.([a-zA-Z]+)/', $twig, $matches);

    $referenced = array_unique($matches[1]);

    expect($referenced)->not->toBeEmpty();

    foreach ($referenced as $key) {
        expect($summary)->toHaveKey($key);
    }
});

// ---------------------------------------------------------------------------
// Colour carries meaning here, so the target marker must not borrow one.
//
// Every colour in the palette is a severity. The target card was built from the
// warning tokens, which put an amber ring and an amber badge on a card reading
// 100% with nothing failing: the one card on the screen with no problem was the
// one drawn as though it had one.
// ---------------------------------------------------------------------------

it('marks the target level without claiming anything about its state', function() {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/src/resources/css/accessibility-audit.css');

    foreach (['.accessibility-audit-kpi__cell--target {', '.accessibility-audit-kpi__badge {'] as $selector) {
        $start = strpos($css, $selector);

        expect($start)->not->toBeFalse();

        $block = substr($css, (int) $start, (int) strpos($css, '}', (int) $start) - (int) $start);

        foreach (['--aa-warning', '--aa-error', '--aa-notice', '--aa-pass'] as $severity) {
            expect($block)->not->toContain($severity);
        }
    }
});

it('counts only questions nobody has answered', function() {
    // Dismissed and confirmed ones are answered. Counting them would leave the
    // notice up permanently and teach people to ignore it.
    $source = (string) file_get_contents((new ReflectionClass(DashboardController::class))->getFileName());

    expect($source)->toContain('$plugin->audit->getPotentialIssues($siteId)');
});

it('does not animate for anyone who asked for less motion', function() {
    // A tool that argues for accessibility cannot be the thing on the page
    // ignoring the setting.
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/src/resources/css/accessibility-audit.css');

    $start = strpos($css, '.accessibility-audit-panelnote__tick path');

    expect($start)->not->toBeFalse();

    // The animation lives inside the guard, not outside it.
    $guard = strpos($css, '@media (prefers-reduced-motion: no-preference)');

    expect($guard)->not->toBeFalse()
        ->and($guard)->toBeLessThan($start);
});

it('hides the tick from a screen reader', function() {
    // It repeats what the sentence beside it already says.
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

    expect($twig)->toContain('class="accessibility-audit-panelnote__tick" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"');
});

it('renders the overview without a Twig error', function() {
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();

    try {
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        $node = $view->getTwig()->parse(
            $view->getTwig()->tokenize($view->getTwig()->getLoader()->getSourceContext('accessibility-audit/index')),
        );

        expect($node)->toBeInstanceOf(\Twig\Node\ModuleNode::class);
    } finally {
        $view->setTemplateMode($mode);
    }
});

// ---------------------------------------------------------------------------
// Class names are shared ground.
//
// "accessibility-audit-note" was already taken, by the muted "/100" beside the
// score on the page report. A second rule under the same name won the cascade
// and put a border and padding round it.
// ---------------------------------------------------------------------------

it('does not reuse a class name another screen already had', function() {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/src/resources/css/accessibility-audit.css');

    // One rule per name. Two blocks under one selector is the shape of the bug.
    expect(substr_count($css, "\n.accessibility-audit-note {"))->toBe(1)
        ->and(substr_count($css, "\n.accessibility-audit-panelnote {"))->toBe(1);
});

it('leaves the page report score alone', function() {
    $report = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/page-report.twig');
    $overview = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

    expect($report)->toContain('class="light accessibility-audit-note">/100<')
        ->and($overview)->not->toContain('"accessibility-audit-note ')
        ->and($overview)->toContain('accessibility-audit-panelnote');
});
