<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use DOMXPath;

/**
 * Removes markup the browser never renders from a parsed scan DOM.
 *
 * Two elements qualify, for the same reason: PHP's HTML parser does not
 * implement the separation that keeps either one's children out of the
 * document.
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
 * A `<noscript>` element's contents are only parsed as markup when scripting
 * is disabled. With scripting on, the browser holds the whole thing as one raw
 * text node and no elements exist inside it. libxml has no scripting flag and
 * parses those children every time, which puts analytics fallback in front of
 * the rules as live content: Google Tag Manager's body snippet is a hidden,
 * sizeless iframe carrying no title.
 *
 * [[ReadabilityService]] excludes both when it decides which words are on the
 * page, so the rules and the readability score agree.
 *
 * Applies to both scan modes, and must keep doing so. The browser pass renders
 * JavaScript first, so its templates have already been expanded into real DOM
 * by the time anything is serialised; any `<template>` still standing in that
 * output is genuinely unrendered and belongs out of the tree too. Noscript
 * elements survive serialisation as inert text and libxml parses them back
 * into elements when that output is read here, so that path needs the same
 * treatment as the source one.
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
     * Removes every `<template>` and `<noscript>` element, and with them the
     * whole inert subtree underneath, from the document behind the given XPath
     * handle.
     *
     * Removing the element itself rather than only its children is deliberate:
     * neither one renders anything or announces anything, so leaving an empty
     * shell behind would only give the structural rules a node to trip over.
     *
     * Nesting needs no special handling, in either direction. Removing an outer
     * element takes its descendants with it, and the null-safe parent call
     * covers the inner nodes whose parent has already gone.
     *
     * @param DOMXPath $xpath The scan document's XPath handle.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function removeFrom(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//template|//noscript') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
    }
}
