<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

// ---------------------------------------------------------------------------
// The control panel's tables, the page report and the front-end overlay all
// build markup as strings and put it through innerHTML, and every value that
// came off a scanned page goes through escHtml on the way. There is no JS
// runner in this plugin, so the escaper is pinned by reading it: what matters
// is that it covers the whole set, and that the overlay's standalone fallback
// covers the same set as the shared one it stands in for.
// ---------------------------------------------------------------------------

/** The characters an HTML escaper has to deal with, and what each becomes. */
function escapedEntities(): array
{
    return [
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        '"' => '&quot;',
        "'" => '&#39;',
    ];
}

it('escapes every character that can break out of markup', function() {
    $js = (string)file_get_contents(
        dirname(__DIR__, 2) . '/src/resources/js/accessibility-audit-shared.js'
    );

    $start = strpos($js, 'function escHtml(');
    expect($start)->not->toBeFalse();

    $body = substr($js, $start, 400);
    $missing = [];

    foreach (escapedEntities() as $char => $entity) {
        if (!str_contains($body, "'" . $entity . "'")) {
            $missing[] = $char . ' -> ' . $entity;
        }
    }

    expect($missing)->toBe([]);
});

it('keeps the overlay fallback in step with the shared escaper', function() {
    // frontend-axe.js runs where AccessibilityAuditShared may not have loaded,
    // so it carries its own copy. Two escapers that drift are worse than one,
    // because the weaker one is the one nobody is looking at.
    $js = (string)file_get_contents(dirname(__DIR__, 2) . '/src/resources/js/frontend-axe.js');

    $start = strpos($js, 'function esc(');
    expect($start)->not->toBeFalse();

    $body = substr($js, $start, 400);
    $missing = [];

    foreach (escapedEntities() as $char => $entity) {
        if (!str_contains($body, "'" . $entity . "'")) {
            $missing[] = $char . ' -> ' . $entity;
        }
    }

    expect($missing)->toBe([]);
});
