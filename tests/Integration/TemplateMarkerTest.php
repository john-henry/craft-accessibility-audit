<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\twig\A11yTemplateNodeVisitor;
use Twig\Node\Nodes;
use Twig\Node\TextNode;

// ---------------------------------------------------------------------------
// The template markers the page report walks to trace an element back to the
// template that rendered it.
//
// They wrap a fragment. A template that opens a document of its own must be
// left alone: anything in front of a doctype, a comment included, puts the
// browser into quirks mode.
//
// The visitor only registers when devMode was on as Twig booted, so the
// detection is exercised directly rather than through a render.
// ---------------------------------------------------------------------------

function opensDocument(string $firstText): bool
{
    $visitor = new A11yTemplateNodeVisitor();
    $method = new ReflectionMethod($visitor, '_opensDocument');
    $method->setAccessible(true);

    return $method->invoke($visitor, new Nodes([new TextNode($firstText, 1)], 1));
}

describe('a template that opens its own document', function() {
    it('is recognised by its doctype', function() {
        expect(opensDocument('<!DOCTYPE html><html><body>x</body></html>'))->toBeTrue();
    });

    it('is recognised whatever the case', function() {
        expect(opensDocument('<!doctype html><html></html>'))->toBeTrue();
    });

    it('is recognised through leading whitespace', function() {
        expect(opensDocument("\n\n   <!DOCTYPE html>\n<html></html>"))->toBeTrue();
    });
});

describe('a fragment', function() {
    it('is not mistaken for a document', function() {
        expect(opensDocument('<p>Hello</p>'))->toBeFalse();
    });

    it('is not mistaken for one because a doctype appears further down', function() {
        // A comment in front of the paragraph harms nothing, so this still
        // gets marked.
        expect(opensDocument("<p>before</p>\n<!DOCTYPE html>"))->toBeFalse();
    });

    it('is not mistaken for one when it opens with a tag', function() {
        expect(opensDocument('<div class="wrap">'))->toBeFalse();
    });

    it('is not mistaken for one when it is empty', function() {
        expect(opensDocument(''))->toBeFalse();
    });
});
