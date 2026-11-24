<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;

/**
 * A per-user cap on how often an action may run, counted in a fixed window.
 *
 * Counted in a fixed window rather than as a minimum interval between calls,
 * because the actions it guards are used in runs: an editor moving down the
 * image list with Save and next, or an assistant re-scanning page after page
 * as it fixes them. An interval throttle stalls that honest use while a
 * counter lets it through and still bounds the total.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class RateLimit
{
    // Public Methods
    // =========================================================================

    /**
     * Whether the current user has used up the window's allowance, counting
     * this call.
     *
     * A request with no user behind it is not counted: every caller checks a
     * permission first, so there is always one by the time this is reached,
     * and a console context with no identity has no rate to limit.
     *
     * @param string $bucket A short name for the action being limited, so two
     *        of them do not share one allowance.
     * @param int $limit The most calls allowed in a window.
     * @param int $window The window, in seconds.
     * @return bool True where the call should be refused.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function exceeded(string $bucket, int $limit, int $window): bool
    {
        $userId = Craft::$app->getUser()->getId();

        if ($userId === null) {
            return false;
        }

        $cache = Craft::$app->getCache();

        // The window's own number, so the count expires with it rather than
        // sliding forward on every call.
        $slot = (int)floor(time() / $window);
        $key = "accessibility-audit:{$bucket}-rate:{$userId}:{$slot}";
        $count = (int)$cache->get($key);

        if ($count >= $limit) {
            return true;
        }

        $cache->set($key, $count + 1, $window);

        return false;
    }
}
