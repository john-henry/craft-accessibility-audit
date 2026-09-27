<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;
use johnhenry\accessibilityaudit\helpers\AccessibleName;
use johnhenry\accessibilityaudit\helpers\ExcludedElements;
use johnhenry\accessibilityaudit\helpers\InertMarkup;
use johnhenry\accessibilityaudit\models\IssueModel;
use yii\base\Component;

/**
 * Scans HTML using DOMDocument and returns IssueModel instances.
 * Covers WCAG 2.2 A and AA success criteria checkable server-side.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class ContentScanner extends Component
{
    private const GENERIC_LINK_TEXTS = [
        'click here', 'click', 'here', 'read more', 'more', 'learn more',
        'this', 'link', 'details', 'info', 'information', 'go', 'continue',
        'next', 'previous', 'page', 'article', 'post', 'open', 'view',
    ];

    /**
     * @var string Tags whose opening auto-closes an open paragraph, per the
     *             HTML parsing spec. Alternated into a pattern below.
     */
    private const P_CLOSING_TAGS = 'address|article|aside|blockquote|details|div|dl|fieldset'
        . '|figcaption|figure|footer|form|h1|h2|h3|h4|h5|h6|header|hgroup|hr|main'
        . '|menu|nav|ol|p|pre|section|table|ul';

    /**
     * @var string Characters that carry meaning by their shape rather than by
     *      what they say: ticks, crosses, arrows, dashes standing in for "no
     *      value", bullets and stars. Deliberately a fixed list rather than
     *      "anything that is not a letter or a digit", which would sweep up
     *      currency, maths and punctuation that read perfectly well.
     *      Variation selectors and joiners ride along with the emoji forms.
     */
    private const MEANING_BY_SHAPE = '\x{2713}\x{2714}\x{2611}\x{2705}'
        . '\x{2717}\x{2718}\x{2715}\x{2716}\x{274C}\x{00D7}\x{2612}'
        . '\x{2192}\x{2190}\x{2191}\x{2193}\x{27F6}\x{2794}\x{279C}\x{27A1}'
        . '\x{2014}\x{2013}\x{2012}\x{2015}\x{2212}\x{002D}'
        . '\x{2022}\x{00B7}\x{25CF}\x{25CB}\x{2605}\x{2606}'
        . '\x{2731}\x{2020}\x{2021}\x{FE0F}\x{FE0E}\x{200D}';

    /**
     * @var array<string, array{0: string, 1: string}> Which criterion a
     *      symbol-only element fails, by tag. A link that announces "right
     *      arrow" has a name that does not describe where it goes, which is
     *      2.4.4. A cell holding a lone tick has a picture sitting in the
     *      place of text, which is 1.1.1.
     */
    private const SYMBOL_ONLY_CRITERIA = [
        'a' => ['2.4.4', 'link-purpose-in-context'],
        'button' => ['1.1.1', 'non-text-content'],
        'td' => ['1.1.1', 'non-text-content'],
        'th' => ['1.1.1', 'non-text-content'],
    ];

    /**
     * @var string The attribute naming the component that rendered an element,
     *      written once on the outermost element of every component.
     *
     * A cross-repository contract, not an implementation detail. It is written
     * by johnhenry/craft-a11y-components and read here to tell library markup
     * from markup written by hand. Nothing enforces the pair: rename it on one
     * side and this side reports every finding as authored, with no error to
     * say the classification has stopped working. Change it in both
     * repositories or in neither.
     *
     * One attribute carrying the name, rather than a prefix shared with the
     * component's styling hooks. A prefix cannot say which of the attributes on
     * an element names the component and which mark its parts, and guessing at
     * it misread every component whose own name contains a hyphen.
     */
    public const COMPONENT_ATTR = 'data-a11y-component';

    /**
     * @var string[] Tags that are not void, so a browser hands one everything
     *      that follows as its content until a closing tag that never comes.
     *      Left unescaped in prose, these delete the rest of the page.
     */
    private const SWALLOWING_TAGS = [
        'iframe', 'script', 'style', 'textarea', 'title', 'select', 'noscript',
    ];

    /**
     * @var string[] Tags that belong inside a code sample. Highlighters wrap
     *      tokens in spans, renderers nest pre and code, and a link to an API
     *      page is deliberate. Anything else in there could only have come
     *      from markup that was meant to be shown and was not escaped.
     */
    private const CODE_PRESENTATION_TAGS = [
        'span', 'a', 'br', 'em', 'strong', 'b', 'i', 'code', 'pre',
        'mark', 'small', 'sub', 'sup', 'wbr', 'var', 'samp', 'kbd', 'abbr',
    ];

    private const WCAG_HELP_BASE = 'https://www.w3.org/WAI/WCAG22/Understanding/';

    /**
     * @var array<string, string> Where each context string's element came from,
     * filled in as contexts are built and read back once the checks are done.
     */
    private array $_origins = [];

    /**
     * Runs every content check over a rendered page.
     *
     * The page is parsed once and each check queries that one DOM. Excluded
     * page furniture (consent banners, chat widgets) is taken out before any
     * check runs, mirroring the browser engines' axe exclude context, so no
     * engine reports what another skips.
     *
     * @param string $html The rendered page.
     * @param string[] $ignoreRules Rule ids to leave out of the findings.
     * @return IssueModel[] The findings, worst first.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function scan(string $html, array $ignoreRules = []): array
    {
        $issues = [];
        $this->_forgetOrigins();

        // libxml's error mode is process-wide, not per-document. Leaving it
        // switched on silences parse errors for everything that comes after,
        // which in a queue worker is every later job in that process, not just
        // the rest of this scan. Put back whatever it was.
        $dom = new DOMDocument('1.0', 'utf-8');
        $libxmlErrors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlErrors);

        $xpath = new DOMXPath($dom);

        // Excluded page furniture (consent banners, chat widgets) comes out
        // of the DOM before any check runs, mirroring the browser engines'
        // axe exclude context, so no engine reports what another skips.
        ExcludedElements::removeFrom($xpath);

        // Template contents are not part of the document, and DOMDocument
        // parses them as though they were. The source-level checks already
        // mask them; the DOM checks need the same.
        InertMarkup::removeFrom($xpath);

        $checks = [
            'block-in-paragraph' => fn() => $this->_checkBlockInParagraph($html),
            'unescaped-markup-in-code' => fn() => $this->_checkUnescapedMarkupInCode($xpath, $html),
            'img-alt' => fn() => $this->_checkImgAlt($xpath),
            'img-alt-filename' => fn() => $this->_checkImgAltFilename($xpath),
            'heading-order' => fn() => $this->_checkHeadingOrder($xpath),
            'empty-heading' => fn() => $this->_checkEmptyHeadings($xpath),
            'multiple-h1' => fn() => $this->_checkMultipleH1($xpath),
            'link-name' => fn() => $this->_checkLinkName($xpath),
            'link-generic' => fn() => $this->_checkLinkGeneric($xpath),
            'symbol-only-content' => fn() => $this->_checkSymbolOnlyContent($xpath),
            'link-new-window' => fn() => $this->_checkLinkNewWindow($xpath),
            'button-name' => fn() => $this->_checkButtonName($xpath),
            'form-label' => fn() => $this->_checkFormLabels($xpath),
            'table-header' => fn() => $this->_checkTableHeaders($xpath),
            'html-lang' => fn() => $this->_checkHtmlLang($xpath),
            'page-title' => fn() => $this->_checkPageTitle($xpath),
            'skip-link' => fn() => $this->_checkSkipLink($xpath),
            'landmark-main' => fn() => $this->_checkLandmarkMain($xpath),
            'iframe-title' => fn() => $this->_checkIframeTitle($xpath),
            'video-captions' => fn() => $this->_checkVideoCaptions($xpath),
            'autoplay' => fn() => $this->_checkAutoplay($xpath),
            'duplicate-id' => fn() => $this->_checkDuplicateIds($xpath),
            'meta-description' => fn() => $this->_checkMetaDescription($xpath),
            'aria-hidden-focus' => fn() => $this->_checkAriaHiddenFocus($xpath),
            'landmark-regions' => fn() => $this->_checkLandmarkRegions($xpath),
            'list-structure' => fn() => $this->_checkListStructure($xpath),
            'input-type' => fn() => $this->_checkInputTypes($xpath),
            'select-label' => fn() => $this->_checkSelectLabels($xpath),
        ];

        foreach ($checks as $ruleId => $check) {
            if (in_array($ruleId, $ignoreRules, true)) {
                continue;
            }

            // Appended in place rather than merged: a merge inside the loop
            // rebuilds the whole list on every check, which on a page carrying
            // a lot of findings is the list copied twenty-odd times over.
            $found = $check();

            if ($found) {
                array_push($issues, ...$found);
            }
        }

        // Placed once at the end rather than by each check, so a rule added
        // later gets this without its author having to remember.
        foreach ($issues as $issue) {
            if ($issue->context !== null) {
                $issue->origin = $this->_origins[$issue->context] ?? null;
            }
        }

        return $issues;
    }

    // Markup Shown as Text
    // =========================================================================

    /**
     * Markup rendering inside a `<code>` where it was meant to be read.
     *
     * Documentation writes about HTML, and `<code>` is where it goes. But
     * `<code>` is presentational: it does not escape anything, so
     * `<code><iframe src></code>` puts a real iframe on the page rather than
     * the three words the author typed. The resulting document is perfectly
     * valid, which is why no validator and no other checker says a word.
     *
     * What it costs depends on the tag. A void one such as `<img>` takes the
     * sentence with it. A tag that is not void takes the rest of the page: the
     * parser gives it everything up to a closing tag that never arrives, so
     * paragraphs, tables and whole sections stop existing while the page still
     * returns 200 and looks fine until somebody scrolls.
     *
     * @param DOMXPath $xpath The parsed page.
     * @param string $html The raw source, for measuring what a tag swallowed.
     * @return IssueModel[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _checkUnescapedMarkupInCode(DOMXPath $xpath, string $html): array
    {
        $issues = [];
        $seen = [];

        foreach ($xpath->query('//code | //pre') as $holder) {
            if (!$holder instanceof DOMElement) {
                continue;
            }

            foreach ($xpath->query('.//*', $holder) as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }

                $tag = strtolower($child->nodeName);

                if (in_array($tag, self::CODE_PRESENTATION_TAGS, true)) {
                    continue;
                }

                // One report per sample per tag: the same mistake nested three
                // deep is still one thing to fix.
                $key = spl_object_id($holder) . '|' . $tag;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $swallowed = $this->_swallowedLength($html, $tag);

                $issues[] = IssueModel::make(
                    'unescaped-markup-in-code',
                    $swallowed > 0 ? 'error' : 'warning',
                    $swallowed > 0
                        ? sprintf(
                            'A <%s> is rendering inside a code sample instead of being shown as text, and '
                            . 'because that tag is not self-closing the browser has given it the rest of the '
                            . 'page: roughly %s characters after it never render. Wrapping markup in <code> '
                            . 'does not escape it. Write the angle brackets as &lt; and &gt;.',
                            $tag,
                            number_format($swallowed),
                        )
                        : sprintf(
                            'A <%s> is rendering inside a code sample instead of being shown as text, so the '
                            . 'reader sees the tag act rather than read it. Wrapping markup in <code> does not '
                            . 'escape it. Write the angle brackets as &lt; and &gt;.',
                            $tag,
                        ),
                    // No WCAG criterion: content that never renders is a
                    // content-integrity problem, not a failure of a success
                    // criterion, and claiming one would be wrong.
                    null, null,
                    $this->_outerHtml($holder),
                    null,
                    'php',
                );
            }
        }

        return $issues;
    }

    /**
     * How much of the source a tag absorbs because nothing ever closes it.
     *
     * Counted on the raw string rather than the parsed tree: libxml recovers
     * from an unclosed tag differently from a browser, and it is the browser's
     * reading that decides what a reader is left with.
     *
     * @param string $html The raw page source.
     * @param string $tag The tag found rendering inside a code sample.
     * @return int Characters swallowed, or 0 when the tag is closed or void.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _swallowedLength(string $html, string $tag): int
    {
        if (!in_array($tag, self::SWALLOWING_TAGS, true)) {
            return 0;
        }

        $opens = preg_match_all('/<' . $tag . '[\s>\/]/i', $html);
        $closes = preg_match_all('/<\/' . $tag . '\s*>/i', $html);

        if ($opens === false || $closes === false || $opens <= $closes) {
            return 0;
        }

        $at = strripos($html, '<' . $tag);

        return $at === false ? 0 : strlen($html) - $at;
    }

    // Paragraph Nesting
    // =========================================================================

    /**
     * Block content inside a paragraph, read from the raw HTML.
     *
     * Deliberately not an XPath query, and it must not be turned into one. The
     * browser closes the paragraph the moment it meets block content, and
     * libxml does the same on load, so by the time there is a DOM the nesting
     * is gone: `//p//p` and `//p//div` both return nothing on markup that is
     * plainly wrong. The evidence only exists in the string.
     *
     * What the reader sees is the wrapper's attributes going with it. A Twig
     * template wrapping a rich-text field in a styled paragraph produces
     * `<p class="text-base"><p>…</p></p>`, the outer paragraph is closed and
     * discarded, and the text is left bare to inherit whatever the surrounding
     * prose sets. Nothing is missing and nothing is mislabelled, so no engine
     * working from the DOM reports a thing.
     *
     * @param string $html The raw page source, before parsing.
     * @return IssueModel[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _checkBlockInParagraph(string $html): array
    {
        $issues = [];
        $masked = $this->_maskUnparsedRegions($html);
        $offset = 0;

        // Adjacency only: the next token after the opening tag. Anything looser
        // catches `<p>One<p>Two`, where the first paragraph is closed
        // implicitly. That is legal HTML and loses nothing.
        $pattern = '/<p\b[^>]*>\s*<(' . self::P_CLOSING_TAGS . ')\b[^>]*>/i';

        while (preg_match($pattern, $masked, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $at = (int)$m[0][1];
            $offset = $at + strlen($m[0][0]);

            $issues[] = IssueModel::make(
                'block-in-paragraph', 'warning',
                'A <' . strtolower($m[1][0]) . '> is nested inside a <p>. The browser will close the '
                . 'paragraph early, discarding its attributes and any styling that depended on them. '
                . 'Either unwrap the injected rich text (retconChange the "p" tag to false on the field) '
                . 'or change the wrapper from <p> to <div>.',
                // Not WCAG 4.1.1: that criterion was removed in WCAG 2.2, so
                // reporting against it would be wrong.
                null, null,
                substr($html, $at, 300),
                null,
                'php',
            );
        }

        return $issues;
    }

    /**
     * Blanks out regions the parser never treats as markup, keeping the string
     * the same length so reported offsets still line up with the original.
     *
     * @param string $html The raw page source.
     * @return string The source with comments, templates, scripts and styles
     *                replaced by spaces.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _maskUnparsedRegions(string $html): string
    {
        $patterns = [
            '/<!--.*?-->/s',
            '/<template\b[^>]*>.*?<\/template\s*>/is',
            '/<script\b[^>]*>.*?<\/script\s*>/is',
            '/<style\b[^>]*>.*?<\/style\s*>/is',
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace_callback(
                $pattern,
                static fn(array $m): string => str_repeat(' ', strlen($m[0])),
                $html,
            ) ?? $html;
        }

        return $html;
    }

    // Images
    // =========================================================================

    /**
     * Images carry an alt attribute.
     *
     * Reports `img-alt` against WCAG 1.1.1 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkImgAlt(DOMXPath $xpath): array
    {
        $issues = [];
        /** @var DOMElement $img */
        foreach ($xpath->query('//img') as $img) {
            if (!$img->hasAttribute('alt')) {
                $issues[] = IssueModel::make(
                    'img-alt', 'error',
                    'Image is missing an alt attribute.',
                    '1.1.1', 'A',
                    $this->_outerHtml($img),
                    self::WCAG_HELP_BASE . 'non-text-content'
                );
            }
        }
        return $issues;
    }

    /**
     * Alt text is not just the image's filename.
     *
     * Reports `img-alt-filename` against WCAG 1.1.1 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkImgAltFilename(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//img[@alt]') as $img) {
            /** @var DOMElement $img */
            $alt = trim($img->getAttribute('alt'));
            if ($alt === '') {
                continue;
            }

            $looksLikeFilename = preg_match('/\.(jpe?g|png|gif|webp|svg|bmp|tiff?)$/i', $alt) === 1
                || $this->_altMatchesFilename($alt, $img->getAttribute('src'));

            if ($looksLikeFilename) {
                $issues[] = IssueModel::make(
                    'img-alt-filename', 'warning',
                    'Image alt text appears to be a filename: "' . htmlspecialchars($alt) . '".',
                    '1.1.1', 'A',
                    $this->_outerHtml($img),
                    self::WCAG_HELP_BASE . 'non-text-content'
                );
            }
        }
        return $issues;
    }

    // Headings
    // =========================================================================

    /**
     * Heading levels do not skip a step.
     *
     * Reports `heading-order` against WCAG 1.3.1, 2.4.6.
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkHeadingOrder(DOMXPath $xpath): array
    {
        $issues = [];
        $headings = $xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]');
        $prev = 0;

        foreach ($headings as $heading) {
            /** @var DOMElement $heading */
            $level = (int) substr($heading->nodeName, 1);
            if ($prev > 0 && ($level - $prev) > 1) {
                $issues[] = IssueModel::make(
                    'heading-order', 'warning',
                    "Heading level skipped: h{$prev} followed by h{$level}.",
                    '1.3.1', 'A',
                    $this->_outerHtml($heading),
                    self::WCAG_HELP_BASE . 'info-and-relationships'
                );
            }
            $prev = $level;
        }
        return $issues;
    }

    /**
     * Headings are not empty.
     *
     * Reports `empty-heading` against WCAG 1.3.1.
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkEmptyHeadings(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $h) {
            /** @var DOMElement $h */
            if (trim($h->textContent) === '') {
                $issues[] = IssueModel::make(
                    'empty-heading', 'error',
                    "Empty <{$h->nodeName}> heading found.",
                    '1.3.1', 'A',
                    $this->_outerHtml($h),
                    self::WCAG_HELP_BASE . 'info-and-relationships'
                );
            }
        }
        return $issues;
    }

    /**
     * The page carries one h1, not several.
     *
     * Reports `multiple-h1` against best practice.
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkMultipleH1(DOMXPath $xpath): array
    {
        $h1s = $xpath->query('//h1');
        if ($h1s->length > 1) {
            return [IssueModel::make(
                'multiple-h1', 'notice',
                "Page has {$h1s->length} <h1> elements. Best practice is one per page.",
                '2.4.6', 'AA',
                null,
                self::WCAG_HELP_BASE . 'headings-and-labels'
            )];
        }
        return [];
    }

    // Links
    // =========================================================================

    /**
     * Links have a name a screen reader can announce.
     *
     * Reports `link-name` against WCAG 4.1.2, 2.4.4 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkLinkName(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            /** @var DOMElement $a */
            if (AccessibleName::for($a, $xpath) === '') {
                $issues[] = IssueModel::make(
                    'link-name', 'error',
                    'Link has no discernible name (no text, aria-label, title, or image alt).',
                    '4.1.2', 'A',
                    $this->_outerHtml($a),
                    self::WCAG_HELP_BASE . 'name-role-value'
                );
            }
        }
        return $issues;
    }

    /**
     * Link text describes where the link goes.
     *
     * Reports `link-generic` against WCAG 2.4.4 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkLinkGeneric(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            /** @var DOMElement $a */
            // The announced name, not the visible text, with the new-tab
            // notice stripped so it cannot make a vague label look specific.
            $text = $this->_linkPurposeText(AccessibleName::for($a, $xpath));
            if (in_array($text, self::GENERIC_LINK_TEXTS, true)) {
                $issues[] = IssueModel::make(
                    'link-generic', 'warning',
                    'Link text "' . htmlspecialchars($text) . '" does not describe its destination.',
                    '2.4.4', 'A',
                    $this->_outerHtml($a),
                    self::WCAG_HELP_BASE . 'link-purpose-in-context'
                );
            }
        }
        return $issues;
    }

    /**
     * Cells, links and buttons whose whole announced name is a symbol.
     *
     * A tick in a comparison table is a picture doing the work of a word. The
     * shape says "supported"; the character says nothing of the sort. Screen
     * readers announce it as "check mark", or as nothing at all depending on
     * how the reader has punctuation and symbol verbosity set, and either way
     * the meaning a sighted reader takes from the column is lost. A lone dash
     * standing in for "not applicable" is the same bargain, and an arrow as a
     * link name leaves the destination unsaid.
     *
     * Read off the announced name rather than the visible text, so anything
     * that already fixes it (a label, a title, visually hidden text beside the
     * glyph) takes the element out of scope without needing a second rule.
     *
     * @param DOMXPath $xpath The document to search.
     * @return IssueModel[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _checkSymbolOnlyContent(DOMXPath $xpath): array
    {
        $issues = [];
        $pattern = '/^[' . self::MEANING_BY_SHAPE . '\s\x{00A0}]+$/u';

        foreach ($xpath->query('//td | //th | //button | //a[@href]') as $node) {
            /** @var DOMElement $node */
            // Out of the accessibility tree altogether: what gets announced in
            // its place is another element's business.
            if (strtolower($node->getAttribute('aria-hidden')) === 'true') {
                continue;
            }

            $name = trim(AccessibleName::for($node, $xpath));

            // An empty cell is a different question, and not this one.
            if ($name === '' || preg_match($pattern, $name) !== 1) {
                continue;
            }

            $tag = strtolower($node->nodeName);
            [$criterion, $help] = self::SYMBOL_ONLY_CRITERIA[$tag] ?? self::SYMBOL_ONLY_CRITERIA['td'];

            $issues[] = IssueModel::make(
                'symbol-only-content',
                'warning',
                $tag === 'a'
                    ? 'This link announces only "' . htmlspecialchars($name) . '", which does not say where it '
                        . 'goes. Give it real text, or add visually hidden text inside the link.'
                    : 'This ' . ($tag === 'button' ? 'button' : 'cell') . ' holds only "'
                        . htmlspecialchars($name) . '". The shape carries the meaning, the character does not, '
                        . 'and some screen readers skip it entirely. Add visually hidden text saying what it '
                        . 'means and mark the symbol aria-hidden.',
                $criterion,
                'A',
                $this->_outerHtml($node),
                self::WCAG_HELP_BASE . $help
            );
        }

        return $issues;
    }

    /**
     * A link opening a new tab says so.
     *
     * Reports `link-new-window` against best practice.
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkLinkNewWindow(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//a[@target="_blank"]') as $a) {
            /** @var DOMElement $a */
            // The announced name, so a warning carried in visually hidden text
            // inside the link counts. An aria-label replaces that text, so when
            // one is set it is the label that has to carry the warning.
            $label = strtolower(AccessibleName::for($a, $xpath) . ' ' . $a->getAttribute('title'));
            $hasWarning = str_contains($label, 'new') || str_contains($label, 'opens') || str_contains($label, 'window') || str_contains($label, 'tab');
            if (!$hasWarning) {
                $issues[] = IssueModel::make(
                    'link-new-window', 'warning',
                    'Link opens in a new tab but does not warn users via aria-label or title.',
                    '3.2.2', 'A',
                    $this->_outerHtml($a),
                    self::WCAG_HELP_BASE . 'on-input'
                );
            }
        }
        return $issues;
    }

    // Buttons
    // =========================================================================

    /**
     * Buttons have a name.
     *
     * Reports `button-name` against WCAG 4.1.2 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkButtonName(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//button') as $btn) {
            /** @var DOMElement $btn */
            $text = trim($btn->textContent);
            $ariaLabel = trim($btn->getAttribute('aria-label'));
            $ariaLabelledBy = $btn->getAttribute('aria-labelledby');
            $title = trim($btn->getAttribute('title'));

            if ($text === '' && $ariaLabel === '' && $title === '' && $ariaLabelledBy === '') {
                $imgAlt = '';
                foreach ($xpath->query('.//img[@alt]', $btn) as $img) {
                    /** @var DOMElement $img */
                    $imgAlt = trim($img->getAttribute('alt'));
                }
                if ($imgAlt === '') {
                    $issues[] = IssueModel::make(
                        'button-name', 'error',
                        'Button has no discernible name.',
                        '4.1.2', 'A',
                        $this->_outerHtml($btn),
                        self::WCAG_HELP_BASE . 'name-role-value'
                    );
                }
            }
        }
        return $issues;
    }

    // Forms
    // =========================================================================

    /**
     * Form inputs have a label, and a placeholder is not counted as one.
     *
     * Reports `form-label` against WCAG 1.3.1, 3.3.2 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkFormLabels(DOMXPath $xpath): array
    {
        $issues = [];
        $skipTypes = ['hidden', 'submit', 'button', 'reset', 'image'];

        foreach ($xpath->query('//input[not(@type) or (@type!="hidden" and @type!="submit" and @type!="button" and @type!="reset" and @type!="image")]') as $input) {
            /** @var DOMElement $input */
            $type = strtolower($input->getAttribute('type') ?: 'text');
            if (in_array($type, $skipTypes, true)) {
                continue;
            }

            $id = $input->getAttribute('id');
            $ariaLabel = trim($input->getAttribute('aria-label'));
            $ariaLabelledBy = $input->getAttribute('aria-labelledby');
            $title = trim($input->getAttribute('title'));
            $placeholder = trim($input->getAttribute('placeholder'));

            $hasLabel = $ariaLabel !== '' || $ariaLabelledBy !== '' || $title !== '';

            if (!$hasLabel && $id !== '') {
                $labels = $xpath->query('//label[@for="' . addslashes($id) . '"]');
                if ($labels->length > 0) {
                    $hasLabel = true;
                }
            }

            if (!$hasLabel && $xpath->query('ancestor::label', $input)->length > 0) {
                $hasLabel = true;
            }

            if (!$hasLabel) {
                $msg = $placeholder !== ''
                    ? 'Form input uses placeholder as label, placeholder is not a substitute for a <label>.'
                    : 'Form input is missing an associated <label>.';
                $issues[] = IssueModel::make(
                    'form-label', $placeholder !== '' ? 'warning' : 'error',
                    $msg,
                    '1.3.1', 'A',
                    $this->_outerHtml($input),
                    self::WCAG_HELP_BASE . 'info-and-relationships'
                );
            }
        }
        return $issues;
    }

    /**
     * Select elements have a label.
     *
     * Reports `select-label` against WCAG 1.3.1.
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkSelectLabels(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//select') as $select) {
            /** @var DOMElement $select */
            $id = $select->getAttribute('id');
            $ariaLabel = trim($select->getAttribute('aria-label'));
            $ariaLabelledBy = $select->getAttribute('aria-labelledby');

            $hasLabel = $ariaLabel !== '' || $ariaLabelledBy !== '';

            if (!$hasLabel && $id !== '') {
                if ($xpath->query('//label[@for="' . addslashes($id) . '"]')->length > 0) {
                    $hasLabel = true;
                }
            }

            if (!$hasLabel) {
                $issues[] = IssueModel::make(
                    'select-label', 'error',
                    'Select element is missing an associated label.',
                    '1.3.1', 'A',
                    $this->_outerHtml($select),
                    self::WCAG_HELP_BASE . 'info-and-relationships'
                );
            }
        }
        return $issues;
    }

    // Tables
    // =========================================================================

    /**
     * Data tables have header cells.
     *
     * Reports `table-header` against WCAG 1.3.1 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkTableHeaders(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//table') as $table) {
            /** @var DOMElement $table */
            // Skip layout tables (role="presentation" or role="none")
            $role = strtolower($table->getAttribute('role'));
            if ($role === 'presentation' || $role === 'none') {
                continue;
            }
            $ths = $xpath->query('.//th', $table);
            if ($ths->length === 0) {
                $issues[] = IssueModel::make(
                    'table-header', 'error',
                    'Table has no header cells (<th>). Use <th> for header cells and add scope attribute.',
                    '1.3.1', 'A',
                    '<table>…</table>',
                    self::WCAG_HELP_BASE . 'info-and-relationships'
                );
            }
        }
        return $issues;
    }

    // Document Structure
    // =========================================================================

    /**
     * The html element declares a language.
     *
     * Reports `html-lang` against WCAG 3.1.1 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkHtmlLang(DOMXPath $xpath): array
    {
        $html = $xpath->query('//html')->item(0);
        if ($html instanceof DOMElement) {
            $lang = trim($html->getAttribute('lang'));
            if ($lang === '') {
                return [IssueModel::make(
                    'html-lang', 'error',
                    'The <html> element is missing a lang attribute.',
                    '3.1.1', 'A',
                    '<html>',
                    self::WCAG_HELP_BASE . 'language-of-page'
                )];
            }
        }
        return [];
    }

    /**
     * The page has a title.
     *
     * Reports `page-title` against WCAG 2.4.2 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkPageTitle(DOMXPath $xpath): array
    {
        $titles = $xpath->query('//title');
        if ($titles->length === 0 || trim($titles->item(0)->textContent) === '') {
            return [IssueModel::make(
                'page-title', 'error',
                'Page is missing a <title> element.',
                '2.4.2', 'A',
                null,
                self::WCAG_HELP_BASE . 'page-titled'
            )];
        }
        return [];
    }

    /**
     * The page offers a skip-navigation link.
     *
     * Reports `skip-link` against WCAG 2.4.1 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkSkipLink(DOMXPath $xpath): array
    {
        // Look for a skip link in the first 3 links on the page
        $links = $xpath->query('//a[@href]');
        $count = min($links->length, 5);
        for ($i = 0; $i < $count; $i++) {
            /** @var DOMElement $a */
            $a = $links->item($i);
            $href = $a->getAttribute('href');
            if (str_starts_with($href, '#')) {
                return [];
            }
        }
        // Also check for aria-label="skip" type links
        if ($xpath->query('//a[contains(translate(@href," ",""), "#main") or contains(translate(@aria-label,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), "skip")]')->length > 0) {
            return [];
        }
        return [IssueModel::make(
            'skip-link', 'warning',
            'No skip navigation link found. Add a "Skip to main content" link as the first focusable element.',
            '2.4.1', 'A',
            null,
            self::WCAG_HELP_BASE . 'bypass-blocks'
        )];
    }

    /**
     * The page has a main landmark.
     *
     * Reports `landmark-main` against best practice.
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkLandmarkMain(DOMXPath $xpath): array
    {
        $main = $xpath->query('//main | //*[@role="main"]');
        if ($main->length === 0) {
            return [IssueModel::make(
                'landmark-main', 'warning',
                'Page has no <main> landmark element. Add <main> to wrap primary content.',
                '1.3.6', 'AAA',
                null,
                self::WCAG_HELP_BASE . 'identify-purpose'
            )];
        }
        return [];
    }

    /**
     * The page uses landmark regions.
     *
     * Reports `landmark-regions` against WCAG 1.3.1.
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkLandmarkRegions(DOMXPath $xpath): array
    {
        $landmarks = $xpath->query(
            '//header | //nav | //main | //footer | //aside | //section[@aria-label or @aria-labelledby] ' .
            '| //*[@role="banner"] | //*[@role="navigation"] | //*[@role="main"] | //*[@role="contentinfo"]'
        );
        if ($landmarks->length === 0) {
            return [IssueModel::make(
                'landmark-regions', 'notice',
                'Page does not use any ARIA landmark regions (header, nav, main, footer).',
                '1.3.1', 'A',
                null,
                self::WCAG_HELP_BASE . 'info-and-relationships'
            )];
        }
        return [];
    }

    // Iframes & Media
    // =========================================================================

    /**
     * Iframes have a title.
     *
     * Reports `iframe-title` against WCAG 4.1.2 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkIframeTitle(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//iframe') as $iframe) {
            /** @var DOMElement $iframe */
            $title = trim($iframe->getAttribute('title'));
            if ($title === '') {
                $issues[] = IssueModel::make(
                    'iframe-title', 'error',
                    'iframe is missing a title attribute.',
                    '4.1.2', 'A',
                    $this->_outerHtml($iframe),
                    self::WCAG_HELP_BASE . 'name-role-value'
                );
            }
        }
        return $issues;
    }

    /**
     * Video carries captions.
     *
     * Reports `video-captions` against WCAG 1.2.2 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkVideoCaptions(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//video') as $video) {
            /** @var DOMElement $video */
            $tracks = $xpath->query('.//track[@kind="captions" or @kind="subtitles"]', $video);
            if ($tracks->length === 0) {
                $issues[] = IssueModel::make(
                    'video-captions', 'error',
                    'Video element has no captions track (<track kind="captions">).',
                    '1.2.2', 'A',
                    '<video>…</video>',
                    self::WCAG_HELP_BASE . 'captions-prerecorded'
                );
            }
        }
        return $issues;
    }

    /**
     * Auto-playing media can be stopped.
     *
     * Reports `autoplay` against WCAG 1.4.2 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkAutoplay(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//video[@autoplay] | //audio[@autoplay]') as $el) {
            /** @var DOMElement $el */
            $muted = $el->hasAttribute('muted');
            if (!$muted) {
                $issues[] = IssueModel::make(
                    'autoplay', 'error',
                    ucfirst($el->nodeName) . ' has autoplay without muted attribute, users cannot control audio.',
                    '1.4.2', 'A',
                    '<' . $el->nodeName . '>',
                    self::WCAG_HELP_BASE . 'audio-control'
                );
            }
        }
        return $issues;
    }

    // ARIA & IDs
    // =========================================================================

    /**
     * No id is used twice on the page.
     *
     * Reports `duplicate-id` against WCAG 4.1.1 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkDuplicateIds(DOMXPath $xpath): array
    {
        $ids = [];
        $duplicates = [];
        foreach ($xpath->query('//*[@id]') as $el) {
            /** @var DOMElement $el */
            $id = $el->getAttribute('id');
            // An empty id="" can't be referenced by anything (labels,
            // aria-labelledby, fragment links all need a value), so two of
            // them collide with nothing and are no duplicate. Sloppy markup,
            // but not an accessibility failure.
            if (trim($id) === '') {
                continue;
            }
            if (isset($ids[$id])) {
                $duplicates[$id] = true;
            } else {
                $ids[$id] = true;
            }
        }

        $issues = [];
        foreach (array_keys($duplicates) as $id) {
            $issues[] = IssueModel::make(
                'duplicate-id', 'error',
                "Duplicate id=\"{$id}\" found. IDs must be unique on the page.",
                '4.1.1', 'A',
                "id=\"{$id}\"",
                self::WCAG_HELP_BASE . 'parsing'
            );
        }
        return $issues;
    }

    /**
     * Nothing focusable sits inside aria-hidden.
     *
     * Reports `aria-hidden-focus` against WCAG 4.1.2 (A).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkAriaHiddenFocus(DOMXPath $xpath): array
    {
        $issues = [];
        $focusable = 'a[@href] | button | input | select | textarea | *[@tabindex]';
        foreach ($xpath->query('//*[@aria-hidden="true"]') as $hidden) {
            /** @var DOMElement $hidden */
            $focusableChildren = $xpath->query('.//' . $focusable, $hidden);
            if ($focusableChildren->length > 0) {
                $issues[] = IssueModel::make(
                    'aria-hidden-focus', 'error',
                    'Focusable element found inside aria-hidden="true". Hidden content must not receive focus.',
                    '4.1.2', 'A',
                    $this->_outerHtml($hidden),
                    self::WCAG_HELP_BASE . 'name-role-value'
                );
            }
        }
        return $issues;
    }

    // Meta
    // =========================================================================

    /**
     * The page has a meta description.
     *
     * Reports `meta-description` against best practice.
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkMetaDescription(DOMXPath $xpath): array
    {
        $meta = $xpath->query('//meta[@name="description"]');
        $metaNode = $meta->length > 0 ? $meta->item(0) : null;
        if (!$metaNode instanceof DOMElement || trim($metaNode->getAttribute('content')) === '') {
            return [IssueModel::make(
                'meta-description', 'notice',
                'Page is missing a meta description.',
                null, null,
                null,
                'https://developers.google.com/search/docs/appearance/snippet'
            )];
        }
        return [];
    }

    // Lists
    // =========================================================================

    /**
     * List items sit inside a list.
     *
     * Reports `list-structure` against WCAG 1.3.1.
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkListStructure(DOMXPath $xpath): array
    {
        $issues = [];
        // li that is not inside ul, ol, or menu
        foreach ($xpath->query('//li[not(parent::ul) and not(parent::ol) and not(parent::menu)]') as $li) {
            $issues[] = IssueModel::make(
                'list-structure', 'error',
                '<li> element is not inside a <ul> or <ol>.',
                '1.3.1', 'A',
                $li instanceof DOMElement ? $this->_outerHtml($li) : null,
                self::WCAG_HELP_BASE . 'info-and-relationships'
            );
        }
        return $issues;
    }

    // Input Types
    // =========================================================================

    /**
     * Inputs name the purpose they collect.
     *
     * Reports `input-type` against WCAG 1.3.5 (AA).
     *
     * @param DOMXPath $xpath A query handle on the parsed page.
     * @return IssueModel[] The findings, empty where the page passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkInputTypes(DOMXPath $xpath): array
    {
        $issues = [];
        $purposeMap = [
            'name' => ['name', 'full-name', 'fullname'],
            'email' => ['email', 'e-mail'],
            'tel' => ['phone', 'telephone', 'tel', 'mobile'],
        ];

        foreach ($xpath->query('//input[@type="text" or not(@type)]') as $input) {
            /** @var DOMElement $input */
            $autocomplete = strtolower(trim($input->getAttribute('autocomplete')));
            $name = strtolower(trim($input->getAttribute('name')));
            $id = strtolower(trim($input->getAttribute('id')));

            if ($autocomplete !== '' && $autocomplete !== 'off') {
                continue;
            }

            // Check if this looks like a personal data field but has no autocomplete
            foreach ($purposeMap as $type => $hints) {
                foreach ($hints as $hint) {
                    if (str_contains($name, $hint) || str_contains($id, $hint)) {
                        $issues[] = IssueModel::make(
                            'input-type', 'warning',
                            "Input field \"{$input->getAttribute('name')}\" looks like a {$type} field but has no autocomplete attribute.",
                            '1.3.5', 'AA',
                            $this->_outerHtml($input),
                            self::WCAG_HELP_BASE . 'identify-input-purpose'
                        );
                        break 2;
                    }
                }
            }
        }
        return $issues;
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether the alt text is the image's own filename in disguise.
     *
     * Craft derives an asset title from its filename, so a template reaching
     * for the title instead of the alt field ships "Asset7623" for
     * asset7623.jpg. The asset itself can hold perfectly good alt text while
     * the page shows none of it, which asset-level checks cannot see.
     *
     * Only flagged when the alt also reads as machine-derived: a run of digits
     * or a single unspaced token. A descriptive alt that happens to match a
     * well-named file is left alone.
     *
     * @param string $alt The alt text as rendered.
     * @param string $src The image's src attribute.
     * @return bool True when the alt is the filename by another name.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _altMatchesFilename(string $alt, string $src): bool
    {
        $src = trim($src);
        if ($src === '' || str_starts_with($src, 'data:')) {
            return false;
        }

        $basename = pathinfo(parse_url($src, PHP_URL_PATH) ?: $src, PATHINFO_FILENAME);
        if ($basename === '') {
            return false;
        }

        $normalise = static function(string $value): string {
            $value = mb_strtolower(rawurldecode($value));
            $value = preg_replace('/[\-_.]+/u', ' ', $value) ?? $value;

            return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        };

        if ($normalise($alt) !== $normalise($basename)) {
            return false;
        }

        return preg_match('/\d{2,}/', $alt) === 1 || !preg_match('/\s/u', trim($alt));
    }

    /**
     * An accessible name with the new-tab notice taken off, leaving only what
     * the name says about where the link goes. The notice describes what the
     * link does to the browser, not where it goes.
     *
     * @param string $name The accessible name.
     * @return string The name reduced to its purpose, lowercased.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _linkPurposeText(string $name): string
    {
        $text = mb_strtolower(trim($name));

        // Parenthesised or bracketed: "(opens in a new tab)", "[external]".
        $text = preg_replace(
            '/[(\[{][^)\]}]*\b(?:opens?|new\s+(?:tab|window)|external)\b[^)\]}]*[)\]}]/u',
            ' ',
            $text,
        ) ?? $text;

        // Trailing clause: "read more, opens in a new window".
        $text = preg_replace(
            '/[,;:\-]?\s*\b(?:opens?|will\s+open)\b[^,;.]*?\b(?:tab|window)\b/u',
            ' ',
            $text,
        ) ?? $text;

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text, " \t\n\r\0\x0B.,;:!?-");
    }

    /**
     * Clears what the previous scan learned about where its markup came from.
     *
     * A method rather than an assignment in `scan()` so that static analysis
     * does not narrow the property to an empty array for the rest of the call.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    private function _forgetOrigins(): void
    {
        $this->_origins = [];
    }

    /**
     * Whether an element sits inside a component from an accessible component
     * library, walking up until it finds one or runs out of ancestors.
     *
     * The marker looked for is [[COMPONENT_ATTR]], which
     * johnhenry/craft-a11y-components writes on the outermost element of every
     * component it renders, carrying the component's name as its value. Nothing
     * here depends on that plugin being installed: a page with no such markup
     * simply has no library issues.
     *
     * The nearest one wins, so a component nested inside another is named as
     * itself rather than as its container.
     *
     * @param DOMElement $el The element to place.
     * @return string|null The component's name, or null when it is not in one.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    private function _componentOrigin(DOMElement $el): ?string
    {
        for ($node = $el; $node instanceof DOMElement; $node = $node->parentNode) {
            if (!$node->hasAttribute(self::COMPONENT_ATTR)) {
                continue;
            }

            $name = trim($node->getAttribute(self::COMPONENT_ATTR));

            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * An element's markup, as the context string a finding is keyed to.
     *
     * @param DOMElement $el The element a finding is about.
     * @return string The element's outer HTML.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _outerHtml(DOMElement $el): string
    {
        // Recorded against the context string rather than passed along, so that
        // the thirty-odd checks that build issues do not each need changing.
        // Two elements with byte-identical markup and preview text share an
        // origin, which is what you would want anyway.
        $this->_origins[$this->_outerHtmlString($el)] = $this->_componentOrigin($el) ?? 'authored';

        return $this->_outerHtmlString($el);
    }

    /**
     * The context string for an element.
     *
     * @param DOMElement $el The element.
     * @return string The context.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    private function _outerHtmlString(DOMElement $el): string
    {
        $tag = '<' . $el->nodeName;
        for ($i = 0; $i < $el->attributes->length; $i++) {
            $attr = $el->attributes->item($i);
            if (!$attr instanceof DOMAttr) {
                continue;
            }
            $tag .= ' ' . $attr->name . '="' . htmlspecialchars($attr->value) . '"';
        }
        $tag .= '>';

        // A short text preview rides along on every context: it names the
        // element for a human (dozens of orphaned `<li>`s once rendered as
        // identical bare tags) and gives the preview's context matcher a
        // text signal. The matcher treats a trailing ellipsis as a prefix.
        $text = trim((string)preg_replace('/\s+/u', ' ', $el->textContent));
        if ($text !== '') {
            if (mb_strlen($text) > 60) {
                $text = mb_substr($text, 0, 60) . '…';
            }
            $tag .= htmlspecialchars($text) . '</' . $el->nodeName . '>';
        }

        return mb_substr($tag, 0, 200);
    }
}
