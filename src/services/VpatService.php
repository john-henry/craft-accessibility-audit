<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\VpatMetaModel;
use yii\base\Component;
use yii\db\Exception;

/**
 * Manages VPAT (Voluntary Product Accessibility Template) data for a site.
 *
 * One record per site stores:
 *  - meta  : product information JSON (name, version, contact, etc.)
 *  - overrides : per-criterion conformance/remarks JSON
 *
 * Auto-conformance is derived live from the scan database so it always
 * reflects the most recent scan results.
 *
 * @property-read array[] $criteria
 */
class VpatService extends Component
{
    // ─── WCAG 2.1 A + AA Criteria ────────────────────────────────────────────
    // auto: 'automated' = scanner can fully detect
    //       'partial'   = scanner detects some violations but not all cases
    //       'manual'    = requires manual evaluation

    private const CRITERIA = [

        // ── Perceivable ──────────────────────────────────────────────────────

        '1.1.1' => [
            'name' => 'Non-text Content',
            'level' => 'A',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/non-text-content',
            'auto' => 'partial',
            'desc' => 'All non-text content presented to the user has a text alternative that serves the equivalent purpose.',
        ],
        '1.2.1' => [
            'name' => 'Audio-only and Video-only (Prerecorded)',
            'level' => 'A',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/audio-only-and-video-only-prerecorded',
            'auto' => 'manual',
            'desc' => 'Prerecorded audio-only and video-only media provide an equivalent alternative.',
        ],
        '1.2.2' => [
            'name' => 'Captions (Prerecorded)',
            'level' => 'A',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/captions-prerecorded',
            'auto' => 'partial',
            'desc' => 'Captions are provided for all prerecorded audio content in synchronized media.',
        ],
        '1.2.3' => [
            'name' => 'Audio Description or Media Alternative (Prerecorded)',
            'level' => 'A',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/audio-description-or-media-alternative-prerecorded',
            'auto' => 'manual',
            'desc' => 'An alternative for time-based media or audio description is provided for all prerecorded video content.',
        ],
        '1.3.1' => [
            'name' => 'Info and Relationships',
            'level' => 'A',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/info-and-relationships',
            'auto' => 'partial',
            'desc' => 'Information, structure, and relationships conveyed through presentation can be programmatically determined.',
        ],
        '1.3.2' => [
            'name' => 'Meaningful Sequence',
            'level' => 'A',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/meaningful-sequence',
            'auto' => 'manual',
            'desc' => 'When the sequence in which content is presented affects its meaning, a correct reading sequence can be programmatically determined.',
        ],
        '1.3.3' => [
            'name' => 'Sensory Characteristics',
            'level' => 'A',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/sensory-characteristics',
            'auto' => 'manual',
            'desc' => 'Instructions provided for understanding and operating content do not rely solely on sensory characteristics.',
        ],
        '1.4.1' => [
            'name' => 'Use of Color',
            'level' => 'A',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/use-of-color',
            'auto' => 'manual',
            'desc' => 'Color is not used as the only visual means of conveying information, indicating an action, prompting a response, or distinguishing a visual element.',
        ],
        '1.4.2' => [
            'name' => 'Audio Control',
            'level' => 'A',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/audio-control',
            'auto' => 'partial',
            'desc' => 'If any audio on a web page plays automatically for more than 3 seconds, a mechanism is available to pause or stop it.',
        ],

        // ── Operable ─────────────────────────────────────────────────────────

        '2.1.1' => [
            'name' => 'Keyboard',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/keyboard',
            'auto' => 'manual',
            'desc' => 'All functionality of the content is operable through a keyboard interface without requiring specific timings for individual keystrokes.',
        ],
        '2.1.2' => [
            'name' => 'No Keyboard Trap',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/no-keyboard-trap',
            'auto' => 'manual',
            'desc' => 'If keyboard focus can be moved to a component, focus can also be moved away using only keyboard.',
        ],
        '2.2.1' => [
            'name' => 'Timing Adjustable',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/timing-adjustable',
            'auto' => 'manual',
            'desc' => 'For each time limit set by the content, the user can turn off, adjust, or extend the limit.',
        ],
        '2.2.2' => [
            'name' => 'Pause, Stop, Hide',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide',
            'auto' => 'manual',
            'desc' => 'For any moving, blinking, scrolling, or auto-updating information, a mechanism is available to pause, stop, or hide it.',
        ],
        '2.3.1' => [
            'name' => 'Three Flashes or Below Threshold',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/three-flashes-or-below-threshold',
            'auto' => 'manual',
            'desc' => 'Web pages do not contain anything that flashes more than three times in any one second period.',
        ],
        '2.4.1' => [
            'name' => 'Bypass Blocks',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/bypass-blocks',
            'auto' => 'partial',
            'desc' => 'A mechanism is available to bypass blocks of content that are repeated on multiple web pages.',
        ],
        '2.4.2' => [
            'name' => 'Page Titled',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/page-titled',
            // Partial, not automated: the scan establishes that a title exists
            // and is not empty. The criterion asks for one that describes the
            // page, and a site where every title is the site name passes the
            // scan while failing the criterion outright.
            'auto' => 'partial',
            'desc' => 'Web pages have titles that describe topic or purpose.',
        ],
        '2.4.3' => [
            'name' => 'Focus Order',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/focus-order',
            'auto' => 'manual',
            'desc' => 'If a web page can be navigated sequentially, the focus order preserves meaning and operability.',
        ],
        '2.4.4' => [
            'name' => 'Link Purpose (In Context)',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/link-purpose-in-context',
            'auto' => 'partial',
            'desc' => 'The purpose of each link can be determined from the link text alone or from the link together with its context.',
        ],
        '2.5.1' => [
            'name' => 'Pointer Gestures',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/pointer-gestures',
            'auto' => 'manual',
            'desc' => 'All functionality that uses multipoint or path-based gestures can be operated with a single pointer without a path-based gesture.',
        ],
        '2.5.2' => [
            'name' => 'Pointer Cancellation',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/pointer-cancellation',
            'auto' => 'manual',
            'desc' => 'For functionality operable with a single pointer, at least one condition is met to help prevent accidental activation.',
        ],
        '2.5.3' => [
            'name' => 'Label in Name',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/label-in-name',
            'auto' => 'manual',
            'desc' => 'For user interface components with labels that include text or images of text, the accessible name contains the text that is presented visually.',
        ],
        '2.5.4' => [
            'name' => 'Motion Actuation',
            'level' => 'A',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/motion-actuation',
            'auto' => 'manual',
            'desc' => 'Functionality triggered by device motion or user motion can also be operated by user interface components.',
        ],

        // ── Understandable ───────────────────────────────────────────────────

        '3.1.1' => [
            'name' => 'Language of Page',
            'level' => 'A',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/language-of-page',
            // Partial, not automated: the scan establishes that a lang
            // attribute is present and well formed. Whether it names the
            // language the page is actually written in is not something any
            // scanner can settle, and lang="en" on a page of Irish is the
            // common way this fails.
            'auto' => 'partial',
            'desc' => 'The default human language of each web page can be programmatically determined.',
        ],
        '3.2.1' => [
            'name' => 'On Focus',
            'level' => 'A',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/on-focus',
            'auto' => 'manual',
            'desc' => 'If any user interface component receives focus, it does not initiate a change of context.',
        ],
        '3.2.2' => [
            'name' => 'On Input',
            'level' => 'A',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/on-input',
            'auto' => 'manual',
            'desc' => 'Changing the setting of any user interface component does not automatically cause a change of context.',
        ],
        '3.2.6' => [
            'name' => 'Consistent Help',
            'level' => 'A',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/consistent-help',
            'auto' => 'manual',
            'desc' => 'If a web page contains help mechanisms (human contact, chat, self-help, automated contact), they occur in the same relative order across pages.',
        ],
        '3.3.1' => [
            'name' => 'Error Identification',
            'level' => 'A',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/error-identification',
            'auto' => 'manual',
            'desc' => 'If an input error is automatically detected, the item that is in error is identified and the error is described to the user in text.',
        ],
        '3.3.2' => [
            'name' => 'Labels or Instructions',
            'level' => 'A',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/labels-or-instructions',
            'auto' => 'partial',
            'desc' => 'Labels or instructions are provided when content requires user input.',
        ],
        '3.3.7' => [
            'name' => 'Redundant Entry',
            'level' => 'A',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/redundant-entry',
            'auto' => 'manual',
            'desc' => 'Information previously entered by or provided to the user that is required again is auto-populated or available for selection.',
        ],

        // ── Robust ───────────────────────────────────────────────────────────

        '4.1.2' => [
            'name' => 'Name, Role, Value',
            'level' => 'A',
            'principle' => 'Robust',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/name-role-value',
            'auto' => 'partial',
            'desc' => 'For all user interface components, the name and role can be programmatically determined; states, properties, and values can be set programmatically.',
        ],

        // ═════════════════════════════════════════════════════════════════════
        // Level AA
        // ═════════════════════════════════════════════════════════════════════

        '1.2.4' => [
            'name' => 'Captions (Live)',
            'level' => 'AA',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/captions-live',
            'auto' => 'manual',
            'desc' => 'Captions are provided for all live audio content in synchronized media.',
        ],
        '1.2.5' => [
            'name' => 'Audio Description (Prerecorded)',
            'level' => 'AA',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/audio-description-prerecorded',
            'auto' => 'manual',
            'desc' => 'Audio description is provided for all prerecorded video content in synchronized media.',
        ],
        '1.3.4' => [
            'name' => 'Orientation',
            'level' => 'AA',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/orientation',
            'auto' => 'manual',
            'desc' => 'Content does not restrict its view and operation to a single display orientation.',
        ],
        '1.3.5' => [
            'name' => 'Identify Input Purpose',
            'level' => 'AA',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/identify-input-purpose',
            'auto' => 'partial',
            'desc' => 'The purpose of each input field that collects information about the user can be programmatically determined.',
        ],
        '1.4.3' => [
            'name' => 'Contrast (Minimum)',
            'level' => 'AA',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum',
            // Partial, not automated: the pass measures computed colours, which
            // is as thorough as this plugin gets, and text drawn into an image
            // has no computed colour to measure. The criterion covers images of
            // text as well, so a clean pass is strong evidence rather than a
            // finished answer.
            'auto' => 'partial',
            'desc' => 'The visual presentation of text and images of text has a contrast ratio of at least 4.5:1, except for large text (3:1), incidental, or logotype text.',
        ],
        '1.4.4' => [
            'name' => 'Resize Text',
            'level' => 'AA',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/resize-text',
            'auto' => 'manual',
            'desc' => 'Text can be resized without assistive technology up to 200 percent without loss of content or functionality.',
        ],
        '1.4.5' => [
            'name' => 'Images of Text',
            'level' => 'AA',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/images-of-text',
            'auto' => 'manual',
            'desc' => 'If the technologies being used can achieve the visual presentation, text is used to convey information rather than images of text.',
        ],
        '1.4.10' => [
            'name' => 'Reflow',
            'level' => 'AA',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/reflow',
            'auto' => 'manual',
            'desc' => 'Content can be presented without loss of information or functionality, and without requiring two-dimensional scrolling at 400% zoom.',
        ],
        '1.4.11' => [
            'name' => 'Non-text Contrast',
            'level' => 'AA',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast',
            'auto' => 'partial',
            'desc' => 'The visual presentation of user interface components and graphical objects has a contrast ratio of at least 3:1.',
        ],
        '1.4.12' => [
            'name' => 'Text Spacing',
            'level' => 'AA',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/text-spacing',
            'auto' => 'manual',
            'desc' => 'No loss of content or functionality occurs when specified text spacing properties are overridden.',
        ],
        '1.4.13' => [
            'name' => 'Content on Hover or Focus',
            'level' => 'AA',
            'principle' => 'Perceivable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/content-on-hover-or-focus',
            'auto' => 'manual',
            'desc' => 'Additional content that appears on pointer hover or keyboard focus is dismissible, hoverable, and persistent.',
        ],
        '2.4.5' => [
            'name' => 'Multiple Ways',
            'level' => 'AA',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/multiple-ways',
            'auto' => 'manual',
            'desc' => 'More than one way is available to locate a web page within a set of web pages.',
        ],
        '2.4.6' => [
            'name' => 'Headings and Labels',
            'level' => 'AA',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/headings-and-labels',
            'auto' => 'partial',
            'desc' => 'Headings and labels describe topic or purpose.',
        ],
        '2.4.7' => [
            'name' => 'Focus Visible',
            'level' => 'AA',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/focus-visible',
            'auto' => 'manual',
            'desc' => 'Any keyboard operable user interface has a mode of operation where the keyboard focus indicator is visible.',
        ],
        '2.4.11' => [
            'name' => 'Focus Appearance',
            'level' => 'AA',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance',
            'auto' => 'manual',
            'desc' => 'When a keyboard focus indicator is visible, the focus indicator area meets minimum size (at least the perimeter of the component) and contrast requirements (3:1 change ratio).',
        ],
        '2.5.7' => [
            'name' => 'Dragging Movements',
            'level' => 'AA',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/dragging-movements',
            'auto' => 'manual',
            'desc' => 'All functionality that uses a dragging movement can be achieved by a single pointer without dragging, unless dragging is essential or the movement is determined by the user agent.',
        ],
        '2.5.8' => [
            'name' => 'Target Size (Minimum)',
            'level' => 'AA',
            'principle' => 'Operable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum',
            'auto' => 'manual',
            'desc' => 'The size of the target for pointer inputs is at least 24 × 24 CSS pixels, except where spacing, inline, essential, native, or distinct conditions apply.',
        ],
        '3.1.2' => [
            'name' => 'Language of Parts',
            'level' => 'AA',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/language-of-parts',
            'auto' => 'manual',
            'desc' => 'The human language of each passage or phrase in the content can be programmatically determined.',
        ],
        '3.2.3' => [
            'name' => 'Consistent Navigation',
            'level' => 'AA',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/consistent-navigation',
            'auto' => 'manual',
            'desc' => 'Navigational mechanisms that are repeated on multiple web pages occur in the same relative order each time they are repeated.',
        ],
        '3.2.4' => [
            'name' => 'Consistent Identification',
            'level' => 'AA',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/consistent-identification',
            'auto' => 'manual',
            'desc' => 'Components that have the same functionality within a set of web pages are identified consistently.',
        ],
        '3.3.3' => [
            'name' => 'Error Suggestion',
            'level' => 'AA',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/error-suggestion',
            'auto' => 'manual',
            'desc' => 'If an input error is automatically detected and suggestions for correction are known, the suggestion is provided to the user.',
        ],
        '3.3.4' => [
            'name' => 'Error Prevention (Legal, Financial, Data)',
            'level' => 'AA',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/error-prevention-legal-financial-data',
            'auto' => 'manual',
            'desc' => 'For web pages that cause legal commitments or financial transactions, submissions are reversible, checked, or confirmed.',
        ],
        '3.3.8' => [
            'name' => 'Accessible Authentication (Minimum)',
            'level' => 'AA',
            'principle' => 'Understandable',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/accessible-authentication-minimum',
            'auto' => 'manual',
            'desc' => 'A cognitive function test is not required for any step in an authentication process unless an alternative method, assistance mechanism, or object recognition is available.',
        ],
        '4.1.3' => [
            'name' => 'Status Messages',
            'level' => 'AA',
            'principle' => 'Robust',
            'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/status-messages',
            'auto' => 'manual',
            'desc' => 'Status messages can be programmatically determined through role or properties so assistive technologies can present them without receiving focus.',
        ],
    ];

