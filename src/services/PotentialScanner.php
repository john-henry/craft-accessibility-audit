<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use johnhenry\accessibilityaudit\helpers\AccessibleName;
use johnhenry\accessibilityaudit\helpers\ExcludedElements;
use johnhenry\accessibilityaudit\models\IssueModel;
use yii\base\Component;

/**
 * Scans HTML for patterns that may be accessibility issues but require human confirmation.
 * All issues are stored with ruleId prefixed "potential:" so they can be queried separately.
 */
class PotentialScanner extends Component
{
    public function scan(string $html): array
    {
        if (empty(trim($html))) {
            return [];
        }

        $dom = new DOMDocument();
        // XML encoding prologue tells libxml the source is UTF-8.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR);
        $xpath = new DOMXPath($dom);

        // Same exclusions as ContentScanner and the browser engines: a
        // consent banner must not generate potential questions either.
        ExcludedElements::removeFrom($xpath);

        return array_merge(
            $this->checkShortAlt($xpath),
            $this->checkLongAlt($xpath),
            $this->checkIdenticalLinks($xpath),
            $this->checkUrlAsLinkText($xpath),
            $this->checkDecorativeImage($xpath),
            $this->checkPossibleHeading($xpath),
            $this->checkTableLayout($xpath),
            $this->checkVideoNoAudioDesc($xpath),
        );
    }

    // ── Checks ───────────────────────────────────────────────────────────────

