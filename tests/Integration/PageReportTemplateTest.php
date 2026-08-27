<?php

use craft\web\View;

// ---------------------------------------------------------------------------
// The page report template compiles.
//
// It is the one template this plugin has that no test could reach: it renders
// only for a CP request, and the suite drives action routes, where the CP
// template root is not registered. So a Twig syntax error in it shipped green.
// Compiling it is cheap and catches exactly that.
// ---------------------------------------------------------------------------

it('compiles the page report template', function() {
    $view = Craft::$app->getView();
    $path = dirname(__DIR__, 2) . '/src/templates/page-report.twig';

    expect($path)->toBeFile();

    $mode = $view->getTemplateMode();
    $view->setTemplateMode(View::TEMPLATE_MODE_CP);

    try {
        $twig = $view->getTwig();
        $source = file_get_contents($path);

        // Parsed, not rendered: rendering wants a scan, an iframe and a live
        // preview. A parse settles whether the syntax is valid, which is the
        // failure that would otherwise reach a browser.
        $node = $twig->parse($twig->tokenize(new \Twig\Source((string) $source, 'page-report.twig')));

        expect($node)->toBeInstanceOf(\Twig\Node\ModuleNode::class);
    } finally {
        $view->setTemplateMode($mode);
    }
});