    /**
     * @var array<string, array{checks: string, cannot: string}> What the
     *      scanner tests for a criterion, and what it cannot establish.
     *
     *      Most of a VPAT is signed off by a person, and a row offering no
     *      more than a dropdown makes that person go and work out what has
     *      already been tested before they can answer. Both halves are needed:
     *      what was covered says the work is smaller than it looks, and what
     *      was not says where to actually go looking. A criterion missing from
     *      this list is one no scanner contributes to at all, which is worth
     *      saying too rather than leaving blank.
     */
    private const EVIDENCE = [
        '1.1.1' => [
            'checks' => 'every image, input and area for a missing or empty alt attribute, and flags alt text that is only a filename',
            'cannot' => 'whether the alt text describes the image, or whether an image left with an empty alt is genuinely decorative',
        ],
        '1.2.2' => [
            'checks' => 'video elements for a captions track',
            'cannot' => 'whether the captions are accurate, complete or in step with the audio',
        ],
        '1.3.1' => [
            'checks' => 'heading order, empty headings, table headers, form and select labels, and list structure',
            'cannot' => 'whether the structure in the markup matches the structure a sighted reader sees',
        ],
        '1.3.5' => [
            'checks' => 'input fields for an autocomplete attribute',
            'cannot' => 'whether the autocomplete value is the right one for that field',
        ],
        '1.4.2' => [
            'checks' => 'media elements set to play on load',
            'cannot' => 'whether a way to pause or stop the audio is provided',
        ],
        '1.4.3' => [
            'checks' => 'the computed text and background colour of every element, at both viewports, including hover, focus and selection states',
            'cannot' => 'text baked into an image',
        ],
        '1.4.11' => [
            'checks' => 'the contrast of interface components and graphics in a real browser, at both viewports',
            'cannot' => 'components that only appear part way through an interaction',
        ],
        '2.4.1' => [
            'checks' => 'each page for a skip link and for landmark regions',
            'cannot' => 'whether the skip link goes anywhere useful when it is used',
        ],
        '2.4.2' => [
            'checks' => 'every page for a title element that is not empty',
            'cannot' => 'whether the title describes that page rather than repeating the site name',
        ],
        '2.4.4' => [
            'checks' => 'links whose text is generic, a bare URL, or repeated on the same page while going somewhere else',
            'cannot' => 'whether a link makes sense where a reader meets it',
        ],
        '2.4.6' => [
            'checks' => 'headings for emptiness and for skipped levels',
            'cannot' => 'whether a heading or a label describes what comes after it',
        ],
        '2.5.8' => [
            'checks' => 'the size and spacing of every touch target in a real browser, at both viewports',
            'cannot' => 'targets that only appear part way through an interaction',
        ],
        '3.1.1' => [
            'checks' => 'every page for a lang attribute on the html element',
            'cannot' => 'whether the language declared is the language actually written',
        ],
        '3.3.2' => [
            'checks' => 'form fields for a label that is properly associated with them',
            'cannot' => 'whether the label or the instructions are clear enough to work from',
        ],
        '4.1.2' => [
            'checks' => 'buttons, links, iframes and form controls for an accessible name',
            'cannot' => 'widgets built in script, or whether a name matches the label a reader can see',
        ],
    ];

