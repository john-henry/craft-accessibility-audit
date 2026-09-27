<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\Site;
use DateTime;
use DOMDocument;
use DOMXPath;
use Exception;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use johnhenry\accessibilityaudit\helpers\Anthropic;
use johnhenry\accessibilityaudit\helpers\PlainLanguage;
use johnhenry\accessibilityaudit\helpers\UrlSafety;
use Throwable;
use yii\base\Component;

/**
 * Readability analysis service.
 *
 * Calculates Flesch-Kincaid Reading Ease and Grade Level from page content,
 * identifies the most complex sentences, and optionally uses Claude Haiku to
 * produce plain-language suggestions for those sentences.
 *
 * WCAG relevance: Success Criterion 3.1.5 (Reading Level, AAA) recommends that
 * content not require reading ability beyond lower secondary education level
 * (~Grade 9 / reading age ~14).
 *
 * @phpstan-import-type PlainSentence from PlainLanguage
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class ReadabilityService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var int The least text, in characters, an analysis is run on.
     */
    public const MIN_CHARACTERS = 100;

    /**
     * @var int How long, in seconds, a user's latest plain-language suggestions
     *      for an element are kept for the editor to show again.
     */
    public const SUGGESTIONS_TTL = 3600;

    /**
     * @var int How many levels of nested entries (a Matrix entry inside a
     *      Matrix entry) text is gathered from.
     */
    private const MAX_NESTING = 4;

    /**
     * @var string Commerce's product element class, whose variants' text is
     *      read with the product's.
     */
    private const COMMERCE_PRODUCT = 'craft\\commerce\\elements\\Product';

    /**
     * @var string The elements of a fetched page whose end is the end of a
     *      block of text, relative to the content element.
     */
    private const BLOCK_XPATH = './/p|.//h1|.//h2|.//h3|.//h4|.//h5|.//h6|.//li|.//dt|.//dd|.//blockquote'
        . '|.//figcaption|.//pre|.//td|.//th|.//caption|.//div|.//section|.//article|.//summary|.//button';

    /**
     * @var string[] The field types whose values are read as an entry's text.
     *      Anything else in a layout (lightswitches, dropdowns, numbers, links,
     *      dates) is a setting or a label rather than prose. Listed by name so
     *      the plugin does not need CKEditor or Redactor installed.
     */
    private const TEXT_FIELD_TYPES = [
        'craft\\fields\\PlainText',
        'craft\\ckeditor\\Field',
        'craft\\redactor\\Field',
    ];

    /**
     * @var int The fewest words a sentence needs before the readability preview
     *      marks it. A shorter sentence is left alone however long its words.
     */
    public const HARD_SENTENCE_MIN_WORDS = 14;


    /**
     * @var int The reading level, from the Automated Readability Index worked
     *      out for the sentence on its own, at which a sentence is marked hard
     *      to read.
     */
    public const HARD_SENTENCE_LEVEL = 10;

    /**
     * @var int The reading level at which a sentence is marked very hard to read.
     */
    public const VERY_HARD_SENTENCE_LEVEL = 14;

    /**
     * @var string The reading target the preview marks sentences against unless
     *      another is chosen.
     */
    public const DEFAULT_TARGET = 'default';

    /**
     * @var array<string, array{hard: int, veryHard: int}> The reading levels at
     *      which the preview marks a sentence hard and very hard to read, for
     *      each target. Accessible marks anything past grade 9, the lower
     *      secondary level WCAG 3.1.5 asks for; technical allows for academic
     *      and specialist writing.
     */
    public const TARGETS = [
        'accessible' => ['hard' => 8, 'veryHard' => 10],
        self::DEFAULT_TARGET => ['hard' => self::HARD_SENTENCE_LEVEL, 'veryHard' => self::VERY_HARD_SENTENCE_LEVEL],
        'technical' => ['hard' => 13, 'veryHard' => 17],
    ];

    // Public API
    // =========================================================================

    /**
     * Whether readability can be scored for a site.
     *
     * The scores are Flesch-Kincaid, whose formula is built for English, and
     * the text handling reads English letters and sentence starts, so on any
     * other language the numbers would mean nothing.
     *
     * @param int|null $siteId The site, or null for a URL with no site.
     * @return bool Whether the site's language is English.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function supportsSite(?int $siteId): bool
    {
        if ($siteId === null) {
            return true;
        }

        $site = Craft::$app->getSites()->getSiteById($siteId, true);

        return $site === null || str_starts_with(strtolower($site->language), 'en');
    }

    /**
     * What readers are told when a site's language can't be scored.
     *
     * @return string The message.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function unsupportedMessage(): string
    {
        return Craft::t('accessibility-audit', 'Readability scores are for English text only, so they are not worked out for this site.');
    }

    /**
     * Analyse an element as readers get it: from its own text fields, or from
     * its rendered page when those hold too little text.
     *
     * @param ElementInterface $element The element.
     * @param bool $withClaude Whether to add plain-language suggestions from Claude.
     * @return array{readingEase: float, gradeLevel: float, ...}|array{error: string}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function analyseElement(ElementInterface $element, bool $withClaude = false): array
    {
        if (!$this->supportsSite((int)$element->siteId)) {
            return ['error' => $this->unsupportedMessage()];
        }

        $text = $this->_extractElementText($element);

        if (mb_strlen($text) < self::MIN_CHARACTERS) {
            $url = $element->getUrl();
            if ($url !== null) {
                return $this->analyseUrl($url, $withClaude);
            }
            return ['error' => Craft::t('accessibility-audit', 'Not enough text content found in this entry\'s fields.')];
        }

        return $this->analyseText($text, $withClaude);
    }

    /**
     * Analyse plain text for readability: the scores and the longest sentences,
     * worked out here without fetching anything.
     *
     * @param string $text The text, with any markup already removed.
     * @param bool $withClaude Whether to add plain-language suggestions from Claude.
     * @return array{readingEase: float, gradeLevel: float, ...}|array{error: string}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function analyseText(string $text, bool $withClaude = false): array
    {
        return $this->_analyse(trim($text), '', $withClaude, true);
    }

    /**
     * Analyse the text an element's own fields hold, as the element holds it.
     *
     * Made for text still being written: a draft, or an entry with unsaved
     * edits. It never falls back to fetching the published page, which is not
     * the text being written, and with too little text it says how much more is
     * needed rather than failing.
     *
     * @param ElementInterface $element The element, draft or otherwise.
     * @param bool $withClaude Whether to add plain-language suggestions from Claude.
     * @return array{readingEase: float, gradeLevel: float, ...}|array{error: string, charactersNeeded?: int}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function analyseElementText(ElementInterface $element, bool $withClaude = false): array
    {
        if (!$this->supportsSite((int)$element->siteId)) {
            return ['error' => $this->unsupportedMessage()];
        }

        return $this->_analyseOwnText($this->_extractElementText($element), $withClaude);
    }

    /**
     * The readability of an element's own text as it stands, together with that
     * text broken into paragraphs and sentences for the Readability preview.
     *
     * @param ElementInterface $element The element, draft or otherwise.
     * @param string $target The key of the reading target in TARGETS to mark
     *        sentences against.
     * @return array{result: array<string, mixed>, blocks: array<int, mixed>, counts: array<string, int>}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function previewElementText(ElementInterface $element, string $target = self::DEFAULT_TARGET): array
    {
        if (!$this->supportsSite((int)$element->siteId)) {
            return [
                'result' => ['error' => $this->unsupportedMessage()],
                'blocks' => [],
                'counts' => ['sentences' => 0, 'hard' => 0, 'veryHard' => 0],
            ];
        }

        $text = $this->_extractElementText($element);
        $blocks = $this->previewBlocks($text, $target);

        // Every line counts as a sentence here, headings and labels included,
        // since the preview shows them all.
        $counts = PlainLanguage::counts($blocks) + ['sentences' => 0, 'hard' => 0, 'veryHard' => 0];

        foreach ($blocks as $sentences) {
            foreach ($sentences as $sentence) {
                $counts['sentences']++;

                if ($sentence['difficulty'] !== null) {
                    $counts[$sentence['difficulty']]++;
                }
            }
        }

        return [
            'result' => $this->_analyseOwnText($text, false),
            'blocks' => $blocks,
            'counts' => $counts,
        ];
    }

    /**
     * Breaks text into paragraphs of sentences, marking each one `hard` or
     * `veryHard` to read.
     *
     * A sentence gets a reading level of its own from the Automated Readability
     * Index, `4.71 × letters per word + 0.5 × words − 21.43`, so a long sentence
     * of short words is not marked and a dense one is. Below
     * HARD_SENTENCE_MIN_WORDS words it is not marked at all.
     *
     * Every sentence is kept, headings and short lines included, since this is
     * the text as it will be read rather than what the scores count.
     *
     * @param string $text Text with paragraphs separated by blank lines.
     * @param string $target The key of the reading target in TARGETS to mark
     *        sentences against. An unknown key falls back to the default.
     * @return array<int, array<int, PlainSentence>> Sentences, grouped by paragraph.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function previewBlocks(string $text, string $target = self::DEFAULT_TARGET): array
    {
        $levels = self::TARGETS[$target] ?? self::TARGETS[self::DEFAULT_TARGET];
        $blocks = [];

        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $block) {
            $block = trim((string)preg_replace('/\s+/', ' ', $block));

            if ($block === '') {
                continue;
            }

            $sentences = [];

            foreach (preg_split('/(?<=[.!?])\s+(?=[A-Z"\'])/', $block) ?: [] as $sentence) {
                $words = str_word_count($sentence);
                $letters = (int)preg_match_all('/[A-Za-z]/', $sentence);
                $level = $words > 0 ? (int)round(4.71 * ($letters / $words) + 0.5 * $words - 21.43) : 0;

                $sentences[] = [
                    'text' => $sentence,
                    'words' => $words,
                    'level' => $level,
                    'segments' => PlainLanguage::segments($sentence),
                    'difficulty' => match (true) {
                        $words < self::HARD_SENTENCE_MIN_WORDS => null,
                        $level >= $levels['veryHard'] => 'veryHard',
                        $level >= $levels['hard'] => 'hard',
                        default => null,
                    },
                ];
            }

            $blocks[] = $sentences;
        }

        return $blocks;
    }

    /**
     * Keeps the current user's latest plain-language suggestions for an
     * element, so the editor can show them again after its content refreshes.
     *
     * Held in the cache, never in the readability table: suggestions made for
     * a draft say nothing about the published page.
     *
     * @param int $elementId The canonical element the suggestions were made for,
     *        so they outlast the draft ids an entry goes through while edited.
     * @param int $siteId The element's site.
     * @param array<string, mixed> $suggestions The `claude` part of an analysis result.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function cacheSuggestions(int $elementId, int $siteId, array $suggestions): void
    {
        $userId = Craft::$app->getUser()->getId();

        if ($userId === null) {
            return;
        }

        Craft::$app->getCache()->set(
            $this->_suggestionsKey((int)$userId, $elementId, $siteId),
            ['claude' => $this->_normaliseSuggestions($suggestions), 'time' => time()],
            self::SUGGESTIONS_TTL,
        );
    }

    /**
     * The current user's latest cached suggestions for an element, if any.
     *
     * @param int $elementId The element.
     * @param int $siteId The element's site.
     * @return array{claude: array<string, mixed>, time: int}|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function getCachedSuggestions(int $elementId, int $siteId): ?array
    {
        $userId = Craft::$app->getUser()->getId();

        if ($userId === null) {
            return null;
        }

        $cached = Craft::$app->getCache()->get($this->_suggestionsKey((int)$userId, $elementId, $siteId));

        return is_array($cached) && isset($cached['claude'], $cached['time']) ? $cached : null;
    }

    /**
     * Fetch a URL and return a full readability analysis.
     *
     * @return array{readingEase: float, gradeLevel: float, ...}|array{error: string}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     * @param string $url The page to read.
     * @param bool $withClaude Whether to ask Claude for suggestions as well.
     */
    public function analyseUrl(string $url, bool $withClaude = false): array
    {
        // SSRF guard: reject private/reserved hosts and non-http(s) schemes.
        try {
            UrlSafety::assertSafeUrl($url);
        } catch (UnsafeUrlException $e) {
            // The guard throws plain strings: it is a helper and has no view of
            // the request's language. Translated here, where the message turns
            // into something a person reads, as every other error this returns
            // already is.
            return ['error' => Craft::t('accessibility-audit', $e->getMessage())];
        }

        try {
            $clientConfig = [
                'timeout' => UrlSafety::FETCH_TIMEOUT,
                // Same scanner UA as the audit fetch, so one WAF allow-list
                // rule covers every scanner request this plugin makes.
                'headers' => [
                    'User-Agent' => AccessibilityAudit::getInstance()->getSettings()->getFetchUserAgent(),
                ],
            ];

            $response = UrlSafety::fetch($url, $clientConfig);
            $html = (string) $response->getBody();
        } catch (Throwable $e) {
            // Logged so a recurring fetch failure is findable later; the client
            // gets a generic message so no internal path or TLS/DNS detail leaks
            // (see security.md).
            Craft::warning("Readability fetch of {$url} failed: " . $e->getMessage(), 'accessibility-audit');
            return ['error' => Craft::t('accessibility-audit', 'Could not fetch that URL. Check it is reachable and try again.')];
        }

        return $this->analyseHtml($html, $url, $withClaude);
    }

    /**
     * Analyse raw HTML for readability.
     *
     * @return array{readingEase: float, gradeLevel: float, ...}|array{error: string}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     * @param string $html The rendered page.
     * @param string $sourceUrl The address it came from, for the stored record.
     * @param bool $withClaude Whether to ask Claude for suggestions as well.
     */
    public function analyseHtml(string $html, string $sourceUrl = '', bool $withClaude = false): array
    {
        // A page that says what language it is in is taken at its word.
        if (
            preg_match('/<html\b[^>]*\blang\s*=\s*["\']?([a-z]{2,3})/i', $html, $lang) === 1
            && strtolower($lang[1]) !== 'en'
        ) {
            return ['error' => $this->unsupportedMessage()];
        }

        return $this->_analyse($this->_extractText($html), $sourceUrl, $withClaude, true);
    }

    /**
     * Analyses an element as readers get it and stores the result against it.
     *
     * Scored from the element's own text fields, or from its rendered page when
     * those hold too little text. Nothing is stored when it cannot be scored.
     *
     * @param ElementInterface $element A saved element with a URL.
     * @return array{readingEase: float, gradeLevel: float, ...}|array{error: string}
     * @throws \yii\db\Exception
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function analyseAndStoreElement(ElementInterface $element): array
    {
        $result = $this->analyseElement($element);

        if (!isset($result['error'])) {
            $this->storeResult($result, (int)$element->id, (int)$element->siteId, (string)$element->getUrl(), (string)($element->title ?? ''));
        }

        return $result;
    }

    /**
     * Scores an element from its own text fields and stores the result against
     * it, for keeping the Readability report current as entries are saved.
     *
     * Nothing is fetched, so it is quick enough to run during a save. An
     * element with too little text of its own, a page built in templates, is
     * left for a full analysis and its stored result, if any, is kept.
     *
     * @param ElementInterface $element A saved element with a URL.
     * @return bool Whether a result was stored.
     * @throws \yii\db\Exception
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function recordElementText(ElementInterface $element): bool
    {
        if (!AccessibilityAudit::getInstance()->isPro()) {
            return false;
        }

        $result = $this->analyseElementText($element);

        if (isset($result['error'])) {
            return false;
        }

        $this->storeResult($result, (int)$element->id, (int)$element->siteId, (string)$element->getUrl(), (string)($element->title ?? ''));

        return true;
    }

    // Persistence
    // =========================================================================

    /**
     * Persist a readability result to {{%accessibilityaudit_readability}}.
     *
     * One row per element and site, written as a single upsert so two writers
     * at once cannot both insert; a result with no element is kept per URL.
     *
     * @param array<string, mixed> $result An analysis result.
     * @param int|null $elementId The element the result belongs to, if any.
     * @param int|null $siteId The element's site.
     * @param string $url The page's URL.
     * @param string $title The page's title.
     * @throws \yii\db\Exception
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function storeResult(
        array $result,
        ?int $elementId = null,
        ?int $siteId = null,
        string $url = '',
        string $title = '',
    ): void {
        $now = Db::prepareDateForDb(new DateTime());

        $data = [
            'elementId' => $elementId,
            'siteId' => $siteId,
            'url' => $url,
            'title' => $title ?: null,
            'readingEase' => $result['readingEase'] ?? null,
            'readingEaseLabel' => $result['readingEaseLabel'] ?? null,
            'gradeLevel' => $result['gradeLevel'] ?? null,
            'readingAge' => $result['readingAge'] ?? null,
            'wordCount' => $result['wordCount'] ?? null,
            'sentenceCount' => $result['sentenceCount'] ?? null,
            'avgWordsPerSentence' => $result['avgWordsPerSentence'] ?? null,
            'hardSentences' => $result['hardSentences'] ?? null,
            'veryHardSentences' => $result['veryHardSentences'] ?? null,
            'wcag315Pass' => (int) ($result['wcag315Pass'] ?? false),
            'dateAnalysed' => $now,
            'dateUpdated' => $now,
        ];

        if ($elementId !== null && $siteId !== null) {
            Db::upsert(
                '{{%accessibilityaudit_readability}}',
                $data + ['uid' => StringHelper::UUID(), 'dateCreated' => $now],
                $data,
            );

            return;
        }

        $existing = (new Query())->from(['{{%accessibilityaudit_readability}}'])->where(['elementId' => null, 'url' => $url])->one();

        if ($existing) {
            Craft::$app->getDb()->createCommand()
                ->update('{{%accessibilityaudit_readability}}', $data, ['id' => $existing['id']])
                ->execute();
        } else {
            $data['uid'] = StringHelper::UUID();
            $data['dateCreated'] = $now;
            Craft::$app->getDb()->createCommand()
                ->insert('{{%accessibilityaudit_readability}}', $data)
                ->execute();
        }
    }

    /**
     * A page of stored results for the CP table, with search and sorting.
     *
     * Separate from {@see getResults()}, which answers "the latest result for
     * this element" for the sidebar and the entry panel. This one exists to
     * feed a paginated table, so it counts the full set and returns only the
     * rows asked for rather than capping at an arbitrary limit.
     *
     * @param int $siteId The site to read.
     * @param int $page 1-indexed page number.
     * @param int $perPage Rows per page.
     * @param string $search Matches the page title or URL.
     * @param string $orderBy A whitelisted column to sort on, or `worst` for failing pages first, hardest to read at the top.
     * @param int $orderDir SORT_ASC or SORT_DESC.
     * @param int|null $elementId Only this element's result, when set.
     * @return array{results: array<int, array<string, mixed>>, total: int}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getResultsPaged(
        int $siteId,
        int $page = 1,
        int $perPage = 100,
        string $search = '',
        string $orderBy = 'worst',
        int $orderDir = SORT_DESC,
        ?int $elementId = null,
    ): array {
        // Whitelisted: the sort column arrives from a query parameter and would
        // otherwise be interpolated straight into ORDER BY. `worst` puts the
        // pages failing WCAG 3.1.5 first, hardest to read at the top.
        $sortable = ['title', 'readingEase', 'gradeLevel', 'wordCount', 'hardSentences', 'veryHardSentences', 'wcag315Pass', 'dateAnalysed'];
        $order = in_array($orderBy, $sortable, true)
            ? ['r.' . $orderBy => $orderDir, 'r.id' => SORT_ASC]
            : ['r.wcag315Pass' => SORT_ASC, 'r.gradeLevel' => SORT_DESC, 'r.id' => SORT_ASC];

        $query = $this->_liveResultsQuery()
            ->select(['r.*'])
            ->andWhere(['r.siteId' => $siteId]);

        if ($elementId !== null) {
            $query->andWhere(['r.elementId' => $elementId]);
        }

        if ($search !== '') {
            $query->andWhere([
                'or',
                ['like', 'r.title', $search],
                ['like', 'r.url', $search],
            ]);
        }

        $total = (int) (clone $query)->count();

        $rows = $query
            ->orderBy($order)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return ['results' => array_map([$this, '_castRow'], $rows), 'total' => $total];
    }

    /**
     * Matches a URL against this install's sites and, if it falls within one,
     * resolves it to the Craft element that owns that URI.
     *
     * Lets the general "Analyse a page" tool store its result against the
     * matching element (like an entry's own field would), instead of always
     * falling back to a URL-only row that an element-scoped lookup can't find.
     *
     * @param string $url
     * @return array{elementId: int, siteId: int}|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function resolveElementForUrl(string $url): ?array
    {
        $match = $this->_siteMatchForUrl($url);

        if ($match === null) {
            return null;
        }

        $uri = trim($match['uri'], '/');
        $element = Craft::$app->getElements()->getElementByUri($uri, $match['site']->id);

        if (!$element || !$element->id) {
            return null;
        }

        return ['elementId' => $element->id, 'siteId' => $match['site']->id];
    }

    /**
     * The site of this install a URL belongs to, if any.
     *
     * The URL has to share the site's scheme, host and port exactly, and sit
     * at or under its base path on a segment boundary, so a lookalike host
     * such as `example.com.evil.test` or a sibling path never counts.
     *
     * @param string $url An absolute URL.
     * @return Site|null The best matching site: the one with the longest base path.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function siteForUrl(string $url): ?Site
    {
        return $this->_siteMatchForUrl($url)['site'] ?? null;
    }

    /**
     * Return stored readability results, newest first.
     *
     * @param int $limit
     * @param int|null $elementId Restrict to a single element's history (e.g. deep-linked from that element's field).
     * @param int|null $siteId Paired with `$elementId` to scope to one site's version of the element. When
     *        `$elementId` and `$url` are both null, scopes the general listing to this site instead: rows with
     *        no `siteId` (a URL analysed via the general tool that didn't resolve to one of this install's own
     *        elements) are excluded, since they don't belong to any site's view.
     * @param string|null $url Restrict to a URL-keyed result instead, used as a fallback when a page was
     *        analysed via the general "Analyse a page" tool (stored with no `elementId`) rather than the
     *        element's own field, so that result still surfaces on the matching entry. Ignored if `$elementId` is set.
     * @return array<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getResults(int $limit = 100, ?int $elementId = null, ?int $siteId = null, ?string $url = null): array
    {
        $query = (new Query())
            ->from(['{{%accessibilityaudit_readability}}'])
            ->orderBy(['dateAnalysed' => SORT_DESC])
            ->limit($limit);

        if ($elementId !== null) {
            $query->andWhere(['elementId' => $elementId]);

            if ($siteId !== null) {
                $query->andWhere(['siteId' => $siteId]);
            }
        } elseif ($url !== null) {
            $query->andWhere(['url' => $url]);
        } elseif ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return array_map([$this, '_castRow'], $query->all());
    }

    /**
     * Casts a stored row's numeric columns.
     *
     * PDO can return numbers as strings depending on driver config, so callers
     * (including JSON-encoded rows handed to the CP table) get real numbers and
     * booleans, matching the shape the live analyse response returns.
     *
     * @param array<string, mixed> $row A raw database row.
     * @return array<string, mixed>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _castRow(array $row): array
    {
        return array_merge($row, [
            'readingEase' => (float) $row['readingEase'],
            'gradeLevel' => (float) $row['gradeLevel'],
            'readingAge' => (int) $row['readingAge'],
            'wordCount' => (int) $row['wordCount'],
            'sentenceCount' => (int) $row['sentenceCount'],
            'avgWordsPerSentence' => (float) $row['avgWordsPerSentence'],
            'hardSentences' => isset($row['hardSentences']) ? (int)$row['hardSentences'] : null,
            'veryHardSentences' => isset($row['veryHardSentences']) ? (int)$row['veryHardSentences'] : null,
            'wcag315Pass' => (bool) $row['wcag315Pass'],
        ]);
    }

    /**
     * Aggregate stats across stored results.
     *
     * @param int|null $siteId Restrict the aggregate to one site; rows with no `siteId` (URLs that didn't
     *        resolve to one of this install's own elements) are excluded when set, same as `getResults()`.
     *        Null aggregates across every site, kept as the default for any other/future caller.
     * @return array{total: int, avgEase: float|null, avgGrade: float|null, passingPct: int|null}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getStats(?int $siteId = null): array
    {
        // Worked out in the database, so a large site's results are never
        // loaded into memory just to be averaged.
        $query = $this->_liveResultsQuery()
            ->select([
                'total' => 'COUNT(*)',
                'sumEase' => 'SUM([[r.readingEase]])',
                'sumGrade' => 'SUM([[r.gradeLevel]])',
                'passing' => 'SUM(CASE WHEN [[r.wcag315Pass]] THEN 1 ELSE 0 END)',
            ]);

        if ($siteId !== null) {
            $query->andWhere(['r.siteId' => $siteId]);
        }

        $row = $query->one() ?: [];
        $total = (int)($row['total'] ?? 0);

        if ($total === 0) {
            return ['total' => 0, 'avgEase' => null, 'avgGrade' => null, 'passingPct' => null];
        }

        return [
            'total' => $total,
            'avgEase' => round((float)$row['sumEase'] / $total, 1),
            'avgGrade' => round((float)$row['sumGrade'] / $total, 1),
            'passingPct' => (int)round(((int)$row['passing'] / $total) * 100),
        ];
    }

    /**
     * Extract the <title> from an HTML string.
     *
     * @return string
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     * @param string $html The rendered page.
     */
    public function extractPageTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/is', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    // Private Methods
    // =========================================================================

    /**
     * Stored results, aliased `r`, leaving out those whose element is in the
     * trash. A deleted element's rows go with it; a trashed one's stay until
     * the trash is emptied, and should not count until it is restored.
     *
     * @return Query<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _liveResultsQuery(): Query
    {
        return (new Query())
            ->from(['r' => '{{%accessibilityaudit_readability}}'])
            ->leftJoin(['e' => '{{%elements}}'], '[[e.id]] = [[r.elementId]]')
            ->where(['or', ['r.elementId' => null], ['e.dateDeleted' => null]]);
    }

    /**
     * Keeps only the parts of Claude's reply the editor shows, as strings, so
     * an unexpected shape cannot break the page that prints them.
     *
     * @param array<string, mixed> $suggestions The `claude` part of an analysis result.
     * @return array{summary: string, suggestions: array<int, array{original: string, simplified: string, reason: string}>, jargon: string[]}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _normaliseSuggestions(array $suggestions): array
    {
        $string = static fn(mixed $value): string => is_scalar($value) ? (string)$value : '';

        $items = [];
        foreach (is_array($suggestions['suggestions'] ?? null) ? $suggestions['suggestions'] : [] as $item) {
            if (is_array($item)) {
                $items[] = [
                    'original' => $string($item['original'] ?? ''),
                    'simplified' => $string($item['simplified'] ?? ''),
                    'reason' => $string($item['reason'] ?? ''),
                ];
            }
        }

        $jargon = array_values(array_filter(
            array_map($string, is_array($suggestions['jargon'] ?? null) ? $suggestions['jargon'] : []),
            static fn(string $term): bool => $term !== '',
        ));

        return [
            'summary' => $string($suggestions['summary'] ?? ''),
            'suggestions' => $items,
            'jargon' => $jargon,
        ];
    }

    /**
     * The cache key for one user's suggestions for one element on one site.
     *
     * @param int $userId The user who asked for the suggestions.
     * @param int $elementId The element.
     * @param int $siteId The element's site.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _suggestionsKey(int $userId, int $elementId, int $siteId): string
    {
        return "accessibility-audit:readability-suggestions:{$userId}:{$elementId}:{$siteId}";
    }

    /**
     * Scores text and finds its longest sentences, adding Claude's suggestions
     * when they are asked for.
     *
     * @param string $text The text, with markup already removed.
     * @param string $sourceUrl The page the text came from, if any.
     * @param bool $withClaude Whether to add plain-language suggestions from Claude.
     * @param bool $paragraphsEndSentences Whether a paragraph break ends a
     *        sentence, so a heading or a label with no full stop is not joined
     *        to the sentence after it.
     * @return array{readingEase: float, gradeLevel: float, ...}|array{error: string}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _analyse(string $text, string $sourceUrl, bool $withClaude, bool $paragraphsEndSentences): array
    {
        // Characters, not bytes. Accented or non-Latin text runs to more
        // bytes than characters, so a byte count let text well short of the
        // minimum through and scored it anyway, while the message and the
        // constant both said characters.
        if (mb_strlen($text) < self::MIN_CHARACTERS) {
            return ['error' => Craft::t('accessibility-audit', 'Not enough text content to analyse (less than 100 characters found).')];
        }

        $scores = $this->_calculateScores($text, $paragraphsEndSentences);
        $sentences = $this->_extractSentences($text, $paragraphsEndSentences);

        $result = array_merge($scores, $this->_sentenceDifficulty($text), [
            'complexSentences' => $this->_findComplexSentences($sentences),
            'sourceUrl' => $sourceUrl,
        ]);

        if ($withClaude) {
            $settings = AccessibilityAudit::getInstance()->getSettings();
            $apiKey = trim(App::parseEnv($settings->anthropicApiKey));
            if ($apiKey !== '') {
                $claudeResult = $this->_analyseWithClaude($text, $apiKey);
                if ($claudeResult !== null) {
                    $result['claude'] = $claudeResult;
                }
            }
        }

        return $result;
    }

    /**
     * How many sentences are hard and very hard to read, counted the way the
     * Readability preview marks them, at the site's Readability Target.
     *
     * @param string $text The text, with paragraphs separated by blank lines.
     * @return array{hardSentences: int, veryHardSentences: int}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _sentenceDifficulty(string $text): array
    {
        $target = AccessibilityAudit::getInstance()->getSettings()->readabilityTarget;
        $counts = ['hard' => 0, 'veryHard' => 0];

        foreach ($this->previewBlocks($text, $target) as $sentences) {
            foreach ($sentences as $sentence) {
                if ($sentence['difficulty'] !== null) {
                    $counts[$sentence['difficulty']]++;
                }
            }
        }

        return [
            'hardSentences' => $counts['hard'],
            'veryHardSentences' => $counts['veryHard'],
        ];
    }

    /**
     * Matches a URL to the site it belongs to and the path left after that
     * site's base path.
     *
     * @param string $url An absolute URL.
     * @return array{site: Site, uri: string}|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _siteMatchForUrl(string $url): ?array
    {
        $target = $this->_urlOrigin($url);

        if ($target === null) {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $base = $this->_urlOrigin((string)$site->getBaseUrl());

            if ($base === null || $base['origin'] !== $target['origin']) {
                continue;
            }

            $basePath = rtrim($base['path'], '/');
            $matches = $basePath === ''
                || $target['path'] === $basePath
                || str_starts_with($target['path'], $basePath . '/');

            if ($matches && strlen($basePath) > $bestLength) {
                $best = ['site' => $site, 'uri' => substr($target['path'], strlen($basePath))];
                $bestLength = strlen($basePath);
            }
        }

        return $best;
    }

    /**
     * A URL's scheme, host and port as one comparable string, and its path.
     *
     * @param string $url An absolute URL.
     * @return array{origin: string, path: string}|null Null when it isn't an absolute http(s) URL.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _urlOrigin(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));

        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return [
            'origin' => $scheme . '://' . $host . ':' . $port,
            'path' => (string)($parts['path'] ?? ''),
        ];
    }

    /**
     * Analyses text gathered from an element's own fields, reporting how much
     * more is needed rather than failing when there is too little.
     *
     * @param string $text The element's text.
     * @param bool $withClaude Whether to add plain-language suggestions from Claude.
     * @return array{readingEase: float, gradeLevel: float, ...}|array{error: string, charactersNeeded?: int}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _analyseOwnText(string $text, bool $withClaude): array
    {
        $length = mb_strlen($text);

        if ($length < self::MIN_CHARACTERS) {
            return [
                'error' => Craft::t('accessibility-audit', 'Not enough text content found in this entry\'s fields.'),
                'charactersNeeded' => self::MIN_CHARACTERS - $length,
            ];
        }

        return $this->analyseText($text, $withClaude);
    }

    /**
     * Whether a field holds prose: text somebody reads as a passage, rather
     * than a setting or a label printed as a value.
     *
     * @param object $field The field.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _isTextField(object $field): bool
    {
        foreach (self::TEXT_FIELD_TYPES as $type) {
            if (is_a($field, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The enabled variants of a Commerce product, whose text fields are part
     * of the product's text.
     *
     * Commerce attaches variants through a layout element of its own rather
     * than a custom field, so walking the product's custom fields never reaches
     * them. Commerce is optional, so the product class is named as a string.
     *
     * @param ElementInterface $element Any element.
     * @return ElementInterface[] The product's enabled variants, or none.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _variantsOf(ElementInterface $element): array
    {
        if (!is_a($element, self::COMMERCE_PRODUCT)) {
            return [];
        }

        // Called by name so static analysis without Commerce installed does not
        // look for the method; is_a() above is the runtime guard.
        $getVariants = 'getVariants';

        try {
            // false leaves out disabled variants, which are not on the page.
            $variants = $element->$getVariants(false);
        } catch (Throwable) {
            return [];
        }

        $found = [];

        foreach ($variants as $variant) {
            $found[] = $variant;
        }

        return $found;
    }

    /**
     * The prose an element holds: its text fields, the text fields of the
     * entries nested in it, and a Commerce product's variants, one block per
     * field and paragraph.
     *
     * @param ElementInterface $element The element.
     * @param int $depth How deeply nested this element is in the one being read.
     * @param array<string, bool> $seen Elements already read, so none is read twice.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _extractElementText(ElementInterface $element, int $depth = 0, array &$seen = []): string
    {
        $key = $element->id ? $element->id . ':' . $element->siteId : 'new:' . spl_object_id($element);

        if (isset($seen[$key]) || $depth > self::MAX_NESTING) {
            return '';
        }

        $seen[$key] = true;

        $parts = [];
        $layout = $element->getFieldLayout();

        foreach ($layout?->getCustomFields() ?? [] as $field) {
            try {
                $value = $element->getFieldValue($field->handle);
            } catch (Throwable $e) {
                // The field is on the element's own layout, so this is a field
                // type misbehaving rather than a bad handle. Skipping it is
                // right, but silently skipping it is not: its words stop
                // counting and the score moves with nothing to say why.
                Craft::warning(
                    "A11y: readability skipped field {$field->handle} on element {$element->id}: " . $e->getMessage(),
                    'accessibility-audit',
                );

                continue;
            }

            // A CKEditor value is markup with nested entries between its
            // chunks. Printed as a string it renders those entries through
            // front-end templates, so each chunk is read on its own instead.
            if (is_object($value) && method_exists($value, 'getChunks')) {
                $text = $this->_chunksToText($value->getChunks(), $depth, $seen);
                if ($text !== '') {
                    $parts[] = $text;
                }

                continue;
            }

            // Only content the element owns is its text: the nested entries of
            // a Matrix or Content Block field. A relation field points at other
            // elements, whose text belongs to their own pages, and following
            // relations from one to the next can go round in a circle.
            if ($value instanceof ElementQuery || $value instanceof ElementInterface) {
                if (!$field instanceof ElementContainerFieldInterface) {
                    continue;
                }

                $nested = $value instanceof ElementInterface ? [$value] : $value->all();

                foreach ($nested as $child) {
                    $text = $this->_extractElementText($child, $depth + 1, $seen);
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }

                continue;
            }

            // Lightswitches, dropdowns, numbers and links print as 1, a stored
            // handle, a figure or a label: settings, not prose to be scored.
            if (!$this->_isTextField($field)) {
                continue;
            }

            $text = $this->_fieldToText($value);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        foreach ($this->_variantsOf($element) as $variant) {
            $text = $this->_extractElementText($variant, $depth + 1, $seen);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * The text of a CKEditor field's chunks: its markup, and the text fields of
     * the entries nested between them.
     *
     * @param iterable<object> $chunks The field's chunks.
     * @param int $depth How deeply nested the field's element is.
     * @param array<string, bool> $seen Elements already read.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _chunksToText(iterable $chunks, int $depth, array &$seen): string
    {
        $parts = [];

        foreach ($chunks as $chunk) {
            if (property_exists($chunk, 'rawHtml')) {
                $text = $this->_fieldToText($chunk->rawHtml);
            } elseif (method_exists($chunk, 'getEntry') && ($entry = $chunk->getEntry()) instanceof ElementInterface) {
                $text = $this->_extractElementText($entry, $depth + 1, $seen);
            } else {
                continue;
            }

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Convert a text field's value to plain text, keeping its paragraphs apart.
     *
     * Block ends become blank lines before the tags come off, or the last word
     * of one paragraph runs straight into the first word of the next.
     *
     * @param mixed $value The field's value.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _fieldToText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_scalar($value) && !(is_object($value) && method_exists($value, '__toString'))) {
            return '';
        }

        $html = (string)preg_replace('~</(?:p|h[1-6]|li|blockquote|div|figcaption|pre|td|th|dd|dt)>~i', "\n\n", (string)$value);
        $html = (string)preg_replace('~<br\s*/?>~i', "\n", $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string)preg_replace('/ *\n */', "\n", $text);
        $text = (string)preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Extract body text from HTML, removing navigation/boilerplate chrome.
     * Prefers <main> or <article>; falls back to <body>.
     *
     * @return string
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     * @param string $html The rendered page.
     */
    private function _extractText(string $html): string
    {
        // Restored rather than left on: the setting is process-wide, and a
        // queue worker runs many jobs in one process. See ContentScanner.
        $dom = new DOMDocument('1.0', 'utf-8');
        $libxmlErrors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlErrors);
        $xpath = new DOMXPath($dom);

        /* Remove non-content nodes */
        // `//template` for the same reason as the scanners: its contents are
        // never rendered, so counting their words would score prose no reader
        // is ever shown.
        $remove = $xpath->query('//script|//style|//nav|//header|//footer|//aside|//form|//noscript|//template');
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }

        /* Prefer semantic content containers */
        $content = $xpath->query('//main')->item(0)
            ?? $xpath->query('//article')->item(0)
            ?? $dom->getElementsByTagName('body')->item(0);

        if (!$content) {
            return '';
        }

        // Source line breaks inside a block are layout, not paragraph breaks,
        // so they are flattened before the block ends are marked.
        foreach (iterator_to_array($xpath->query('.//text()', $content) ?: []) as $node) {
            $node->nodeValue = (string)preg_replace('/\s+/u', ' ', (string)$node->nodeValue);
        }

        // Block ends become blank lines, as they do for field text, so a
        // heading, card title or label with no full stop ends where it ends
        // instead of running into the next block as one long sentence.
        foreach ($xpath->query(self::BLOCK_XPATH, $content) ?: [] as $block) {
            $block->appendChild($dom->createTextNode("\n\n"));
        }

        foreach (iterator_to_array($xpath->query('.//br', $content) ?: []) as $break) {
            $break->parentNode?->replaceChild($dom->createTextNode("\n"), $break);
        }

        $text = $content->textContent;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Calculate Flesch-Kincaid Reading Ease and Grade Level.
     *
     * Reading Ease  = 206.835 − 1.015 × (words/sentences) − 84.6 × (syllables/words)
     * Grade Level   = 0.39 × (words/sentences) + 11.8 × (syllables/words) − 15.59
     *
     * @return array{readingEase: float, gradeLevel: float, ...}|array{error: string}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     * @param string $text The prose to score.
     * @param bool $paragraphsEndSentences Whether a block's end closes a sentence,
     *         for text whose blocks carry no full stops of their own.
     */
    private function _calculateScores(string $text, bool $paragraphsEndSentences = false): array
    {
        $sentences = $this->_extractSentences($text, $paragraphsEndSentences);
        $sentenceCount = count($sentences);
        if ($sentenceCount === 0) {
            return ['error' => Craft::t('accessibility-audit', 'No sentences detected.')];
        }

        // Words are counted over the sentences kept, so the fragments left out
        // (labels and buttons of a word or two) do not lengthen every sentence.
        $wordTokens = preg_split('/\s+/', trim((string) preg_replace('/[^a-zA-Z\s\'-]/', ' ', implode(' ', $sentences))));
        $words = array_values(array_filter($wordTokens ?: [], fn($w) => strlen($w) > 0));
        $wordCount = count($words);
        if ($wordCount === 0) {
            return ['error' => Craft::t('accessibility-audit', 'No words detected.')];
        }

        $syllableCount = array_sum(array_map([$this, '_countSyllables'], $words));

        $wordsPerSentence = $wordCount / $sentenceCount;
        $syllablesPerWord = $syllableCount / $wordCount;

        $readingEase = max(0.0, min(100.0, round(
            206.835 - (1.015 * $wordsPerSentence) - (84.6 * $syllablesPerWord),
            1
        )));
        $gradeLevel = max(0.0, round(
            (0.39 * $wordsPerSentence) + (11.8 * $syllablesPerWord) - 15.59,
            1
        ));

        return [
            'readingEase' => $readingEase,
            'readingEaseLabel' => $this->_readingEaseLabel($readingEase),
            'gradeLevel' => $gradeLevel,
            'readingAge' => max(5, (int) round($gradeLevel + 5)),
            'wordCount' => $wordCount,
            'sentenceCount' => $sentenceCount,
            'syllableCount' => $syllableCount,
            'avgWordsPerSentence' => round($wordsPerSentence, 1),
            /* WCAG 3.1.5 (Reading Level, AAA): lower secondary education ≈ Grade 9 */
            'wcag315Pass' => $gradeLevel <= 9.0,
        ];
    }

    /**
     * Split text into sentences on terminal punctuation followed by a capital.
     *
     * With `$paragraphsEndSentences`, a blank line ends a sentence as well, so
     * a heading or a label with no full stop is not joined to the sentence
     * after it.
     *
     * @return string[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     * @param string $text The prose to split.
     * @param bool $paragraphsEndSentences Whether a block's end closes a sentence.
     */
    private function _extractSentences(string $text, bool $paragraphsEndSentences = false): array
    {
        $blocks = $paragraphsEndSentences ? (preg_split('/\n\s*\n/', $text) ?: []) : [$text];
        $parts = [];

        foreach ($blocks as $block) {
            if ($paragraphsEndSentences) {
                $block = (string)preg_replace('/\s+/', ' ', $block);
            }

            foreach (preg_split('/(?<=[.!?])\s+(?=[A-Z"\'])/', $block) ?: [] as $part) {
                $parts[] = $part;
            }
        }

        return array_values(array_filter(
            array_map('trim', $parts),
            fn($s) => str_word_count($s) >= 4
        ));
    }

    /**
     * Return the 5 longest sentences, these are the most likely to be
     * complex and are surfaced in the UI for manual review.
     *
     * @param string[] $sentences
     * @return string[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _findComplexSentences(array $sentences): array
    {
        usort($sentences, fn($a, $b) => str_word_count($b) <=> str_word_count($a));
        return array_slice($sentences, 0, 5);
    }

    /**
     * Count syllables in an English word using a vowel-group heuristic.
     * Accurate enough for FK scoring purposes; does not handle every exception.
     *
     * @return int
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     * @param string $word The word to count.
     */
    private function _countSyllables(string $word): int
    {
        $word = strtolower((string) preg_replace('/[^a-zA-Z]/', '', $word));
        if (strlen($word) <= 3) {
            return 1;
        }

        /* Remove common silent-e patterns and leading consonant Y */
        $word = (string) preg_replace('/(?:[^laeiouy]es|[^aeiou]ed|[^laeiouy]e)$/', '', $word);
        $word = (string) preg_replace('/^y/', '', $word);

        return max(1, (int) preg_match_all('/[aeiouy]{1,2}/', $word));
    }

    /**
     * Human-readable label for a Flesch-Kincaid Reading Ease score.
     *
     * @return string
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     * @param float $score The reading-ease score.
     */
    private function _readingEaseLabel(float $score): string
    {
        return match (true) {
            $score >= 90 => 'Very easy',
            $score >= 80 => 'Easy',
            $score >= 70 => 'Fairly easy',
            $score >= 60 => 'Standard',
            $score >= 50 => 'Fairly difficult',
            $score >= 30 => 'Difficult',
            default => 'Very difficult',
        };
    }

    /**
     * Send the page text to Claude Haiku for plain-language analysis.
     * Returns an array with keys: summary, suggestions, jargon.
     * Returns null on failure (logged internally).
     *
     * @return array{summary: string, suggestions: array<int, mixed>, jargon: array<int, mixed>}|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     * @param string $text The prose to send.
     * @param string $apiKey The resolved Anthropic key.
     */
    private function _analyseWithClaude(string $text, string $apiKey): ?array
    {
        /* Trim to ~2 500 words to keep cost predictable */
        $words = explode(' ', $text);
        if (count($words) > 2500) {
            $text = implode(' ', array_slice($words, 0, 2500)) . ' [truncated]';
        }

        $prompt =
            "Analyse the following text for readability and plain language. " .
            "Respond with valid JSON only: no markdown fences, no explanation outside the JSON.\n\n" .
            "Return exactly this structure:\n" .
            "{\n" .
            "  \"summary\": \"1-2 sentence overall plain-language assessment\",\n" .
            "  \"suggestions\": [\n" .
            "    {\"original\": \"...\", \"simplified\": \"...\", \"reason\": \"...\"}\n" .
            "  ],\n" .
            "  \"jargon\": [\"term1\", \"term2\"]\n" .
            "}\n\n" .
            "Provide 3-5 suggestions for the most complex or jargon-heavy sentences. " .
            "Limit jargon list to 8 terms maximum.\n\n" .
            "TEXT:\n" . $text;

        try {
            $client = Craft::createGuzzleClient(Anthropic::clientConfig());
            $response = $client->post(Anthropic::ENDPOINT, [
                'headers' => Anthropic::headers($apiKey),
                'json' => [
                    'model' => Anthropic::MODEL,
                    'max_tokens' => 1000,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = $body['content'][0]['text'] ?? '';

            /* Strip any accidental markdown fences */
            $content = (string) preg_replace('/^```(?:json)?\s*/m', '', $content);
            $content = (string) preg_replace('/\s*```$/m', '', $content);

            $decoded = json_decode(trim($content), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return null;
            }

            return $decoded;
        } catch (Throwable $e) {
            Craft::error(
                'ReadabilityService: Claude analysis failed: ' . $e->getMessage(),
                'accessibility-audit'
            );
            return null;
        }
    }
}
