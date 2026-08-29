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
        "{% set allClear = openTotal == 0 and (pendingPotential ?? 0) == 0 and summary.score is defined and summary.score >= 100 %}",
    );
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

    $start = strpos($css, '.accessibility-audit-allclear__tick path');

    expect($start)->not->toBeFalse();

    // The animation lives inside the guard, not outside it.
    $guard = strpos($css, '@media (prefers-reduced-motion: no-preference)');

    expect($guard)->not->toBeFalse()
        ->and($guard)->toBeLessThan($start);
});

it('hides the tick from a screen reader', function() {
    // It repeats what the sentence beside it already says.
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/index.twig');

    expect($twig)->toContain('class="accessibility-audit-allclear__tick" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"');
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
