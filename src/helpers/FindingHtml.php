<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use craft\helpers\Html;

/**
 * Lays out a finding's message for the control panel: the first ratio (such
 * as 4.09:1) in bold, and the part naming colours on its own line, with a
 * swatch beside each hex colour.
 *
 * The page report draws the same layout in JavaScript, so a change to it here
 * needs the matching change in page-report.js.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class FindingHtml
{
    // Const Properties
    // =========================================================================

    /**
     * @var string A hex colour. The lookbehind leaves numeric entities like
     * &#039; in the encoded message alone.
     */
    private const HEX = '/(?<!&)#(?:[0-9a-f]{6}|[0-9a-f]{3})\b/i';

    // Public Methods
    // =========================================================================

    /**
     * The message as HTML, ready to print raw.
     *
     * The message came off a scanned page, so it is encoded first and only the
     * markup added here is HTML. A swatch's colour is the matched hex value
     * itself.
     *
     * @param string $message The finding's message, as stored.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function format(string $message): string
    {
        $html = (string)preg_replace(
            '/\b\d+(?:\.\d+)?:1\b/',
            '<strong class="accessibility-audit-occ-ratio">$0</strong>',
            Html::encode($message),
            1,
        );
        $out = '';

        foreach (explode(' · ', $html) as $i => $part) {
            if (preg_match(self::HEX, $part) === 1) {
                $out .= Html::tag('span', (string)preg_replace(
                    self::HEX,
                    '<span class="accessibility-audit-occ-colour"><span class="accessibility-audit-occ-swatch" style="background:$0" aria-hidden="true"></span>$0</span>',
                    $part,
                ), ['class' => 'accessibility-audit-occ-colours']);
                continue;
            }

            $out .= ($i > 0 ? ' · ' : '') . $part;
        }

        return $out;
    }
}
