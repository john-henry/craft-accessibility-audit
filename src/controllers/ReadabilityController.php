<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\web\Controller;
use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use johnhenry\accessibilityaudit\helpers\UrlSafety;
use johnhenry\accessibilityaudit\jobs\AnalyseReadability;
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Runs readability analysis for URLs and Craft elements, and renders the
 * readability report page.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
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
    protected array|bool|int $allowAnonymous = ['preview'];

    /**
     * Renders the readability report for an element, or the picker when none is named.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $elementId = $this->request->getQueryParam('elementId');
        $elementId = $elementId !== null ? (int) $elementId : null;
        $siteId = AccessibilityAudit::getInstance()->requestedSiteId();
        $siteHandle = AccessibilityAudit::getInstance()->requestedSite()->handle;
        $sites = AccessibilityAudit::getInstance()->allowedSites();

        // Readability is a Pro-only feature. The page is reachable from the CP
        // nav, so render an upsell state on Standard rather than throwing. The
        // stats/results queries only run when they'll actually be shown.
        $isPro = AccessibilityAudit::getInstance()->isPro();

        // Analysing fetches a page and can reach the Anthropic API, so the
        // controls for it are shown only to whoever is allowed to do that.
        // Reading the results stays on the viewing permission.
        $canRunScans = Craft::$app->getUser()->checkPermission('accessibility-audit:run-scans');

        if (!$isPro) {
            return $this->renderTemplate('accessibility-audit/readability', [
                'isPro' => false,
                'canRunScans' => $canRunScans,
                'siteId' => $siteId,
                'siteHandle' => $siteHandle,
                'sites' => $sites,
            ]);
        }

        $service = AccessibilityAudit::getInstance()->getReadability();

        return $this->renderTemplate('accessibility-audit/readability', [
            'isPro' => true,
            'canRunScans' => $canRunScans,
            'stats' => $service->getStats($siteId),
            'supportsSite' => $service->supportsSite($siteId),
            'unsupportedMessage' => $service->unsupportedMessage(),
            'filteredElementId' => $elementId,
            'siteId' => $siteId,
            'siteHandle' => $siteHandle,
            'sites' => $sites,
        ]);
    }

    /**
     * Queues a readability analysis of every page in a site the scanner covers.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException|MethodNotAllowedHttpException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function actionAnalyseAll(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:run-scans');

        if (($refusal = $this->requireProJson('Readability analysis')) !== null) {
            return $refusal;
        }

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getBodyParam('siteId'));

        if (!$plugin->getReadability()->supportsSite($siteId)) {
            return $this->asJson([
                'success' => false,
                'error' => $plugin->getReadability()->unsupportedMessage(),
            ]);
        }

        if (AnalyseReadability::isRunning($siteId)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Every page on this site is already being analysed. The table fills as it goes.'),
            ]);
        }

        Craft::$app->getCache()->set(AnalyseReadability::runningKey($siteId), true, AnalyseReadability::RUNNING_TTL);
        Craft::$app->getQueue()->push(new AnalyseReadability(['siteId' => $siteId]));

        return $this->asJson([
            'success' => true,
            'queued' => (int)$plugin->getAudit()->getUrlElementsQuery($siteId)->count()
                + count($plugin->getSettings()->resolvedCustomUrls($siteId)),
        ]);
    }

    /**
     * Queues the pages selected in the Page results table to be analysed again.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException|MethodNotAllowedHttpException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function actionAnalyseSelected(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        if (($refusal = $this->requireProJson('Readability analysis')) !== null) {
            return $refusal;
        }

        $ids = $this->request->getBodyParam('ids');
        $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));

        if ($ids === []) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Select at least one page.')]);
        }

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getBodyParam('siteId'));
        $job = new AnalyseReadability(['siteId' => $siteId, 'resultIds' => $ids]);
        $count = $job->selectedCount();

        if ($count === 0) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Select at least one page.')]);
        }

        Craft::$app->getQueue()->push($job);

        return $this->asJson([
            'success' => true,
            'queued' => $count,
            'message' => Craft::t('accessibility-audit', 'Analysing {n, plural, =1{# page} other{# pages}} in the background.', ['n' => $count]),
        ]);
    }

    /**
     * Analyse a URL and store the result.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionAnalyse(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // run-scans, not view-reports: this fetches a URL from the server and can
        // reach the Anthropic API, so it spends the site's outbound requests
        // and the account's budget. That is the scanning tier's authority, not
        // the reading tier's, whatever the results are used for afterwards.
        $this->requirePermission('accessibility-audit:run-scans');

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

        // Only this install's own pages: the results belong to a site's
        // report, and a page elsewhere has no place in one.
        $site = AccessibilityAudit::getInstance()->getReadability()->siteForUrl($url);
        if ($site === null) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Only pages on this site can be analysed. Enter an address that starts with one of the site\'s own URLs.')]);
        }

        // SSRF guard: reject private/reserved hosts and non-http(s) schemes
        // before making any outbound request.
        try {
            UrlSafety::assertSafeUrl($url);
        } catch (UnsafeUrlException $e) {
            // The guard's own messages are written to be read by whoever typed
            // the URL, so they are shown rather than swallowed. Through Craft::t
            // because they are thrown as plain strings from a helper that has
            // no view of the request's language.
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', $e->getMessage()),
            ]);
        }

        try {
            $service = AccessibilityAudit::getInstance()->getReadability();

            // A page that belongs to one of this install's own entries is scored
            // the way saving and Analyse every page score it, from the entry's
            // own text, so the three agree; the page is fetched only when the
            // entry has too little text of its own.
            $resolved = $service->resolveElementForUrl($url);
            $element = $resolved !== null
                ? Craft::$app->getElements()->getElementById($resolved['elementId'], null, $resolved['siteId'])
                : null;

            if ($element !== null && Craft::$app->getElements()->canView($element)) {
                $result = $service->analyseElement($element, $withClaude);

                if (isset($result['error'])) {
                    return $this->asJson(['success' => false, 'error' => $result['error']]);
                }

                $service->storeResult($result, (int)$element->id, (int)$element->siteId, $url, $element->title ?? '');

                return $this->asJson(['success' => true, 'result' => $result]);
            }

            /* Fetched once and reused for the title and the analysis. The
               guard validates every redirect hop and connects only to the
               addresses it validated. */
            $response = UrlSafety::fetch($url, ['timeout' => 15]);
            $html = (string) $response->getBody();

            $result = $service->analyseHtml($html, $url, $withClaude);

            if (isset($result['error'])) {
                return $this->asJson(['success' => false, 'error' => $result['error']]);
            }

            $service->storeResult($result, null, (int)$site->id, $url, $service->extractPageTitle($html));

            return $this->asJson(['success' => true, 'result' => $result]);
        } catch (Throwable $e) {
            Craft::error('ReadabilityController: ' . $e->getMessage(), 'accessibility-audit');
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Analysis failed. Check Craft logs for details.')]);
        }
    }

    /**
     * Analyse a Craft element's field content and store the result.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     * @throws MethodNotAllowedHttpException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionAnalyseEntry(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // Same tier as actionAnalyse, and for the same reason.
        $this->requirePermission('accessibility-audit:run-scans');

        if (($refusal = $this->requireProJson('Readability analysis')) !== null) {
            return $refusal;
        }

        $elementId = (int)  $this->request->getRequiredBodyParam('elementId');
        $siteId = (int)  $this->request->getBodyParam('siteId', Craft::$app->getSites()->getCurrentSite()->id);
        $withClaude = (bool) $this->request->getBodyParam('withClaude', false);
        // The Readability preview asks for the element's own text only: it is
        // showing text being written, which is never fetched or stored.
        $textOnly = (bool) $this->request->getBodyParam('textOnly', false);

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        // Drafts are found by id too, so without this the id alone would reach
        // another user's unsaved text.
        if (!$element || !Craft::$app->getElements()->canView($element)) {
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
            $service = AccessibilityAudit::getInstance()->getReadability();

            // A draft or revision is not what readers see: its own text is
            // analysed with no fallback to fetching the live page, and nothing
            // is stored, so the stored result keeps describing the published
            // version.
            $isDraft = $textOnly || $element->getIsDraft() || $element->getIsRevision();
            $result = $isDraft
                ? $service->analyseElementText($element, $withClaude)
                : $service->analyseElement($element, $withClaude);

            if (isset($result['error'])) {
                return $this->asJson(array_filter([
                    'success' => false,
                    'error' => $result['error'],
                    'charactersNeeded' => $result['charactersNeeded'] ?? null,
                ], static fn(mixed $value): bool => $value !== null));
            }

            if (!empty($result['claude'])) {
                $service->cacheSuggestions((int)$element->getCanonicalId(), (int)$element->siteId, $result['claude']);
            }

            if (!$isDraft) {
                $url = $element->getUrl() ?? '';
                $title = (string)$element->title;
                $service->storeResult($result, $elementId, $siteId, $url, $title);
            }

            return $this->asJson([
                'success' => true,
                'result' => $result,
                'stored' => !$isDraft,
            ]);
        } catch (Throwable $e) {
            Craft::error('ReadabilityController::actionAnalyseEntry: ' . $e->getMessage(), 'accessibility-audit');
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Analysis failed. Check Craft logs for details.')]);
        }
    }

    /**
     * GET actions/accessibility-audit/readability/preview
     *
     * The Readability view in an element's Preview menu: the draft being
     * edited, its scores, and its text with the long sentences marked.
     *
     * Craft's preview checks the preview token and swaps the draft in as the
     * placeholder element before routing the request here, which is what lets
     * an anonymous shared preview link through, to that draft and nothing else.
     *
     * Opened without a token, as the edit screen's View menu opens it, there is
     * no placeholder. A signed-in user who can view the element then gets its
     * saved version; anyone else gets nothing.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     * @throws InvalidConfigException
     * @throws Exception
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function actionPreview(): Response
    {
        $plugin = AccessibilityAudit::getInstance();
        $elements = Craft::$app->getElements();
        $elementId = (int)$this->request->getRequiredQueryParam('elementId');
        $siteId = (int)$this->request->getRequiredQueryParam('siteId');
        $element = $elements->getPlaceholderElement($elementId, $siteId);

        if ($element === null && !Craft::$app->getUser()->getIsGuest()) {
            $saved = $elements->getElementById($elementId, null, $siteId);
            $element = $saved !== null && $elements->canView($saved) ? $saved : null;
        }

        // Split so the log and a dev-mode error page say which of the two it
        // was. The messages never reach a production visitor, who gets the 404
        // template either way.
        if (!$plugin->isPro() || !$plugin->getSettings()->readabilityPreviewTarget) {
            throw new NotFoundHttpException('The readability preview is switched off for this site.');
        }

        // Says no more than that on purpose: this action is anonymous, and
        // naming the reason would confirm whether the element exists.
        if ($element === null) {
            throw new NotFoundHttpException('Nothing to preview.');
        }

        $readability = $plugin->getReadability();
        $cached = $readability->getCachedSuggestions((int)$element->getCanonicalId(), (int)$element->siteId);

        $canSuggest = Craft::$app->getUser()->checkPermission('accessibility-audit:run-scans')
            && trim((string)App::parseEnv($plugin->getSettings()->anthropicApiKey)) !== '';

        // Rendered as a plain template rather than a page, so the CSS and JS
        // other code registers for CP screens and front-end pages (the CP
        // stylesheet, SEO tags, the axe-core overlay) is not injected into it.
        $html = Craft::$app->getView()->renderTemplate('accessibility-audit/_readability/preview', [
            'element' => $element,
            'isDraft' => $element->getIsDerivative(),
            'preview' => $readability->previewElementText($element, $plugin->getSettings()->readabilityTarget),
            'target' => $plugin->getSettings()->readabilityTarget,
            'targets' => $readability::TARGETS,
            'suggestions' => $cached['claude'] ?? null,
            'suggestedAt' => $cached !== null ? Craft::$app->getFormatter()->asTime($cached['time'], 'short') : null,
            'canSuggest' => $canSuggest,
            'minWords' => $readability::HARD_SENTENCE_MIN_WORDS,
        ], View::TEMPLATE_MODE_CP);

        $this->response->setNoCacheHeaders();
        $this->response->format = Response::FORMAT_HTML;
        $this->response->data = $html;

        return $this->response;
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _rateLimitWait(): int
    {
        // The throttle is per user, so there is nothing to count against
        // without one. Reached only from actions that already require the
        // run-scans permission, so this is a lapsed session rather than a
        // visitor who wandered in.
        $userId = Craft::$app->getUser()->getId();

        if ($userId === null) {
            throw new ForbiddenHttpException('Sign in again to analyse readability.');
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
