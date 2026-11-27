<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\errors\ImageTransformException;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\queue\BaseJob;
use craft\queue\Queue;
use Exception;
use GuzzleHttp\Exception\ClientException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AssetScanner;
use Throwable;

/**
 * Drafts alt text for a newly uploaded image via the Anthropic API. The draft
 * waits for an editor to review, or, where the New uploads setting asks for it
 * and Claude flagged nothing to check, its Standard version is saved to the
 * image. Existing alt text is never overwritten.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class GenerateAltTextJob extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The ID of the asset to draft alt text for.
     */
    public int $assetId = 0;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws Exception When the API refuses the request.
     * @throws ImageTransformException
     * @throws SiteNotFoundException
     */
    public function execute($queue): void
    {
        $asset = AssetScanner::findAsset($this->assetId);

        // instanceof, not a truthiness check: one() is signed to hand back an
        // array as well as an element, so a falsy test would let one through to
        // code that reads it as an Asset.
        if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
            return;
        }

        $plugin = AccessibilityAudit::getInstance();
        $apiKey = trim((string)App::parseEnv($plugin->getSettings()->anthropicApiKey));

        if (!$plugin->canGenerateAltText()) {
            Craft::warning('A11y: GenerateAltTextJob skipped, AI alt text needs the Plus edition and an API key.', 'accessibility-audit');
            return;
        }

        // Alt text written before the job ran is never second-guessed, and an
        // image that is decorative, excluded or already drafted needs nothing.
        $drafts = $plugin->getAltDrafts();
        if (!$drafts->needsDraft($asset)) {
            return;
        }

        try {
            $draft = $this->requestDraft($asset, $apiKey);
            if ($draft === null) {
                return;
            }

            $outcome = $drafts->settleNewUpload($asset, $draft, $plugin->getSettings()->newAssetAltMode);
            Craft::info("A11y: GenerateAltTextJob settled the alt text draft for asset {$this->assetId}: {$outcome}.", 'accessibility-audit');
        } catch (ClientException $e) {
            // Rethrow API refusals (credit balance, invalid key, rate limit)
            // instead of swallowing them, so they surface as failed jobs.
            $reason = self::refusalReason($e);
            Craft::error('A11y: GenerateAltTextJob refused for asset ' . $this->assetId . ': ' . $reason, 'accessibility-audit');

            // A billing refusal hits every sibling job the same way, so cancel
            // the rest of the batch. A rate limit (429) is transient, so it doesn't.
            if (self::isBillingRefusal($e, $reason)) {
                $cancelled = self::cancelPendingJobs();
                if ($cancelled > 0) {
                    $reason .= ' ' . "Cancelled {$cancelled} pending alt text job(s), since they would all be refused the same way.";
                }
            }

            throw new Exception($reason, 0, $e);
        } catch (Throwable $e) {
            Craft::error('A11y: GenerateAltTextJob failed for asset ' . $this->assetId . ': ' . $e->getMessage(), 'accessibility-audit');
        }
    }

    // Public Static Methods
    // =========================================================================

    /**
     * Cancels every waiting GenerateAltTextJob in the queue.
     *
     * Scoped hard to this one job class: the serialized job blob is matched on
     * the class name, so scan jobs, asset sweeps, and anything else in the
     * queue are untouched. Only waiting rows are released. A job currently
     * reserved by a worker is left to finish (or fail) on its own.
     *
     * Requires Craft's database queue; on a custom queue driver this is a
     * no-op, since there is no portable way to enumerate pending jobs.
     *
     * @return int How many jobs were cancelled.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function cancelPendingJobs(): int
    {
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof Queue) {
            Craft::warning('A11y: cannot cancel pending alt text jobs on a non-database queue driver.', 'accessibility-audit');
            return 0;
        }

        $ids = (new Query())
            ->select(['id'])
            ->from('{{%queue}}')
            ->where(['fail' => false, 'dateReserved' => null])
            ->andWhere(['like', 'job', 'GenerateAltTextJob'])
            ->column();

        foreach ($ids as $id) {
            $queue->release((string)$id);
        }

        if (!empty($ids)) {
            Craft::error('A11y: cancelled ' . count($ids) . ' pending alt text job(s) after an API billing refusal.', 'accessibility-audit');
        }

        return count($ids);
    }

    /**
     * The error message Anthropic gave with a refusal, or the exception's own
     * where the body carries none.
     *
     * @param ClientException $e The refusal.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function refusalReason(ClientException $e): string
    {
        $body = json_decode((string)$e->getResponse()->getBody(), true);

        return (string)($body['error']['message'] ?? $e->getMessage());
    }

    /**
     * Whether an API refusal is a billing one: HTTP 400 with Anthropic's
     * credit-balance wording. Deliberately narrow, so an invalid key (also
     * 400) or a rate limit (429) never cancels the batch.
     *
     * @param ClientException $e The refusal.
     * @param string $reason The error message extracted from its body.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function isBillingRefusal(ClientException $e, string $reason): bool
    {
        return $e->getResponse()->getStatusCode() === 400
            && stripos($reason, 'credit balance') !== false;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Asks Claude for the image's draft.
     *
     * @param Asset $asset The image.
     * @param string $apiKey The resolved API key.
     * @return array{alt: string, imageType: string, reviewReasons: list<string>, failure: string|null, variants: list<array{key: string, alt: string, tooLong: bool}>}|null
     *         The draft, or null where the image could not be read.
     * @throws Throwable When the API request or the image read fails.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    protected function requestDraft(Asset $asset, string $apiKey): ?array
    {
        return AccessibilityAudit::getInstance()->getAltDrafts()->requestDraft($asset, $apiKey);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Drafting alt text for image');
    }
}
