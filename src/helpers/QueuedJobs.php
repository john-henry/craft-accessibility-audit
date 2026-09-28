<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\queue\Queue;

/**
 * Answers whether a job of a given class is still waiting or running in the
 * queue.
 *
 * Used to spot an "already running" flag left behind by a job that failed or
 * was deleted from the Queue utility, which would otherwise refuse the button
 * that set it until the flag expired.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class QueuedJobs
{
    // Public Methods
    // =========================================================================

    /**
     * Whether a job of this class is waiting or running.
     *
     * Only Craft's own database queue can be looked into. On any other queue
     * driver the answer is null, and the caller should trust its flag.
     *
     * @param class-string $class The job class.
     * @return bool|null Whether one is live, or null when that can't be known.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function isLive(string $class): ?bool
    {
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof Queue) {
            return null;
        }

        $rows = (new Query())
            ->select(['job'])
            ->from([Table::QUEUE])
            ->where(['fail' => false])
            ->each();

        foreach ($rows as $row) {
            $job = $row['job'];

            if (is_resource($job)) {
                $job = (string)stream_get_contents($job);
            }

            if (str_contains((string)$job, $class)) {
                return true;
            }
        }

        return false;
    }
}
