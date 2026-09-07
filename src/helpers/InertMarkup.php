<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use DOMXPath;

/**
 * Removes markup the browser never renders from a parsed scan DOM.
 *
 * A `<template>` element's children are not part of the document. The HTML
 * spec puts them in a separate, inert "template contents" fragment: never
 * rendered, never matched by CSS, carrying no accessibility semantics at all.
 * PHP's DOMDocument does not implement that separation and parses them as
 * ordinary child nodes, so without this they reach the rules as though they
 * were live.
 *
 * That produces false positives on exactly the sites this plugin is pointed
 * at. Every framework doing client-side list or conditional rendering puts
 * real markup inside a template: Alpine's `x-for` and `x-if`, Vue's `v-for`
 * and `v-if`, Angular, and Web Components. Alpine's `x-for` in particular
 * *must* sit on a `<template>` that is a direct child of the list, so
 * `ul > template > li` is not sloppy markup, it is the required structure,
 * and reporting the `<li>` as outside a list is simply wrong.
 *
 * Applies to both scan modes, and must keep doing so. The browser pass renders
 * JavaScript first, so its templates have already been expanded into real DOM
 * by the time anything is serialised; any `<template>` still standing in that
 * output is genuinely unrendered and belongs out of the tree too.
 *
 * Kept apart from [[ExcludedElements]] on purpose. That one drops page
 * furniture somebody configured. This one drops markup the browser itself
 * never shows, which is not a setting and should never become one.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
final class InertMarkup
{
    // Public Methods
    // =========================================================================

    /**
     * Removes every `<template>` element, and with it the whole inert subtree
     * underneath, from the document behind the given XPath handle.
     *
     * Removing the element itself rather than only its children is deliberate:
     * a `<template>` renders nothing and announces nothing, so leaving an empty
     * shell behind would only give the structural rules a node to trip over.
     *
     * Nested templates need no special handling. Removing an outer one takes
     * its descendants with it, and the null-safe parent call covers the inner
     * nodes whose parent has already gone.
     *
     * @param DOMXPath $xpath The scan document's XPath handle.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function removeFrom(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//template') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
    }
}
