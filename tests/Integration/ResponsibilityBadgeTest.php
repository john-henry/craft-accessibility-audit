<?php

use craft\web\View;
use johnhenry\accessibilityaudit\services\RuleRegistry;

// ---------------------------------------------------------------------------
// The responsibility badge.
//
// The same badge is drawn three ways: by the Twig macro on a server-rendered
// row, and by the table builders in issues.twig, potential.twig and cp.js on a
// row drawn in the browser. They each used to carry their own copy of the
// labels, and they had already drifted: the client one said "Content" where the
// server said "Content writing", and never went through the translation file.
//
// So this pins both halves. The macro is rendered for real, and the map every
// client-side renderer is handed is checked to agree with it.
// ---------------------------------------------------------------------------

/** The macro's output for one responsibility value. */
function badgeFor(?string $responsibility): string
{
    $view = Craft::$app->getView();
    $template = "{% import 'accessibility-audit/_macros' as macros %}{{ macros.responsibility(value) }}";

    return $view->renderString(
        $template,
        ['value' => $responsibility],
        View::TEMPLATE_MODE_CP,
    );
}

describe('the responsibility badge', function() {
    it('renders the label and the class modifier for every value', function(string $value, string $modifier, string $label) {
        $html = badgeFor($value);

        expect($html)
            ->toContain('accessibility-audit-owner--' . $modifier)
            ->toContain($label);
    })->with([
        'content' => ['content', 'content', 'Content writing'],
        'design' => ['design', 'design', 'Visual design'],
        'development' => ['development', 'dev', 'Development'],
        'technical' => ['technical', 'technical', 'Technical'],
    ]);

    it('falls back rather than rendering a broken class for an unknown value', function() {
        // A responsibility the registry does not know about still has to
        // produce a badge with a real modifier: an empty one renders
        // `accessibility-audit-owner--`, which matches no rule and shows as
        // unstyled text.
        $html = badgeFor('something-else');

        expect($html)->toContain('accessibility-audit-owner--technical')
            ->and($html)->not->toContain('owner--"');
    });

    it('shows a dash where nothing is set', function() {
        expect(trim(badgeFor(null)))->toBe('—');
    });

    it('hands the client renderers the same labels the macro prints', function() {
        // The map injected for cp.js and rendered into the two table
        // templates. If these ever diverge from the macro, a row drawn in the
        // browser reads differently to the same row drawn on the server.
        foreach (RuleRegistry::responsibilities() as $value => $badge) {
            $html = badgeFor((string) $value);

            expect($html)
                ->toContain('accessibility-audit-owner--' . $badge['modifier'])
                ->toContain($badge['label']);
        }
    });
});
