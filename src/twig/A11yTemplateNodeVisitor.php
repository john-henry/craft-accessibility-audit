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

        $body = $node->getNode('body');

        // Three kinds of template are left alone.
        //
        // One that does not write markup at all. A partial included inside a
        // {% css %} or {% js %} block is a stylesheet or a script, and an HTML
        // comment in a stylesheet is not merely untidy: `<!--` and `-->` are
        // legacy tokens a CSS parser only tolerates on their own, so the
        // marker's text runs into the selector after it and the whole rule is
        // dropped. Nothing reads the markers there either, since the page
        // report walks elements.
        //
        // One that opens a document itself, which is any layout: anything in
        // front of a doctype, a comment included, puts the browser into quirks
        // mode.
        //
        // And one that extends a layout. Its own body is empty and its output
        // is really the parent's document, so a marker wrapped around it sits
        // outside the whole page rather than around anything in it.
        if ($node->hasNode('parent')
            || !$this->_writesMarkup($body)
            || $this->_opensDocument($body)
        ) {
            return $node;
        }

        $line = $node->getTemplateLine();
        $open = new TextNode('<!-- accessibility-audit-tpl:' . $name . ' -->', $line);
        $close = new TextNode('<!-- /accessibility-audit-tpl -->', $line);

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
        $text = $this->_leadingText($body);

        return $text !== null && str_starts_with(strtolower($text), '<!doctype');
    }

    /**
     * Whether a template's output is markup rather than CSS, JavaScript or
     * some other text.
     *
     * Decided on the first thing written, since that is all a compile-time
     * pass can know. A template opening with anything but `<` is taken as not
     * markup. One whose first output is a variable or a tag rather than
     * literal text is taken as markup: that is the ordinary partial, and it is
     * the case worth marking.
     *
     * @param Node $body The module's body node.
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.3.0
     */
    private function _writesMarkup(Node $body): bool
    {
        $text = $this->_leadingText($body);

        return $text === null || str_starts_with($text, '<');
    }

    /**
     * The first literal text a template writes, with leading whitespace
     * dropped.
     *
     * Null when the first thing written is not literal text: a variable, a tag
     * or a block. Whitespace-only text is passed over rather than counted as
     * the start of the output.
     *
     * @param Node $body The module's body node.
     * @return string|null
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.3.0
     */
    private function _leadingText(Node $body): ?string
    {
        foreach ($body as $child) {
            if ($child instanceof TextNode) {
                $text = ltrim((string)$child->getAttribute('data'));

                if ($text === '') {
                    continue;
                }

                return $text;
            }

            if ($child instanceof Node && iterator_count($child) > 0) {
                return $this->_leadingText($child);
            }

            return null;
        }

        return null;
    }
}