    // ─── Public API ──────────────────────────────────────────────────────────

    /** Returns the full hardcoded criteria list. */
    public function getCriteria(): array
    {
        return self::CRITERIA;
    }

    /**
     * Returns the stored VPAT record for the given site.
     * Creates a blank record if none exists.
     *
     * @return array{id: int, meta: array, overrides: array}
     * @throws Exception
     * @throws \Exception
     */
    public function getRecord(int $siteId): array
    {
        $row = (new Query())
            ->select(['id', 'meta', 'overrides'])
            ->from('{{%accessibilityaudit_vpat}}')
            ->where(['siteId' => $siteId])
            ->one();

        // The shared half (product name, contact, evaluation method) lives in
        // its own row so an accessibility statement reads the same values. It is
        // merged back in here rather than at each call site, so the editor, the
        // export template and craft.a11y.vpatReport() all keep seeing one flat
        // `meta` array and none of them need to know the storage is split.
        $shared = AccessibilityAudit::getInstance()->organisation->getMeta($siteId);

        if (!$row) {
            $this->_createRecord($siteId);
            return [
                'id' => (int) Craft::$app->getDb()->getLastInsertID(),
                'meta' => $shared,
                'overrides' => [],
            ];
        }

        return [
            'id' => (int) $row['id'],
            'meta' => array_merge($shared, $row['meta'] ? Json::decode($row['meta']) : []),
            'overrides' => $row['overrides'] ? Json::decode($row['overrides']) : [],
        ];
    }

