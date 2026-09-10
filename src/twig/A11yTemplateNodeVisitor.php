<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\twig;

use Twig\Environment;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\TextNode;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Injects HTML comment markers around every compiled Twig template's output.
 *
 * When devMode is on, each rendered template produces:
 *
 *   <!-- accessibility-audit-tpl:_partials/hero.twig -->
 *   [template output]
 *   <!-- /accessibility-audit-tpl -->
 *
 * Nested includes produce nested comment pairs, allowing the JS scanner
 * in the page-report to walk the DOM and identify which template rendered
 * a specific element (used for the "needs review" contrast panel).
 *
 * Only registered when devMode is true; has no effect in production.
 */
class A11yTemplateNodeVisitor implements NodeVisitorInterface
{
    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function leaveNode(Node $node, Environment $env): Node
    {
        if (!$node instanceof ModuleNode) {
            return $node;
        }

        $name = $node->getTemplateName() ?? '';

        // Skip Twig-internal string templates and embedded inline templates
        if ($name === '' || str_starts_with($name, '__string_template__')) {
            return $node;
        }

        // Two kinds of template are left alone, both for the same reason:
        // the marker would land in front of a doctype and put the browser into
        // quirks mode.
        //
        // A template that opens a document itself, which is any layout.
        //
        // And a template that extends one. Its own body is empty and its output
        // is really the parent's document, so a marker wrapped around it sits
        // outside the whole page rather than around anything in it.
        if ($node->hasNode('parent') || $this->_opensDocument($node->getNode('body'))) {
            return $node;
        }

        $line = $node->getTemplateLine();
        $open = new TextNode('<!-- accessibility-audit-tpl:' . $name . ' -->', $line);
        $close = new TextNode('<!-- /accessibility-audit-tpl -->', $line);
        $body = $node->getNode('body');

        // Twig\Node\Nodes is the concrete node collection; instantiating the base
        // Twig\Node\Node directly is deprecated (3.15) and becomes a fatal in 4.0.
        $node->setNode('body', new Nodes([$open, $body, $close], $line));

        return $node;
    }

    public function getPriority(): int
    {
        return 0;
    }

    /**
     * Whether the first thing a template writes is a doctype.
     *
     * Only leading text is considered. A template whose output begins with a
     * tag, a variable or anything else is a fragment as far as this is
     * concerned, even if a doctype appears further down, because a comment in
     * front of that is harmless.
     *
     * @param Node $body The module's body node.
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.3.0
     */
    private function _opensDocument(Node $body): bool
    {
        foreach ($body as $child) {
            if ($child instanceof TextNode) {
                $text = ltrim((string)$child->getAttribute('data'));

                if ($text === '') {
                    continue;
                }

                return str_starts_with(strtolower($text), '<!doctype');
            }

            if ($child instanceof Node && iterator_count($child) > 0) {
                return $this->_opensDocument($child);
            }

            return false;
        }

        return false;
    }
}
