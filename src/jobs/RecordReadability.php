<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\base\Element;
use craft\helpers\ElementHelper;
use craft\queue\BaseJob;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use Throwable;

/**
 * Records a saved element's readability, from its own text fields, on every
 * site the element is on.
 *
 * A save changes the text on each site that shares the edited fields, so each
 * site's result is brought up to date, not only the saved site's. A site where
 * the element has no page, is excluded, or has too little text of its own
 * keeps whatever result it had.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class RecordReadability extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The saved element.
     */
    public int $elementId = 0;

    /**
     * @var int|null The site it was saved on. Null (a job queued before this
     * was carried) finds it on whichever site it lives on.
     */
    public ?int $siteId = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin = AccessibilityAudit::getInstance();
        $elements = Craft::$app->getElements();
        // Without a site the query uses the queue's current one, the primary,
        // and an element that isn't on the primary site would come back null.
        $element = $elements->getElementById($this->elementId, null, $this->siteId ?? '*');

        if (!$element instanceof Element) {
            return;
        }

        foreach (ElementHelper::supportedSitesForElement($element) as $site) {
            // The whole per-site step is inside the catch, not just the record
            // call: getUrl() fires two events, so a third-party handler on one
            // site throws here as readily as the recording does, and this job
            // runs on every save.
            try {
                $onSite = $elements->getElementById($this->elementId, $element::class, (int)$site['siteId']);

                if (!$onSite instanceof Element || !$onSite->getEnabledForSite() || !$onSite->getUrl() || $plugin->getAudit()->isElementExcluded($onSite)) {
                    continue;
                }

                $plugin->getReadability()->recordElementText($onSite);
            } catch (Throwable $e) {
                Craft::warning("Readability on save failed for element {$this->elementId} on site {$site['siteId']}: " . $e->getMessage(), 'accessibility-audit');
            }
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Recording readability');
    }
}
