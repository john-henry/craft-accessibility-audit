<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\jobs;

use Craft;
use craft\elements\Asset;
use craft\errors\ImageTransformException;
use craft\errors\InvalidFieldException;
use craft\fs\Local;
use craft\helpers\App;
use craft\queue\BaseJob;
use GuzzleHttp\Exception\ClientException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\AltTextPrompt;
use johnhenry\accessibilityaudit\helpers\VisionImage;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Generates alt text for a single image asset via the Anthropic API
 * and saves it to the configured alt text field.
 *
 * @author JohnHenry <info@johnhenry.ie>
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
     * @throws \Exception
     * @throws ImageTransformException
     */
    public function execute($queue): void
    {
        $asset = Asset::find()->id($this->assetId)->one();

        if (!$asset || $asset->kind !== Asset::KIND_IMAGE) {
            return;
        }

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();
        $apiKey = trim(App::parseEnv($settings->anthropicApiKey ?? ''));

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

        $imageSource = $this->resolveImageSource($asset);
        if (!$imageSource) {
            Craft::warning('A11y: GenerateAltTextJob: could not resolve image source for asset ' . $this->assetId, 'accessibility-audit');
            return;
        }

        $prompt = AltTextPrompt::build($asset, $settings);

        try {
            $client = Craft::createGuzzleClient();
            $response = $client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 150,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'image', 'source' => $imageSource],
                            ['type' => 'text',  'text' => $prompt],
                        ],
                    ]],
                ],
            ]);

            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $altText = trim($body['content'][0]['text'] ?? '');

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
            if ($this->isBillingRefusal($e, $reason)) {
                $cancelled = self::cancelPendingJobs();
                if ($cancelled > 0) {
                    $reason .= ' ' . "Cancelled {$cancelled} pending alt text job(s), since they would all be refused the same way.";
                }
            }

            throw new \Exception($reason, 0, $e);
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function cancelPendingJobs(): int
    {
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof \craft\queue\Queue) {
            Craft::warning('A11y: cannot cancel pending alt text jobs on a non-database queue driver.', 'accessibility-audit');
            return 0;
        }

        $ids = (new \craft\db\Query())
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function isBillingRefusal(ClientException $e, string $reason): bool
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
     * Resolves the image source payload for the Anthropic API (mirrors AltController).
     *
     * @param Asset $asset The asset to resolve.
     * @return array|null The image source payload, or null if unresolved.
     * @throws InvalidConfigException
     * @throws ImageTransformException
     */
    private function resolveImageSource(Asset $asset): ?array
    {
        // Oversized images are scaled down first. Must precede the URL tier,
        // which would hand over the full-size original.
        $downscaled = VisionImage::downscaledSource($asset);
        if ($downscaled !== null) {
            return $downscaled;
        }

        $url = $asset->getUrl();
        $mime = $asset->getMimeType() ?: 'image/jpeg';

        if ($url && !$this->isLocalUrl($url)) {
            return ['type' => 'url', 'url' => $url];
        }

        $fs = $asset->getVolume()->getFs();
        if ($fs instanceof Local) {
            try {
                $root = rtrim(Craft::getAlias($fs->path), '/\\');
                $filePath = $root . DIRECTORY_SEPARATOR . ltrim($asset->getPath(), '/\\');
                if (file_exists($filePath)) {
                    return ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode(file_get_contents($filePath))];
                }
            } catch (Throwable $e) {
                Craft::warning('A11y: job local file read failed: ' . $e->getMessage(), 'accessibility-audit');
            }
        }

        try {
            $data = base64_encode($asset->getContents());
            if ($data) {
                return ['type' => 'base64', 'media_type' => $mime, 'data' => $data];
            }
        } catch (Throwable $e) {
            Craft::warning('A11y: job stream read failed: ' . $e->getMessage(), 'accessibility-audit');
        }

        if ($url) {
            // TLS verification is only bypassed in dev/ephemeral environments
            // (DDEV/Lando/Valet self-signed certs); production verifies certs.
            $verifyTls = !App::isEphemeral() && !Craft::$app->getConfig()->getGeneral()->devMode;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => $verifyTls,
                CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body && $httpCode === 200) {
                return ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($body)];
            }
        }

        return null;
    }

    /**
     * Returns whether the given URL is local / non-public.
     *
     * @param string $url The URL to test.
     * @return bool
     */
    private function isLocalUrl(string $url): bool
    {
        if (!str_starts_with($url, 'https://')) {
            return true;
        }
        // parse_url keeps the brackets on an IPv6 host ("[::1]"), so trim them
        // before matching the loopback address, or an internal IPv6 URL would
        // be handed to the third-party API rather than base64'd.
        $host = trim((string)(parse_url($url, PHP_URL_HOST) ?? ''), '[]');
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.ddev.site')
            || str_ends_with($host, '.lndo.site');
    }
}
