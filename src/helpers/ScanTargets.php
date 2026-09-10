<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use craft\base\Batchable;
use craft\db\QueryBatcher;
use yii\db\QueryInterface;

/**
 * Everything a site-wide sweep has to visit, as one batchable run.
 *
 * A sweep covers two kinds of page. Most are elements with a URL, which come
 * out of a query and are paged through by [[QueryBatcher]]. The rest are the
 * Additional URLs an admin listed under Settings: pages Craft routes with no
 * element behind them, which no element query can reach.
 *
 * Chaining them here rather than queuing a second job keeps one progress
 * count, one sweep flag, and one place where a batch size applies. The
 * elements come first and the URLs last, so a sweep interrupted part way has
 * covered the bulk of the site rather than a slice of each.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.3.0
 */
class ScanTargets implements Batchable
{
    // Private Properties
    // =========================================================================

    /**
     * @var QueryBatcher The element rows, paged.
     */
    private QueryBatcher $_elements;

    /**
     * @var int How many element rows there are, which is also where the URLs
     * start in the combined run.
     */
    private int $_elementCount;

    /**
     * @var string[] The configured URLs, after the element rows.
     */
    private array $_urls;

    // Public Methods
    // =========================================================================

    /**
     * @param QueryInterface $query The URL-bearing elements to scan.
     * @param string[] $urls The configured URLs to scan after them.
     */
    public function __construct(QueryInterface $query, array $urls)
    {
        $this->_elements = new QueryBatcher($query);
        $this->_elementCount = $this->_elements->count();
        $this->_urls = array_values($urls);
    }

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        return $this->_elementCount + count($this->_urls);
    }

    /**
     * @inheritdoc
     *
     * A slice can straddle the join, taking the last of the elements and the
     * first of the URLs, so both halves are asked for whatever the offset.
     *
     * @return array<int, array<string, mixed>|string>
     */
    public function getSlice(int $offset, int $limit): iterable
    {
        $items = [];

        if ($offset < $this->_elementCount && $limit > 0) {
            $take = min($limit, $this->_elementCount - $offset);

            foreach ($this->_elements->getSlice($offset, $take) as $row) {
                $items[] = $row;
            }

            $offset += $take;
            $limit -= $take;
        }

        if ($limit > 0) {
            foreach (array_slice($this->_urls, $offset - $this->_elementCount, $limit) as $url) {
                $items[] = $url;
            }
        }

        return $items;
    }
}