    private function checkShortAlt(DOMXPath $xpath): array
    {
        $issues = [];
        $nodes = $xpath->query('//img[@alt and string-length(normalize-space(@alt)) >= 1 and string-length(normalize-space(@alt)) <= 3]');
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ($this->isDecorative($node)) {
                continue;
            }
            $issues[] = IssueModel::make(
                ruleId: 'potential:short-alt',
                severity: 'notice',
                message: 'Is this image alt text descriptive enough? It is very short, confirm it adequately describes the image.',
                wcagCriterion: '1.1.1',
                wcagLevel: 'A',
                // 300, not the default cap: image snippets must keep enough
                // of the src URL to tell sibling images on a shared upload
                // path apart, or the report highlights the lot of them.
                context: $this->outerHtml($node, 300),
                helpUrl: null,
                source: 'php',
            );
        }
        return $issues;
    }

    private function checkLongAlt(DOMXPath $xpath): array
    {
        $issues = [];
        $nodes = $xpath->query('//img[@alt and string-length(@alt) > 150]');
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $issues[] = IssueModel::make(
                ruleId: 'potential:long-alt',
                severity: 'notice',
                message: 'Is this image alt text too long? Consider using aria-describedby for descriptions over 150 characters.',
                wcagCriterion: '1.1.1',
                wcagLevel: 'A',
                context: mb_substr($node->getAttribute('alt'), 0, 100) . '…',
                helpUrl: null,
                source: 'php',
            );
        }
        return $issues;
    }

    /**
     * Reduces an href to the destination it actually resolves to, so two ways
     * of writing the same target compare equal. Without this,
     * `https://example.com/contact` and `/contact` look like different places
     * and a page's own navigation reads as a fault.
     *
     * Absolute URLs on this site collapse to their path; the host is kept for
     * genuinely external links so two different sites are never merged.
     * Trailing slashes go, since `/contact` and `/contact/` are one page. The
     * query and fragment stay: `?page=2` and `#section` really are different
     * destinations, and merging them would hide a real ambiguity.
     *
     * @param string $href The raw href attribute.
     * @param string[] $siteHosts Hosts that belong to this install.
     * @return string A comparable form of the destination.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function normaliseHref(string $href, array $siteHosts): string
    {
        $href = trim($href);
        $parts = parse_url($href);

        // Unparseable: compare it as-is rather than guessing.
        if ($parts === false) {
            return $href;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        // Drop a trailing slash, but never turn the root into an empty string.
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        if ($path === '') {
            $path = '/';
        }

        $suffix = (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

        // Same site, or already relative: the path alone identifies the target.
        if ($host === '' || in_array($host, $siteHosts, true)) {
            return $path . $suffix;
        }

        return $host . $path . $suffix;
    }

    /**
     * The hosts that count as "this site", used to tell an absolute link home
     * apart from a link to somewhere else.
     *
     * @return string[] Lowercased hostnames.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function siteHosts(): array
    {
        $hosts = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $host = parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    private function checkIdenticalLinks(DOMXPath $xpath): array
    {
        $issues = [];
        $nodes = $xpath->query('//a[normalize-space(.) != ""]');
        $linkMap = [];
        $siteHosts = $this->siteHosts();

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            // The announced name, not the visible text. Two buttons both
            // reading "Visit Website" with aria-labels naming their
            // destinations are two distinct names: a screen reader user hears
            // them apart, so 2.4.4 is satisfied and there is no question to
            // ask. Compared on the text alone this reported correct markup.
            $text = AccessibleName::for($node, $xpath);
            $href = trim($node->getAttribute('href'));

            if ($text && $href && !str_starts_with($href, '#')) {
                // Keyed by the resolved destination so the same target written
                // two ways counts once. The raw href is kept for the message,
                // since that is what the author will recognise in their markup.
                $linkMap[$text][$this->normaliseHref($href, $siteHosts)] = $href;
            }
        }

        $seen = [];
        foreach ($linkMap as $text => $hrefsByTarget) {
            $unique = array_values($hrefsByTarget);
            if (count($unique) > 1 && !isset($seen[$text])) {
                $seen[$text] = true;
                $issues[] = IssueModel::make(
                    ruleId: 'potential:identical-links',
                    severity: 'notice',
                    message: 'Are these identical links going to the same place? Multiple links share the same text but point to different destinations.',
                    wcagCriterion: '2.4.4',
                    wcagLevel: 'A',
                    context: '"' . $text . '" → ' . implode(', ', array_slice($unique, 0, 3)),
                    helpUrl: null,
                    source: 'php',
                );
            }
        }

        return $issues;
    }

    private function checkUrlAsLinkText(DOMXPath $xpath): array
    {
        $issues = [];
        $nodes = $xpath->query('//a[normalize-space(.) != ""]');
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match('#^https?://#i', $text) || preg_match('#^www\.#i', $text)) {
                $issues[] = IssueModel::make(
                    ruleId: 'potential:url-as-link-text',
                    severity: 'notice',
                    message: 'Is this link text meaningful? Raw URLs as link text are hard to understand for screen reader users.',
                    wcagCriterion: '2.4.4',
                    wcagLevel: 'A',
                    context: mb_substr($text, 0, 100),
                    helpUrl: null,
                    source: 'php',
                );
            }
        }
        return $issues;
    }

    private function checkDecorativeImage(DOMXPath $xpath): array
    {
        $issues = [];
        $nodes = $xpath->query('//img[@alt=""]');
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $role = strtolower($node->getAttribute('role'));
            $ariaHidden = strtolower($node->getAttribute('aria-hidden'));
            if (!in_array($role, ['presentation', 'none'], true) && $ariaHidden !== 'true') {
                $issues[] = IssueModel::make(
                    ruleId: 'potential:decorative-image',
                    severity: 'notice',
                    message: 'Is this image purely decorative? If so, add role="presentation" or aria-hidden="true". If it conveys meaning, add descriptive alt text.',
                    wcagCriterion: '1.1.1',
                    wcagLevel: 'A',
                    // Same 300 cap as short-alt: see the comment there.
                    context: $this->outerHtml($node, 300),
                    helpUrl: null,
                    source: 'php',
                );
            }
        }
        return $issues;
    }

    private function checkPossibleHeading(DOMXPath $xpath): array
    {
        $issues = [];
        // <p> whose only child content is entirely wrapped in <strong> or <b>
        $nodes = $xpath->query('//p[count(*) = 1 and (strong or b)][not(text()[normalize-space() != ""])]');
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            $wordCount = str_word_count($text);
            if ($wordCount >= 1 && $wordCount <= 10 && strlen($text) > 0) {
                $issues[] = IssueModel::make(
                    ruleId: 'potential:possible-heading',
                    severity: 'notice',
                    message: 'Should this bold paragraph be a heading? Short bold text that stands alone often functions as a heading and should use h2–h6.',
                    wcagCriterion: '1.3.1',
                    wcagLevel: 'A',
                    context: $text,
                    helpUrl: null,
                    source: 'php',
                );
            }
        }
        return $issues;
    }

    private function checkTableLayout(DOMXPath $xpath): array
    {
        $issues = [];
        $nodes = $xpath->query(
            '//table[not(.//th) and not(.//caption) and not(@role="presentation") and not(@role="none")]'
        );
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $issues[] = IssueModel::make(
                ruleId: 'potential:table-layout',
                severity: 'notice',
                message: 'Is this a data table or a layout table? Data tables need <th> headers; layout tables should use role="presentation".',
                wcagCriterion: '1.3.1',
                wcagLevel: 'A',
                context: '<table class="' . htmlspecialchars($node->getAttribute('class')) . '">',
                helpUrl: null,
                source: 'php',
            );
        }
        return $issues;
    }

    private function checkVideoNoAudioDesc(DOMXPath $xpath): array
    {
        $issues = [];
        $nodes = $xpath->query('//video');
        foreach ($nodes as $node) {
            $descTracks = $xpath->query('.//track[@kind="descriptions" or @kind="description"]', $node);
            if ($descTracks->length === 0) {
                $issues[] = IssueModel::make(
                    ruleId: 'potential:video-audio-desc',
                    severity: 'notice',
                    message: 'Does this video need an audio description? Videos with visual-only information require audio description for blind users.',
                    wcagCriterion: '1.2.5',
                    wcagLevel: 'AA',
                    context: $this->outerHtml($node, 80),
                    helpUrl: null,
                    source: 'php',
                );
            }
        }
        return $issues;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function isDecorative(DOMElement $node): bool
    {
        $role = strtolower($node->getAttribute('role'));
        return in_array($role, ['presentation', 'none'], true)
            || strtolower($node->getAttribute('aria-hidden')) === 'true';
    }

    private function outerHtml(DOMNode $node, int $maxLen = 150): string
    {
        $doc = new DOMDocument();
        $doc->appendChild($doc->importNode($node, true));
        $html = trim($doc->saveHTML());
        // mb_, not byte functions: a byte cut can split a multibyte character,
        // and the invalid sequence would abort the issue INSERT on strict-mode
        // MySQL, taking the whole scan transaction with it.
        return mb_strlen($html) > $maxLen ? mb_substr($html, 0, $maxLen) . '…' : $html;
    }
}
