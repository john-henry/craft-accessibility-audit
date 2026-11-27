<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;

/**
 * Static registry mapping accessibility rule IDs to metadata used by the CP
 * reports: difficulty, responsibility, affected element type, the user
 * abilities each rule impacts, and the title and fix each rule is shown with.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class RuleRegistry
{
    // Const Properties
    // =========================================================================

    /**
     * @var array<string, string[]> Every rule ID the plugin's own checks can
     * store, grouped as the Ignored Rule IDs setting lists them: the page
     * checks, the browser pass's contrast rules, and the review questions.
     * axe-core rules are left out because they are named by axe, as `axe:`
     * followed by axe's rule ID. PDF checks are left out because the ignore
     * list does not apply to them.
     */
    public const IGNORABLE_RULE_IDS = [
        'page' => [
            'img-alt',
            'img-alt-filename',
            'heading-order',
            'empty-heading',
            'multiple-h1',
            'link-name',
            'link-generic',
            'link-new-window',
            'symbol-only-content',
            'button-name',
            'form-label',
            'select-label',
            'input-type',
            'table-header',
            'list-structure',
            'html-lang',
            'page-title',
            'meta-description',
            'skip-link',
            'landmark-main',
            'landmark-regions',
            'iframe-title',
            'video-captions',
            'autoplay',
            'duplicate-id',
            'aria-hidden-focus',
            'block-in-paragraph',
            'unescaped-markup-in-code',
        ],
        'browser' => [
            'color-contrast',
            'color-contrast-enhanced',
            'contrast-hover',
            'contrast-focus',
            'contrast-selection',
        ],
        'review' => [
            'potential:short-alt',
            'potential:long-alt',
            'potential:identical-links',
            'potential:url-as-link-text',
            'potential:decorative-image',
            'potential:possible-heading',
            'potential:table-layout',
            'potential:video-audio-desc',
            'potential:contrast-unmeasurable',
            'potential:focus-outline-removed',
            'potential:focus-not-visible',
            'potential:focus-obscured',
        ],
    ];

    // Private Properties
    // =========================================================================

    // difficulty:     beginner | intermediate | advanced
    // responsibility: content | design | development | technical
    // elementType:    human-readable category
    // abilities:      array of vision | cognition | motor | hearing

    /**
     * @var array<string, array{difficulty: string, responsibility: string, elementType: string, abilities: string[], description?: string}> Rule metadata, keyed by rule ID.
     */
    private static array $_rules = [
        'img-alt' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Images',         'abilities' => ['vision']],
        'img-alt-filename' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Images',         'abilities' => ['vision']],
        'heading-order' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Headings',       'abilities' => ['cognition']],
        'empty-heading' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Headings',       'abilities' => ['cognition']],
        'multiple-h1' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Headings',       'abilities' => ['cognition']],
        'link-name' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Links',          'abilities' => ['vision', 'cognition']],
        'link-generic' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Links',          'abilities' => ['cognition']],
        'link-new-window' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Links',          'abilities' => ['cognition']],
        'button-name' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Buttons',        'abilities' => ['vision', 'cognition']],
        'form-label' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['vision', 'cognition']],
        'select-label' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['vision', 'cognition']],
        'table-header' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Tables',         'abilities' => ['cognition']],
        'html-lang' => ['difficulty' => 'beginner',     'responsibility' => 'technical',   'elementType' => 'Page',           'abilities' => ['vision', 'cognition']],
        'page-title' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Page',           'abilities' => ['cognition']],
        'skip-link' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Navigation',     'abilities' => ['motor']],
        'landmark-main' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Page structure', 'abilities' => ['cognition']],
        'landmark-regions' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Page structure', 'abilities' => ['cognition']],
        'iframe-title' => ['difficulty' => 'beginner',     'responsibility' => 'development', 'elementType' => 'Iframes',        'abilities' => ['vision', 'cognition']],
        'video-captions' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Media',          'abilities' => ['hearing']],
        'autoplay' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Media',          'abilities' => ['cognition', 'motor']],
        'duplicate-id' => ['difficulty' => 'advanced',     'responsibility' => 'technical',   'elementType' => 'Page',           'abilities' => ['cognition']],
        'meta-description' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Page',           'abilities' => ['cognition']],
        'aria-tab-name' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Interactive',    'abilities' => ['vision', 'cognition']],
        'summary-name' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Interactive',    'abilities' => ['vision', 'cognition']],
        'aria-hidden-focus' => ['difficulty' => 'advanced',     'responsibility' => 'development', 'elementType' => 'Interactive',    'abilities' => ['vision']],
        'list-structure' => ['difficulty' => 'beginner',     'responsibility' => 'development', 'elementType' => 'Lists',          'abilities' => ['cognition']],
        'contrast-hover' => ['difficulty' => 'beginner',     'responsibility' => 'design',      'elementType' => 'Text',           'abilities' => ['vision']],
        'contrast-focus' => ['difficulty' => 'beginner',     'responsibility' => 'design',      'elementType' => 'Text',           'abilities' => ['vision']],
        'contrast-selection' => ['difficulty' => 'beginner',     'responsibility' => 'design',      'elementType' => 'Text',           'abilities' => ['vision']],
        'unescaped-markup-in-code' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Page structure', 'abilities' => ['vision', 'cognition']],
        'block-in-paragraph' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Page structure', 'abilities' => ['cognition']],
        'input-type' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['motor']],

        // Potential issue rules
        'potential:short-alt' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Images',   'abilities' => ['vision']],
        'potential:long-alt' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Images',   'abilities' => ['vision', 'cognition']],
        'potential:identical-links' => [
            'difficulty' => 'beginner',
            'responsibility' => 'content',
            'elementType' => 'Links',
            'abilities' => ['cognition'],
            'description' => 'Two or more links on a page read the same but go to different places. Someone '
                . 'moving through a list of links in a screen reader sees the same words twice with nothing '
                . 'to choose between them. Whether it fails WCAG 2.4.4 at level A or is a 2.4.9 improvement '
                . 'at AAA depends on what surrounds each link, so every occurrence is judged on its own and '
                . 'says which it is.',
        ],
        'symbol-only-content' => [
            'difficulty' => 'beginner',
            'responsibility' => 'development',
            'elementType' => 'Text',
            'abilities' => ['vision', 'cognition'],
            'description' => 'A cell, link or button whose whole announced name is a symbol: a tick, a '
                . 'cross, an arrow, or a dash standing in for "not applicable". The shape carries the '
                . 'meaning and the character does not, so a screen reader announces "check mark" or, '
                . 'depending on how the reader has symbol verbosity set, nothing at all. The fix is '
                . 'visually hidden text saying what the symbol means, with the symbol itself marked '
                . 'aria-hidden.',
        ],
        'potential:url-as-link-text' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Links',    'abilities' => ['cognition']],
        'potential:decorative-image' => ['difficulty' => 'beginner',     'responsibility' => 'development', 'elementType' => 'Images',   'abilities' => ['vision']],
        'potential:possible-heading' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Headings', 'abilities' => ['cognition']],
        'potential:table-layout' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Tables',   'abilities' => ['cognition']],
        'potential:video-audio-desc' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Media',    'abilities' => ['vision']],
        'potential:contrast-unmeasurable' => ['difficulty' => 'intermediate', 'responsibility' => 'design',      'elementType' => 'Text',     'abilities' => ['vision']],
        'potential:focus-outline-removed' => [
            'difficulty' => 'beginner',
            'responsibility' => 'design',
            'elementType' => 'Interactive',
            'abilities' => ['vision', 'motor'],
            'description' => 'A rule in the stylesheet turns off the outline browsers draw around whatever has '
                . 'keyboard focus, and nothing else in the stylesheet draws an indicator in its place. Someone '
                . 'using the keyboard moves through the page without being able to see where they are. Read '
                . 'from the stylesheet, so a script that adds its own focus styling is not seen: tab through '
                . 'the page to check before answering.',
        ],
        'potential:focus-not-visible' => [
            'difficulty' => 'intermediate',
            'responsibility' => 'design',
            'elementType' => 'Interactive',
            'abilities' => ['vision', 'motor'],
            'description' => 'The browser pass moved keyboard focus onto this control and nothing changed: '
                . 'no outline, shadow, border, background, colour or underline, on the control or on the '
                . 'elements around it. A change too faint to see still passes this check, so it asks rather '
                . 'than fails. Tab to the control in a browser and look.',
        ],
        'potential:focus-obscured' => [
            'difficulty' => 'intermediate',
            'responsibility' => 'development',
            'elementType' => 'Page structure',
            'abilities' => ['vision', 'motor'],
            'description' => 'A fixed or sticky element, usually a header, a footer bar or a chat button, '
                . 'completely covered controls as keyboard focus reached them, so someone using the keyboard '
                . 'cannot see where they are. Reported once per covering element, since that is where the fix '
                . 'goes: scroll-padding on the page, or a smaller element. Consent banners on the excluded '
                . 'list are hidden during the check.',
        ],

        // PDF document rules, stored against the asset rather than a page.
        'pdf-field-no-tooltip' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision', 'cognition']],
        'pdf-untagged' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision', 'cognition']],
        'pdf-no-text' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision']],
        'pdf-at-blocked' => ['difficulty' => 'beginner',     'responsibility' => 'technical',   'elementType' => 'Documents', 'abilities' => ['vision']],
        'pdf-no-title' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision', 'cognition']],
        'pdf-weak-title' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision', 'cognition']],
        'pdf-title-not-shown' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision', 'cognition']],
        'pdf-no-lang' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision', 'cognition']],
        'pdf-figure-no-alt' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision']],
        'pdf-figure-junk-alt' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision']],
        'pdf-suspects' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision', 'cognition']],
        'pdf-heading-order' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['cognition']],
        'pdf-no-headings' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['cognition']],
        'pdf-table-no-th' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision', 'cognition']],
        'pdf-th-no-scope' => ['difficulty' => 'advanced',     'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision', 'cognition']],
        'pdf-list-structure' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['cognition']],
        'pdf-link-untagged' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['vision', 'motor']],
        'pdf-tab-order' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['motor']],
        'pdf-no-bookmarks' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Documents', 'abilities' => ['cognition', 'motor']],

        // axe-core rules (common ones)
        'color-contrast' => ['difficulty' => 'intermediate', 'responsibility' => 'design',      'elementType' => 'Text',           'abilities' => ['vision']],
        'color-contrast-enhanced' => ['difficulty' => 'intermediate', 'responsibility' => 'design', 'elementType' => 'Text',      'abilities' => ['vision']],
        'image-alt' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Images',         'abilities' => ['vision']],
        'label' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['vision', 'cognition']],
        'html-has-lang' => ['difficulty' => 'beginner',     'responsibility' => 'technical',   'elementType' => 'Page',           'abilities' => ['vision', 'cognition']],
        'document-title' => ['difficulty' => 'beginner',     'responsibility' => 'content',     'elementType' => 'Page',           'abilities' => ['cognition']],
        'frame-title' => ['difficulty' => 'beginner',     'responsibility' => 'development', 'elementType' => 'Iframes',        'abilities' => ['vision', 'cognition']],
        'duplicate-id-active' => ['difficulty' => 'advanced',     'responsibility' => 'technical',   'elementType' => 'Page',           'abilities' => ['cognition']],
        'region' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Page structure', 'abilities' => ['cognition']],
        'landmark-one-main' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Page structure', 'abilities' => ['cognition']],
        'bypass' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Navigation',     'abilities' => ['motor']],
        'video-caption' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Media',          'abilities' => ['hearing']],
        'td-headers-attr' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Tables',         'abilities' => ['cognition']],
        'th-has-data-cells' => ['difficulty' => 'intermediate', 'responsibility' => 'content',     'elementType' => 'Tables',         'abilities' => ['cognition']],
        'select-name' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['vision', 'cognition']],
        'input-button-name' => ['difficulty' => 'intermediate', 'responsibility' => 'development', 'elementType' => 'Forms',          'abilities' => ['vision', 'cognition']],
    ];

    /**
     * @var array{difficulty: string, responsibility: string, elementType: string, abilities: string[]} Fallback metadata for unknown rules.
     */
    private static array $_fallback = [
        'difficulty' => 'intermediate',
        'responsibility' => 'technical',
        'elementType' => 'Other',
        'abilities' => ['cognition'],
    ];

    /**
     * @var array<string, array{title: string, fix?: string}> The words for the
     * plugin's own rules, in English: a short title naming the problem, and
     * where the check is precise enough to say, how to fix it. Translated as
     * they are read. axe rules are not here: they are titled from axe's own
     * help text, stored on each finding. PDF rules take theirs from PdfChecker.
     */
    private static array $_texts = [
        'img-alt' => [
            'title' => 'Image has no alt text',
            'fix' => 'Add an alt attribute to the image. If the image carries meaning, describe what it shows in a few '
                . 'words; if it is purely decorative, leave the alt empty (alt="") so screen readers skip it. For '
                . 'Craft assets, fill in the alt text on the asset and check the template outputs it.',
        ],
        'img-alt-filename' => [
            'title' => 'Alt text is a filename',
            'fix' => 'Replace the filename with a few words describing what the image shows. This usually comes from a '
                . 'template printing the asset title instead of its alt text: output the alt field and fill it in on '
                . 'the asset.',
        ],
        'heading-order' => [
            'title' => 'Heading level skipped',
            'fix' => 'Use heading levels in order without jumping a step, so an h2 is followed by another h2 or an h3, '
                . 'not an h4. Choose the level for where the heading sits in the page outline, and change how big it '
                . 'looks with CSS.',
        ],
        'empty-heading' => [
            'title' => 'Heading is empty',
            'fix' => 'Put text in the heading, or remove the heading if it is only there for spacing or decoration. The '
                . 'check reads the text inside the heading, so a heading holding only an image or an icon needs '
                . 'visually hidden text as well.',
        ],
        'multiple-h1' => [
            'title' => 'More than one h1 on the page',
            'fix' => 'Keep one h1 for the main heading of the page and use h2 and below for the sections under it. A '
                . 'logo or site name marked up as an h1 in the header is the usual second one.',
        ],
        'link-name' => [
            'title' => 'Link has no name',
            'fix' => 'Give the link text that says where it goes. For a link holding only an icon or an image, add alt '
                . 'text to the image or an aria-label to the link.',
        ],
        'link-generic' => [
            'title' => 'Link text is too vague',
            'fix' => 'Change the link text so it makes sense on its own, such as "Read the annual report" in place of '
                . '"Read more". If the design needs the visible text kept short, add the rest as visually hidden text '
                . 'inside the link.',
        ],
        'link-new-window' => [
            'title' => 'Link opens a new tab without saying so',
            'fix' => 'Tell people before the link opens a new tab: add "opens in a new tab" to the link text (visually '
                . 'hidden if need be) or to its aria-label. Or remove target="_blank" so the link opens in the same '
                . 'tab.',
        ],
        'symbol-only-content' => [
            'title' => 'Symbol used without words',
            'fix' => 'Add visually hidden text saying what the symbol means, such as "Included" for a tick, and mark the '
                . 'symbol itself aria-hidden="true". For a link, give it real text saying where it goes.',
        ],
        'button-name' => [
            'title' => 'Button has no name',
            'fix' => 'Give the button text that says what it does. For an icon-only button, add an aria-label or '
                . 'visually hidden text inside it, or alt text on the image it holds.',
        ],
        'form-label' => [
            'title' => 'Form field has no label',
            'fix' => 'Give each field a <label>, linked with a for attribute that matches the field\'s id, or wrap the '
                . 'field inside the label. Placeholder text does not count: it disappears as soon as someone starts '
                . 'typing.',
        ],
        'select-label' => [
            'title' => 'Dropdown has no label',
            'fix' => 'Give the dropdown a <label> linked with a for attribute that matches the select\'s id. Where a '
                . 'visible label really cannot fit, use aria-label or aria-labelledby.',
        ],
        'table-header' => [
            'title' => 'Table has no header cells',
            'fix' => 'Mark the header row or column with <th> cells in place of <td>, and add scope="col" or '
                . 'scope="row" to say which way each applies. If the table is only there for layout, add '
                . 'role="presentation" to it, or better, lay the content out with CSS.',
        ],
        'html-lang' => [
            'title' => 'Page language is not set',
            'fix' => 'Add a lang attribute to the <html> element in your layout template, such as lang="en", so screen '
                . 'readers pronounce the page in the right language. In Craft, craft.app.language gives the current '
                . 'site\'s language.',
        ],
        'page-title' => [
            'title' => 'Page has no title',
            'fix' => 'Add a <title> element inside the page\'s <head> naming the page, then the site. In a Craft layout '
                . 'this usually comes from the entry title, or from an SEO plugin where you use one.',
        ],
        'skip-link' => [
            'title' => 'No skip link',
            'fix' => 'Add a "Skip to main content" link as the first thing on the page, pointing at the id of your main '
                . 'content area. It can stay visually hidden until it takes keyboard focus.',
        ],
        'landmark-main' => [
            'title' => 'No main landmark',
            'fix' => 'Wrap the main content of the page in one <main> element in your layout template, so screen reader '
                . 'users can jump straight to it.',
        ],
        'landmark-regions' => [
            'title' => 'No landmark regions',
            'fix' => 'Mark up the regions of the page with <header>, <nav>, <main> and <footer> in place of plain '
                . '<div> elements. Screen reader users move between these regions to find their way around.',
        ],
        'iframe-title' => [
            'title' => 'Iframe has no title',
            'fix' => 'Add a title attribute to the iframe saying what it holds, such as title="Map of our office" or '
                . 'title="Video: product tour". Embed codes from video and map services often leave it out.',
        ],
        'video-captions' => [
            'title' => 'Video has no captions',
            'fix' => 'Add a captions file to the video with a <track kind="captions"> element pointing at a WebVTT '
                . 'file. Captions burnt into the picture itself are not detected, so check those by eye.',
        ],
        'autoplay' => [
            'title' => 'Media plays sound on its own',
            'fix' => 'Remove the autoplay attribute, or add muted so the media starts silently. If it has to play with '
                . 'sound, give people a visible control to pause or stop it.',
        ],
        'duplicate-id' => [
            'title' => 'Same id used twice',
            'fix' => 'Give every id on the page a value of its own. Repeated components such as cards and accordions are '
                . 'the usual cause: build the id from something unique, like the entry ID or the loop index.',
        ],
        'meta-description' => [
            'title' => 'No meta description',
            'fix' => 'Add a <meta name="description"> tag to the page\'s <head> with a sentence or two summing up the '
                . 'page. It is not a WCAG requirement, but search results and link previews show it.',
        ],
        'aria-tab-name' => [
            'title' => 'Tab has no name',
        ],
        'summary-name' => [
            'title' => 'Summary has no name',
        ],
        'aria-hidden-focus' => [
            'title' => 'Hidden content can take focus',
            'fix' => 'Content inside aria-hidden="true" is hidden from screen readers, so nothing in it should take '
                . 'keyboard focus. Remove aria-hidden from the element, or move the links, buttons and form fields out '
                . 'of it.',
        ],
        'list-structure' => [
            'title' => 'List item outside a list',
            'fix' => 'Put every <li> directly inside a <ul> or <ol>. The usual cause is a template loop that outputs list '
                . 'items without the list around them, or a <div> sitting between the list and its items.',
        ],
        'input-type' => [
            'title' => 'Personal details field has no autocomplete',
            'fix' => 'Add an autocomplete attribute saying what the field collects, such as autocomplete="name", "email" '
                . 'or "tel", so browsers can fill it in. Where it fits, changing the field type to email or tel does '
                . 'the job too.',
        ],
        'unescaped-markup-in-code' => [
            'title' => 'Markup renders inside a code sample',
            'fix' => 'Escape the angle brackets in the sample: write &lt; and &gt; in place of < and >. Wrapping markup '
                . 'in <code> or <pre> does not escape it, so the browser renders the tag instead of showing it.',
        ],
        'block-in-paragraph' => [
            'title' => 'Block element inside a paragraph',
            'fix' => 'Change the wrapping <p> to a <div>, or stop the content inside bringing its own paragraphs. This '
                . 'usually happens when a template wraps a rich text field, which outputs its own <p> tags, in another '
                . '<p>.',
        ],
        'color-contrast' => [
            'title' => 'Text contrast too low',
            'fix' => 'Darken the text or lighten the background, or the other way round, until the contrast ratio is at '
                . 'least 4.5:1, or 3:1 for large text. Each finding gives the colours and ratio measured. Change the '
                . 'colour where your stylesheet defines it, so every use is fixed at once.',
        ],
        'color-contrast-enhanced' => [
            'title' => 'Text contrast below the AAA level',
            'fix' => 'Level AAA asks for a contrast ratio of at least 7:1, or 4.5:1 for large text. Darken the text or '
                . 'lighten the background until each finding reaches it.',
        ],
        'contrast-hover' => [
            'title' => 'Text contrast too low on hover',
            'fix' => 'The colours your stylesheet sets on hover fall below the contrast the text needs. Change the hover '
                . 'colour or background until the ratio reaches 4.5:1, or 3:1 for large text.',
        ],
        'contrast-focus' => [
            'title' => 'Text contrast too low on focus',
            'fix' => 'The colours your stylesheet sets on keyboard focus fall below the contrast the text needs. Change '
                . 'the focus colour or background until the ratio reaches 4.5:1, or 3:1 for large text.',
        ],
        'contrast-selection' => [
            'title' => 'Selected text contrast too low',
            'fix' => 'The colours your stylesheet sets for selected text (::selection) fall below the contrast the text '
                . 'needs. Change them until the ratio reaches 4.5:1, or 3:1 for large text.',
        ],

        // Potential issues are titled with the question they put to a person.
        'potential:short-alt' => ['title' => 'Is this image alt text descriptive enough?'],
        'potential:long-alt' => ['title' => 'Is this image alt text too long?'],
        'potential:identical-links' => ['title' => 'Are these identical links going to different places?'],
        'potential:url-as-link-text' => ['title' => 'Is this link text meaningful?'],
        'potential:decorative-image' => ['title' => 'Is this image purely decorative?'],
        'potential:possible-heading' => ['title' => 'Should this bold paragraph be a heading?'],
        'potential:table-layout' => ['title' => 'Is this a data table or a layout table?'],
        'potential:video-audio-desc' => ['title' => 'Does this video need an audio description?'],
        'potential:contrast-unmeasurable' => ['title' => 'Does this text have enough contrast against what is behind it?'],
        'potential:focus-outline-removed' => ['title' => 'Does something replace the focus outline this stylesheet removes?'],
        'potential:focus-not-visible' => ['title' => 'Can you see where keyboard focus is on this control?'],
        'potential:focus-obscured' => ['title' => 'Does this fixed or sticky element hide controls when they take keyboard focus?'],
    ];

    /**
     * @var array<string, array{description: string, help: string}>|null axe's
     * rule texts, once read.
     */
    private static ?array $_axeTexts = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the metadata for a rule ID, falling back to a generic default.
     *
     * @param string $ruleId The rule identifier, optionally prefixed with `axe:`.
     * @return array{difficulty: string, responsibility: string, elementType: string, abilities: string[]}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function get(string $ruleId): array
    {
        $baseId = str_starts_with($ruleId, 'axe:') ? substr($ruleId, 4) : $ruleId;
        return self::$_rules[$baseId] ?? self::$_fallback;
    }

    /**
     * The short title a rule is listed under, translated.
     *
     * The plugin's own rules are titled here. An axe rule is titled with axe's
     * own help text for it, whole, read from the copy of axe the plugin ships
     * and scans with. Anything else is listed under its ID.
     *
     * @param string $ruleId The rule identifier.
     * @return string The title, or the rule ID where there is nothing better.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function title(string $ruleId): string
    {
        if (str_starts_with($ruleId, 'axe:')) {
            return self::_axeTexts()[substr($ruleId, 4)]['help'] ?? $ruleId;
        }

        if (isset(self::$_texts[$ruleId])) {
            return Craft::t('accessibility-audit', self::$_texts[$ruleId]['title']);
        }

        if (isset(PdfChecker::RULES[$ruleId])) {
            return PdfChecker::ruleTexts()[$ruleId]['label'] ?? $ruleId;
        }

        return $ruleId;
    }

    /**
     * What a rule checks, where there is more to say than its title.
     *
     * @param string $ruleId The rule identifier.
     * @return string|null The plugin's own description for the rule, or axe's
     *                     description for an axe rule, or null.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function description(string $ruleId): ?string
    {
        if (str_starts_with($ruleId, 'axe:')) {
            return self::_axeTexts()[substr($ruleId, 4)]['description'] ?? null;
        }

        return self::$_rules[$ruleId]['description'] ?? null;
    }

    /**
     * The part of one stored finding worth showing beside a rule's title and
     * description.
     *
     * An axe finding's message opens with the help text the rule is titled
     * with, and a potential issue's with its question, so where the title
     * makes up a whole first sentence it is cut and only what the finding adds
     * is kept. A message saying nothing past the title or description is
     * dropped.
     *
     * @param string $message One of the rule's stored messages.
     * @param string $title The rule's title.
     * @param string|null $description The rule's description, where it has one.
     * @return string What the finding adds, or an empty string.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function exampleFinding(string $message, string $title, ?string $description): string
    {
        $message = trim($message);
        $title = rtrim(trim($title), '.?');

        if ($title !== '' && stripos($message, $title) === 0) {
            $rest = substr($message, strlen($title));

            if ($rest === '' || in_array($rest[0], ['.', '?'], true)) {
                $message = ltrim($rest, " .?\n");
            }
        }

        $same = static fn(?string $a): bool => $a !== null
            && strcasecmp(rtrim(trim($a), '.?'), rtrim($message, '.?')) === 0;

        return $message === '' || $same($title) || $same($description) ? '' : $message;
    }

    /**
     * How to fix one of the plugin's own rules, translated.
     *
     * @param string $ruleId The rule identifier.
     * @return string|null The advice, or null for axe rules and for rules whose
     *                     check is not precise enough to say.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function fix(string $ruleId): ?string
    {
        $fix = self::$_texts[$ruleId]['fix'] ?? null;

        return $fix !== null ? Craft::t('accessibility-audit', $fix) : null;
    }

    /**
     * The plugin's own rule an axe rule shares its name with, where there is one.
     *
     * Both can fire on the same page, and they are not the same check: the
     * WCAG criterion each is reported against says how they differ.
     *
     * @param string $ruleId The rule identifier.
     * @return string|null The plugin rule's ID, or null where the rule is not an
     *                     axe rule or no plugin rule shares its name.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function pluginRuleFor(string $ruleId): ?string
    {
        if (!str_starts_with($ruleId, 'axe:')) {
            return null;
        }

        $baseId = substr($ruleId, 4);

        return isset(self::$_texts[$baseId]) ? $baseId : null;
    }

    /**
     * Every rule ID the plugin titles itself.
     *
     * @return string[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function titledRuleIds(): array
    {
        return array_keys(self::$_texts);
    }

    /**
     * Who a rule is for, in words, translated.
     *
     * @param string $r The responsibility value.
     * @return string The label, or a general one where the value is unknown.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function responsibilityLabel(string $r): string
    {
        return self::responsibilityLabels()[$r]
            ?? Craft::t('accessibility-audit', 'Other');
    }

    /**
     * Every responsibility, with the label to print and the class modifier the
     * badge is styled by.
     *
     * The one source for these. The same badge is built server-side by the Twig
     * macro and client-side by the table renderers, so the map is handed to the
     * JavaScript rather than written out again there. Every listing heads the
     * column "Owner".
     *
     * @return array<string, array{modifier: string, label: string}> Keyed by
     *         responsibility value.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function responsibilities(): array
    {
        return [
            'content' => ['modifier' => 'content', 'label' => Craft::t('accessibility-audit', 'Content')],
            'design' => ['modifier' => 'design', 'label' => Craft::t('accessibility-audit', 'Design')],
            'development' => ['modifier' => 'dev', 'label' => Craft::t('accessibility-audit', 'Development')],
            'technical' => ['modifier' => 'technical', 'label' => Craft::t('accessibility-audit', 'Technical')],
        ];
    }

    /**
     * Every responsibility label, keyed by value.
     *
     * @return array<string, string> The labels, keyed by responsibility value.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function responsibilityLabels(): array
    {
        return array_map(
            static fn(array $r): string => $r['label'],
            self::responsibilities(),
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * axe's help and description for each of its rules, keyed by axe's rule ID.
     *
     * Read from the axe build the plugin ships, which is the one every scan
     * runs, so the words always belong to the version that found the issue.
     * The build carries them as an object literal of
     * `id:{description:"…",help:"…"}` entries. Stored findings cannot stand in:
     * their message is the help with each element's failure reasons appended,
     * and help text such as "(i.e. accessible name)" makes the join between
     * the two impossible to find again.
     *
     * Cached against the build's modification time, so an axe upgrade is read
     * fresh.
     *
     * @return array<string, array{description: string, help: string}>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _axeTexts(): array
    {
        if (self::$_axeTexts !== null) {
            return self::$_axeTexts;
        }

        $path = dirname(__DIR__) . '/resources/axe/axe.min.js';
        $modified = is_file($path) ? (int)filemtime($path) : 0;

        if ($modified === 0) {
            return self::$_axeTexts = [];
        }

        $texts = Craft::$app->getCache()->getOrSet(
            ['accessibility-audit:axe-texts', $modified],
            static function() use ($path): array {
                $string = '"((?:[^"\\\\]|\\\\.)*)"';
                preg_match_all(
                    '/[{,](?:"([a-z0-9-]+)"|([a-z0-9]+)):\{description:' . $string . ',help:' . $string . '\}/',
                    (string)file_get_contents($path),
                    $matches,
                    PREG_SET_ORDER,
                );

                $texts = [];

                foreach ($matches as $match) {
                    // The strings are JavaScript literals. JSON decodes every
                    // escape in them except an escaped apostrophe.
                    $decode = static fn(string $literal): string
                        => (string)json_decode('"' . str_replace("\\'", "'", $literal) . '"');

                    $texts[$match[1] !== '' ? $match[1] : $match[2]] = [
                        'description' => $decode($match[3]),
                        'help' => $decode($match[4]),
                    ];
                }

                return $texts;
            },
            0,
        );

        return self::$_axeTexts = $texts;
    }
}
