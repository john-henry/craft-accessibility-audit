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

// ---------------------------------------------------------------------------
// Locating a capped occurrence on the page.
//
// Contexts arrive in two shapes. The PHP rules mark a cut with an ellipsis;
// axe's snippets are cut at 300 characters with nothing to show for it. On
// utility-class markup that cut nearly always lands inside the class
// attribute, leaving a fragment like "prose-a:focus-v" that selects nothing.
// Reading only the ellipsis meant the second shape was matched as though it
// were whole, and "Show on page" could never find it.
// ---------------------------------------------------------------------------

it('treats a context that stops short of a closing bracket as capped', function() {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/src/resources/js/page-report.js');

    expect($js)->toContain("var truncated = html.slice(-1) === '…' || !/>\s*$/.test(html);")
        ->and($js)->toContain('if (truncated && classes.length > 1) classes.pop();');
});

it('caps axe contexts without changing what a verdict hashes', function() {
    // The cap stays exactly as it was on purpose. Appending an ellipsis to
    // mark it would change the stored context, and the verdict hash is taken
    // over that string, so every dismissal on a long snippet would be orphaned
    // and the question would come back.
    $source = (string) file_get_contents(
        (new ReflectionClass(\johnhenry\accessibilityaudit\services\AuditService::class))->getFileName(),
    );

    expect($source)->toContain("mb_substr(\$node['html'] ?? '', 0, 300)")
        ->and($source)->not->toContain("mb_substr(\$node['html'] ?? '', 0, 300) . '…'");
});
