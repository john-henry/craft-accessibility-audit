<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;

/**
 * Suggests what "what is affected" is asking for, per criterion.
 *
 * The statement asks the author to name the content a member of the public
 * would recognise, and that is a genuinely hard sentence to write from a cold
 * start, especially from a criterion number. The scanner cannot write it: it
 * knows a rule fired nineteen times, not that the pages in question are last
 * year's news archive.
 *
 * So these are placeholders, shown greyed and never saved. A default value
 * would end up published verbatim by somebody in a hurry, in a document with
 * legal weight; a placeholder shows the shape of the answer and still leaves
 * the writing to a person.
 *
 * Each names a place or a kind of content rather than the failure, because
 * that is what the field is for. The "Why" field takes the failure.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
class AffectedExample
{
    // Public Methods
    // =========================================================================

    /**
     * An example of the sort of answer the field wants.
     *
     * @param string $criterion The WCAG criterion number, e.g. "2.4.4".
     * @return string A placeholder, never a value.
     */
    public static function for(string $criterion): string
    {
        $examples = self::_examples();

        return $examples[trim($criterion)] ?? Craft::t(
            'accessibility-audit',
            'For example: videos on the training pages, or PDFs published before 2024',
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Examples for the criteria this plugin can actually raise, written the way
     * a reader of the published statement would recognise the content.
     *
     * @return array<string, string>
     */
    private static function _examples(): array
    {
        return [
            '1.1.1' => Craft::t('accessibility-audit', 'For example: photographs in news articles published before 2024'),
            '1.2.2' => Craft::t('accessibility-audit', 'For example: videos on the training pages'),
            '1.2.5' => Craft::t('accessibility-audit', 'For example: product demonstration videos'),
            '1.3.1' => Craft::t('accessibility-audit', 'For example: the application form on the contact page'),
            '1.3.5' => Craft::t('accessibility-audit', 'For example: the address fields in the booking form'),
            '1.3.6' => Craft::t('accessibility-audit', 'For example: the icon buttons in the members area'),
            '1.4.2' => Craft::t('accessibility-audit', 'For example: the background video on the home page'),
            '1.4.3' => Craft::t('accessibility-audit', 'For example: body text on the pricing pages'),
            '2.4.1' => Craft::t('accessibility-audit', 'For example: every page in the members area'),
            '2.4.2' => Craft::t('accessibility-audit', 'For example: older news articles'),
            '2.4.4' => Craft::t('accessibility-audit', 'For example: the "Read more" links on the news listing'),
            '2.4.6' => Craft::t('accessibility-audit', 'For example: headings in staff biographies'),
            '3.1.1' => Craft::t('accessibility-audit', 'For example: pages in the Irish language section'),
            '3.2.2' => Craft::t('accessibility-audit', 'For example: the filter menu on the shop pages'),
            '4.1.1' => Craft::t('accessibility-audit', 'For example: the events calendar'),
            '4.1.2' => Craft::t('accessibility-audit', 'For example: the cookie banner and the search box'),
        ];
    }
}
