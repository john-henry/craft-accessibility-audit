<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\queue\Queue;
use yii\db\Expression;

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
     * The search runs in the database: the job payload is a serialised object
     * that names its class, so a match on it answers without loading every
     * queued job into PHP, which matters on a page that asks on every view.
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

        $db = Craft::$app->getDb();
        $query = (new Query())
            ->from([Table::QUEUE])
            ->where(['fail' => false]);

        if ($db->getIsPgsql()) {
            // Postgres keeps the payload as bytea, which LIKE cannot read, and
            // encode(..., 'escape') doubles every backslash, so a namespaced
            // class name never matches the decoded text. position() searches
            // the bytes themselves for the class name's own UTF-8 bytes, which
            // is how serialize() wrote them.
            $query->andWhere(new Expression(
                "position(convert_to(:needle, 'UTF8') in [[job]]) > 0",
                [':needle' => $class],
            ));
        } else {
            $query->andWhere(['like', 'job', $class]);
        }

        return $query->exists($db);
    }
}
