<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\web\Controller;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use johnhenry\accessibilityaudit\helpers\UrlSafety;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Runs readability analysis for URLs and Craft elements, and renders the
 * readability report page.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class ReadabilityController extends Controller
{
    // Traits
    // =========================================================================

    use ProGateTrait;

    // Const Properties
    // =========================================================================

    /**
     * @var int The minimum number of seconds a user must wait between
     *          readability analyses (per-user throttle).
     */
    public const THROTTLE_SECONDS = 10;

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $elementId = $this->request->getQueryParam('elementId');
        $elementId = $elementId !== null ? (int) $elementId : null;
        $siteId = AccessibilityAudit::getInstance()->requestedSiteId();
        $siteHandle = AccessibilityAudit::getInstance()->requestedSite()->handle;
        $sites = AccessibilityAudit::getInstance()->allowedSites();

        // Readability is a Pro-only feature. The page is reachable from the CP
        // nav, so render an upsell state on Standard rather than throwing. The
        // stats/results queries only run when they'll actually be shown.
        $isPro = AccessibilityAudit::getInstance()->isPro();

        if (!$isPro) {
            return $this->renderTemplate('accessibility-audit/readability', [
                'isPro' => false,
                'siteId' => $siteId,
                'siteHandle' => $siteHandle,
                'sites' => $sites,
            ]);
        }

        $service = AccessibilityAudit::getInstance()->readability;
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $hasApiKey = trim(App::parseEnv($settings->anthropicApiKey ?? '')) !== '';

        return $this->renderTemplate('accessibility-audit/readability', [
            'isPro' => true,
            'hasApiKey' => $hasApiKey,
            'stats' => $service->getStats($siteId),
            'results' => $service->getResults(elementId: $elementId, siteId: $siteId),
            'filteredElementId' => $elementId,
            'siteId' => $siteId,
            'siteHandle' => $siteHandle,
            'sites' => $sites,
        ]);
    }

    /**
     * Analyse a URL and store the result.
     *
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     */
    public function actionAnalyse(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:viewReports');

        if (($refusal = $this->requireProJson('Readability analysis')) !== null) {
            return $refusal;
        }

        $url = trim((string) $this->request->getRequiredBodyParam('url'));
        $withClaude = (bool) $this->request->getBodyParam('withClaude', false);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Please enter a valid URL.')]);
        }

        // Rate limit the (potentially AI-backed) analysis per user to prevent
        // a user from looping calls and driving unbounded Anthropic API spend.
        if (($wait = $this->_rateLimitWait()) > 0) {
            return $this->asJson([
                'success' => false,
                // Machine-readable wait so the CP button can count down
                // instead of reporting a throttle as a failure.
                'retryAfter' => $wait,
                'error' => Craft::t(
                    'accessibility-audit',
                    'Please wait {seconds} seconds before analysing again.',
                    ['seconds' => $wait],
                ),
            ]);
        }

        // SSRF guard: reject private/reserved hosts and non-http(s) schemes
        // before making any outbound request.
        try {
            UrlSafety::assertSafeUrl($url);
        } catch (UnsafeUrlException $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        try {
            $service = AccessibilityAudit::getInstance()->readability;

            /* Fetch once, reuse for title extraction and analysis. The redirect
               config re-validates each hop's host to block redirect-based SSRF. */
            $client = Craft::createGuzzleClient(array_merge(
                ['timeout' => 15],
                UrlSafety::guzzleRedirectConfig(),
            ));
            $response = $client->get($url);
            $html = (string) $response->getBody();

            $result = $service->analyseHtml($html, $url, $withClaude);

            if (isset($result['error'])) {
                return $this->asJson(['success' => false, 'error' => $result['error']]);
            }

            $title = $service->extractPageTitle($html);

            // If the URL belongs to one of this install's own elements,
            // store the result against it (as if run from that element's own
            // field) rather than always falling back to a URL-only row.
            $resolved = $service->resolveElementForUrl($url);
            $service->storeResult($result, $resolved['elementId'] ?? null, $resolved['siteId'] ?? null, $url, $title);

            return $this->asJson(['success' => true, 'result' => $result]);
        } catch (Throwable $e) {
            Craft::error('ReadabilityController: ' . $e->getMessage(), 'accessibility-audit');
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Analysis failed. Check Craft logs for details.')]);
        }
    }

    /**
     * Analyse a Craft element's field content and store the result.
     *
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     */
    public function actionAnalyseEntry(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:viewReports');

        if (($refusal = $this->requireProJson('Readability analysis')) !== null) {
            return $refusal;
        }

        $elementId = (int)  $this->request->getRequiredBodyParam('elementId');
        $siteId = (int)  $this->request->getBodyParam('siteId', Craft::$app->getSites()->getCurrentSite()->id);
        $withClaude = (bool) $this->request->getBodyParam('withClaude', false);

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
        if (!$element) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Element not found.')]);
        }

        // Rate limit: this path can also reach the Anthropic API via Claude.
        if (($wait = $this->_rateLimitWait()) > 0) {
            return $this->asJson([
                'success' => false,
                // Machine-readable wait so the CP button can count down
                // instead of reporting a throttle as a failure.
                'retryAfter' => $wait,
                'error' => Craft::t(
                    'accessibility-audit',
                    'Please wait {seconds} seconds before analysing again.',
                    ['seconds' => $wait],
                ),
            ]);
        }

        try {
            $service = AccessibilityAudit::getInstance()->readability;
            $result = $service->analyseElement($element, $withClaude);

            if (isset($result['error'])) {
                return $this->asJson(['success' => false, 'error' => $result['error']]);
            }

            $url = $element->getUrl() ?? '';
            $title = property_exists($element, 'title') ? (string) $element->title : '';
            $service->storeResult($result, $elementId, $siteId, $url, $title);

            return $this->asJson(['success' => true, 'result' => $result]);
        } catch (Throwable $e) {
            Craft::error('ReadabilityController::actionAnalyseEntry: ' . $e->getMessage(), 'accessibility-audit');
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Analysis failed. Check Craft logs for details.')]);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Enforces a per-user throttle on readability analysis.
     *
     * Sets a short-lived cache flag keyed on the current user ID. Returns the
     * number of seconds the caller must wait, or 0 when a fresh request is
     * permitted (in which case the throttle window is (re)started).
     *
     * @return int The remaining wait time in seconds, or 0 when permitted.
     * @throws ForbiddenHttpException If no user is logged in.
     */
    private function _rateLimitWait(): int
    {
        $userId = Craft::$app->getUser()->getId();
        if ($userId === null) {
            throw new ForbiddenHttpException();
        }

        $cache = Craft::$app->getCache();
        $key = "accessibility-audit:readability-throttle:$userId";
        $until = (int) $cache->get($key);
        $now = time();

        if ($until > $now) {
            return $until - $now;
        }

        $cache->set($key, $now + self::THROTTLE_SECONDS, self::THROTTLE_SECONDS);

        return 0;
    }
}
