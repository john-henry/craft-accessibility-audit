<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use johnhenry\accessibilityaudit\helpers\RateLimit;

/**
 * A per-user cap on how often an action may reach the AI service.
 *
 * Every one of these endpoints spends the site's Anthropic credit on request,
 * and the permission to use one is not the same as permission to spend without
 * limit. A signed-in editor looping a call, or a page left open with a script
 * in it, would otherwise run the account's budget down with nothing to stop it.
 *
 * The counting is RateLimit's, a fixed window per user and per action.
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
     * @param string $bucket A short name for the action being limited, so two
     *        of them do not share one allowance.
     * @param int $limit The most calls allowed in a window.
     * @param int $window The window, in seconds.
     * @return bool True where the call should be refused.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     * @see RateLimit::exceeded()
     */
    protected function aiRateLimitExceeded(string $bucket, int $limit, int $window): bool
    {
        return RateLimit::exceeded($bucket, $limit, $window);
    }
}
