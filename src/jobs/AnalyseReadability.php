<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\base\Batchable;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\QueryBatcher;
use craft\queue\BaseBatchedJob;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\QueuedJobs;
use johnhenry\accessibilityaudit\helpers\ScanTargets;
use Throwable;
use yii\db\Exception;

/**
 * Analyses the readability of every page in a site the scanner covers, or of
 * the results selected in the Page results table, one batch per job step, and
 * stores the results for the Readability page.
 *
 * Each page is scored from its entry's own text fields, falling back to the
 * rendered page when those hold too little text, as a template-built homepage
 * does. Pages matched by an excluded URI pattern are left out, and no text is
 * sent to Claude.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class AnalyseReadability extends BaseBatchedJob
{
    // Const Properties
    // =========================================================================

    /**
     * @var int How long, in seconds, a full run's "already running" flag lasts
     *      if the job never reaches after() to clear it.
     */
    public const RUNNING_TTL = 21600;

    // Public Properties
    // =========================================================================

    /**
     * @var int The site whose pages are analysed.
     */
    public int $siteId = 0;

    /**
     * @var int[] Stored results to analyse again, by id. Empty analyses every
     * page the scanner covers.
     */
    public array $resultIds = [];

    // Public Methods
    // =========================================================================

    /**
     * The cache key that marks a full run as under way for a site, so a second
     * one is not queued on top of it.
     *
     * @param int $siteId The site.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function runningKey(int $siteId): string
    {
        return "accessibility-audit:readability-sweep:$siteId";
    }

    /**
     * Whether a full run is under way for a site.
     *
     * On Craft's database queue a flag with no run left in the queue is dropped,
     * so a failed or deleted job doesn't lock the button until it expires.
     *
     * @param int $siteId The site.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function isRunning(int $siteId): bool
    {
        $cache = Craft::$app->getCache();

        if (!$cache->exists(self::runningKey($siteId))) {
            return false;
        }

        if (QueuedJobs::isLive(self::class) === false) {
            $cache->delete(self::runningKey($siteId));

            return false;
        }

        return true;
    }

    /**
     * How many of the selected results this job will analyse: those on its
     * site.
     *
     * @return int
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function selectedCount(): int
    {
        return (int)$this->_selectedQuery()->count();
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        if ($this->resultIds === []) {
            $plugin = AccessibilityAudit::getInstance();

            // The same pages the accessibility sweep covers: every element
            // with a URL, then the Additional URLs listed under Settings.
            return new ScanTargets(
                $plugin->getAudit()->getUrlElementsQuery($this->siteId),
                $plugin->getSettings()->resolvedCustomUrls($this->siteId),
            );
        }

        return new QueryBatcher($this->_selectedQuery());
    }

    /**
     * @inheritdoc
     *
     * @param array{id?: int|string, elementId: int|string|null, elementType?: string, url?: string, title?: string|null}|string $item
     * An element row, a stored result, or an Additional URL as it was entered.
     * @throws Exception
     */
    protected function processItem(mixed $item): void
    {
        $plugin = AccessibilityAudit::getInstance();

        if (is_string($item)) {
            $this->_analyseConfiguredUrl($item);

            return;
        }

        // A result stored for a URL with no element of this install behind it
        // is fetched again from that URL.
        if (empty($item['elementId'])) {
            $this->_analyseUrl((string)($item['url'] ?? ''), (string)($item['title'] ?? ''));

            return;
        }

        // One page that cannot be read, a fetch that times out or a field that
        // throws, is logged and passed over rather than ending the run. The
        // whole step is inside that, resolving the element included: getUrl()
        // fires two events, so a third-party handler on one page throws here as
        // readily as the analysis does, and this runs over a whole site.
        $elementId = (int)$item['elementId'];

        try {
            /** @var class-string<ElementInterface>|null $elementType */
            $elementType = ($item['elementType'] ?? '') ?: null;

            $element = Craft::$app->getElements()->getElementById(
                $elementId,
                $elementType,
                $this->siteId,
            );

            if (!$element instanceof Element || !$element->getUrl() || $plugin->getAudit()->isElementExcluded($element)) {
                // A selected result for a page that is gone, has no page any more
                // or is now excluded describes nothing readers can reach.
                if (isset($item['id'])) {
                    Craft::$app->getDb()->createCommand()
                        ->delete('{{%accessibilityaudit_readability}}', ['id' => (int)$item['id']])
                        ->execute();
                }

                return;
            }

            $plugin->getReadability()->analyseAndStoreElement($element);
        } catch (Throwable $e) {
            Craft::warning("Readability analysis of element {$elementId} failed: " . $e->getMessage(), 'accessibility-audit');
        }
    }

    /**
     * @inheritdoc
     */
    protected function after(): void
    {
        if ($this->resultIds === []) {
            Craft::$app->getCache()->delete(self::runningKey($this->siteId));
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return $this->resultIds === []
            ? Craft::t('accessibility-audit', 'Analysing the readability of all pages')
            : Craft::t('accessibility-audit', 'Analysing the readability of selected pages');
    }

    // Private Methods
    // =========================================================================

    /**
     * The selected results on this job's site. The Page results table only
     * lists results with a site, so nothing else can be selected.
     *
     * @return Query<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _selectedQuery(): Query
    {
        return (new Query())
            ->select(['id', 'elementId', 'url', 'title'])
            ->from(['{{%accessibilityaudit_readability}}'])
            ->where(['id' => array_map('intval', $this->resultIds)])
            ->andWhere(['siteId' => $this->siteId])
            // QueryBatcher pages with LIMIT/OFFSET, which needs a total order.
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * Analyses one of the Additional URLs listed under Settings.
     *
     * Readability covers this install's own pages only, so a URL on another
     * host is passed over, and so is one that belongs to an entry, which the
     * element pass has already scored from its own text.
     *
     * @param string $entered The URL as it was entered, absolute or site-relative.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _analyseConfiguredUrl(string $entered): void
    {
        $plugin = AccessibilityAudit::getInstance();
        $readability = $plugin->getReadability();
        $url = $plugin->getAudit()->absoluteUrl($entered, $this->siteId);

        if ($url === null || $readability->siteForUrl($url)?->id !== $this->siteId) {
            Craft::info("Readability skipped {$entered}: not a page on this site.", 'accessibility-audit');

            return;
        }

        if ($readability->resolveElementForUrl($url) !== null) {
            return;
        }

        $this->_analyseUrl($url, '');
    }

    /**
     * Analyses a page by its URL and stores the result against that URL.
     *
     * @param string $url The page's URL.
     * @param string $title The title stored with the result.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _analyseUrl(string $url, string $title): void
    {
        if ($url === '') {
            return;
        }

        try {
            $readability = AccessibilityAudit::getInstance()->getReadability();
            $result = $readability->analyseUrl($url);

            if (isset($result['error'])) {
                Craft::info("Readability skipped {$url}: {$result['error']}", 'accessibility-audit');

                return;
            }

            $readability->storeResult($result, null, $this->siteId, $url, $title);
        } catch (Throwable $e) {
            Craft::warning("Readability analysis of {$url} failed: " . $e->getMessage(), 'accessibility-audit');
        }
    }
}
