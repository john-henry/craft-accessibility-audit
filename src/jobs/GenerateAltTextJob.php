<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\errors\ImageTransformException;
use craft\errors\InvalidFieldException;
use craft\helpers\App;
use craft\queue\BaseJob;
use craft\queue\Queue;
use Exception;
use GuzzleHttp\Exception\ClientException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\AltTextPrompt;
use johnhenry\accessibilityaudit\helpers\ImageSource;
use johnhenry\accessibilityaudit\helpers\VisionImage;
use Throwable;

/**
 * Generates alt text for a single image asset via the Anthropic API
 * and saves it to the configured alt text field.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class GenerateAltTextJob extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The ID of the asset to generate alt text for.
     */
    public int $assetId = 0;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws InvalidFieldException
     * @throws Exception
     * @throws ImageTransformException
     */
    public function execute($queue): void
    {
        $asset = Asset::find()->id($this->assetId)->one();

        // instanceof, not a truthiness check: one() is signed to hand back an
        // array as well as an element, so a falsy test would let one through to
        // code that reads it as an Asset.
        if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
            return;
        }

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();
        $apiKey = trim((string)App::parseEnv($settings->anthropicApiKey));

        if (!$apiKey) {
            Craft::warning('A11y: GenerateAltTextJob skipped, no API key configured.', 'accessibility-audit');
            return;
        }

        // Don't overwrite existing alt text
        $altField = $settings->altTextField ?: 'alt';
        $currentAlt = $altField === 'alt' ? ($asset->alt ?? '') : ($asset->getFieldValue($altField) ?? '');
        if (!empty(trim((string) $currentAlt))) {
            return;
        }

        $imageSource = ImageSource::for($asset, 'alt job');
        if (!$imageSource) {
            Craft::warning(
                VisionImage::isVector($asset)
                    ? 'A11y: GenerateAltTextJob: could not render SVG asset ' . $this->assetId . ' for description.'
                    : 'A11y: GenerateAltTextJob: could not resolve image source for asset ' . $this->assetId,
                'accessibility-audit',
            );
            return;
        }

        try {
            $altText = AltTextPrompt::draft($apiKey, $imageSource, $asset, $settings);

            if (!$altText) {
                Craft::warning('A11y: GenerateAltTextJob: empty response from API for asset ' . $this->assetId, 'accessibility-audit');
                return;
            }

            if ($altField === 'alt') {
                $asset->alt = $altText;
            } else {
                $asset->setFieldValue($altField, $altText);
            }

            Craft::$app->getElements()->saveElement($asset, false);
            Craft::info('A11y: Auto-generated alt text for asset ' . $this->assetId . ': ' . $altText, 'accessibility-audit');
        } catch (ClientException $e) {
            // Rethrow API refusals (credit balance, invalid key, rate limit)
            // instead of swallowing them, so they surface as failed jobs.
            $body = json_decode((string)$e->getResponse()->getBody(), true);
            $reason = $body['error']['message'] ?? $e->getMessage();
            Craft::error('A11y: GenerateAltTextJob refused for asset ' . $this->assetId . ': ' . $reason, 'accessibility-audit');

            // A billing refusal hits every sibling job the same way, so cancel
            // the rest of the batch. A rate limit (429) is transient, so it doesn't.
            if ($this->_isBillingRefusal($e, $reason)) {
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

    // Private Methods
    // =========================================================================

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
     * @since 1.0.0
     */
    private function _isBillingRefusal(ClientException $e, string $reason): bool
    {
        return $e->getResponse()->getStatusCode() === 400
            && stripos($reason, 'credit balance') !== false;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('accessibility-audit', 'Generating alt text for image');
    }

    // Private Methods
    // =========================================================================
    /**
     * Returns whether the given URL is local / non-public.
     *
     * @param string $url The URL to test.
     * @return bool
     */
}
