<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;
use yii\base\Action;
use yii\web\Response;

/**
 * Serves the axe-core overlay to decoupled front ends.
 *
 * A headless site (Next, Nuxt, Astro) never passes through Craft's templating,
 * so the plugin's EVENT_END_BODY injection can't reach it. This controller is
 * the replacement surface: a loader script the front end includes with a plain
 * script tag, a resolve endpoint that turns the current page URL back into a
 * Craft element and hands over the overlay config, and token-authenticated
 * twins of the store/read endpoints the overlay talks to.
 *
 * Everything here is cross-origin by design: CSRF validation is off (auth is a
 * bearer token generated in the plugin's Tools settings — see
 * OverlayService::isValidToken()), CORS headers are set from the allowed
 * origins, and every response opts out of Blitz caching. Responses carrying
 * config or findings also send no-cache headers so no proxy stores a payload
 * only token holders may see; the loader script itself is public and identical
 * for everyone, so it is served cacheable instead.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class OverlayController extends Controller
{
    // Traits
    // =========================================================================

    use ProGateTrait;

    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['script', 'resolve', 'store-axe-results', 'page-issues'];

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    // Public Methods
    // =========================================================================

    /**
     * Sets CORS headers, answers preflight requests, and keeps every overlay
     * response out of Blitz's cache before any action runs.
     *
     * @param Action $action
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function beforeAction($action): bool
    {
        $headers = $this->response->getHeaders();
        $headers->set('Vary', 'Origin');

        $origin = (string)$this->request->getHeaders()->get('Origin', '');
        if ($origin !== '' && AccessibilityAudit::getInstance()->getOverlay()->isAllowedOrigin($origin)) {
            $headers->set('Access-Control-Allow-Origin', rtrim($origin, '/'));
            $headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, X-Requested-With');
            $headers->set('Access-Control-Max-Age', '3600');
        }

        // Preflight: answer before Craft's own beforeAction machinery runs, so
        // an OPTIONS probe never trips POST checks or touches an action.
        if ($this->request->getMethod() === 'OPTIONS') {
            $this->response->setStatusCode(204);
            return false;
        }

        // These are URL-rule site routes, so a Blitz include pattern of `.*`
        // would happily cache them; per-token payloads must never be stored.
        if (class_exists(\putyourlightson\blitz\Blitz::class)) {
            \putyourlightson\blitz\Blitz::$plugin->generateCache->options->cachingEnabled = false;
        }

        return parent::beforeAction($action);
    }

    /**
     * GET /accessibility-audit/overlay.js
     *
     * The loader script a decoupled front end includes with a script tag. It
     * is inert without a stored or fragment-carried overlay token, so it is
     * public, identical for everyone, and served cacheable — Cloudflare's
     * extension-based default caching may hold it at the edge for the short
     * max-age below. The heavy assets it references are Craft-published URLs
     * whose paths change on update, so a cached loader can't pin old code for
     * longer than that window. On the Standard edition, or with the feature
     * switched off, a no-op stub is served instead so front-end builds never
     * break on a licence or settings change.
     *
     * @return Response
     * @throws \yii\base\InvalidConfigException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionScript(): Response
    {
        $this->response->format = Response::FORMAT_RAW;
        $this->response->getHeaders()
            ->set('Content-Type', 'application/javascript; charset=utf-8')
            ->set('Cache-Control', 'public, max-age=300');

        $plugin = AccessibilityAudit::getInstance();
        if (!$plugin->isPro() || !$plugin->getSettings()->decoupledOverlay) {
            $this->response->data = '/* Accessibility Audit overlay is not enabled on this install. */' . "\n";
            return $this->response;
        }

        $overlay = $plugin->getOverlay();
        $assetManager = Craft::$app->getAssetManager();
        $published = static fn(string $file): string => (string)$assetManager->getPublishedUrl(
            '@johnhenry/accessibilityaudit/resources',
            true,
            $file,
        );

        $boot = [
            'resolveUrl' => $overlay->absoluteFromRequest('/accessibility-audit/overlay/resolve'),
            'cssUrls' => [
                $overlay->absoluteFromRequest($published('css/frontend-axe.css')),
            ],
            // Order matters: the shared helpers must execute before
            // frontend-axe.js reads them; axe itself is lazy-loaded by
            // frontend-axe.js from the config's axeSrc.
            'jsUrls' => [
                $overlay->absoluteFromRequest($published('js/accessibility-audit-shared.js')),
                $overlay->absoluteFromRequest($published('js/frontend-axe.js')),
            ],
        ];

        $loader = (string)file_get_contents(
            Craft::getAlias('@johnhenry/accessibilityaudit/resources/js/overlay-loader.js')
        );

        $this->response->data = 'window.__A11Y_OVERLAY_BOOT__ = ' . Json::encode($boot) . ";\n" . $loader;

        return $this->response;
    }

    /**
     * POST /accessibility-audit/overlay/resolve
     *
     * Authenticates the token and turns the posted page URL into the overlay
     * config payload: the matched element, its latest stored scan, the
     * resolved axe tags, and absolute endpoint URLs. Doubles as the token
     * verification step for the loader's activation flow — a 401 here tells
     * the loader to forget a revoked token.
     *
     * @return Response
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionResolve(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        if (($refusal = $this->_requireOverlayAccess()) !== null) {
            return $refusal;
        }

        $url = (string)$this->request->getBodyParam('url', '');
        if ($url === '') {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'No URL provided.')]);
        }

        $this->response->setNoCacheHeaders();

        $overlay = AccessibilityAudit::getInstance()->getOverlay();
        $resolved = $overlay->resolveElementFromUrl($url);

        return $this->asJson([
            'success' => true,
            'config' => $overlay->buildConfig($resolved['element'], $resolved['siteId'], true),
        ]);
    }

    /**
     * POST /accessibility-audit/overlay/store-axe-results
     *
     * Token-authenticated twin of AuditController::actionStoreAxeResults().
     * The session variant fences the posted site against the user's editable
     * sites; here the token is generated by an admin and grants install-wide
     * scan rights, so the fence is that the site exists — the element-type and
     * URI exclusions still apply through ensureScan().
     *
     * @return Response
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     * @throws \Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionStoreAxeResults(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        if (($refusal = $this->_requireOverlayAccess()) !== null) {
            return $refusal;
        }

        $this->response->setNoCacheHeaders();

        $audit = AccessibilityAudit::getInstance()->getAudit();
        $scanId = (int)$this->request->getBodyParam('scanId', 0);
        $rawViolations = $this->request->getBodyParam('violations', []);
        $decodedViolations = is_array($rawViolations) ? $rawViolations : Json::decodeIfJson($rawViolations);
        $violations = is_array($decodedViolations) ? $decodedViolations : [];
        $rawIncomplete = $this->request->getBodyParam('incomplete', []);
        $decodedIncomplete = is_array($rawIncomplete) ? $rawIncomplete : Json::decodeIfJson($rawIncomplete);
        $incomplete = is_array($decodedIncomplete) ? $decodedIncomplete : [];
        $elementId = (int)$this->request->getBodyParam('elementId', 0);
        $elementType = (string)$this->request->getBodyParam('elementType', '');
        $siteId = (int)($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_requireExistingSite($siteId)) !== null) {
            return $refusal;
        }

        if ($scanId === 0 && $elementId > 0) {
            $scanId = $audit->ensureScan($elementId, $elementType, $siteId);
        }

        $summary = null;
        if ($scanId > 0) {
            $audit->storeAxeIssues($scanId, $violations, $this->_resolveViewport(), $incomplete);
            $scan = $audit->getScanSummary($scanId);
            if ($scan !== null) {
                $summary = [
                    'score' => (int)$scan['score'],
                    'errorCount' => (int)$scan['errorCount'],
                    'warningCount' => (int)$scan['warningCount'],
                    'noticeCount' => (int)$scan['noticeCount'],
                    'scannedLabel' => Craft::$app->getFormatter()->asDatetime($scan['dateScanned'], 'short'),
                ];
            }
        }

        return $this->asJson(['success' => true, 'scanId' => $scanId, 'scan' => $summary]);
    }


    /**
     * GET /accessibility-audit/overlay/page-issues
     *
     * Token-authenticated twin of DashboardController::actionPageIssues(),
     * feeding the overlay's stored-scan hydration.
     *
     * @return Response
     * @throws \yii\base\InvalidConfigException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPageIssues(): Response
    {
        if (($refusal = $this->_requireOverlayAccess()) !== null) {
            return $refusal;
        }

        $this->response->setNoCacheHeaders();

        $scanId = (int)($this->request->getQueryParam('scanId') ?? 0);
        if ($scanId === 0) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Invalid scan ID.')]);
        }

        $issues = AccessibilityAudit::getInstance()->getAudit()->getIssuesGroupedByScan($scanId);

        return $this->asJson(['success' => true, 'issues' => $issues]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Gates an overlay API action: Pro edition, the decoupled overlay setting
     * on, and a valid bearer token. Returns the refusal response, or null when
     * the request may proceed. Token refusals are 401 so the loader can tell
     * "revoked, forget the token" apart from a plain failure.
     *
     * @return Response|null
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _requireOverlayAccess(): ?Response
    {
        $plugin = AccessibilityAudit::getInstance();

        if (($refusal = $this->requireProJson('Decoupled frontend overlay')) !== null) {
            return $refusal;
        }

        if (!$plugin->getSettings()->decoupledOverlay) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'The decoupled frontend overlay is not enabled.'),
            ]);
        }

        $auth = (string)$this->request->getHeaders()->get('Authorization', '');
        $token = str_starts_with($auth, 'Bearer ') ? trim(substr($auth, 7)) : '';

        if (!$plugin->getOverlay()->isValidToken($token)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Invalid or missing token'),
            ])->setStatusCode(401);
        }

        return null;
    }

    /**
     * Refuses a siteId that doesn't exist on the install. The token grants
     * install-wide scan rights, so unlike the session endpoints no per-user
     * editable-sites fence applies here.
     *
     * @param int $siteId
     * @return Response|null
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _requireExistingSite(int $siteId): ?Response
    {
        if (Craft::$app->getSites()->getSiteById($siteId) !== null) {
            return null;
        }

        return $this->asJson([
            'success' => false,
            'error' => Craft::t('accessibility-audit', 'Unknown site.'),
        ]);
    }

    /**
     * The viewport bucket for the posted results, mirroring
     * AuditController::_resolveViewport() so both store surfaces bucket
     * desktop/mobile findings identically.
     *
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _resolveViewport(): string
    {
        $viewport = (string)$this->request->getBodyParam('viewport', '');

        if (in_array($viewport, [AuditService::VIEWPORT_DESKTOP, AuditService::VIEWPORT_MOBILE], true)) {
            return $viewport;
        }

        return AuditService::viewportForWidth((int)$this->request->getBodyParam('viewportWidth', 0));
    }
}
