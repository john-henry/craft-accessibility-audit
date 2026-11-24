<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use craft\base\Batchable;
use craft\db\Query;

/**
 * The images a drafting run still has to draft for, paged by asset id rather
 * than by offset.
 *
 * Every image the run drafts leaves the pending set, and so does every image
 * an editor fixes while it runs, so an offset into that set would skip past
 * images that had moved up into the space. Each slice instead takes the next
 * images after the last one the run reached, and the count is what has been
 * done plus what is left beyond that point, which is the figure the batched
 * job compares its progress against.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class PendingAltDrafts implements Batchable
{
    // Private Properties
    // =========================================================================

    /**
     * @var Query<int, array<string, mixed>> The pending asset ids, unordered and unlimited.
     */
    private Query $_pending;

    /**
     * @var int The last asset id the run reached.
     */
    private int $_after;

    /**
     * @var int How many images the run has already been through.
     */
    private int $_done;

    // Public Methods
    // =========================================================================

    /**
     * Holds the pending set and how far through it the run has got.
     *
     * @param Query<int, array<string, mixed>> $pending The pending asset ids, selected as `ai.assetId`.
     * @param int $after The last asset id the run reached, 0 at the start.
     * @param int $done How many images the run has already been through.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function __construct(Query $pending, int $after, int $done)
    {
        $this->_pending = $pending;
        $this->_after = $after;
        $this->_done = $done;
    }

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        return $this->_done + (int)$this->_remaining()->count();
    }

    /**
     * @inheritdoc
     *
     * The offset is ignored: the slice always starts after the last image the
     * run reached.
     *
     * @return int[] The next asset ids, in id order.
     */
    public function getSlice(int $offset, int $limit): iterable
    {
        return array_map('intval', $this->_remaining()
            ->orderBy(['ai.assetId' => SORT_ASC])
            ->limit($limit)
            ->column());
    }

    // Private Methods
    // =========================================================================

    /**
     * The pending images past the point the run reached.
     *
     * @return Query<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _remaining(): Query
    {
        return (clone $this->_pending)->andWhere(['>', 'ai.assetId', $this->_after]);
    }
}
