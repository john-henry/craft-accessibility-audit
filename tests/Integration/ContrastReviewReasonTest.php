<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\services\AuditService;

// ---------------------------------------------------------------------------
// Why a contrast question was asked.
//
// axe hands over a key naming what stopped it measuring. Turning that into a
// sentence is the whole value of the question: "check this" on its own gives
// the reader nowhere to look, and a question nobody can answer gets dismissed
// unread, which is worse than never asking.
// ---------------------------------------------------------------------------

/** Every key axe's colour-contrast check can report, read from the bundled build. */
function axeContrastKeys(): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/resources/axe/axe.min.js');

    $start = strpos($source, 'incomplete:{default:"Unable to determine contrast ratio"');
    expect($start)->not->toBeFalse('the colour-contrast message block moved in this axe build');

    preg_match_all('/([a-zA-Z]+):"/', substr($source, (int) $start, 1500), $m);

    return array_values(array_diff(array_unique($m[1]), ['default', 'incomplete', 'impact']));
}

/** The sentence the plugin builds for a given axe key. */
function reasonFor(?string $key): string
{
    $method = new ReflectionMethod(AuditService::class, '_contrastNeedsReviewMessage');
    $method->setAccessible(true);

    return $method->invoke(Craft::$app->getModule('accessibility-audit')?->audit ?? new AuditService(), array_filter([
        'messageKey' => $key,
    ]));
}

it('has a reason for every key this axe build can report', function() {
    // Pinned to the bundled file rather than a list written out by hand, so
    // upgrading axe fails here instead of silently turning new keys into the
    // catch-all sentence.
    $generic = reasonFor(null);
    $unmapped = [];

    // "hidden" is never turned into a sentence because it is never asked:
    // there was nothing visible to measure, so there is no question.
    foreach (array_diff(axeContrastKeys(), ['hidden']) as $key) {
        if (str_contains(reasonFor($key), $generic)) {
            $unmapped[] = $key;
        }
    }

    expect($unmapped)->toBe([], 'unmapped axe messageKeys: ' . implode(', ', $unmapped));
});

it('names the thing to look at, not just that it gave up', function() {
    expect(reasonFor('bgImage'))->toContain('background image')
        ->and(reasonFor('fgAlpha'))->toContain('partly transparent')
        ->and(reasonFor('elmPartiallyObscured'))->toContain('covers part of it')
        ->and(reasonFor('complexTextShadows'))->toContain('text shadows');
});

it('still answers when axe sends a key it has never seen', function() {
    expect(reasonFor('somethingNewInAxe6'))->toContain('could not be worked out');
});

it('shows the reason next to the occurrence, not only when there is no markup', function() {
    // The reason used to render on an "elseif" behind the markup snippet, so
    // a contrast finding, which always carries markup, never showed one.
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/page-report.twig');

    expect($twig)->toContain('{% if row.message and row.message != group.question %}')
        ->and($twig)->not->toContain('{% elseif row.message %}');
});

it('does not ask about text that was not visible', function() {
    // axe reports "hidden" when the element had nothing on screen to sample.
    // Contrast is about what a person can see, so this is noise in a queue
    // the reader has to work through by hand.
    $scanner = new ReflectionMethod(AuditService::class, '_storeContrastNeedsReview');

    expect(file_get_contents((new ReflectionClass(AuditService::class))->getFileName()))
        ->toContain("if ((\$data['messageKey'] ?? '') === 'hidden') {")
        ->and($scanner->isPrivate())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Reading a long explanation.
//
// The longer messages are written as a headline, the places involved, then a
// numbered list of fixes. Rendered in a plain paragraph the newlines collapse
// and it arrives as one unbroken block with "1." and "2." buried in the
// middle of sentences, which is where a reader gives up.
// ---------------------------------------------------------------------------

it('keeps the line breaks in an explanation that has them', function() {
    $report = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/page-report.twig');
    $detail = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/issue-detail.twig');
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/src/resources/css/accessibility-audit.css');

    expect($css)->toContain('white-space: pre-line;')
        ->and($detail)->toContain('white-space:pre-line')
        ->and($report)->toContain('accessibility-audit-pr-review__msg');
});

it('describes the rule on a rule page, rather than quoting one page', function() {
    // The rule page totals every page the rule fired on, so a message naming
    // one page's links read as the definition of the rule.
    $meta = \johnhenry\accessibilityaudit\services\RuleRegistry::get('potential:identical-links');

    expect($meta)->toHaveKey('description')
        ->and($meta['description'])->toContain('read the same but go to different places')
        ->and($meta['description'])->not->toContain('johnhenry.ie');

    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/issue-detail.twig');

    expect($twig)->toContain('{{ ruleDescription ?? detail.message }}')
        ->and($twig)->toContain("'One of these findings'|t('accessibility-audit')");
});
