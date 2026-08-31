<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
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
 */
class ReadabilityService extends Component
{
    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Analyse a Craft element by extracting text directly from its fields.
     *
     * Walks PlainText, Textarea, and rich-text fields (CKEditor / Redactor).
     * Matrix / nested entry queries are recursed into automatically.
     * Falls back to a URL fetch for published elements when fields yield < 100 chars.
     *
     * @return array{readingEase: float, gradeLevel: float, ...}|array{error: string}
     */
    public function analyseElement(ElementInterface $element, bool $withClaude = false): array
    {
        $text = $this->_extractElementText($element);

        /* Published element with too little field text, fetch the rendered page */
        if (strlen($text) < 100) {
            $url = $element->getUrl();
            if ($url !== null) {
                return $this->analyseUrl($url, $withClaude);
            }
            return ['error' => Craft::t('accessibility-audit', 'Not enough text content found in this entry\'s fields.')];
        }

        /* Wrap in minimal HTML so _extractText can handle the content node */
        return $this->analyseHtml('<html><body>' . htmlspecialchars($text, ENT_QUOTES | ENT_HTML5) . '</body></html>', '', $withClaude);
    }

    /**
     * Fetch a URL and return a full readability analysis.
     *
     * @return array{readingEase: float, gradeLevel: float, ...}|array{error: string}
     */
    public function analyseUrl(string $url, bool $withClaude = false): array
    {
        // SSRF guard: reject private/reserved hosts and non-http(s) schemes.
        try {
            UrlSafety::assertSafeUrl($url);
        } catch (UnsafeUrlException $e) {
            return ['error' => $e->getMessage()];
        }

        try {
            $clientConfig = array_merge(
                ['timeout' => 15],
                UrlSafety::guzzleRedirectConfig(),
            );

            // Same scanner UA as the audit fetch, so one WAF allow-list rule
            // covers every scanner request this plugin makes.
            $clientConfig['headers'] = [
                'User-Agent' => AccessibilityAudit::getInstance()->getSettings()->getFetchUserAgent(),
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
     */
    public function analyseHtml(string $html, string $sourceUrl = '', bool $withClaude = false): array
    {
        $text = $this->_extractText($html);

        if (strlen($text) < 100) {
            return ['error' => Craft::t('accessibility-audit', 'Not enough text content to analyse (less than 100 characters found).')];
        }

        $scores = $this->_calculateScores($text);
        $sentences = $this->_extractSentences($text);

        $result = array_merge($scores, [
            'complexSentences' => $this->_findComplexSentences($sentences),
            'sourceUrl' => $sourceUrl,
        ]);

        if ($withClaude) {
            $settings = AccessibilityAudit::getInstance()->getSettings();
            $apiKey = trim(App::parseEnv($settings->anthropicApiKey ?? ''));
            if ($apiKey !== '') {
                $claudeResult = $this->_analyseWithClaude($text, $apiKey);
                if ($claudeResult !== null) {
                    $result['claude'] = $claudeResult;
                }
            }
        }

        return $result;
    }

    // ─── Persistence ─────────────────────────────────────────────────────────

    /**
     * Persist a readability result to {{%accessibilityaudit_readability}}.
     * Upserts on elementId+siteId (or url when no element).
     *
     * @throws \yii\db\Exception
     * @throws \Exception
     */
    public function storeResult(
        array $result,
        ?int $elementId = null,
        ?int $siteId = null,
        string $url = '',
        string $title = '',
    ): void {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

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
            'wcag315Pass' => (int) ($result['wcag315Pass'] ?? false),
            'dateAnalysed' => $now,
            'dateUpdated' => $now,
        ];

        $condition = $elementId !== null
            ? ['elementId' => $elementId, 'siteId' => $siteId]
            : ['url' => $url];

        $existing = (new Query())->from(['{{%accessibilityaudit_readability}}'])->where($condition)->one();

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
     * @param string $orderBy A whitelisted column to sort on.
     * @param int $orderDir SORT_ASC or SORT_DESC.
     * @return array{results: array<int, array<string, mixed>>, total: int}
     */
    public function getResultsPaged(
        int $siteId,
        int $page = 1,
        int $perPage = 100,
        string $search = '',
        string $orderBy = 'dateAnalysed',
        int $orderDir = SORT_DESC,
    ): array {
        // Whitelisted: the sort column arrives from a query parameter and would
        // otherwise be interpolated straight into ORDER BY.
        $sortable = ['title', 'readingEase', 'gradeLevel', 'wordCount', 'wcag315Pass', 'dateAnalysed'];
        $column = in_array($orderBy, $sortable, true) ? $orderBy : 'dateAnalysed';

        $query = (new Query())
            ->from(['{{%accessibilityaudit_readability}}'])
            ->where(['siteId' => $siteId]);

        if ($search !== '') {
            $query->andWhere([
                'or',
                ['like', 'title', $search],
                ['like', 'url', $search],
            ]);
        }

        $total = (int) (clone $query)->count();

        $rows = $query
            ->orderBy([$column => $orderDir])
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
     */
    public function resolveElementForUrl(string $url): ?array
    {
        $bestSite = null;
        $bestBaseUrlLength = 0;

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = rtrim((string) $site->getBaseUrl(), '/');

            if ($baseUrl !== '' && str_starts_with($url, $baseUrl) && strlen($baseUrl) > $bestBaseUrlLength) {
                $bestSite = $site;
                $bestBaseUrlLength = strlen($baseUrl);
            }
        }

        if ($bestSite === null) {
            return null;
        }

        $uri = trim(explode('?', substr($url, $bestBaseUrlLength), 2)[0], '/');
        $element = Craft::$app->getElements()->getElementByUri($uri, $bestSite->id);

        if (!$element || !$element->id) {
            return null;
        }

        return ['elementId' => $element->id, 'siteId' => $bestSite->id];
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
     * @return array[]
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
     */
    public function getStats(?int $siteId = null): array
    {
        $query = (new Query())
            ->select(['readingEase', 'gradeLevel', 'wcag315Pass'])
            ->from(['{{%accessibilityaudit_readability}}']);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $rows = $query->all();

        $total = count($rows);
        if ($total === 0) {
            return ['total' => 0, 'avgEase' => null, 'avgGrade' => null, 'passingPct' => null];
        }

        $avgEase = round(array_sum(array_column($rows, 'readingEase')) / $total, 1);
        $avgGrade = round(array_sum(array_column($rows, 'gradeLevel')) / $total, 1);
        $passing = count(array_filter($rows, fn($r) => (bool) $r['wcag315Pass']));
        $passingPct = (int) round(($passing / $total) * 100);

        return [
            'total' => $total,
            'avgEase' => $avgEase,
            'avgGrade' => $avgGrade,
            'passingPct' => $passingPct,
        ];
    }

    /**
     * Extract the <title> from an HTML string.
     */
    public function extractPageTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/is', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Walk an element's field layout and concatenate all readable text.
     */
    private function _extractElementText(ElementInterface $element): string
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return '';
        }

        $parts = [];
        foreach ($layout->getCustomFields() as $field) {
            try {
                $value = $element->getFieldValue($field->handle);
            } catch (Throwable) {
                continue;
            }
            $text = $this->_fieldToText($value);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Convert a field value to plain text.
     *
     * - Scalar / stringable values: strip HTML tags (no-op for plain text)
     * - Element queries (Matrix / nested entries): recurse into each element
     * - Anything else: skip
     */
    private function _fieldToText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        /* Element queries: Matrix blocks, nested entries, etc. */
        if ($value instanceof \craft\elements\db\ElementQuery) {
            $texts = [];
            foreach ($value->all() as $el) {
                if ($el instanceof ElementInterface) {
                    $texts[] = $this->_extractElementText($el);
                }
            }
            return implode("\n\n", array_filter($texts));
        }

        /* Scalar / stringable: covers PlainText, Textarea, CKEditor, Redactor */
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            $text = strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = (string) preg_replace('/\s+/', ' ', $text);
            return trim($text);
        }

        return '';
    }

    /**
     * Extract body text from HTML, removing navigation/boilerplate chrome.
     * Prefers <main> or <article>; falls back to <body>.
     */
    private function _extractText(string $html): string
    {
        $dom = new \DOMDocument('1.0', 'utf-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

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
     */
    private function _calculateScores(string $text): array
    {
        $sentences = $this->_extractSentences($text);
        $sentenceCount = count($sentences);
        if ($sentenceCount === 0) {
            return ['error' => Craft::t('accessibility-audit', 'No sentences detected.')];
        }

        $wordTokens = preg_split('/\s+/', trim((string) preg_replace('/[^a-zA-Z\s\'-]/', ' ', $text)));
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
     * @return string[]
     */
    private function _extractSentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z"\'])/', $text) ?: [];
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
     */
    private function _findComplexSentences(array $sentences): array
    {
        usort($sentences, fn($a, $b) => str_word_count($b) <=> str_word_count($a));
        return array_values(array_slice($sentences, 0, 5));
    }

    /**
     * Count syllables in an English word using a vowel-group heuristic.
     * Accurate enough for FK scoring purposes; does not handle every exception.
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
     * @return array{summary: string, suggestions: array, jargon: array}|null
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
            $client = Craft::createGuzzleClient(['timeout' => 30]);
            $response = $client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => 'claude-haiku-4-5-20251001',
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
