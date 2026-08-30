<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\web\View;
use johnhenry\accessibilityaudit\controllers\DashboardController;

// ---------------------------------------------------------------------------
// Telling two pages apart when they share a title.
//
// Titles are not unique. A plugin's landing page and its support page carry
// the same one, share a layout, and so throw up the same findings off the same
// shared component. Listed by title alone they are indistinguishable, and a
// ruling made on one reads as a ruling that did not hold on the other.
//
// Real shape, from a real site:
//   19665  Container Deposits  plugins/container-deposits
//   19936  Container Deposits  plugins/container-deposits/support
// ---------------------------------------------------------------------------

it('carries the address on every dismissed row', function() {
    $source = (string) file_get_contents((new ReflectionClass(DashboardController::class))->getFileName());

    $start = strpos($source, 'private function _dismissedTableData(');
    $body = substr($source, (int) $start, 3000);

    expect($body)->toContain("'url' => ScanTarget::url(\$row, \$element),");
});

it('draws the address under the title in the dismissed listing', function() {
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/potential.twig');

    // Two tables live in this template and both need it, so neither cell
    // builder may be the bare title-only one it started as.
    expect(substr_count($twig, 'const pageCell = (p) => p'))->toBe(0)
        ->and(substr_count($twig, 'p.url'))->toBeGreaterThanOrEqual(2);
});

it('shows the address on the page report itself', function() {
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/page-report.twig');
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/src/resources/css/accessibility-audit.css');

    expect($twig)->toContain('class="light code accessibility-audit-pr-pageurl"')
        ->and($css)->toContain('.accessibility-audit-pr-pageurl {');
});

it('keeps the address in the sidebar, beside the score', function() {
    // Loose in the content area it reads as stray output: the title sits in
    // the header bar, so a line under it in the white area below belongs to
    // neither. Beside the score it is part of this page's summary.
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/page-report.twig');

    $sidebar = strpos($twig, 'accessibility-audit-pr-sidebar');
    $url = strpos($twig, 'accessibility-audit-pr-pageurl');
    $score = strpos($twig, 'accessibility-audit-pr-score-row');

    expect($url)->toBeGreaterThan($sidebar)
        ->and($url)->toBeLessThan($score);
});

it('clips a long address rather than pushing the score down', function() {
    // Docs URLs run long and the sidebar is narrow. The whole address stays
    // on the title attribute.
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/page-report.twig');
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/src/resources/css/accessibility-audit.css');

    expect($twig)->toContain('title="{{ pageUrl }}"')
        ->and(substr($css, strpos($css, '.accessibility-audit-pr-pageurl {'), 420))
        ->toContain('-webkit-line-clamp: 2;');
});

it('renders both templates without a Twig error', function() {
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();

    try {
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        foreach (['accessibility-audit/potential', 'accessibility-audit/page-report'] as $template) {
            $node = $view->getTwig()->parse(
                $view->getTwig()->tokenize($view->getTwig()->getLoader()->getSourceContext($template)),
            );

            expect($node)->toBeInstanceOf(\Twig\Node\ModuleNode::class);
        }
    } finally {
        $view->setTemplateMode($mode);
    }
});
