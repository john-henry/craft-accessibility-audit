<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */
namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\elements\Asset;
use craft\errors\ElementNotFoundException;
use craft\errors\ImageTransformException;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\web\Controller;
use GuzzleHttp\Exception\ClientException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\AltTextPrompt;
use johnhenry\accessibilityaudit\helpers\Anthropic;
use johnhenry\accessibilityaudit\helpers\ImageSource;
use johnhenry\accessibilityaudit\helpers\VisionImage;
use johnhenry\accessibilityaudit\services\AltDrafts;
use johnhenry\accessibilityaudit\services\AssetScanner;
use JsonException;
use Throwable;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Handles AI alt-text generation, the drafts waiting for review, and saving
 * alt text for image assets.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class AltController extends Controller
{
    // Traits
    // =========================================================================

    use AiRateLimitTrait;
    use ProGateTrait;

    // Const Properties
    // =========================================================================

    /**
     * @var int The most AI alt-text generations one user may request within a
     *          single rate-limit window. Generous by design: an editor working
     *          down a list with Save and next asks for one image at a time, at
     *          the pace of the API's replies, and stays comfortably under it.
     *          The limit only bites a script hammering the endpoint to drive
     *          unbounded Anthropic spend.
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
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionGenerate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:run-scans');

        if (($refusal = $this->requireStandardJson('AI alt text')) !== null) {
            return $refusal;
        }

        // Rate limit the (paid) AI call per user, so a script can't loop the
        // endpoint and drive unbounded Anthropic spend. A rolling per-user
        // counter, not a minimum interval, so an editor moving quickly from
        // one image to the next is never made to wait.
        if ($this->aiRateLimitExceeded('alt-generate', self::GENERATE_RATE_LIMIT, self::GENERATE_RATE_WINDOW)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Too many alt-text requests, please wait a moment.'),
            ]);
        }

        $assetId = (int) $this->request->getRequiredBodyParam('assetId');
        $asset = AssetScanner::findAsset($assetId);

        if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Image asset not found.')]);
        }

        if (!Craft::$app->getElements()->canView($asset)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'You do not have permission to view this asset.')]);
        }

        $settings = AccessibilityAudit::getInstance()->getSettings();
        $apiKey = trim((string)App::parseEnv($settings->anthropicApiKey));

        if (!$apiKey) {
            return $this->asJson(['success' => false, 'error' => $this->_noApiKeyMessage()]);
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
            $variants = (bool)$this->request->getBodyParam('variants', false);
            $draft = AltTextPrompt::draft($apiKey, $imageSource, $asset, $settings, $variants);

            if ($draft['failure'] === AltTextPrompt::FAILURE_REFUSED) {
                return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'The AI declined to describe this image. Write its alt text by hand.')]);
            }

            if ($draft['failure'] !== null) {
                return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'The AI reply could not be read. Try again, or write the alt text by hand.')]);
            }

            return $this->asJson([
                'success' => true,
                'alt' => $draft['alt'],
                'imageType' => $draft['imageType'],
                'reviewReasons' => AltDrafts::reasonEntries($draft['reviewReasons']),
                'variants' => $draft['variants'],
            ]);
        } catch (ClientException $e) {
            // Surface Anthropic's own error message (credit, key, rate limit)
            // rather than a generic one; the raw exception is logged, not returned.
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
     * POST /accessibility-audit/alt/queue-drafts
     * Queues a drafting run over every image on the missing-alt list, in one
     * volume or all of them. Answers in JSON where that is asked for, and
     * otherwise redirects back with a notice.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException
     * @throws InvalidConfigException
     * @throws Throwable When the run cannot be pushed to the queue.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionQueueDrafts(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        $plugin = AccessibilityAudit::getInstance();

        if (!$plugin->isStandard()) {
            return $this->_queueResponse(false, $this->editionRequiredMessage('AI alt text', AccessibilityAudit::EDITION_STANDARD));
        }

        if (trim((string)App::parseEnv($plugin->getSettings()->anthropicApiKey)) === '') {
            return $this->_queueResponse(false, $this->_noApiKeyMessage());
        }

        // A volume that was asked for but is not one the audit covers is
        // refused rather than widened to the whole library, which would spend
        // far more than the editor agreed to.
        $handle = trim((string)$this->request->getBodyParam('volume', ''));
        $volumeId = $handle !== '' ? $this->_volumeId($handle) : null;
        if ($handle !== '' && $volumeId === null) {
            return $this->_queueResponse(false, Craft::t('accessibility-audit', 'That volume could not be found, or is excluded from the audit.'));
        }

        $queued = $plugin->getAltDrafts()->queueRun($volumeId);

        return match (true) {
            $queued === null => $this->_queueResponse(false, Craft::t('accessibility-audit', 'Alt text is already being drafted. Drafts appear under Drafts to review as they are ready.')),
            $queued === 0 => $this->_queueResponse(false, Craft::t('accessibility-audit', 'Every image on the missing-alt list already has a draft waiting.')),
            default => $this->_queueResponse(true, Craft::t('accessibility-audit', 'Drafting alt text for {n, plural, =1{# image} other{# images}} in the background. Drafts appear under Drafts to review as they are ready.', ['n' => $queued]), $queued),
        };
    }

    /**
     * POST /accessibility-audit/alt/discard-draft
     * Deletes the AI draft waiting for one image.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException
     * @throws Exception
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionDiscardDraft(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:run-scans');

        $assetId = (int)$this->request->getRequiredBodyParam('assetId');
        $asset = AssetScanner::findAsset($assetId);

        if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Image asset not found.')]);
        }

        if (!Craft::$app->getElements()->canSave($asset)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'You do not have permission to edit this asset.')]);
        }

        AccessibilityAudit::getInstance()->getAltDrafts()->discard($assetId);

        return $this->asJson(['success' => true, 'message' => Craft::t('accessibility-audit', 'Draft discarded.')]);
    }

    /**
     * GET /accessibility-audit/alt/edit
     * The alt text editor for one image, as a CP screen for Craft's slideout.
     * A draft waiting for the image comes with it, so its versions show
     * without a fresh request.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionEdit(): Response
    {
        $this->requirePermission('accessibility-audit:run-scans');

        $asset = AssetScanner::findAsset((int)$this->request->getRequiredQueryParam('assetId'));
        if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
            throw new NotFoundHttpException(Craft::t('accessibility-audit', 'Image asset not found.'));
        }

        if (!Craft::$app->getElements()->canSave($asset)) {
            throw new ForbiddenHttpException(Craft::t('accessibility-audit', 'You do not have permission to edit this asset.'));
        }

        $plugin = AccessibilityAudit::getInstance();
        $assets = $plugin->getAssets();
        $drafts = $plugin->getAltDrafts();
        // The review queue is part of AI alt text, so Lite shows no draft.
        $draft = $plugin->isStandard() ? $drafts->getReady((int)$asset->id) : null;

        return $this->asCpScreen()
            ->title($asset->getUiLabel())
            ->contentTemplate('accessibility-audit/_assets/alt-editor', [
                'asset' => $asset,
                'currentAlt' => $assets->altText($asset) ?? '',
                'decorative' => $assets->isDecorative((int)$asset->id),
                'hasApiKey' => $plugin->canGenerateAltText(),
                'draft' => $draft !== null ? $drafts->suggestion($draft) : null,
            ])
            ->action('accessibility-audit/alt/save-screen')
            ->redirectUrl('accessibility-audit/assets')
            ->submitButtonLabel(Craft::t('app', 'Save'));
    }

    /**
     * POST /accessibility-audit/alt/save-screen
     * Saves the alt text editor: the alt text, or the decorative mark in its
     * place. A decorative image carries an empty alt, so any text sent with
     * the mark is not written. Either way, any draft waiting for the image is
     * retired.
     *
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException
     * @throws ElementNotFoundException When the asset's id no longer resolves.
     * @throws InvalidConfigException
     * @throws Exception
     * @throws \yii\base\Exception When the asset supports no sites.
     * @throws Throwable
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionSaveScreen(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        $assetId = (int)$this->request->getRequiredBodyParam('assetId');
        $altText = trim((string)$this->request->getBodyParam('altText', ''));
        $decorative = (bool)$this->request->getBodyParam('decorative', false);

        $asset = AssetScanner::findAsset($assetId);
        if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
            return $this->asFailure(Craft::t('accessibility-audit', 'Image asset not found.'));
        }

        if (!Craft::$app->getElements()->canSave($asset)) {
            return $this->asFailure(Craft::t('accessibility-audit', 'You do not have permission to edit this asset.'));
        }

        if (!$decorative && $altText === '') {
            return $this->asFailure(Craft::t('accessibility-audit', 'Write the alt text, or mark the image decorative.'));
        }

        $plugin = AccessibilityAudit::getInstance();
        $assets = $plugin->getAssets();
        $drafts = $plugin->getAltDrafts();

        if (!$decorative && !$drafts->approve($asset, $altText)) {
            return $this->asFailure(implode(', ', $asset->getErrorSummary(true)));
        }

        if ($decorative !== $assets->isDecorative($assetId)) {
            $assets->setDecorative($assetId, $decorative);
        }

        if ($decorative) {
            $drafts->discard($assetId);
        }

        $assets->syncAssetAudit($asset);

        return $this->asSuccess(
            $decorative
                ? Craft::t('accessibility-audit', 'Marked decorative.')
                : Craft::t('accessibility-audit', 'Alt text saved.'),
            [
                'assetId' => $assetId,
                'decorative' => $decorative,
            ],
        );
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
     * @throws SiteNotFoundException
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

        $asset = AssetScanner::findAsset($assetId);
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
     * @throws SiteNotFoundException
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

            $asset = AssetScanner::findAsset($assetId);
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
     * POST /accessibility-audit/alt/approve-drafts
     * Saves the Standard version of each selected image's draft as its alt
     * text. Drafts flagged for checking, and any image the user can't save,
     * are left on the drafts list.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException
     * @throws ElementNotFoundException When an asset's id no longer resolves.
     * @throws Exception
     * @throws SiteNotFoundException
     * @throws \yii\base\Exception When an asset supports no sites.
     * @throws Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionApproveDrafts(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:run-scans');

        if (($refusal = $this->requireStandardJson('AI alt text')) !== null) {
            return $refusal;
        }

        $result = AccessibilityAudit::getInstance()->getAltDrafts()
            ->approveReady((array)$this->request->getBodyParam('assetIds', []));

        return $this->asJson([
            'success' => true,
            'approved' => count($result['approved']),
            'approvedIds' => $result['approved'],
            'skipped' => $result['skipped'],
        ]);
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
     * The answer to a drafting request: JSON where that was asked for, and
     * otherwise a flash message and a redirect back to the posted URL.
     *
     * @param bool $success Whether a run was queued.
     * @param string $message What to tell the editor.
     * @param int $queued How many images were queued.
     * @return Response
     * @throws BadRequestHttpException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _queueResponse(bool $success, string $message, int $queued = 0): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asJson($success
                ? ['success' => true, 'queued' => $queued, 'message' => $message]
                : ['success' => false, 'error' => $message]);
        }

        $session = Craft::$app->getSession();
        if ($success) {
            $session->setNotice($message);
        } else {
            $session->setError($message);
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * A volume handle from the Assets screen's filter, as the volume id a
     * drafting run is limited to.
     *
     * @param string $handle The volume handle.
     * @return int|null The volume id, or null where the handle names no volume
     *         or one the audit excludes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _volumeId(string $handle): ?int
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($handle);
        if ($volume === null || in_array($volume->uid, AccessibilityAudit::getInstance()->getSettings()->excludedVolumes, true)) {
            return null;
        }

        return (int)$volume->id;
    }

    /**
     * The message for an AI request made with no API key configured.
     *
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _noApiKeyMessage(): string
    {
        return Craft::t('accessibility-audit', 'No Anthropic API key configured. Add it in Accessibility → Settings → AI Alt Text.');
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
