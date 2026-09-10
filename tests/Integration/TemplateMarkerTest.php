<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\twig\A11yTemplateNodeVisitor;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Node\Nodes;
use Twig\Node\TextNode;

// ---------------------------------------------------------------------------
// The template markers the page report walks to trace an element back to the
// template that rendered it.
//
// They wrap a fragment of markup, and two kinds of template have to be left
// alone. One that opens a document of its own: anything in front of a doctype,
// a comment included, puts the browser into quirks mode. And one that writes
// something other than markup, because a partial included inside {% css %} is
// a stylesheet, where `<!--` and `-->` are legacy tokens the parser only
// tolerates on their own; the marker's text runs into the selector after it
// and the rule is dropped.
//
// The visitor only registers when devMode was on as Twig booted, so the
// detection is exercised directly, and the compile and render through a Twig
// environment of this file's own.
// ---------------------------------------------------------------------------

function markerVisitorProbe(string $method, string $firstText): bool
{
    $visitor = new A11yTemplateNodeVisitor();
    $probe = new ReflectionMethod($visitor, $method);
    $probe->setAccessible(true);

    return $probe->invoke($visitor, new Nodes([new TextNode($firstText, 1)], 1));
}

function opensDocument(string $firstText): bool
{
    return markerVisitorProbe('_opensDocument', $firstText);
}

function writesMarkup(string $firstText): bool
{
    return markerVisitorProbe('_writesMarkup', $firstText);
}

/** Renders templates through a Twig carrying the visitor, as devMode does. */
function markerRender(array $templates, string $name): string
{
    $twig = new Environment(new ArrayLoader($templates));
    $twig->addNodeVisitor(new A11yTemplateNodeVisitor());

    return $twig->render($name);
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

describe('a template that does not write markup', function() {
    it('is recognised by a stylesheet rule', function() {
        expect(writesMarkup('.vpat-report h1 { font-size: 2rem }'))->toBeFalse();
    });

    it('is recognised by a line of JavaScript', function() {
        expect(writesMarkup('const x = 1;'))->toBeFalse();
    });

    it('is recognised by a JSON object', function() {
        expect(writesMarkup('{ "a": 1 }'))->toBeFalse();
    });

    it('is recognised through leading whitespace', function() {
        expect(writesMarkup("\n\n  .vpat-report p { margin: 0 }"))->toBeFalse();
    });
});

describe('a template that does write markup', function() {
    it('is recognised by its first tag', function() {
        expect(writesMarkup('<div class="wrap">'))->toBeTrue();
    });

    it('is recognised through leading whitespace', function() {
        expect(writesMarkup("\n   <div>"))->toBeTrue();
    });

    it('is assumed when the output opens with something other than text', function() {
        // A partial starting with a variable or a tag is the ordinary case,
        // and it is the one worth marking.
        expect(writesMarkup(''))->toBeTrue();
    });

    it('includes a document, which is skipped for its own reason', function() {
        expect(writesMarkup('<!doctype html>'))->toBeTrue()
            ->and(opensDocument('<!doctype html>'))->toBeTrue();
    });
});

describe('rendering an included stylesheet', function() {
    it('writes no marker into it, and leaves its first rule alone', function() {
        $out = markerRender([
            'styles.twig' => ".vpat-report h1 { font-size: 2rem }\n.vpat-report p { margin: 0 }",
            'page.twig' => "<div>{% include 'styles.twig' %}</div>",
        ], 'page.twig');

        expect($out)->not->toContain('accessibility-audit-tpl:styles.twig')
            ->and($out)->toContain('.vpat-report h1 { font-size: 2rem }')
            ->and($out)->toContain('.vpat-report p { margin: 0 }')
            // The page around it is markup, so it still carries its own pair.
            ->and($out)->toContain('<!-- accessibility-audit-tpl:page.twig -->')
            ->and($out)->toContain('<!-- /accessibility-audit-tpl -->');
    });

    it('still marks an included fragment of markup', function() {
        $out = markerRender([
            'card.twig' => '<article>Hello</article>',
            'page.twig' => "<div>{% include 'card.twig' %}</div>",
        ], 'page.twig');

        expect($out)->toContain('<!-- accessibility-audit-tpl:card.twig -->');
    });
});
