<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\web\Controller;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * Hands the front-end overlay to an admin viewing a page that came out of a
 * full-page cache.
 *
 * A cached page never renders for the admin, so the server-side injection
 * can't reach it. The static loader on every page calls this endpoint instead,
 * same-origin and with the session cookie, and gets the payload the injection
 * would have written. The session is the only thing trusted here: the marker
 * cookie that prompts the loader to call is a hint, and the eligibility check
 * is the one the injection path uses.
 *
 * The answer is per-session and carries a CSRF token, so it is never cached
 * and sends no CORS headers: a cross-origin page can't read it.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.6.0
 */
class FrontendOverlayController extends Controller
{
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['config'];

    // Public Methods
    // =========================================================================

    /**
     * GET actions/accessibility-audit/frontend-overlay/config?url=…
     *
     * The overlay config, CSRF pair and asset URLs for the page at `url`, or
     * `active: false` when the overlay shouldn't run there for this session.
     * A guest or non-admin also has the marker cookie expired, so a stale
     * marker costs one request at most. An admin keeps it on an ineligible
     * page (excluded, or a non-primary site on Standard), since the next page
     * may well be eligible.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    public function actionConfig(): Response
    {
        if (!$this->request->getIsGet()) {
            throw new MethodNotAllowedHttpException();
        }

        $this->requireAcceptsJson();

        $overlay = AccessibilityAudit::getInstance()->getOverlay();
        $overlay->preventFullPageCaching();

        if (!Craft::$app->getUser()->getIsAdmin()) {
            $overlay->removeMarkerCookie();

            return $this->asJson(['success' => true, 'active' => false]);
        }

        $url = trim((string)$this->request->getQueryParam('url', ''));

        if ($url === '') {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'No URL provided.')]);
        }

        if (!$this->_isOnRequestHost($url)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'That URL is not one this site scans.'),
            ]);
        }

        try {
            $resolved = $overlay->resolveElementFromUrl($url);

            if (!$overlay->canShowFrontendOverlay($resolved['element'], $resolved['uri'], $resolved['siteId'])) {
                return $this->asJson(['success' => true, 'active' => false]);
            }

            $assets = $overlay->frontendAssetUrls();

            return $this->asJson([
                'success' => true,
                'active' => true,
                'config' => $overlay->buildSessionConfig($resolved['element'], $resolved['siteId']),
                'cssUrls' => $assets['css'],
                'jsUrls' => $assets['js'],
            ]);
        } catch (Throwable $e) {
            Craft::error('Front-end overlay config failed: ' . $e->getMessage(), 'accessibility-audit');

            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Error, please try again.')]);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether a page URL is an http(s) URL on the host this request came to.
     * The loader always calls from the page it runs on, so any other host is
     * a URL this endpoint has no business resolving.
     *
     * @param string $url
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    private function _isOnRequestHost(string $url): bool
    {
        $parsed = parse_url($url);

        if ($parsed === false || empty($parsed['host'])) {
            return false;
        }

        if (!in_array(strtolower((string)($parsed['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }

        return strtolower($parsed['host']) === strtolower((string)$this->request->getHostName());
    }
}
