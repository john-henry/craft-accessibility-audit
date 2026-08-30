<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */
namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\elements\Asset;
use craft\errors\ImageTransformException;
use craft\fs\Local;
use craft\helpers\App;
use craft\web\Controller;
use GuzzleHttp\Exception\ClientException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\AltTextPrompt;
use johnhenry\accessibilityaudit\helpers\VisionImage;
use JsonException;
use Throwable;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * Handles AI alt-text generation and saving for image assets.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class AltController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * @var int The most AI alt-text generations one user may request within a
     *          single rate-limit window. Generous by design: the "Generate all"
     *          button loops generations sequentially, awaiting each API round
     *          trip, so its natural pace (API-latency-bound) stays comfortably
     *          under this cap. The limit only bites a script hammering the
     *          endpoint to drive unbounded Anthropic spend.
     */
    public const GENERATE_RATE_LIMIT = 60;

    /**
     * @var int The length of the rolling rate-limit window, in seconds.
     */
    public const GENERATE_RATE_WINDOW = 60;

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    // Public Methods
    // =========================================================================

    /**
     * POST /accessibility-audit/alt/generate
     * Sends an asset image to Claude and returns a suggested alt text string.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws ImageTransformException
     * @throws MethodNotAllowedHttpException
     */
    public function actionGenerate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:runScans');

        // Rate limit the (paid) AI call per user, so a script can't loop the
        // endpoint and drive unbounded Anthropic spend. A rolling per-user
        // counter, not a minimum interval: the "Generate all" button awaits each
        // call in turn, so an interval throttle would wrongly stall it.
        if ($this->_rateLimitExceeded()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Too many alt-text requests, please wait a moment.'),
            ]);
        }

        $assetId = (int) $this->request->getRequiredBodyParam('assetId');
        $asset = Asset::find()->id($assetId)->one();

        if (!$asset || $asset->kind !== Asset::KIND_IMAGE) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Image asset not found.')]);
        }

        if (!Craft::$app->getElements()->canView($asset)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'You do not have permission to view this asset.')]);
        }

        $settings = AccessibilityAudit::getInstance()->getSettings();
        $apiKey = trim(App::parseEnv($settings->anthropicApiKey ?? ''));

        if (!$apiKey) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'No Anthropic API key configured. Add it in Accessibility → Settings → AI Alt Text.')]);
        }

        $imageSource = $this->resolveImageSource($asset);
        if (!$imageSource) {
            return $this->asJson([
                'success' => false,
                'error' => VisionImage::isVector($asset)
                    ? $this->_vectorFailureMessage()
                    : Craft::t('accessibility-audit', 'Could not read asset. Ensure the asset has a public URL or a supported filesystem.'),
            ]);
        }

        $prompt = AltTextPrompt::build($asset, $settings);

        try {
            $client = Craft::createGuzzleClient();
            $altText = $this->_askClaude($client, $apiKey, $imageSource, $prompt);

            // One more go when the model runs past the length it was asked
            // for, then a trim.
            if (AltTextPrompt::exceedsLimit($altText)) {
                $altText = $this->_askClaude(
                    $client,
                    $apiKey,
                    $imageSource,
                    AltTextPrompt::retryPrompt($prompt, $altText),
                );
                $altText = AltTextPrompt::trimToLimit($altText);
            }

            if (!$altText) {
                return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Empty response from API.')]);
            }

            return $this->asJson(['success' => true, 'alt' => $altText]);
        } catch (ClientException $e) {
            // Surface Anthropic's own error message (credit, key, rate limit) rather
            // than a generic one; the raw exception is logged, not returned.
            Craft::error($e->getMessage(), 'accessibility-audit');
            $body = json_decode((string)$e->getResponse()->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $error = $body['error']['message'] ?? Craft::t('accessibility-audit', 'The API rejected the request. Check the key and try again.');
            return $this->asJson(['success' => false, 'error' => $error]);
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), 'accessibility-audit');
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'An unexpected error occurred.')]);
        }
    }

    /**
     * POST /accessibility-audit/alt/save
     * Persists alt text to an asset's configured alt field.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:runScans');

        $assetId = (int) $this->request->getRequiredBodyParam('assetId');
        $altText = trim((string) $this->request->getRequiredBodyParam('altText'));
        $field = AccessibilityAudit::getInstance()->getSettings()->altTextField ?: 'alt';

        $asset = Asset::find()->id($assetId)->one();
        if (!$asset) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Asset not found.')]);
        }

        if (!Craft::$app->getElements()->canSave($asset)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'You do not have permission to edit this asset.')]);
        }

        if ($field === 'alt') {
            $asset->alt = $altText;
        } else {
            $asset->setFieldValue($field, $altText);
        }

        if (!Craft::$app->getElements()->saveElement($asset, false)) {
            return $this->asJson(['success' => false, 'error' => implode(', ', $asset->getErrorSummary(true))]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * POST /accessibility-audit/alt/set-decorative
     * Marks an image asset decorative, or unmarks it. A decorative image
     * correctly carries an empty alt, so it is not flagged for missing alt.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws MethodNotAllowedHttpException
     * @throws Exception
     * @throws \Exception
     */
    public function actionSetDecorative(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:runScans');

        $assetId = (int) $this->request->getRequiredBodyParam('assetId');
        $decorative = (bool) $this->request->getBodyParam('decorative', false);

        $asset = Asset::find()->id($assetId)->one();
        if (!$asset || $asset->kind !== Asset::KIND_IMAGE) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Image asset not found.')]);
        }

        if (!Craft::$app->getElements()->canSave($asset)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'You do not have permission to edit this asset.')]);
        }

        $assets = AccessibilityAudit::getInstance()->getAssets();
        $assets->setDecorative($assetId, $decorative);

        // Reconcile the stored audit rows straight away so the listing and its
        // counts reflect the change without waiting for the next sweep.
        $assets->syncAssetAudit($asset);

        return $this->asJson(['success' => true]);
    }

    /**
     * POST /accessibility-audit/alt/set-decorative-bulk
     * Marks a set of image assets decorative, or unmarks them, in one request.
     * Non-image assets and any the user can't save are skipped, so the returned
     * count reports only how many were actually applied.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws MethodNotAllowedHttpException
     * @throws Exception
     * @throws \Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.1
     */
    public function actionSetDecorativeBulk(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:runScans');

        $assetIds = array_map('intval', (array) $this->request->getBodyParam('assetIds', []));
        $decorative = (bool) $this->request->getBodyParam('decorative', false);

        $assets = AccessibilityAudit::getInstance()->getAssets();
        $elements = Craft::$app->getElements();
        $count = 0;

        foreach ($assetIds as $assetId) {
            if ($assetId <= 0) {
                continue;
            }

            $asset = Asset::find()->id($assetId)->one();
            if (!$asset || $asset->kind !== Asset::KIND_IMAGE) {
                continue;
            }

            if (!$elements->canSave($asset)) {
                continue;
            }

            $assets->setDecorative($assetId, $decorative);

            // Reconcile the stored audit rows straight away so the listing and
            // its counts reflect the change without waiting for the next sweep.
            $assets->syncAssetAudit($asset);
            $count++;
        }

        return $this->asJson(['success' => true, 'count' => $count]);
    }

    /**
     * POST /accessibility-audit/alt/verify-key
     * Sends a minimal request to the Anthropic API to confirm the key is valid.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException|JsonException
     */
    public function actionVerifyKey(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $settings = AccessibilityAudit::getInstance()->getSettings();
        $apiKey = trim(App::parseEnv($settings->anthropicApiKey ?? ''));

        if (!$apiKey) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'No API key configured.')]);
        }

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
                    'max_tokens' => 5,
                    'messages' => [[
                        'role' => 'user',
                        'content' => 'Hi',
                    ]],
                ],
            ]);

            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $model = $body['model'] ?? 'claude-haiku';

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('accessibility-audit', 'API key is valid. Connected to {model}.', ['model' => $model]),
            ]);
        } catch (ClientException $e) {
            $body = json_decode((string)$e->getResponse()->getBody(), true, 512, JSON_THROW_ON_ERROR);
            // Anthropic's own error text is safe to show for a key check; the raw
            // exception is logged, not returned, so it can't leak request internals.
            Craft::error($e->getMessage(), 'accessibility-audit');
            $error = $body['error']['message'] ?? Craft::t('accessibility-audit', 'The API rejected the request. Check the key and try again.');
            return $this->asJson(['success' => false, 'error' => $error]);
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), 'accessibility-audit');
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'An unexpected error occurred.')]);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether the current user has spent this window's alt-text generation
     * budget. A rolling per-user counter bucketed by the window: the count for
     * the active bucket is incremented on each permitted call and expires with
     * the window, so a caller is capped at GENERATE_RATE_LIMIT calls per window
     * without stalling the sequential "Generate all" loop the way a
     * minimum-interval throttle would.
     *
     * @return bool True when the cap is reached and the call should be refused.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.1
     */
    private function _rateLimitExceeded(): bool
    {
        $userId = Craft::$app->getUser()->getId();
        if ($userId === null) {
            return false;
        }

        $cache = Craft::$app->getCache();
        $bucket = (int) floor(time() / self::GENERATE_RATE_WINDOW);
        $key = "accessibility-audit:alt-generate-rate:$userId:$bucket";
        $count = (int) $cache->get($key);

        if ($count >= self::GENERATE_RATE_LIMIT) {
            return true;
        }

        $cache->set($key, $count + 1, self::GENERATE_RATE_WINDOW);

        return false;
    }

    /**
     * @throws InvalidConfigException
     * @throws ImageTransformException
     */
    /**
     * One alt-text request to Claude, returning the text it came back with.
     *
     * @param \GuzzleHttp\Client $client The HTTP client.
     * @param string $apiKey The resolved Anthropic key.
     * @param array<string, mixed> $imageSource The image content block source.
     * @param string $prompt The prompt to send.
     * @return string The alt text, empty if the response carried none.
     * @throws JsonException
     */
    private function _askClaude(\GuzzleHttp\Client $client, string $apiKey, array $imageSource, string $prompt): string
    {
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

        return trim($body['content'][0]['text'] ?? '');
    }

    /**
     * Why a vector could not be described, in the terms that matter to whoever
     * has to do something about it.
     *
     * Three separate problems land here, and one message about SVG support
     * covers none of them on a server that already has it. The common case is
     * a renderer that draws fills but not strokes, and the fix for that is a
     * server package, so it is worth naming.
     *
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _vectorFailureMessage(): string
    {
        if (!VisionImage::canReadVectors()) {
            return Craft::t('accessibility-audit', 'This server cannot read SVG files. Write this alt text by hand, or check that ImageMagick was built with SVG support.');
        }

        if (!VisionImage::rendersStrokes()) {
            return Craft::t('accessibility-audit', 'This SVG is drawn with strokes, and the SVG renderer on this server drops them, so there was nothing to describe. Write this alt text by hand, or ask your host to install librsvg. SVGs drawn with fills still work.');
        }

        return Craft::t('accessibility-audit', 'This SVG came out blank when rendered, so there was nothing to describe. Write its alt text by hand.');
    }

    private function resolveImageSource(Asset $asset): ?array
    {
        // Oversized images are scaled down before the URL tier, which would
        // otherwise hand over the full-size original.
        $downscaled = VisionImage::downscaledSource($asset);
        if ($downscaled !== null) {
            return $downscaled;
        }

        // A vector that could not be rendered is never sent as-is: the API
        // takes raster formats only and would refuse it.
        if (VisionImage::isVector($asset)) {
            return null;
        }

        $url = $asset->getUrl();
        $mime = $asset->getMimeType() ?: 'image/jpeg';

        // Tier 1: public HTTPS URL
        if ($url && !$this->isLocalUrl($url)) {
            return ['type' => 'url', 'url' => $url];
        }

        // Tier 2a: local filesystem, resolve the alias path and read directly
        $fs = $asset->getVolume()->getFs();
        if ($fs instanceof Local) {
            try {
                $root = rtrim(Craft::getAlias($fs->path), '/\\');
                $filePath = $root . DIRECTORY_SEPARATOR . ltrim($asset->getPath(), '/\\');
                Craft::info('A11y: reading local file ' . $filePath, 'accessibility-audit');
                if (file_exists($filePath)) {
                    return ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode(file_get_contents($filePath))];
                }
                Craft::warning('A11y: local file not found at ' . $filePath, 'accessibility-audit');
            } catch (Throwable $e) {
                Craft::warning('A11y: local file read failed: ' . $e->getMessage(), 'accessibility-audit');
            }
        }

        // Tier 2b: cloud / other filesystem via Asset::getContents()
        try {
            $data = base64_encode($asset->getContents());
            if ($data) {
                return ['type' => 'base64', 'media_type' => $mime, 'data' => $data];
            }
        } catch (Throwable $e) {
            Craft::warning('A11y: stream read failed: ' . $e->getMessage(), 'accessibility-audit');
        }

        // Tier 3: curl fetch. TLS verification is only bypassed in dev/ephemeral
        // environments (DDEV/Lando/Valet self-signed certs); production verifies.
        if ($url) {
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
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($body && $httpCode === 200) {
                return ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($body)];
            }
            Craft::warning('A11y: curl fetch failed (HTTP ' . $httpCode . '): ' . $curlErr, 'accessibility-audit');
        }

        return null;
    }

    private function isLocalUrl(string $url): bool
    {
        // Anthropic only accepts HTTPS public URLs
        if (!str_starts_with($url, 'https://')) {
            return true;
        }

        // parse_url keeps the brackets on an IPv6 host ("[::1]"), so trim them
        // before matching the loopback address, matching GenerateAltTextJob:
        // an internal IPv6 URL would otherwise be handed to the third-party API
        // rather than read locally.
        $host = trim((string)(parse_url($url, PHP_URL_HOST) ?? ''), '[]');
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.ddev.site')
            || str_ends_with($host, '.lndo.site');
    }
}
