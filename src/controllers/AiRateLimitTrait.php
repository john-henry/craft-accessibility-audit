<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;

/**
 * A per-user cap on how often an action may reach the AI service.
 *
 * Every one of these endpoints spends the site's Anthropic credit on request,
 * and the permission to use one is not the same as permission to spend without
 * limit. A signed-in editor looping a call, or a page left open with a script
 * in it, would otherwise run the account's budget down with nothing to stop it.
 *
 * Counted in a fixed window rather than as a minimum interval between calls,
 * because these actions are used in runs: "Generate all" walks a page of images
 * one after another, and an author working through the VPAT drafts one
 * criterion after the next. An interval throttle stalls that honest use while a
 * counter lets it through and still bounds the total.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
trait AiRateLimitTrait
{
    // Protected Methods
    // =========================================================================

    /**
     * Whether this user has used up the window's allowance, counting this call.
     *
     * A request with no user behind it is not counted: every action using this
     * checks a permission first, so there is always one by the time it is
     * reached, and a console context has no rate to limit.
     *
     * @param string $bucket A short name for the action being limited, so two
     *        of them do not share one allowance.
     * @param int $limit The most calls allowed in a window.
     * @param int $window The window, in seconds.
     * @return bool True where the call should be refused.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    protected function aiRateLimitExceeded(string $bucket, int $limit, int $window): bool
    {
        $userId = Craft::$app->getUser()->getId();

        if ($userId === null) {
            return false;
        }

        $cache = Craft::$app->getCache();

        // The window's own number, so the count expires with it rather than
        // sliding forward on every call.
        $slot = (int) floor(time() / $window);
        $key = "accessibility-audit:{$bucket}-rate:{$userId}:{$slot}";
        $count = (int) $cache->get($key);

        if ($count >= $limit) {
            return true;
        }

        $cache->set($key, $count + 1, $window);

        return false;
    }
}
