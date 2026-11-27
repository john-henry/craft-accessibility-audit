<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\events;

use johnhenry\accessibilityaudit\reports\BaseReport;
use yii\base\Event;

/**
 * Lets a module add its own reports to the Reports screen, or take one away.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class RegisterReportsEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var BaseReport[] The reports, in the order they are listed.
     */
    public array $reports = [];
}
