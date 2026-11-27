<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use craft\queue\BaseBatchedJob;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use Throwable;

/**
 * Checks every PDF in the audited library, a batch per job step.
 *
 * A file already checked as it is now, under the current rules, is passed
 * over unless the sweep is forced, so running it again after a few uploads
 * costs the new files and nothing else.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class CheckPdfs extends BaseBatchedJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether to check files that have not changed since their last check.
     */
    public bool $force = false;

    /**
     * @inheritdoc
     *
     * Small, because an item can drive Chrome through a whole document.
     */
    public int $batchSize = 10;


    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        // Oldest first by id, so a file uploaded during the sweep lands at the
        // end rather than shifting every page after it by one.
        return new QueryBatcher(
            AccessibilityAudit::getInstance()->getPdfAudit()->pdfQuery()->orderBy(['elements.id' => SORT_ASC]),
        );
    }

    /**
     * @inheritdoc
     *
     * @param Asset $item
     */
    protected function processItem(mixed $item): void
    {
        $pdfAudit = AccessibilityAudit::getInstance()->getPdfAudit();

        if (!$pdfAudit->isEnabled()) {
            return;
        }

        // One file that will not read is logged and passed over rather than
        // ending the sweep.
        try {
            if ($this->force || $pdfAudit->needsCheck($item)) {
                $pdfAudit->checkAsset($item);
            }
        } catch (Throwable $e) {
            Craft::warning("A11y: PDF sweep skipped asset {$item->id}: " . $e->getMessage(), 'accessibility-audit');
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Checking PDF files for accessibility');
    }
}
