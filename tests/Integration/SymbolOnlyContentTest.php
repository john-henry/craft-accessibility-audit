<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\services\ContentScanner;

// ---------------------------------------------------------------------------
// Symbols standing in for words.
//
// A tick in a comparison table is a picture doing the work of a word. The
// shape says "supported"; the character says nothing of the sort, and a screen
// reader either announces "check mark" or, at the symbol verbosity most people
// leave set, skips it and reads an empty cell.
//
// The check reads the announced name, not the visible text, so any of the
// usual fixes takes an element out of scope without a second rule needing to
// know about them.
// ---------------------------------------------------------------------------

/** The symbol-only findings in a fragment. */
function symbolIssues(string $html): array
{
    $found = (new ContentScanner())->scan($html);

    return array_values(array_filter(
        $found,
        static fn($i): bool => $i->ruleId === 'symbol-only-content',
    ));
}

describe('a symbol standing in for a word', function() {
    it('flags a tick alone in a cell', function() {
        $issues = symbolIssues('<table><tr><td>✓</td></tr></table>');

        expect($issues)->toHaveCount(1)
            ->and($issues[0]->wcagCriterion)->toBe('1.1.1')
            ->and($issues[0]->wcagLevel)->toBe('A')
            ->and($issues[0]->severity)->toBe('warning');
    });

    it('flags a dash standing in for "not applicable"', function() {
        // The one people argue about. A sighted reader takes "nothing here"
        // from it; a screen reader user gets "dash", or silence.
        expect(symbolIssues('<table><tr><td>—</td></tr></table>'))->toHaveCount(1);
        expect(symbolIssues('<table><tr><td>-</td></tr></table>'))->toHaveCount(1);
    });

    it('flags crosses and arrows in cells', function() {
        expect(symbolIssues('<table><tr><td>✗</td><td>×</td><td>→</td></tr></table>'))->toHaveCount(3);
    });

    it('calls an arrow link a link purpose failure, not a missing alternative', function() {
        // The link has a name. The problem is that the name says nothing about
        // the destination, which is 2.4.4, the same failure as "click here".
        $issues = symbolIssues('<a href="/next">→</a>');

        expect($issues)->toHaveCount(1)
            ->and($issues[0]->wcagCriterion)->toBe('2.4.4')
            ->and($issues[0]->message)->toContain('does not say where it goes');
    });

    it('flags a symbol-only button', function() {
        expect(symbolIssues('<button>×</button>'))->toHaveCount(1);
    });
});

describe('what it leaves alone', function() {
    it('accepts a symbol with visually hidden text beside it', function() {
        // The recommended fix. Announced name is "Supported", so there is
        // nothing left to report.
        expect(symbolIssues(
            '<table><tr><td><span aria-hidden="true">✓</span><span class="sr-only">Supported</span></td></tr></table>',
        ))->toBeEmpty();
    });

    it('accepts a symbol carrying an aria-label', function() {
        expect(symbolIssues('<table><tr><td aria-label="Supported">✓</td></tr></table>'))->toBeEmpty();
        expect(symbolIssues('<a href="/next" aria-label="Next page">→</a>'))->toBeEmpty();
    });

    it('is not fooled by a title on a cell that already has content', function() {
        // Worth pinning, because title is the first thing people reach for and
        // it does not work here. The accessible name algorithm takes the
        // element's own content ahead of title, so the cell still announces
        // "check mark" and the finding stands.
        expect(symbolIssues('<table><tr><td title="Supported">✓</td></tr></table>'))->toHaveCount(1);
    });

    it('says nothing about an empty cell', function() {
        // A different question, and not this rule's to ask.
        expect(symbolIssues('<table><tr><td></td><td>  </td></tr></table>'))->toBeEmpty();
    });

    it('says nothing about a cell hidden from the accessibility tree', function() {
        expect(symbolIssues('<table><tr><td aria-hidden="true">✓</td></tr></table>'))->toBeEmpty();
    });

    it('leaves text that happens to contain a symbol alone', function() {
        expect(symbolIssues('<table><tr><td>✓ Supported</td><td>Yes</td></tr></table>'))->toBeEmpty();
    });

    it('does not sweep up punctuation, currency or maths that reads fine', function() {
        // The reason the list is fixed rather than "anything that is not a
        // letter or a digit": these all announce something useful.
        expect(symbolIssues(
            '<table><tr><td>€50</td><td>12%</td><td>3.14</td><td>N/A</td><td>?</td><td>+</td></tr></table>',
        ))->toBeEmpty();
    });
});
