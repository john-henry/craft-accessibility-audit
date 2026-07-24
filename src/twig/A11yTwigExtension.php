<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\twig;

use Twig\Extension\AbstractExtension;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Registers the A11yTemplateNodeVisitor so every Twig template's compiled
 * output is wrapped with <!-- accessibility-audit-tpl:filename.twig --> comment markers.
 *
 * Only added to the Twig environment when devMode is true.
 */
class A11yTwigExtension extends AbstractExtension
{
    /**
     * @return NodeVisitorInterface[]
     */
    public function getNodeVisitors(): array
    {
        return [new A11yTemplateNodeVisitor()];
    }
}
