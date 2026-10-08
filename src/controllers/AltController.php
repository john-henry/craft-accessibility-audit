<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */
namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\elements\Asset;
use craft\errors\ElementNotFoundException;
use craft\errors\ImageTransformException;
use craft\helpers\App;
use craft\web\Controller;
use GuzzleHttp\Exception\ClientException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\AltTextPrompt;
use johnhenry\accessibilityaudit\helpers\Anthropic;
use johnhenry\accessibilityaudit\helpers\ImageSource;
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
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class AltController extends Controller
{
    // Traits
    // =========================================================================

    use AiRateLimitTrait;

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
     * @throws MethodNotAllowedHttpException|JsonException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionGenerate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:run-scans');

        // Rate limit the (paid) AI call per user, so a script can't loop the
        // endpoint and drive unbounded Anthropic spend. A rolling per-user
        // counter, not a minimum interval: the "Generate all" button awaits each
        // call in turn, so an interval throttle would wrongly stall it.
        if ($this->aiRateLimitExceeded('alt-generate', self::GENERATE_RATE_LIMIT, self::GENERATE_RATE_WINDOW)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Too many alt-text requests, please wait a moment.'),
            ]);
        }

        $assetId = (int) $this->request->getRequiredBodyParam('assetId');
        $asset = Asset::find()->id($assetId)->one();

        if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Image asset not found.')]);
        }

        if (!Craft::$app->getElements()->canView($asset)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'You do not have permission to view this asset.')]);
        }

        $settings = AccessibilityAudit::getInstance()->getSettings();
        $apiKey = trim((string)App::parseEnv($settings->anthropicApiKey));

        if (!$apiKey) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'No Anthropic API key configured. Add it in Accessibility → Settings → AI Alt Text.')]);
        }

        $imageSource = ImageSource::for($asset, 'alt');
        if (!$imageSource) {
            return $this->asJson([
                'success' => false,
                'error' => VisionImage::isVector($asset)
                    ? $this->_vectorFailureMessage()
                    : Craft::t('accessibility-audit', 'Could not read asset. Ensure the asset has a public URL or a supported filesystem.'),
            ]);
        }

        try {
            $altText = AltTextPrompt::draft($apiKey, $imageSource, $asset, $settings);

            if (!$altText) {
                return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Empty response from API.')]);
            }

            return $this->asJson(['success' => true, 'alt' => $altText]);
        } catch (ClientException $e) {
            // Surface Anthropic's own error message (credit, key, rate limit) rather
            // than a generic one; the raw exception is logged, not returned.
            Craft::error($e->getMessage(), 'accessibility-audit');
            $body = json_decode((string)$e->getResponse()->getBody(), true);
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
     * @throws ElementNotFoundException When the asset's id no longer resolves.
     * @throws \yii\base\Exception When the asset supports no sites. Spelled out
     *         rather than imported: `Exception` in this file is the db one.
     * @throws Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:run-scans');

        $assetId = (int) $this->request->getRequiredBodyParam('assetId');
        $altText = trim((string) $this->request->getRequiredBodyParam('altText'));
        $field = AccessibilityAudit::getInstance()->getSettings()->altTextField ?: 'alt';

        $asset = Asset::find()->id($assetId)->one();
        if (!$asset instanceof Asset) {
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionSetDecorative(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:run-scans');

        $assetId = (int) $this->request->getRequiredBodyParam('assetId');
        $decorative = (bool) $this->request->getBodyParam('decorative', false);

        $asset = Asset::find()->id($assetId)->one();
        if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.1
     */
    public function actionSetDecorativeBulk(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:run-scans');

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
            if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionVerifyKey(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $settings = AccessibilityAudit::getInstance()->getSettings();
        $apiKey = trim((string)App::parseEnv($settings->anthropicApiKey));

        if (!$apiKey) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'No API key configured.')]);
        }

        try {
            $client = Craft::createGuzzleClient(Anthropic::clientConfig(Anthropic::KEY_CHECK_TIMEOUT));
            $response = $client->post(Anthropic::ENDPOINT, [
                'headers' => Anthropic::headers($apiKey),
                'json' => [
                    'model' => Anthropic::MODEL,
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
            $body = json_decode((string)$e->getResponse()->getBody(), true);
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
     * Why a vector could not be described, in the terms that matter to whoever
     * has to do something about it.
     *
     * Three separate problems land here, and one message about SVG support
     * covers none of them on a server that already has it. The common case is
     * a renderer that draws fills but not strokes, and the fix for that is a
     * server package, so it is worth naming.
     *
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
}
