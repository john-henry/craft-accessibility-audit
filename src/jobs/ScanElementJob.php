<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use Throwable;

/**
 * Scans a single element for accessibility issues in the background.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
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
     * @throws Throwable
     */
    public function execute($queue): void
    {
        /** @var class-string<ElementInterface>|null $elementType */
        $elementType = $this->elementType ?: null;

        $element = Craft::$app->getElements()->getElementById($this->elementId, $elementType, $this->siteId);

        if (!$element || !$element->getUrl()) {
            return;
        }

        $result = AccessibilityAudit::getInstance()->getAudit()->scanElement($element);

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
