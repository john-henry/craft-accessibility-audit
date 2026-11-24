<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\base\Batchable;
use craft\elements\Asset;
use craft\helpers\App;
use craft\queue\BaseBatchedJob;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\PendingAltDrafts;
use johnhenry\accessibilityaudit\helpers\QueuedJobs;
use johnhenry\accessibilityaudit\services\AssetScanner;
use Throwable;
use yii\queue\RetryableJobInterface;

/**
 * Drafts AI alt text for every image on the missing-alt list that is not
 * decorative and has no draft waiting, and stores each draft for an editor to
 * review. Nothing is ever written to an asset.
 *
 * The images are worked through one at a time, each one waiting on its API
 * reply before the next is sent, and only one run is queued at a time, so the
 * whole run never has more than one request in flight. That keeps it well
 * inside Anthropic's own rate limits without a pause between images, and the
 * per-user limit on the Generate with AI button has no user to count against
 * here. Where Anthropic does answer with a rate limit, or is overloaded or
 * unreachable, the batch is retried once the queue's time-to-run has passed,
 * which is the back-off; the images it had already drafted are not sent again.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class DraftAltText extends BaseBatchedJob implements RetryableJobInterface
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The cache key that marks a run as queued or running. Craft's
     *      database queue is asked first; the key is what is left to go on with
     *      any other queue driver, and what two requests race to claim.
     */
    public const RUNNING_KEY = 'accessibility-audit:alt-drafting';

    /**
     * @var int How long, in seconds, the running key lasts without being
     *      refreshed. Every batch refreshes it, so only a run that died
     *      without clearing it lets it expire.
     */
    public const RUNNING_TTL = 21600;

    /**
     * @var int How long, in seconds, a freshly claimed key with no run yet in
     *      the queue is taken as a request still pushing its job, rather than
     *      a leftover.
     */
    public const CLAIM_GRACE = 60;

    /**
     * @var int How many times a batch is tried when the API is rate limiting,
     *      overloaded or unreachable.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * @var int How many images in a row may be refused for the same reason
     *      before the run stops: a refusal that repeats is about the account
     *      or the request, not the image, and would fail every image left.
     */
    public const MAX_SAME_FAILURES = 5;

    /**
     * @var int The time to run for one batch, in seconds. Kept under the
     *      fifteen minutes some hosts allow a queue job.
     */
    public const TTR = 600;

    // Public Properties
    // =========================================================================

    /**
     * @var int|null The volume to draft for, or null for every volume.
     */
    public ?int $volumeId = null;

    /**
     * @var int The last asset id the run reached, carried from one batch to
     *      the next.
     */
    public int $cursor = 0;

    /**
     * @var int How many images in a row the API has refused for the same
     *      reason, carried from one batch to the next.
     */
    public int $failureStreak = 0;

    /**
     * @var string The reason behind the current run of refusals.
     */
    public string $failureReason = '';

    /**
     * @inheritdoc
     *
     * Each image is an API round trip of several seconds, so a batch is kept
     * small enough to finish well inside its time to run.
     */
    public int $batchSize = 25;

    // Public Methods
    // =========================================================================

    /**
     * Whether a drafting run is queued or running.
     *
     * Craft's database queue is the answer wherever it can be read: a run is
     * live exactly when a job of this class is waiting or reserved in it, so a
     * cleared cache cannot let a second run in, and a run that failed or was
     * deleted does not hold the button. Only on another queue driver does the
     * running key decide.
     *
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function isRunning(): bool
    {
        return QueuedJobs::isLive(self::class) ?? Craft::$app->getCache()->exists(self::RUNNING_KEY);
    }

    /**
     * @inheritdoc
     *
     * A run with no API key ends here, before anything is loaded. A batch that
     * throws anything that will not be retried ends the run, so the running
     * key goes with it.
     *
     * @throws Throwable When a batch fails.
     */
    public function execute($queue): void
    {
        if (!AccessibilityAudit::getInstance()->canGenerateAltText()) {
            Craft::warning('A11y: alt text drafting stopped, AI alt text needs the Standard edition and an API key.', 'accessibility-audit');
            self::_release();

            return;
        }

        try {
            parent::execute($queue);
        } catch (Throwable $e) {
            if (!self::_isTransient($e)) {
                self::_release();
            }

            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        return self::TTR;
    }

    /**
     * @inheritdoc
     *
     * Only a rate limit, an overloaded API or a dropped connection is worth
     * trying again. Anything else would fail the same way next time. A batch
     * that is not retried ends the run, so the running key goes with it.
     *
     * The queue asks again, with no error, just before it runs a retried
     * attempt. That attempt was only queued because its error passed, so a
     * null error is judged on the attempt count alone.
     *
     * @param int $attempt The attempt number.
     * @param Throwable|null $error The error, or null when asked before a
     *        retried attempt runs.
     * @return bool
     */
    public function canRetry($attempt, $error): bool
    {
        $retry = $attempt < self::MAX_ATTEMPTS && ($error === null || self::_isTransient($error));

        if (!$retry) {
            self::_release();
        }

        return $retry;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        return new PendingAltDrafts(
            AccessibilityAudit::getInstance()->getAltDrafts()->pendingQuery($this->volumeId),
            $this->cursor,
            $this->itemOffset,
        );
    }

    /**
     * @inheritdoc
     *
     * Refreshes the running key, so a long run never outlives it, and drops
     * the worker's memo of the decorative set, so an image marked decorative
     * since the last batch is passed over.
     */
    protected function beforeBatch(): void
    {
        Craft::$app->getCache()->set(self::RUNNING_KEY, time(), self::RUNNING_TTL);
        AccessibilityAudit::getInstance()->getAssets()->forgetDecorative();
    }

    /**
     * @inheritdoc
     *
     * @param int $item The asset id.
     * @throws Exception When the API refuses the key or the account, which
     *         would refuse every image after this one too.
     * @throws Throwable When the API is rate limiting, overloaded or
     *         unreachable, so the batch is retried.
     */
    protected function processItem(mixed $item): void
    {
        $assetId = (int)$item;
        $this->cursor = $assetId;
        $drafts = AccessibilityAudit::getInstance()->getAltDrafts();

        try {
            $asset = AssetScanner::findAsset($assetId);

            // Judged on the image as it is now: the stored list it came from
            // may be behind an edit made since.
            if (!$asset instanceof Asset || !$drafts->needsDraft($asset)) {
                return;
            }

            $apiKey = trim((string)App::parseEnv(AccessibilityAudit::getInstance()->getSettings()->anthropicApiKey));
            $this->draft($asset, $apiKey);
            $this->failureStreak = 0;
            $this->failureReason = '';
        } catch (ClientException $e) {
            $this->_handleRefusal($e, $assetId);
        } catch (Throwable $e) {
            if (self::_isTransient($e)) {
                Craft::warning("A11y: alt text drafting paused at asset {$assetId}: " . $e->getMessage(), 'accessibility-audit');
                throw $e;
            }

            Craft::warning("A11y: alt text drafting failed for asset {$assetId}: " . $e->getMessage(), 'accessibility-audit');
            $drafts->storeFailure($assetId, $e->getMessage());
        }
    }

    /**
     * Drafts for one image and stores the result.
     *
     * @param Asset $asset The image.
     * @param string $apiKey The resolved API key.
     * @return string The stored status.
     * @throws Throwable When the API request or the image read fails.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    protected function draft(Asset $asset, string $apiKey): string
    {
        return AccessibilityAudit::getInstance()->getAltDrafts()->draftAsset($asset, $apiKey);
    }

    /**
     * @inheritdoc
     */
    protected function after(): void
    {
        self::_release();
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Drafting alt text for images');
    }

    // Private Methods
    // =========================================================================

    /**
     * Sorts an API refusal into one that ends the run and one that only
     * concerns this image.
     *
     * A rate limit is retried. A billing refusal or a rejected key would
     * refuse every image the same way, so the run stops there, and a billing
     * refusal cancels the waiting per-upload drafts as well. Anything else,
     * such as an image the API will not take, is stored as a failed draft and
     * the run moves on, unless the same reason has now come back for
     * MAX_SAME_FAILURES images in a row: a usage limit or a retired model
     * reads that way, and the run is stopped like a billing refusal rather
     * than finishing with every image failed.
     *
     * @param ClientException $e The refusal.
     * @param int $assetId The image it was for.
     * @throws ClientException When it is a rate limit, so the batch is retried.
     * @throws Exception When it ends the run.
     * @throws \yii\db\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _handleRefusal(ClientException $e, int $assetId): void
    {
        $status = $e->getResponse()->getStatusCode();
        $reason = GenerateAltTextJob::refusalReason($e);

        if ($status === 429) {
            Craft::warning("A11y: alt text drafting rate limited at asset {$assetId}: {$reason}", 'accessibility-audit');
            throw $e;
        }

        $billing = GenerateAltTextJob::isBillingRefusal($e, $reason);

        if ($billing || in_array($status, [401, 403], true)) {
            Craft::error("A11y: alt text drafting refused at asset {$assetId}: {$reason}", 'accessibility-audit');
            self::_release();

            if ($billing) {
                $cancelled = GenerateAltTextJob::cancelPendingJobs();
                if ($cancelled > 0) {
                    $reason .= ' ' . "Cancelled {$cancelled} pending alt text job(s), since they would all be refused the same way.";
                }
            }

            throw new Exception($reason, 0, $e);
        }

        Craft::warning("A11y: alt text draft refused for asset {$assetId}: {$reason}", 'accessibility-audit');
        AccessibilityAudit::getInstance()->getAltDrafts()->storeFailure($assetId, $reason);

        $this->failureStreak = $reason === $this->failureReason ? $this->failureStreak + 1 : 1;
        $this->failureReason = $reason;

        if ($this->failureStreak >= self::MAX_SAME_FAILURES) {
            Craft::error("A11y: alt text drafting stopped after {$this->failureStreak} images in a row were refused: {$reason}", 'accessibility-audit');
            self::_release();

            throw new Exception("Stopped after {$this->failureStreak} images in a row were refused the same way: {$reason}", 0, $e);
        }
    }

    /**
     * Clears the running key, as the run is over.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _release(): void
    {
        Craft::$app->getCache()->delete(self::RUNNING_KEY);
    }

    /**
     * Whether an error is one that passes: a rate limit, an overloaded or
     * failing API, or a connection that never got through.
     *
     * @param Throwable $e The error.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _isTransient(Throwable $e): bool
    {
        if ($e instanceof ClientException) {
            return $e->getResponse()->getStatusCode() === 429;
        }

        return $e instanceof ServerException || $e instanceof ConnectException;
    }
}