    /**
     * Saves the validated VPAT-specific report information for the given site.
     *
     * This writes only the VPAT's own half. The shared organisation metadata is
     * saved separately through OrganisationService, so a caller handling a form
     * that posts both must save both.
     *
     * @param int $siteId The site the metadata belongs to.
     * @param VpatMetaModel $meta The validated metadata model.
     * @return void
     * @throws Exception
     * @see OrganisationService::saveMeta()
     * @throws \Exception
     */
    public function saveMeta(int $siteId, VpatMetaModel $meta): void
    {
        $this->_ensureRecord($siteId);

        Craft::$app->getDb()->createCommand()
            ->update(
                '{{%accessibilityaudit_vpat}}',
                [
                    'meta' => Json::encode($meta->toStorageArray()),
                    'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                ],
                ['siteId' => $siteId],
            )
            ->execute();
    }

    /**
     * Saves (or clears) the conformance level and remarks for one criterion.
     * Passing empty strings for both $level and $remarks removes the override.
     *
     * @throws Exception
     * @throws \Exception
     */
    public function saveOverride(int $siteId, string $criterion, string $level, string $remarks): void
    {
        $record = $this->getRecord($siteId);
        $overrides = $record['overrides'];

        if ($level === '' && $remarks === '') {
            unset($overrides[$criterion]);
        } else {
            $overrides[$criterion] = ['level' => $level, 'remarks' => $remarks];

            // What the findings looked like when this wording was written. A
            // remark is stored text and nothing recomputes it, so one that
            // counts four occurrences keeps saying four after they are fixed
            // or the questions behind them are answered. Recorded here rather
            // than when a draft is generated, because a remark typed by hand
            // goes out of date exactly the same way.
            if ($remarks !== '') {
                $overrides[$criterion]['remarkFindings'] = $this->getEvidence($siteId)[$criterion]['findings'] ?? 0;
                $overrides[$criterion]['remarkSavedAt'] = (new DateTime())->format('Y-m-d');
            }
        }

        Craft::$app->getDb()->createCommand()
            ->update(
                '{{%accessibilityaudit_vpat}}',
                [
                    'overrides' => Json::encode($overrides),
                    'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                ],
                ['siteId' => $siteId],
            )
            ->execute();
    }

    /**
     * Derives conformance levels from live scan data.
     *
     * For criteria with scan violations:
     *   - any 'error' severity  → Does Not Support
     *   - warnings/notices only → Partially Supports
     *
     * For 'automated' criteria where pages have been scanned and no
     * violations were found → Supports.
     *
     * @return array<string, array{level: string, basis: string}>
     */
    /**
     * What the scans can say about each criterion, for a person deciding how
     * to sign it off.
     *
     * The level suggestion answers "what did the scanner conclude". This
     * answers the question in front of it: what was actually looked at, over
     * how many pages, and what is left for a person. Most criteria are signed
     * off by hand, and without this every one of those rows is a blank box
     * with no way of telling a five-second decision from an afternoon's work.
     *
     * @param int $siteId The site to report on.
     * @return array<string, array{checks: ?string, cannot: ?string, findings: int, pages: int}>
     *         Keyed by criterion number. checks and cannot are null where no
     *         scanner contributes to that criterion at all.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getEvidence(int $siteId): array
    {
        $latestScanIds = (new Query())
            ->select(['MAX(id)'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['siteId' => $siteId])
            ->groupBy(['elementId', 'url'])
            ->column();

        $counts = [];

        if (!empty($latestScanIds)) {
            $counts = (new Query())
                ->select(['wcagCriterion', 'COUNT(*) as n'])
                ->from('{{%accessibilityaudit_issues}}')
                ->where(['scanId' => $latestScanIds, 'isResolved' => false])
                ->andWhere(AccessibilityAudit::getInstance()->audit->definiteCondition())
                ->andWhere(['not', ['wcagCriterion' => null]])
                ->groupBy(['wcagCriterion'])
                ->indexBy('wcagCriterion')
                ->column();
        }

        $pages = count($latestScanIds);
        $evidence = [];

        foreach (array_keys(self::CRITERIA) as $num) {
            $evidence[(string)$num] = [
                'checks' => self::EVIDENCE[$num]['checks'] ?? null,
                'cannot' => self::EVIDENCE[$num]['cannot'] ?? null,
                'findings' => (int)($counts[$num] ?? 0),
                'pages' => $pages,
            ];
        }

        return $evidence;
    }

    public function getAutoConformance(int $siteId): array
    {
        $audit = AccessibilityAudit::getInstance()->audit;

        $latestScanIds = (new Query())
            ->select(['MAX(id)'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['siteId' => $siteId])
            // Grouped by url as well as elementId: every URL scan shares a null
            // elementId, so grouping on that alone folds the lot into one row.
            ->groupBy(['elementId', 'url'])
            ->column();

        if (empty($latestScanIds)) {
            return [];
        }

        // Read the same way every other report does. A question the author has
        // dismissed is answered, and a fixed issue is fixed: counting either
        // one puts a criterion on the statement with nothing behind it on the
        // Issues screen, and no way to work out where it came from.
        $rows = (new Query())
            ->select(['wcagCriterion', 'severity'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => $latestScanIds, 'isResolved' => false])
            ->andWhere($audit->definiteCondition())
            ->andWhere(['not', ['wcagCriterion' => null]])
            ->all();

        // Accumulate worst severity per criterion
        $worst = [];
        foreach ($rows as $row) {
            $c = $row['wcagCriterion'];
            $s = $row['severity'];

            if (!isset($worst[$c]) || $s === 'error') {
                $worst[$c] = $s;
            }
        }

        $result = [];

        // Criteria with violations
        foreach ($worst as $criterion => $severity) {
            $result[$criterion] = [
                'level' => $severity === 'error' ? 'Does Not Support' : 'Partially Supports',
                'basis' => 'automated',
            ];
        }

        // Fully automated criteria with no violations → Supports
        foreach (self::CRITERIA as $num => $criterion) {
            if ($criterion['auto'] === 'automated' && !isset($result[$num])) {
                $result[$num] = ['level' => 'Supports', 'basis' => 'automated'];
            }
        }

        return $result;
    }

    /**
     * Merges criteria definitions, auto-conformance, and manual overrides
     * into a single report structure ready for rendering.
     *
     * @return array{meta: array, levelA: array, levelAA: array, hasScanData: bool}
     * @throws Exception
     * @throws \Exception
     */
    public function getFullReport(int $siteId): array
    {
        $record = $this->getRecord($siteId);
        $auto = $this->getAutoConformance($siteId);
        $evidence = $this->getEvidence($siteId);
        $overrides = $record['overrides'];

        $levelA = [];
        $levelAA = [];

        foreach (self::CRITERIA as $num => $criterion) {
            $autoData = $auto[$num] ?? null;
            $override = $overrides[$num] ?? null;

            // Manual override wins; fall back to auto suggestion
            $effectiveLevel = $override['level'] ?? $autoData['level'] ?? '';
            $effectiveRemarks = $override['remarks'] ?? '';

            $row = array_merge($criterion, [
                'number' => $num,
                'autoLevel' => $autoData['level'] ?? null,
                'autoBasis' => $autoData['basis'] ?? null,
                'overrideLevel' => $override['level'] ?? null,
                'overrideRemarks' => $override['remarks'] ?? '',
                'effectiveLevel' => $effectiveLevel,
                'effectiveRemarks' => $effectiveRemarks,
                'evidence' => $evidence[$num] ?? null,
                'remarkStale' => $this->_remarkIsStale($override, $evidence[$num] ?? null),
                'remarkSavedAt' => $override['remarkSavedAt'] ?? null,
                'remarkFindings' => $override['remarkFindings'] ?? null,
            ]);

            if ($criterion['level'] === 'A') {
                $levelA[$num] = $row;
            } else {
                $levelAA[$num] = $row;
            }
        }

        return [
            'meta' => $record['meta'],
            'levelA' => $levelA,
            'levelAA' => $levelAA,
            'hasScanData' => !empty($auto),
            // Whether to state EN 301 549 alongside WCAG: clause 9 restates WCAG
            // 2.1 Level AA, and this report is Level AA throughout.
            'en301549' => (bool)(AccessibilityAudit::getInstance()->getSettings()->en301549 ?? false),
        ];
    }

    /**
     * The display metadata for one WCAG criterion (name, level, principle, and
     * the WCAG Understanding URL), or null for an unknown number. Lets the
     * statement page turn the bare criterion numbers from
     * [[StatementService::deriveComplianceStatus()]] into a readable checklist
     * without exposing the whole private CRITERIA map.
     *
     * @param string $number The criterion number, e.g. '1.4.3'.
     * @return array{number: string, name: string, level: string, principle: string, url: string}|null
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function criterionMeta(string $number): ?array
    {
        $c = self::CRITERIA[$number] ?? null;
        if ($c === null) {
            return null;
        }

        return [
            'number' => $number,
            'name' => $c['name'],
            'level' => $c['level'],
            'principle' => $c['principle'],
            'url' => $c['url'],
        ];
    }

    /**
     * Drafts a VPAT remark for one criterion with Claude Haiku, grounded in
     * the site's actual scan findings for that criterion and/or the author's
     * own rough notes.
     *
     * Guardrails: the AI only ever drafts the remark text, never the
     * conformance level, and it only works from real material: scanner
     * findings, a clean automated or browser-verified sweep (the same
     * evidence getAutoConformance() accepts), or notes the author typed
     * after their own manual evaluation. Criteria with none of those are
     * refused rather than padded out with plausible-sounding claims nobody
     * has verified.
     *
     * @param int $siteId The site the report belongs to.
     * @param string $criterion The WCAG criterion number, e.g. '1.4.3'.
     * @param string $level The conformance level currently shown for the row.
     * @param string $notes The author's rough notes for the criterion, if any.
     * @return array{success: bool, remark?: string, error?: string, hint?: bool}
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function draftRemark(int $siteId, string $criterion, string $level, string $notes = ''): array
    {
        $meta = self::CRITERIA[$criterion] ?? null;
        if ($meta === null) {
            return ['success' => false, 'error' => Craft::t('accessibility-audit', 'Unknown criterion.')];
        }

        $settings = AccessibilityAudit::getInstance()->getSettings();
        $apiKey = trim(App::parseEnv($settings->anthropicApiKey ?? ''));
        if ($apiKey === '') {
            return ['success' => false, 'error' => Craft::t('accessibility-audit', 'Add an Anthropic API key under Settings → Tools to draft remarks.')];
        }

        $latestScanIds = (new Query())
            ->select(['MAX(id)'])
            ->from('{{%accessibilityaudit_scans}}')
            ->where(['siteId' => $siteId])
            // Grouped by url as well as elementId: every URL scan shares a null
            // elementId, so grouping on that alone folds the lot into one row.
            ->groupBy(['elementId', 'url'])
            ->column();

        $evidence = [];
        if (!empty($latestScanIds)) {
            // Read the same way the rest of the plugin reads findings. A
            // question the author has dismissed is answered, and handing it to
            // the model as evidence puts a count in the remark that appears
            // nowhere else on the site: the draft says four instances were
            // found while the row beside it says nothing was.
            $evidence = (new Query())
                ->select(['ruleId', 'severity', 'MIN(message) as message', 'COUNT(*) as occurrences', 'COUNT(DISTINCT elementId) as pages'])
                ->from('{{%accessibilityaudit_issues}}')
                ->where(['scanId' => $latestScanIds, 'wcagCriterion' => $criterion, 'isResolved' => false])
                ->andWhere(AccessibilityAudit::getInstance()->audit->definiteCondition())
                ->groupBy(['ruleId', 'severity'])
                ->orderBy(['occurrences' => SORT_DESC])
                ->limit(8)
                ->all();
        }

        $notes = trim($notes);

        // Nothing recorded and no author notes: there is no honest basis for a
        // draft, so make the human evaluate first. A clean scan is not a basis
        // on its own. No criterion is settled end to end by this plugin, so
        // "the sweep found nothing" is evidence towards an answer rather than
        // the answer, and drafting a conformance claim off it would put words
        // in the mouth of somebody who has not looked yet.
        // `hint` marks this as guidance rather than a failure, so the editor
        // can style it as a nudge instead of an error.
        if (empty($evidence) && $notes === '') {
            return [
                'success' => false,
                'hint' => true,
                'error' => Craft::t('accessibility-audit', 'Jot your own findings in the box first: drafting tidies them into VPAT wording.'),
            ];
        }

        $sources = [];

        if (!empty($evidence)) {
            $lines = array_map(
                static fn(array $row): string => sprintf(
                    '- %s (%s): %d occurrence(s) across %d page(s). Example: %s',
                    $row['ruleId'],
                    $row['severity'],
                    (int)$row['occurrences'],
                    (int)$row['pages'],
                    StringHelper::safeTruncate((string)$row['message'], 160),
                ),
                $evidence,
            );
            $sources[] = "Scanner findings for this criterion (latest scans):\n" . implode("\n", $lines);
        } elseif (isset(self::EVIDENCE[$criterion]) && !empty($latestScanIds)) {
            // What the sweep covered, stated as coverage rather than as a
            // verdict, and paired with what it could not reach. The author has
            // written notes to get this far; this tells the model what the
            // scans can and cannot back those notes up with.
            $sources[] = sprintf(
                'The scanner checked %s across %d scanned page(s) and found nothing. It cannot establish %s, which is why this criterion still rests on the author.',
                self::EVIDENCE[$criterion]['checks'],
                count($latestScanIds),
                self::EVIDENCE[$criterion]['cannot'],
            );
        }

        if ($notes !== '') {
            $sources[] = "The author's own rough notes from their manual evaluation:\n" .
                StringHelper::safeTruncate($notes, 2000);
        }

        $prompt =
            "You are helping complete a VPAT (Voluntary Product Accessibility Template) for a website.\n" .
            "Write the \"Remarks and Explanations\" text for one WCAG success criterion.\n\n" .
            "Criterion {$criterion}: {$meta['name']} (Level {$meta['level']})\n" .
            "Criterion description: {$meta['desc']}\n" .
            'Conformance level chosen for this row: ' . ($level !== '' ? $level : 'not yet selected') . "\n\n" .
            implode("\n\n", $sources) . "\n\n" .
            // The rules below are drawn from a corpus of published conformance
            // reports. See reference/vpat-remark-patterns.md, which records
            // what the corpus does at each of these decisions and how often.
            "Rules:\n" .
            "- Plain text only: no markdown, no headings, no preamble. One or two sentences where there is nothing failing, two to four where there is.\n" .
            "- Where you are listing more than one place a thing fails, write a stem sentence, then one line per item starting with a bullet character and a space. That is how nearly every published report handles a list of exceptions, and it beats a sentence with four clauses in it. One failure stays as prose.\n" .
            "- Name what fails, say where, and say how much of it there is. Published reports do one of those and almost never all three: a count with nothing attached to it is a statistic, and a named fault with no scale leaves the reader unable to judge severity. Where the material gives you a rule, a page and an occurrence count, use them together.\n" .
            "- Where something is failing, lead with what does work, then the exception. That is how the strongest reports read, and it stops a single fault reading as a broken product.\n" .
            "- Never offer a workaround, a setting or an add-on as though it settled the criterion. A fault that is worked around is still a fault.\n" .
            "- Base every statement strictly on the material above. Do not invent features, testing activity, or fixes that are not in it.\n" .
            "- When the author's notes are present, treat them as the primary source: keep their facts and intent, tidy the wording.\n" .
            "- That said, never describe how something was established unless the material says so. Do not write that anything was manually evaluated, audited, reviewed or tested by a person. The box may hold an earlier draft rather than notes, and rewriting one is the point at which an unattributed claim quietly acquires a provenance it never had.\n" .
            "- Counts and findings in the box may be out of date, because they are stored text and the scans have moved on. Where the material above gives you numbers, prefer those. Where it gives you none, do not carry a count forward from the box.\n" .
            "- Do not state or suggest a conformance level; describe the situation the material shows. The person completing the report chooses the level.\n" .
            "- Do not restate the success criterion. The reader has it in the next column.\n" .
            "- Do not describe testing tools or method. Those belong to the report as a whole, not to one row.\n" .
            "- No hedging unless something concrete sits beside it. \"Some pages\" on its own is worthless; \"some\" with a number or a named component is fine.\n" .
            "- Describe what the product does, not what it was designed or intended to do.\n" .
            "- No commitments to fix anything, and no dates, unless the author's notes give them.\n" .
            "- Do not push the problem onto whoever is implementing or configuring the site. Describe what was found.\n" .
            "- No marketing language. This is a factual document a buyer will compare against a competitor's.\n" .
            'Respond with the remark text only.';

        try {
            $client = Craft::createGuzzleClient(['timeout' => 30]);
            $response = $client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 300,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ],
            ]);

            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $remark = trim((string)($body['content'][0]['text'] ?? ''));

            if ($remark === '') {
                return ['success' => false, 'error' => Craft::t('accessibility-audit', 'The draft came back empty. Try again, or write the remark yourself.')];
            }

            return ['success' => true, 'remark' => $remark];
        } catch (\Throwable $e) {
            Craft::error('VpatService: remark drafting failed: ' . $e->getMessage(), 'accessibility-audit');
            return ['success' => false, 'error' => Craft::t('accessibility-audit', 'Drafting failed. Check the Anthropic API key and try again.')];
        }
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    /**
     * Whether a saved remark was written against a different set of findings
     * than the ones standing now.
     *
     * Only says so where it can be sure. A remark saved before the plugin
     * started recording this carries no count, and guessing at one would put a
     * warning on every row an author had already dealt with.
     *
     * @param array<string, mixed>|null $override The stored override, if any.
     * @param array{checks: ?string, cannot: ?string, findings: int, pages: int}|null $evidence
     *        The criterion's current evidence.
     * @return bool Whether the wording predates the current findings.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _remarkIsStale(?array $override, ?array $evidence): bool
    {
        if ($override === null || $evidence === null) {
            return false;
        }

        if (trim((string)($override['remarks'] ?? '')) === '' || !isset($override['remarkFindings'])) {
            return false;
        }

        return (int)$override['remarkFindings'] !== (int)$evidence['findings'];
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    private function _ensureRecord(int $siteId): void
    {
        $exists = (new Query())
            ->from('{{%accessibilityaudit_vpat}}')
            ->where(['siteId' => $siteId])
            ->exists();

        if (!$exists) {
            $this->_createRecord($siteId);
        }
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    private function _createRecord(int $siteId): void
    {
        Craft::$app->getDb()->createCommand()
            ->insert('{{%accessibilityaudit_vpat}}', [
                'siteId' => $siteId,
                'meta' => null,
                'overrides' => null,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])
            ->execute();
    }
}
