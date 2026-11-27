<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use johnhenry\accessibilityaudit\services\FixGuideService;
use johnhenry\accessibilityaudit\services\RuleRegistry;

/**
 * Writes a fix guide out as Markdown.
 *
 * Takes the guide already gathered (FixGuideService does that) and lays it out
 * in two parts by who does the work: developers, for the rules fixed in
 * templates, styles and scripts and for any rule repeating across enough pages
 * to come from a shared template; and content editors, for the rest of the
 * content rules, which are fixed in the entries themselves.
 *
 * Page titles, messages, selectors and markup all come off the site, so every
 * one of them is escaped for where it lands: markup and selectors in code, the
 * rest as inline text with Markdown's punctuation backslash-escaped. Only
 * http and https addresses are made into links.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class FixGuideMarkdown
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The part of the guide for developers.
     */
    public const AUDIENCE_DEVELOPERS = 'developers';

    /**
     * @var string The part of the guide for content editors.
     */
    public const AUDIENCE_EDITORS = 'editors';

    // Public Methods
    // =========================================================================

    /**
     * Which part of the guide a rule goes in.
     *
     * A content rule repeating across enough pages is not an editor's to fix:
     * the same finding on thirty pages is a template printing it thirty times.
     *
     * @param string $responsibility Who the rule is for, from RuleRegistry.
     * @param bool $templateLevel Whether the rule repeats across enough pages
     *                            to come from a shared template.
     * @return string One of the AUDIENCE_* constants.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function audience(string $responsibility, bool $templateLevel): string
    {
        return $responsibility === 'content' && !$templateLevel
            ? self::AUDIENCE_EDITORS
            : self::AUDIENCE_DEVELOPERS;
    }

    /**
     * The guide, as Markdown.
     *
     * @param array{siteName: string, siteUrl?: string|null, generated: string, scope: string, scopeLabel?: string|null, scopeUrl?: string|null, maxExamples: int, maxPages: int, craftVersion?: string|null, rules: array<int, array<string, mixed>>} $guide
     *        The gathered guide. Each rule carries `ruleId`, `title`,
     *        `severity`, `responsibility`, `occurrences`, `pageCount`,
     *        `templateLevel`, `templatePages`, `pages` and `examples`, the
     *        optional `wcagCriterion`, `wcagLevel`, `fix`, `description` and
     *        `helpUrl`, and `prompt`, the same rule's words in English.
     * @return string The Markdown.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function render(array $guide): string
    {
        $out = [];
        $out[] = '# ' . self::_text(Craft::t('accessibility-audit', 'Accessibility fix guide: {site}', ['site' => $guide['siteName']]));
        $out[] = '';
        $out[] = '- **' . Craft::t('accessibility-audit', 'Site') . ':** ' . self::_text($guide['siteName'])
            . (self::_isWebUrl((string)($guide['siteUrl'] ?? '')) ? ' (' . self::_link((string)$guide['siteUrl'], (string)$guide['siteUrl']) . ')' : '');
        $out[] = '- **' . Craft::t('accessibility-audit', 'Generated') . ':** ' . self::_text($guide['generated']);
        $out[] = '- **' . Craft::t('accessibility-audit', 'Scope') . ':** ' . self::_scope($guide);
        $out[] = '';
        $out[] = self::_text(Craft::t('accessibility-audit', 'How to use this guide: developers work through the rules under For developers in the codebase, and content editors fix the entries listed under For content editors. Each developer rule ends with a prompt to paste into an AI coding assistant.'));
        $out[] = '';

        if ($guide['rules'] === []) {
            $out[] = self::_text(Craft::t('accessibility-audit', 'Nothing to fix: there are no open issues in this scope.'));
            $out[] = '';

            return implode("\n", $out);
        }

        $developers = self::_for($guide['rules'], self::AUDIENCE_DEVELOPERS);
        $editors = self::_for($guide['rules'], self::AUDIENCE_EDITORS);

        if ($developers !== []) {
            $out[] = '## ' . self::_text(Craft::t('accessibility-audit', 'For developers'));
            $out[] = '';
            $out[] = self::_text(Craft::t('accessibility-audit', 'Rules fixed in the templates, styles or scripts, and any rule repeating across enough pages to come from a shared template.'));
            $out[] = '';

            foreach ($developers as $rule) {
                array_push($out, ...self::_developerRule($rule, $guide));
            }
        }

        if ($editors !== []) {
            $out[] = '## ' . self::_text(Craft::t('accessibility-audit', 'For content editors'));
            $out[] = '';
            $out[] = self::_text(Craft::t('accessibility-audit', 'Rules fixed in the entries themselves. Open each entry listed, find the content named, and change it in its field.'));
            $out[] = '';

            foreach ($editors as $rule) {
                array_push($out, ...self::_editorRule($rule, $guide));
            }
        }

        return implode("\n", $out);
    }

    // Private Methods
    // =========================================================================

    /**
     * The rules belonging in one part of the guide, in order.
     *
     * @param array<int, array<string, mixed>> $rules Every rule in the guide.
     * @param string $audience One of the AUDIENCE_* constants.
     * @return array<int, array<string, mixed>> The rules for that part.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _for(array $rules, string $audience): array
    {
        return array_values(array_filter(
            $rules,
            static fn(array $rule): bool => self::audience((string)$rule['responsibility'], (bool)$rule['templateLevel']) === $audience,
        ));
    }

    /**
     * What the guide covers, for its header.
     *
     * @param array<string, mixed> $guide The gathered guide.
     * @return string The scope line's value, as Markdown.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _scope(array $guide): string
    {
        $label = (string)($guide['scopeLabel'] ?? '');
        $url = (string)($guide['scopeUrl'] ?? '');

        return match ($guide['scope']) {
            FixGuideService::SCOPE_RULE => self::_text(Craft::t('accessibility-audit', 'Rule: {rule}', ['rule' => $label])),
            FixGuideService::SCOPE_PAGE => self::_text(Craft::t('accessibility-audit', 'Page: {page}', ['page' => $label]))
                . (self::_isWebUrl($url) ? ' (' . self::_link($url, $url) . ')' : ''),
            default => self::_text(Craft::t('accessibility-audit', 'Whole site')),
        };
    }

    /**
     * One rule in the developers' part.
     *
     * @param array<string, mixed> $rule The rule.
     * @param array<string, mixed> $guide The gathered guide, for its caps.
     * @return string[] The rule's lines.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _developerRule(array $rule, array $guide): array
    {
        $out = self::_ruleHeader($rule);
        $out[] = '- **' . Craft::t('accessibility-audit', 'Where it belongs') . ':** ' . self::_text($rule['templateLevel']
            ? Craft::t('accessibility-audit', 'Repeats on {n} pages, so it lives in a shared Twig template, layout, or component. Fix it once there.', ['n' => (int)$rule['templatePages']])
            : Craft::t('accessibility-audit', 'Only on the pages listed below.'));
        $out[] = '';

        array_push($out, ...self::_howToFix($rule));

        $examples = array_slice($rule['examples'], 0, (int)$guide['maxExamples']);

        if ($examples !== []) {
            $out[] = '#### ' . self::_text(Craft::t('accessibility-audit', 'Examples'));
            $out[] = '';

            foreach ($examples as $i => $example) {
                $lines = self::_exampleLines($example);
                $marker = ($i + 1) . '. ';
                $out[] = $marker . array_shift($lines);

                // Indented to the item's content, so the lines stay inside it.
                $indent = str_repeat(' ', strlen($marker));

                foreach ($lines as $line) {
                    $out[] = $line === '' ? '' : $indent . $line;
                }

                $out[] = '';
            }

            $more = (int)$rule['occurrences'] - count($examples);

            if ($more > 0) {
                $out[] = self::_text(Craft::t('accessibility-audit', 'And {n, plural, =1{# more occurrence} other{# more occurrences}}.', ['n' => $more]));
                $out[] = '';
            }
        }

        array_push($out, ...self::_pages($rule, $guide, false));

        // The heading stays English, as the prompt under it does.
        $prompt = FixPrompt::build($rule['prompt'], $examples, $guide['craftVersion'] ?? null);
        $fence = FixPrompt::fence($prompt);

        $out[] = '#### AI prompt (English)';
        $out[] = '';
        $out[] = self::_text(Craft::t('accessibility-audit', 'Paste this into an AI coding assistant opened on the site\'s codebase. It stays in English whatever language this guide is in.'));
        $out[] = '';
        $out[] = $fence . 'text';
        $out[] = $prompt;
        $out[] = $fence;
        $out[] = '';

        return $out;
    }

    /**
     * One rule in the content editors' part.
     *
     * @param array<string, mixed> $rule The rule.
     * @param array<string, mixed> $guide The gathered guide, for its caps.
     * @return string[] The rule's lines.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _editorRule(array $rule, array $guide): array
    {
        $out = self::_ruleHeader($rule);
        $out[] = '';

        array_push($out, ...self::_howToFix($rule));
        array_push($out, ...self::_pages($rule, $guide, true));

        return $out;
    }

    /**
     * A rule's heading and the facts listed under it.
     *
     * @param array<string, mixed> $rule The rule.
     * @return string[] The lines.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _ruleHeader(array $rule): array
    {
        $criterion = trim((string)($rule['wcagCriterion'] ?? ''));
        $level = trim((string)($rule['wcagLevel'] ?? ''));
        $wcag = match (true) {
            $criterion !== '' && $level !== '' => Craft::t('accessibility-audit', '{criterion} (Level {level})', ['criterion' => $criterion, 'level' => $level]),
            $criterion !== '' => $criterion,
            default => Craft::t('accessibility-audit', 'Best practice (no formal WCAG criterion)'),
        };
        $severity = match ($rule['severity']) {
            'error' => Craft::t('accessibility-audit', 'Error'),
            'warning' => Craft::t('accessibility-audit', 'Warning'),
            'notice' => Craft::t('accessibility-audit', 'Notice'),
            default => (string)$rule['severity'],
        };

        return [
            '### ' . self::_text((string)$rule['title']),
            '',
            '- **' . Craft::t('accessibility-audit', 'Rule ID') . ':** ' . self::_code((string)$rule['ruleId']),
            '- **WCAG:** ' . self::_text($wcag),
            '- **' . Craft::t('accessibility-audit', 'Severity') . ':** ' . self::_text($severity),
            '- **' . Craft::t('accessibility-audit', 'Owner') . ':** ' . self::_text(RuleRegistry::responsibilityLabel((string)$rule['responsibility'])),
            '- **' . Craft::t('accessibility-audit', 'Pages affected') . ':** ' . (int)$rule['pageCount']
                . ', **' . Craft::t('accessibility-audit', 'Occurrences') . ':** ' . (int)$rule['occurrences'],
        ];
    }

    /**
     * A rule's How to fix section.
     *
     * The plugin's own advice where it has some, or what the rule checks where
     * it does not, then the guide to the rule where one is linked.
     *
     * @param array<string, mixed> $rule The rule.
     * @return string[] The lines, or none where there is nothing to say.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _howToFix(array $rule): array
    {
        $advice = trim((string)($rule['fix'] ?? '')) ?: trim((string)($rule['description'] ?? ''));
        $helpUrl = trim((string)($rule['helpUrl'] ?? ''));

        if ($advice === '' && !self::_isWebUrl($helpUrl)) {
            return [];
        }

        $out = ['#### ' . self::_text(Craft::t('accessibility-audit', 'How to fix')), ''];

        if ($advice !== '') {
            $out[] = self::_text($advice);
            $out[] = '';
        }

        if (self::_isWebUrl($helpUrl)) {
            $out[] = self::_link(Craft::t('accessibility-audit', 'Guide to this rule'), $helpUrl);
            $out[] = '';
        }

        return $out;
    }

    /**
     * The pages a rule was found on, capped.
     *
     * @param array<string, mixed> $rule The rule.
     * @param array<string, mixed> $guide The gathered guide, for its caps.
     * @param bool $forEditors Whether to head the list for editors and link
     *                         each entry's edit screen.
     * @return string[] The lines, or none where no page is listed.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _pages(array $rule, array $guide, bool $forEditors): array
    {
        $pages = array_slice($rule['pages'], 0, (int)$guide['maxPages']);

        if ($pages === []) {
            return [];
        }

        $heading = $forEditors
            ? Craft::t('accessibility-audit', 'Entries to fix')
            : Craft::t('accessibility-audit', 'Pages');
        $out = ['#### ' . self::_text($heading), ''];

        foreach ($pages as $page) {
            $parts = ['**' . self::_text((string)$page['label']) . '**'];

            if ($forEditors && self::_isWebUrl((string)($page['editUrl'] ?? ''))) {
                $parts[] = self::_link(Craft::t('accessibility-audit', 'Edit in the control panel'), (string)$page['editUrl']);
            }

            if (self::_isWebUrl((string)($page['url'] ?? ''))) {
                $parts[] = self::_link(Craft::t('accessibility-audit', 'View page'), (string)$page['url']);
            }

            $parts[] = self::_text(Craft::t('accessibility-audit', '{n, plural, =1{# occurrence} other{# occurrences}}', ['n' => (int)$page['occurrences']]));
            $out[] = '- ' . implode(', ', $parts);

            // What to look for in the entry, so an editor can find it.
            $found = FixPrompt::snippet((string)($page['found'] ?? ''));

            if ($forEditors && $found !== '') {
                $out[] = '  - ' . Craft::t('accessibility-audit', 'Look for') . ': ' . self::_code($found);
            }
        }

        $out[] = '';
        $more = (int)$rule['pageCount'] - count($pages);

        if ($more > 0) {
            $out[] = self::_text(Craft::t('accessibility-audit', 'And {n, plural, =1{# more page} other{# more pages}}.', ['n' => $more]));
            $out[] = '';
        }

        return $out;
    }

    /**
     * One example occurrence: where it was, what the scan said, and its
     * markup in a fenced block.
     *
     * @param array{markup: string, selector?: string, pageUrl?: string|null, finding?: string} $example
     *        The occurrence.
     * @return string[] The lines, the first one starting the list item.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _exampleLines(array $example): array
    {
        $pageUrl = (string)($example['pageUrl'] ?? '');
        $lines = [
            '**' . Craft::t('accessibility-audit', 'Page') . ':** '
                . (self::_isWebUrl($pageUrl) ? self::_link($pageUrl, $pageUrl) : self::_text($pageUrl ?: '-')),
        ];

        if (trim((string)($example['selector'] ?? '')) !== '') {
            $lines[] = '**' . Craft::t('accessibility-audit', 'Selector') . ':** ' . self::_code((string)$example['selector']);
        }

        if (trim((string)($example['finding'] ?? '')) !== '') {
            $lines[] = '**' . Craft::t('accessibility-audit', 'Finding') . ':** ' . self::_text((string)$example['finding']);
        }

        // A backslash at the end of a line is a hard break, so each fact
        // keeps a line of its own rather than running into the next.
        foreach (array_keys(array_slice($lines, 0, -1)) as $i) {
            $lines[$i] .= '\\';
        }

        $markup = FixPrompt::snippet($example['markup']);

        if ($markup !== '') {
            $fence = FixPrompt::fence($markup);
            $lines[] = '';
            $lines[] = $fence . 'html';
            array_push($lines, ...explode("\n", $markup));
            $lines[] = $fence;
        }

        return $lines;
    }

    /**
     * Text safe to sit inline in Markdown.
     *
     * Folded onto one line, with the punctuation Markdown or HTML acts on
     * anywhere in a line backslash-escaped, so a page title holding `*`, `[`
     * or a tag reads as itself. A heading or list marker opening the text is
     * escaped too, since the text can start a paragraph.
     *
     * @param string $text The text.
     * @return string The escaped text.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _text(string $text): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        $text = (string)preg_replace('/([\\\\`*_\[\]<>|~&])/', '\\\\$1', $text);

        return (string)preg_replace(['/^([#+-])(?=\s|$)/', '/^(\d+)([.)])(?=\s|$)/'], ['\\\\$1', '$1\\\\$2'], $text);
    }

    /**
     * Text as an inline code span.
     *
     * @param string $text The text.
     * @return string The code span, its fence longer than any backtick run in
     *                the text.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _code(string $text): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        $fence = str_repeat('`', FixPrompt::longestBacktickRun($text) + 1);
        $pad = str_starts_with($text, '`') || str_ends_with($text, '`') ? ' ' : '';

        return $fence . $pad . $text . $pad . $fence;
    }

    /**
     * A link.
     *
     * @param string $label The link text, unescaped.
     * @param string $url An http or https address.
     * @return string The link, its address in angle brackets so spaces and
     *                parentheses in it cannot end it early.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _link(string $label, string $url): string
    {
        $url = str_replace([' ', '<', '>', "\n", "\r"], ['%20', '%3C', '%3E', '', ''], trim($url));

        return '[' . self::_text($label) . '](<' . $url . '>)';
    }

    /**
     * Whether a value is an http or https address.
     *
     * @param string $url The value.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _isWebUrl(string $url): bool
    {
        return preg_match('~^https?://~i', trim($url)) === 1;
    }
}
