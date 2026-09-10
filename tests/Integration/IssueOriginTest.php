<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;

/**
 * Where a finding's markup came from.
 *
 * The split matters because the two need different answers. A fault in
 * hand-written markup is fixed on the page it is on. A fault inside a component
 * is either the component's bug or the way it is being used, and fixing it once
 * fixes every page it appears on.
 */

/**
 * @param string $html
 * @return array<string, string> Origins keyed by rule id.
 */
function originsFor(string $html): array
{
    $issues = AccessibilityAudit::getInstance()->content->scan($html);
    $origins = [];

    foreach ($issues as $issue) {
        $origins[$issue->ruleId] = $issue->origin;
    }

    return $origins;
}

it('marks hand-written markup as authored', function() {
    $origins = originsFor('<main><p><img src="/a.png"></p></main>');

    expect($origins['img-alt'] ?? null)->toBe('authored');
});

it('names the component a finding sits inside', function() {
    $html = '<main><div data-a11y-component="dialog"><p><img src="/a.png"></p></div></main>';

    expect(originsFor($html)['img-alt'] ?? null)->toBe('dialog');
});

it('finds the component however deep the fault is buried', function() {
    $html = '<main><div data-a11y-component="carousel"><div><ul><li><span>'
        . '<img src="/a.png"></span></li></ul></div></div></main>';

    expect(originsFor($html)['img-alt'] ?? null)->toBe('carousel');
});

it('names the component rather than one of its innards', function() {
    // The marker names the component; data-a11y-dialog-document is a styling hook on a part of it.
    $html = '<main><div data-a11y-component="dialog"><div data-a11y-dialog-document>'
        . '<img src="/a.png"></div></div></main>';

    expect(originsFor($html)['img-alt'] ?? null)->toBe('dialog');
});

it('tells the two apart on the same page', function() {
    $html = '<main>'
        . '<div data-a11y-component="dialog"><a href="/x"></a></div>'
        . '<p><img src="/a.png"></p>'
        . '</main>';

    $origins = originsFor($html);

    expect($origins['link-name'] ?? null)->toBe('dialog')
        ->and($origins['img-alt'] ?? null)->toBe('authored');
});

it('leaves a page-level finding unattributed, because it belongs to no element', function() {
    // Nothing to place: the fault is the absence of something, so there is no
    // element to trace back to a component.
    $html = '<html lang="en"><head><title>A page</title></head>'
        . '<body><main><p>Fine</p></main></body></html>';

    $issues = AccessibilityAudit::getInstance()->content->scan($html);
    $pageLevel = array_values(array_filter(
        $issues,
        static fn($issue): bool => $issue->context === null
    ));

    expect($pageLevel)->not->toBeEmpty();

    foreach ($pageLevel as $issue) {
        expect($issue->origin)->toBeNull();
    }
});

it('counts the split for a site', function() {
    $counts = AccessibilityAudit::getInstance()->audit->getIssuesByOrigin(
        Craft::$app->getSites()->getPrimarySite()->id
    );

    // No scans in a rolled-back transaction, so the shape is what is checked.
    expect($counts)->toBeArray();
});
it('names a component whose own name contains a hyphen', function() {
    // The reason the marker carries the name as a value. Inferring it from a
    // shared prefix could not tell a two-word component from a part of one, so
    // checkbox-group, radio-group, back-to-top and the rest were all reported
    // as hand-written markup.
    $html = '<main><div data-a11y-component="checkbox-group"><img src="/a.png"></div></main>';

    expect(originsFor($html)['img-alt'] ?? null)->toBe('checkbox-group');
});

it('names the nearest component when one is nested in another', function() {
    $html = '<main><div data-a11y-component="dialog">'
        . '<div data-a11y-component="carousel"><img src="/a.png"></div>'
        . '</div></main>';

    expect(originsFor($html)['img-alt'] ?? null)->toBe('carousel');
});

it('treats an empty marker as hand-written', function() {
    $html = '<main><div data-a11y-component=""><img src="/a.png"></div></main>';

    expect(originsFor($html)['img-alt'] ?? null)->toBe('authored');
});
