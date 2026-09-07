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
use johnhenry\accessibilityaudit\helpers\InertMarkup;
use johnhenry\accessibilityaudit\helpers\LinkContext;
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

        // Nor should markup the browser never renders. An unrendered template
        // asking a person a question is worse than a false positive: they
        // cannot answer it by looking at the page.
        InertMarkup::removeFrom($xpath);

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

    /**
     * @var int The length past which alt text is worth a second look. Longer
     *      than most screen readers will read out in one breath, and past the
     *      point where the detail belongs in the page rather than in an
     *      attribute nobody can skim.
     *
     *      A guideline, not a cap. Craft's own alt field sets no limit and
     *      neither does this plugin: text is counted and flagged, never cut.
     *      Public so the editing field counts against the same number the
     *      rule reports on.
     */
    public const MAX_ALT_LENGTH = 150;

    private function checkLongAlt(DOMXPath $xpath): array
    {
        $issues = [];
        $nodes = $xpath->query('//img[@alt and string-length(@alt) > ' . self::MAX_ALT_LENGTH . ']');
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            // The number, not just the verdict. Trimming to a limit means
            // knowing how far over it is, and counting a truncated preview by
            // eye is the part nobody wants to do twice.
            $length = mb_strlen($node->getAttribute('alt'));
            $over = $length - self::MAX_ALT_LENGTH;

            $issues[] = IssueModel::make(
                ruleId: 'potential:long-alt',
                severity: 'notice',
                message: sprintf(
                    'Is this image alt text too long? It is %d characters, %d over the %d guideline. '
                        . 'Consider moving the detail to aria-describedby.',
                    $length,
                    $over,
                    self::MAX_ALT_LENGTH,
                ),
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

    /**
     * Links that read the same but go to different places.
     *
     * 2.4.4 is satisfied when the context tells them apart, so the verdict
     * depends on where each one sits. Same context with nothing to separate
     * them is a failure. Different landmarks, one of them unnamed, is also a
     * failure, and the missing name is the fix. Different named landmarks pass
     * at AA and are still a problem in a screen reader's links list, which
     * strips context away and shows two identical entries, so that is reported
     * as advice rather than as a breach.
     *
     * Reporting all three at one weight is what teaches people to clear the
     * queue without reading it, and that is how the real ones get missed.
     *
     * @param DOMXPath $xpath The parsed page.
     * @return IssueModel[]
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function checkIdenticalLinks(DOMXPath $xpath): array
    {
        $issues = [];
        $linkMap = [];
        $siteHosts = $this->siteHosts();

        foreach ($xpath->query('//a[@href]') as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            // Markup that cannot be reached at the same time as its twin is not
            // a duplicate anybody experiences.
            if (LinkContext::isHidden($node)) {
                continue;
            }

            // The announced name, not the visible text: an icon link named only
            // by aria-label is exactly the case this rule is for.
            $name = AccessibleName::for($node, $xpath);
            $href = trim($node->getAttribute('href'));

            if ($name === '' || $href === '' || str_starts_with($href, '#')) {
                continue;
            }

            // Compared case-insensitively and whitespace-collapsed, since a
            // reader hears no difference. The first spelling seen is kept for
            // the report, because that is what the author will recognise.
            $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
            $target = $this->normaliseHref($href, $siteHosts);

            $linkMap[$key]['name'] ??= $name;
            // Keyed by resolved destination so the same target written two ways
            // counts once, and only the first link to each is kept: a repeated
            // destination is not what this rule is about.
            $linkMap[$key]['links'][$target] ??= ['href' => $href, 'node' => $node];
        }

        foreach ($linkMap as $group) {
            // A name seen once has one destination and is not this rule's
            // business; two or more means the same words go to different places.
            if (count($group['links']) < 2) {
                continue;
            }

            $issues[] = $this->identicalLinkIssue((string)$group['name'], $group['links'], $xpath);
        }

        return $issues;
    }

    /**
     * Builds one finding for a set of links sharing an announced name.
     *
     * @param string $name The name they share.
     * @param array<string, array{href: string, node: DOMElement}> $links Keyed by destination.
     * @param DOMXPath $xpath The document.
     * @return IssueModel
     */
    private function identicalLinkIssue(string $name, array $links, DOMXPath $xpath): IssueModel
    {
        $lines = [];
        $places = [];
        $strengths = [];
        $contexts = [];

        // Do the destinations differ in the way that matters? Two links to
        // different sections of one document are a tidiness question, not
        // somebody being sent somewhere they did not expect, and in
        // documentation that is most of what this rule finds.
        $documents = array_unique(array_map(
            static fn(string $target): string => explode('#', $target, 2)[0],
            array_keys($links),
        ));
        $sameDocument = count($documents) === 1;

        foreach ($links as $link) {
            $context = LinkContext::for($link['node'], $xpath);
            $contexts[] = $context;
            $strengths[] = LinkContext::strength($context, $name);
            $lines[] = '  ' . $context['label'] . ' → ' . $link['href'];

            // Identity of the place, for deciding whether anything separates
            // them: the landmark element itself, plus the heading above.
            $places[] = ($context['landmark'] !== null ? spl_object_id($context['landmark']) : 0)
                . '|' . $context['heading'];
        }

        $separated = count(array_unique($places)) === count($places);

        // A pair is only as distinguishable as its weaker side. One link under
        // a named landmark and one under an unnamed region is not two links a
        // reader can tell apart: it is one they can and one they cannot.
        $weakest = in_array(LinkContext::STRENGTH_NONE, $strengths, true)
            ? LinkContext::STRENGTH_NONE
            : (in_array(LinkContext::STRENGTH_WEAK, $strengths, true)
                ? LinkContext::STRENGTH_WEAK
                : LinkContext::STRENGTH_STRONG);

        if ($sameDocument) {
            $verdict = 'These go to the same page, different sections. Nobody is sent anywhere they did '
                . 'not expect, so this is not the WCAG 2.4.4 Link Purpose (In Context) failure it looks '
                . 'like from the URLs alone. It is still worth tidying: in a screen reader links list, '
                . 'stripped of everything around them, both read the same with no way to tell which part '
                . 'of the page each goes to.';
            $criterion = '2.4.9';
            $level = 'AAA';
            $severity = 'notice';
        } elseif (!$separated) {
            $verdict = 'These sit in the same part of the page with nothing to tell them apart, which is '
                . 'the failure WCAG 2.4.4 Link Purpose (In Context) describes.';
            $criterion = '2.4.4';
            $level = 'A';
            $severity = 'warning';
        } elseif ($weakest === LinkContext::STRENGTH_NONE) {
            $verdict = 'These are in different parts of the page, but at least one of those parts has '
                . 'nothing to announce that separates them: no name on the region, and no heading above '
                . 'the link that says anything the link text does not already say. That leaves WCAG 2.4.4 '
                . 'Link Purpose (In Context) failing.';
            $criterion = '2.4.4';
            $level = 'A';
            $severity = 'warning';
        } elseif ($weakest === LinkContext::STRENGTH_WEAK) {
            $verdict = 'One of these leans on the heading above it rather than on a named region. A '
                . 'heading has to be reached to be any use, and a reader moving link to link never '
                . 'reaches it, so this is weaker than it looks and worth fixing rather than passing.';
            $criterion = '2.4.4';
            $level = 'A';
            $severity = 'warning';
        } else {
            $verdict = 'These are in different named parts of the page, so they satisfy WCAG 2.4.4 at AA. '
                . 'They are still identical in a screen reader links list, which strips that context away '
                . 'and shows both entries reading the same, so it is worth fixing under WCAG 2.4.9 Link '
                . 'Purpose (Link Only).';
            $criterion = '2.4.9';
            $level = 'AAA';
            $severity = 'notice';
        }

        $headline = $sameDocument
            ? sprintf('"%s" goes to %d different sections of the same page.', $name, count($links))
            : sprintf('"%s" goes to %d different places.', $name, count($links));

        if ($sameDocument) {
            return IssueModel::make(
                ruleId: 'potential:identical-links',
                severity: $severity,
                message: $headline . "\n" . implode("\n", $lines) . "\n\n" . $verdict . "\n\n"
                    . "How to tidy it up:\n"
                    . '  1. Name the section in the link text, so one reads "' . $name . ', overview" '
                    . 'rather than the same words twice.' . "\n"
                    . '  2. Or drop the fragment from one of them, if both were only ever meant to reach '
                    . 'the page itself.',
                wcagCriterion: $criterion,
                wcagLevel: $level,
                context: $this->identicalLinkContext($name, $links),
                helpUrl: null,
                source: 'php',
            );
        }

        $message = $headline . "\n"
            . implode("\n", $lines) . "\n\n"
            . $verdict . "\n\n"
            . "How to fix it, best first:\n"
            . "  1. Change the visible text so the two read differently. Everyone benefits and no ARIA is involved.\n";

        // Where a side is weak because its region has no name, naming the
        // region is one attribute and settles every ambiguous link inside it
        // at once, without touching any link's announced name. On a real page
        // that beats editing each link by a distance, so it goes second.
        $unnamed = $this->unnamedLandmarks($contexts);

        if ($unnamed !== []) {
            $message .= '  2. Give the unnamed region a name, with aria-label on it. One attribute, and it '
                . 'settles every ambiguous link inside that region at once, without changing what any link '
                . "announces:\n";

            foreach ($unnamed as $description => $count) {
                $message .= '       ' . $description . ' — ' . $count . ' flagged link'
                    . ($count === 1 ? '' : 's') . " in here\n";
            }

            $message .= '  3. If the visible text has to stay, add to the announced name inside the link '
                . 'with visually hidden text: <a href="...">' . $name
                . '<span class="sr-only">, API reference</span></a>.';
        } else {
            $message .= '  2. If the visible text has to stay, add to the announced name inside the link '
                . 'with visually hidden text: <a href="...">' . $name
                . '<span class="sr-only">, API reference</span></a>.' . "\n"
                . '  3. Name the part of the page it sits in, with aria-label on the surrounding landmark.';
        }

        $message .= "\n\n" . 'Do not reach for aria-label on the link itself. It replaces the announced name '
            . 'rather than adding to it, so a voice-control user can no longer say "click ' . $name . '", '
            . 'which breaks WCAG 2.5.3 Label in Name, and many translation tools skip it. If you use it '
            . 'anyway, the visible text has to still appear inside it word for word.';

        return IssueModel::make(
            ruleId: 'potential:identical-links',
            severity: $severity,
            message: $message,
            wcagCriterion: $criterion,
            wcagLevel: $level,
            context: $this->identicalLinkContext($name, $links),
            helpUrl: null,
            source: 'php',
        );
    }

    /**
     * The unnamed regions these links sit in, and how many are in each.
     *
     * A report saying "the region has no name" is no use on a page with four
     * of them. Naming the element, and saying how many of the flagged links it
     * holds, is what turns the advice into an edit somebody can make.
     *
     * @param array<int, array{name: string, heading: string, landmark: DOMElement|null}> $contexts
     *        The resolved context of each link in the group.
     * @return array<string, int> Keyed by the region's markup, valued by count.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function unnamedLandmarks(array $contexts): array
    {
        $found = [];

        foreach ($contexts as $context) {
            if ($context['landmark'] === null || trim($context['name']) !== '') {
                continue;
            }

            $description = LinkContext::describe($context['landmark']);

            if ($description === '') {
                continue;
            }

            $found[$description] = ($found[$description] ?? 0) + 1;
        }

        return $found;
    }

    /**
     * The context string for a set of same-named links.
     *
     * Deliberately unchanged in shape. This string is hashed to key the
     * author's ruling on the question, so rewording it would quietly discard
     * every dismissal already made against this rule.
     *
     * @param string $name The name the links share.
     * @param array<string, array{href: string, node: DOMElement}> $links Keyed by destination.
     * @return string
     */
    private function identicalLinkContext(string $name, array $links): string
    {
        return '"' . $name . '" → ' . implode(', ', array_slice(array_column($links, 'href'), 0, 3));
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
