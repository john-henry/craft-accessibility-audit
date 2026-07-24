<?php

use Twig\Source;

// ---------------------------------------------------------------------------
// CP template compile smoke test
// ---------------------------------------------------------------------------
// The CP pages' Twig/JS behaviour is browser territory, but a template that
// won't even compile takes its whole page down, and nothing else here would
// notice. Tokenising and parsing every plugin template under Craft's Twig
// environment catches syntax slips without needing any page context.

describe('Plugin CP templates', function() {
    it('compiles every template without a syntax error', function() {
        $templatesPath = Craft::getAlias('@root') . '/plugins/craft-accessibility-audit/src/templates';

        // CP-only Twig functions (iconSvg, etc.) only exist in the CP
        // environment, which is what these templates render under anyway.
        $view = Craft::$app->getView();
        $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);
        $twig = $view->getTwig();

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesPath));
        $checked = 0;

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $name = substr($file->getPathname(), strlen($templatesPath) + 1);
            $twig->parse($twig->tokenize(new Source(file_get_contents($file->getPathname()), $name)));
            $checked++;
        }

        expect($checked)->toBeGreaterThan(20);
    });

    // Compiling can't catch a missing {% import %}: Twig only raises
    // "Variable does not exist" at render time, which is how the Issues
    // page's Pages tab shipped broken. Statically require the import in any
    // template that references the alias.
    it('imports macros and forms wherever they are used', function() {
        $templatesPath = Craft::getAlias('@root') . '/plugins/craft-accessibility-audit/src/templates';
        $aliases = [
            'macros' => '/import\s+[\'"]accessibility-audit\/_macros(\.twig)?[\'"]\s+as\s+macros/',
            'forms' => '/import\s+[\'"]_includes\/forms(\.twig)?[\'"]\s+as\s+forms/',
        ];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesPath));
        $missing = [];

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $name = substr($file->getPathname(), strlen($templatesPath) + 1);

            foreach ($aliases as $alias => $importPattern) {
                if (preg_match('/\b' . $alias . '\./', $source) && !preg_match($importPattern, $source)) {
                    $missing[] = "{$name} uses {$alias}. without importing it";
                }
            }
        }

        expect($missing)->toBe([]);
    });
});
