<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\HeadlessScanner;
use Throwable;
use yii\db\Exception;

/**
 * Checks one PDF asset and stores what it fails.
 *
 * Queued on upload, when a file is replaced, and from Re-check on the Assets
 * screen. Never run inside a save: copying a file off a remote volume and
 * driving Chrome both take far longer than a save should.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class CheckPdf extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The PDF asset to check.
     */
    public int $assetId = 0;

    /**
     * @var bool Whether to check the file even when nothing has changed since
     *      its last check, as Re-check asks.
     */
    public bool $force = false;

    // Public Methods
    // =========================================================================

    /**
     * The reservation a PDF check is pushed with: long enough for the browser
     * pass, which is bounded by its own timeouts, and never below the queue's
     * own setting.
     *
     * The queue only asks a job for its own time to run when it implements
     * RetryableJobInterface, which these checks do not, so every push passes
     * this. The library sweep uses it too: Craft ends a batch early rather
     * than let it outrun its reservation, so it has to cover one document.
     *
     * @return int The time to run, in seconds.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function ttr(): int
    {
        return max(HeadlessScanner::worstCasePdfSeconds() + 60, (int)Craft::$app->getQueue()->ttr);
    }

    /**
     * @inheritdoc
     *
     * Rechecks the edition and setting when it runs, not only when it was
     * queued: a job left in the queue across a downgrade must not write rows.
     * A file checked since this job was queued, by another job for the same
     * upload, is passed over unless the check was forced.
     *
     * @throws Exception
     * @throws Throwable
     */
    public function execute($queue): void
    {
        $pdfAudit = AccessibilityAudit::getInstance()->getPdfAudit();

        if ($this->assetId === 0 || !$pdfAudit->isEnabled()) {
            return;
        }

        $asset = Asset::find()->id($this->assetId)->site('*')->one();

        if (!$asset instanceof Asset || !$pdfAudit->covers($asset)) {
            return;
        }

        if (!$this->force && !$pdfAudit->needsCheck($asset)) {
            return;
        }

        $pdfAudit->checkAsset($asset);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Checking a PDF for accessibility');
    }
}
