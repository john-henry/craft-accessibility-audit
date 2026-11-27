<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\queue\BaseJob;
use johnhenry\accessibilityaudit\AccessibilityAudit;

/**
 * Sends one site's scheduled directors summary.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class SendScheduledReport extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The site whose summary to send.
     */
    public int $siteId = 0;

    /**
     * @var string|null The site's last send date before this send claimed the
     * period, given back if nothing is delivered.
     */
    public ?string $previousSent = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        AccessibilityAudit::getInstance()->getScheduledReports()->sendClaimed($this->siteId, $this->previousSent);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Sending the scheduled accessibility summary');
    }
}
