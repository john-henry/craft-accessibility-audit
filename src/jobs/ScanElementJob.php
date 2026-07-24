<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\queue\BaseJob;
use johnhenry\accessibilityaudit\AccessibilityAudit;

/**
 * Scans a single element for accessibility issues in the background.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class ScanElementJob extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The ID of the element to scan.
     */
    public int $elementId = 0;

    /**
     * @var string The fully-qualified element class name.
     */
    public string $elementType = '';

    /**
     * @var int The site ID to scan the element in.
     */
    public int $siteId = 0;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $element = Craft::$app->getElements()->getElementById($this->elementId, $this->elementType ?: null, $this->siteId);

        if (!$element || !$element->getUrl()) {
            return;
        }

        $result = AccessibilityAudit::getInstance()->audit->scanElement($element);

        // On the Standard edition the distinct-page cap can refuse a brand-new
        // page. Treat that as a quiet skip: no exception, no failed job.
        if (!empty($result['limitReached'])) {
            Craft::info('Scan skipped: Standard edition page limit reached', 'accessibility-audit');
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Scanning element for accessibility issues');
    }
}
