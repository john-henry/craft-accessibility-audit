<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\services\ContentScanner;

// ---------------------------------------------------------------------------
// Markup rendering inside a code sample.
//
// `<code>` is presentational. It does not escape anything, so documentation
// that writes about HTML and wraps it in `<code>` without turning the angle
// brackets into entities puts real elements on the page. The document that
// results is perfectly valid, which is why nothing else reports it.
//
// The cost depends on the tag: a void one mangles a sentence, one that is not
// void is handed the rest of the page and it stops existing.
// ---------------------------------------------------------------------------

/**
 * The rule's findings for a body snippet.
 *
 * @return array<int, \johnhenry\accessibilityaudit\models\IssueModel>
 */
function codeLeakIssues(string $body): array
{
    return array_values(array_filter(
        (new ContentScanner())->scan(a11yCleanPage($body)),
        static fn($issue): bool => $issue->ruleId === 'unescaped-markup-in-code',
    ));
}

describe('unescaped markup in a code sample', function() {
    it('flags a void tag that renders instead of being read', function() {
        $found = codeLeakIssues('<p>Check <code><img src=""></code> found in rich text.</p>');

        expect($found)->toHaveCount(1)
            ->and($found[0]->message)->toContain('<img>')
            ->and($found[0]->severity)->toBe('warning');
    });

    it('treats a tag that swallows the page as an error and says what it cost', function() {
        // Nothing closes it, so a browser hands it everything that follows.
        $found = codeLeakIssues(
            '<p>Check <code><iframe src=""></code> found in rich text.</p>'
            . '<p>This paragraph never renders.</p>',
        );

        expect($found)->toHaveCount(1)
            ->and($found[0]->severity)->toBe('error')
            ->and($found[0]->message)->toContain('<iframe>')
            ->and($found[0]->message)->toContain('characters after it never render');
    });

    it('leaves properly escaped markup alone', function() {
        // The whole point: this is what the author meant, and it is correct.
        expect(codeLeakIssues('<p>Check <code>&lt;img src=""&gt;</code> found in rich text.</p>'))
            ->toBeEmpty();
    });

    it('leaves a syntax highlighter alone', function() {
        // Highlighters wrap tokens in spans, and renderers nest pre and code.
        expect(codeLeakIssues(
            '<pre><code><span class="tok-k">const</span> <span class="tok-v">x</span></code></pre>',
        ))->toBeEmpty();
    });

    it('leaves a link inside a code sample alone', function() {
        expect(codeLeakIssues('<p><code><a href="/api">craft.a11y</a></code></p>'))->toBeEmpty();
    });

    it('catches an unknown tag, which renders as nothing at all', function() {
        // `array<string>` displays as "array". Nothing looks broken, and the
        // reader is quietly told the wrong thing.
        $found = codeLeakIssues('<p>Returns <code>array<string></code> of ids.</p>');

        expect($found)->toHaveCount(1)
            ->and($found[0]->message)->toContain('<string>');
    });

    it('reports one finding per sample, not one per nested element', function() {
        $found = codeLeakIssues('<p><code><img src=""><img src=""></code></p>');

        expect($found)->toHaveCount(1);
    });

    it('reports each sample that has the mistake', function() {
        $found = codeLeakIssues(
            '<p><code><img src=""></code></p><p><code>array<handle></code></p>',
        );

        expect($found)->toHaveCount(2);
    });

    it('claims no WCAG criterion, because none of them covers this', function() {
        // Content that never renders is a content-integrity problem, not a
        // failure of a success criterion. Claiming one would be wrong.
        $found = codeLeakIssues('<p><code><img src=""></code></p>');

        expect($found[0]->wcagCriterion)->toBeNull()
            ->and($found[0]->wcagLevel)->toBeNull();
    });

    it('carries the sample as context so the report can point at it', function() {
        $found = codeLeakIssues('<p><code><img src=""></code></p>');

        expect($found[0]->context)->toContain('<code');
    });
});
