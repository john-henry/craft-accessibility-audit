<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// A template's children are not in the document.
//
// The HTML spec puts them in a separate, inert fragment: never rendered, never
// matched by CSS, no accessibility semantics. PHP's DOMDocument does not
// implement that separation and hands them to the rules as ordinary children.
//
// Alpine's x-for *must* sit on a template that is a direct child of the list,
// so `ul > template > li` is the required structure rather than sloppy markup.
// The list rule asks for the li's parent, found the template, and reported a
// correctly built list as broken. Vue, Angular and Web Components all produce
// the same shape, which is a good share of the sites this plugin is aimed at.
// ---------------------------------------------------------------------------

function scanIds(string $html): array
{
    return array_map(
        static fn($issue) => $issue->ruleId,
        AccessibilityAudit::getInstance()->content->scan($html),
    );
}

describe('markup inside a template', function() {
    it('does not report an Alpine list as a list item outside a list', function() {
        $html = <<<'HTML'
            <ul class="mt-6 space-y-3">
                <template x-for="rule in rules" x-bind:key="rule.ruleId">
                    <li class="rounded-2xl border p-5"><p x-text="rule.message"></p></li>
                </template>
            </ul>
            HTML;

        expect(scanIds($html))->not->toContain('list-structure');
    });

    it('does the same for a Vue list', function() {
        $html = '<ol><template v-for="r in rules"><li>{{ r.name }}</li></template></ol>';

        expect(scanIds($html))->not->toContain('list-structure');
    });

    it('does not report a heading whose only content is templated', function() {
        expect(scanIds('<template><h2></h2></template>'))->not->toContain('empty-heading');
    });

    it('does not report a button whose only content is templated', function() {
        expect(scanIds('<template><button type="button"></button></template>'))
            ->not->toContain('button-name');
    });

    it('reaches inside nested templates', function() {
        $html = '<template><div><template><li>Buried</li></template></div></template>';

        expect(scanIds($html))->not->toContain('list-structure');
    });

    it('handles a template in the head', function() {
        $html = '<html><head><template><li>In the head</li></template></head><body><p>Hi</p></body></html>';

        expect(scanIds($html))->not->toContain('list-structure');
    });
});

// ---------------------------------------------------------------------------
// The guard against over-suppression. Skipping template contents is only right
// while everything outside them is still examined, and a fix that quietened the
// rule generally would look identical on the failing case above.
// ---------------------------------------------------------------------------

describe('markup outside a template', function() {
    it('still reports a list item with no list around it', function() {
        expect(scanIds('<div><li>Orphan</li></div>'))->toContain('list-structure');
    });

    it('still reports one sitting beside a template in the same document', function() {
        $html = <<<'HTML'
            <ul><template x-for="r in rules"><li>Fine</li></template></ul>
            <div><li>Orphan</li></div>
            HTML;

        expect(scanIds($html))->toContain('list-structure');
    });

    it('still reports an empty heading on the page itself', function() {
        expect(scanIds('<template><h2></h2></template><h2></h2>'))->toContain('empty-heading');
    });

    it('leaves the rendered output of a browser scan alone', function() {
        // Once the framework has run there is no template left and the items
        // are real children. Nothing here should be skipped.
        expect(scanIds('<ul><li>One</li><li>Two</li></ul>'))->not->toContain('list-structure');
    });
});

it('clears the markup that reported this in the first place', function() {
    // Trimmed from the plugin's own free checker page, where the whole results
    // interface sits inside `<template x-if="result">`: four headings, seven
    // links and a button, none of it rendered until Alpine runs. The list rule
    // was the visible symptom, and the rest was being scanned just as wrongly.
    $html = <<<'HTML'
        <template x-if="result">
            <section x-show="result.rules.length" class="mt-8">
                <h2 class="font-heading text-3xl font-semibold">What&rsquo;s failing</h2>
                <ul class="mt-6 space-y-3">
                    <template x-for="rule in visibleRules()" x-bind:key="rule.ruleId">
                        <li class="rounded-2xl border bg-white p-5 shadow-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="ml-auto font-tags text-sm" x-text="rule.count + '×'"></span>
                            </div>
                            <p class="mt-3 text-slate-700" x-text="rule.message"></p>
                            <a x-show="rule.helpUrl" x-bind:href="rule.helpUrl" target="_blank" rel="noopener">
                                How to fix this
                                <span class="sr-only"> (opens in a new tab)</span>
                            </a>
                        </li>
                    </template>
                </ul>
                <button type="button" x-on:click="expanded = true">
                    Show all <span x-text="result.rules.length"></span> issues
                </button>
            </section>
        </template>
        HTML;

    // Page-level rules still fire, correctly: this is a fragment with no
    // <title>, no skip link and no <main>. What must not fire is anything
    // judging the elements inside the template.
    $found = scanIds($html);

    foreach (['list-structure', 'empty-heading', 'button-name', 'link-name', 'link-generic'] as $ruleId) {
        expect($found)->not->toContain($ruleId);
    }
});

it('keeps template contents out of the potential-issue questions too', function() {
    // An unrendered template raising a question is worse than a false positive:
    // the person cannot answer it by looking at the page.
    $html = '<template><img src="/a.png" alt="a"></template>';

    $questions = array_map(
        static fn($issue) => $issue->ruleId,
        AccessibilityAudit::getInstance()->potential->scan($html),
    );

    expect($questions)->not->toContain('potential:short-alt');
});

it('does not count unrendered words in the readability score', function() {
    $source = (string) file_get_contents(
        (new ReflectionClass(\johnhenry\accessibilityaudit\services\ReadabilityService::class))->getFileName(),
    );

    expect($source)->toContain('|//noscript|//template');
});
