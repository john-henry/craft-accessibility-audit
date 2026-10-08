<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\errors\SiteNotFoundException;
use craft\helpers\UrlHelper;
use craft\web\Response as WebResponse;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\assets\FrontendAxeAsset;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\web\Cookie;

/**
 * Builds and authenticates the frontend axe-core overlay.
 *
 * On a monolith site the overlay is injected server-side (the plugin's
 * EVENT_END_BODY listener), with its config built from the matched element and
 * the admin's session. A page served from a full-page cache never renders for
 * the admin, so a static loader on every page asks the session config
 * endpoint for the same payload instead; both go through
 * canShowFrontendOverlay() and buildSessionConfig() here.
 *
 * A decoupled front end (Next, Nuxt, Astro, any headless consumer) never
 * triggers that event either, so this service also provides the token-based
 * pieces: it verifies the overlay token, resolves the requesting page's URL
 * back to a Craft element, and builds the identical config payload, so the
 * delivery paths cannot drift.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 *
 * @property-read string[] $allowedOrigins
 */
class OverlayService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The cookie marking a browser an admin signed in from. The
     * static loader reads it to decide whether asking for the overlay is worth
     * a request; it grants nothing.
     *
     * @since 1.6.0
     */
    public const MARKER_COOKIE = 'a11yOverlay';

    // Public Methods
    // =========================================================================

    /**
     * Whether a presented plaintext token matches the stored overlay token
     * hash. Mirrors the CI token check: settings store only the SHA-256 hash
     * (they land in project config, which is committed), so the presented
     * token is hashed and compared with hash_equals().
     *
     * @param string $token The plaintext token presented by the loader.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function isValidToken(string $token): bool
    {
        $configuredHash = trim((string)(AccessibilityAudit::getInstance()->getSettings()->overlayApiToken ?? ''));

        return $configuredHash !== '' && $token !== '' && hash_equals($configuredHash, hash('sha256', $token));
    }

    /**
     * Whether an Origin header value may call the overlay endpoints.
     *
     * The allow-list is the origins of every site's base URL plus any extra
     * origins configured in settings (covering dev servers and preview
     * deployments Craft doesn't know about), plus the CMS's own origin.
     *
     * @param string $origin The Origin header value (scheme://host[:port]).
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function isAllowedOrigin(string $origin): bool
    {
        $origin = rtrim(trim($origin), '/');

        return $origin !== '' && in_array($origin, $this->getAllowedOrigins(), true);
    }

    /**
     * All origins allowed to call the overlay endpoints.
     *
     * @return string[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getAllowedOrigins(): array
    {
        $origins = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();
            if ($baseUrl && ($parsed = $this->_origin($baseUrl)) !== null) {
                $origins[] = $parsed;
            }
        }

        $extra = AccessibilityAudit::getInstance()->getSettings()->overlayAllowedOrigins;
        foreach (preg_split('/[\r\n,]+/', $extra) ?: [] as $line) {
            $line = rtrim(trim($line), '/');
            if ($line !== '') {
                $origins[] = $line;
            }
        }

        // The CMS's own origin, so a monolith page loading the loader by
        // script tag (rather than via injection) still passes.
        $request = Craft::$app->getRequest();
        if (!$request->getIsConsoleRequest()) {
            $origins[] = $request->getHostInfo();
        }

        return array_values(array_unique(array_filter($origins)));
    }

    /**
     * Resolves a front-end page URL back to the Craft element it renders.
     *
     * The site is picked by matching the URL's origin (and base path) against
     * each site's base URL, longest base-path match winning so sub-path sites
     * resolve correctly. When no site claims the origin (typically a local
     * dev server on a different port), every site is tried in turn, primary
     * first, matching by URI alone. The element must be one of the scanned
     * element types. A URI matched by an excluded pattern resolves to no
     * element and is flagged `excluded`, so the loader keeps the overlay off it.
     *
     * `uri` is the page's path relative to the site it resolved to.
     *
     * @param string $url The full URL of the page the overlay is running on.
     * @return array{element: ElementInterface|null, siteId: int, excluded: bool, uri: string}
     * @throws SiteNotFoundException|InvalidConfigException
     * @since 1.0.0
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function resolveElementFromUrl(string $url): array
    {
        $sites = Craft::$app->getSites();
        $primaryId = $sites->getPrimarySite()->id;

        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            return ['element' => null, 'siteId' => $primaryId, 'excluded' => false, 'uri' => ''];
        }

        $origin = $this->_origin($url);
        $path = '/' . ltrim((string)($parsed['path'] ?? '/'), '/');

        // Origin-matched candidates first: [siteId => uri], longest base path wins.
        $candidates = [];
        foreach ($sites->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();
            if (!$baseUrl || $this->_origin($baseUrl) !== $origin) {
                continue;
            }

            $basePath = rtrim('/' . ltrim((string)(parse_url($baseUrl, PHP_URL_PATH) ?: '/'), '/'), '/');
            // Segment-boundary match: a site based at /de must not claim /design.
            if ($basePath !== '' && $path !== $basePath && !str_starts_with($path, $basePath . '/')) {
                continue;
            }

            $uri = trim(substr($path, strlen($basePath)), '/');
            $candidates[strlen($basePath)][] = [$site->id, $uri];
        }

        if ($candidates !== []) {
            krsort($candidates);
            foreach ($candidates as $group) {
                foreach ($group as [$siteId, $uri]) {
                    if ($this->isPageExcluded(null, $uri, (int)$siteId)) {
                        return ['element' => null, 'siteId' => (int)$siteId, 'excluded' => true, 'uri' => $uri];
                    }
                    $element = $this->_findByUri($uri, $siteId);
                    if ($element !== null) {
                        return ['element' => $element, 'siteId' => $siteId, 'excluded' => false, 'uri' => $uri];
                    }
                }
            }
            // The origin belongs to a site but nothing matched the URI: stay on
            // that site so the overlay at least reports against the right one.
            $first = reset($candidates)[0];
            return ['element' => null, 'siteId' => $first[0], 'excluded' => false, 'uri' => $first[1]];
        }

        // No site claims the origin (dev server, preview deploy): try the URI
        // against every site, primary first.
        $uri = trim($path, '/');
        $siteIds = array_map(static fn($site) => $site->id, $sites->getAllSites());
        usort($siteIds, static fn(int $a, int $b) => ($b === $primaryId) <=> ($a === $primaryId));

        foreach ($siteIds as $siteId) {
            if ($this->isPageExcluded(null, $uri, (int)$siteId)) {
                return ['element' => null, 'siteId' => (int)$siteId, 'excluded' => true, 'uri' => $uri];
            }
            $element = $this->_findByUri($uri, $siteId);
            if ($element !== null) {
                return ['element' => $element, 'siteId' => (int)$siteId, 'excluded' => false, 'uri' => $uri];
            }
        }

        return ['element' => null, 'siteId' => $primaryId, 'excluded' => false, 'uri' => $uri];
    }

    /**
     * Builds the overlay's config payload (`window.__accessibilityAudit`) for
     * a resolved element, shared by the server-side injection path and the
     * decoupled resolve endpoint so the two cannot drift.
     *
     * CSRF fields and the token are deliberately absent: the injection path
     * adds the session's CSRF pair, while the decoupled loader appends the
     * token client-side; neither credential belongs in the shared shape.
     *
     * @param ElementInterface|null $element The matched element, or null when
     *                                       the page maps to no scannable element.
     * @param int $siteId The site the page belongs to.
     * @param bool $absoluteUrls Whether URLs must carry the CMS origin
     *                           (required cross-origin; the injection path
     *                           keeps Craft's defaults).
     * @return array<string, mixed> The config payload.
     * @throws InvalidConfigException|Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function buildConfig(?ElementInterface $element, int $siteId, bool $absoluteUrls = false): array
    {
        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        $scanId = 0;
        $elementId = 0;
        $elementType = '';
        $storedScan = null;

        // A draft or revision is what Craft hands over inside a preview pane.
        // The overlay still runs so the markup can be checked, but nothing it
        // finds is stored against a draft.
        $isDerivative = $element !== null && $element->getIsDerivative();

        if ($element && $element->id && !$isDerivative) {
            $elementId = (int)$element->id;
            $elementType = get_class($element);
            $siteId = (int)$element->siteId;

            $latestScan = $plugin->getAudit()->getLatestScan($elementId, $siteId);
            if ($latestScan) {
                $scanId = (int)$latestScan['id'];
                $storedScan = [
                    'score' => (int)$latestScan['score'],
                    'errorCount' => (int)$latestScan['errorCount'],
                    'warningCount' => (int)$latestScan['warningCount'],
                    'noticeCount' => (int)$latestScan['noticeCount'],
                    'scannedLabel' => Craft::$app->getFormatter()->asDatetime($latestScan['dateScanned'], 'short'),
                ];
            }
        }

        $axeSrc = (string)Craft::$app->getAssetManager()->getPublishedUrl(
            '@johnhenry/accessibilityaudit/resources',
            true,
            'axe/axe.min.js',
        );

        $storeUrl = $absoluteUrls
            ? $this->absoluteFromRequest('/accessibility-audit/overlay/store-axe-results')
            : UrlHelper::actionUrl('accessibility-audit/audit/store-axe-results');
        $pageIssuesUrl = $absoluteUrls
            ? $this->absoluteFromRequest('/accessibility-audit/overlay/page-issues')
            : UrlHelper::actionUrl('accessibility-audit/dashboard/page-issues');

        // The report belongs to the canonical element even in a preview.
        $reportElementId = $isDerivative ? (int)$element->getCanonicalId() : $elementId;

        $reportUrl = $reportElementId > 0
            ? UrlHelper::cpUrl('accessibility-audit/page-report', ['elementId' => $reportElementId, 'siteId' => $siteId])
            : UrlHelper::cpUrl('accessibility-audit');
        if ($absoluteUrls) {
            $reportUrl = $this->absoluteFromRequest($reportUrl);
            $axeSrc = $this->absoluteFromRequest($axeSrc);
        }

        // Token requests carry no user session, so the per-admin "useShapes"
        // preference can't be read there; the config-file default still applies.
        $identity = Craft::$app->getUser()->getIdentity();
        $a11yDefaults = Craft::$app->getConfig()->getGeneral()->accessibilityDefaults;
        $useShapes = (bool)($identity?->getPreference('useShapes') ?? ($a11yDefaults['useShapes'] ?? false));

        return [
            'axeSrc' => $axeSrc,
            'storeUrl' => $storeUrl,
            'scanId' => $scanId,
            'elementId' => $elementId,
            'elementType' => $elementType,
            'siteId' => $siteId,
            'axeTags' => $plugin->getAudit()->getAxeTags(),
            'axeExclude' => $plugin->getAudit()->getAxeExclude(),
            'collapseWhenIdle' => (bool)$settings->overlayCollapseWhenIdle,
            'position' => $settings->overlayPosition ?: 'bottom-right',
            'idleMs' => max(3, (int)$settings->overlayIdleSeconds) * 1000,
            'reportUrl' => $reportUrl,
            'storedScan' => $storedScan,
            'pageIssuesUrl' => $pageIssuesUrl,
            'useShapes' => $useShapes,
            // False inside a preview pane: scan and show, but post nothing.
            'storeResults' => !$isDerivative,
            // The language every string in `strings` was translated into, so
            // the JS can set `lang` on the panel and trigger button: neither
            // path (server injection or the decoupled loader) carries the
            // page's own `<html lang>`, since a headless front end's markup
            // is never read here.
            'lang' => Craft::$app->language,
            'strings' => $this->_overlayStrings(),
        ];
    }

    /**
     * The overlay config for a page Craft renders, with the current session's
     * CSRF pair the overlay stores its results with.
     *
     * The CSRF value belongs to one session, so this payload may only reach
     * the admin it was built for: an uncacheable render or an uncached JSON
     * response, never anything a shared cache could hand to someone else.
     *
     * @param ElementInterface|null $element The matched element, if any.
     * @param int $siteId The site the page belongs to.
     * @return array<string, mixed>
     * @throws InvalidConfigException|Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    public function buildSessionConfig(?ElementInterface $element, int $siteId): array
    {
        $config = $this->buildConfig($element, $siteId);
        $config['csrfName'] = Craft::$app->getConfig()->getGeneral()->csrfTokenName;
        $config['csrfValue'] = Craft::$app->getRequest()->getCsrfToken();

        return $config;
    }

    /**
     * Whether the front-end overlay may run for the current user on a page
     * Craft serves.
     *
     * The one gate for both delivery paths on such a page: the server-side
     * injection and the session config endpoint a cached page's loader calls.
     * The overlay setting has to be on and the user an admin. On Standard the
     * page has to be on the primary site, since multi-site is a Pro feature
     * and the plugin stores nothing for the other sites. An excluded page is
     * refused, since nothing found there could be stored.
     *
     * The identity is resolved here, so call this from a request handler or an
     * event listener, never during init(): resolving it at bootstrap caches the
     * User element before Craft Commerce registers CustomerBehavior, stripping
     * it off currentUser.
     *
     * @param ElementInterface|null $element The page's element, if any.
     * @param string $path The page's path relative to its site, for a page
     *                     with no element or an element with no URI.
     * @param int $siteId The site the page belongs to.
     * @return bool
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    public function canShowFrontendOverlay(?ElementInterface $element, string $path, int $siteId): bool
    {
        $plugin = AccessibilityAudit::getInstance();

        if (!$plugin->getSettings()->frontendAxe) {
            return false;
        }

        if (!Craft::$app->getUser()->getIsAdmin()) {
            return false;
        }

        if (!$plugin->isPro() && $siteId !== (int)Craft::$app->getSites()->getPrimarySite()->id) {
            return false;
        }

        return !$this->isPageExcluded($element, $path, $siteId);
    }

    /**
     * Published URLs of the overlay's stylesheets and scripts, in the order a
     * page has to load them. Read off the asset bundle the injection path
     * registers, so an overlay loaded late gets exactly the same files.
     *
     * @return array{css: string[], js: string[]}
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    public function frontendAssetUrls(): array
    {
        $assetManager = Craft::$app->getAssetManager();
        $bundle = $assetManager->getBundle(FrontendAxeAsset::class);
        $url = static fn(string|array $file): string => $assetManager->getAssetUrl(
            $bundle,
            is_array($file) ? (string)$file[0] : $file,
        );

        return [
            'css' => array_map($url, $bundle->css),
            'js' => array_map($url, $bundle->js),
        ];
    }

    /**
     * Keeps the current response out of every shared cache: no-cache headers
     * for proxies and CDNs, and Blitz told not to store it.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    public function preventFullPageCaching(): void
    {
        $response = Craft::$app->getResponse();

        if ($response instanceof WebResponse) {
            $response->setNoCacheHeaders();
        }

        if (class_exists(\putyourlightson\blitz\Blitz::class)) {
            \putyourlightson\blitz\Blitz::$plugin->generateCache->options->cachingEnabled = false;
        }
    }

    /**
     * Root-relative URL of the session config endpoint, for the static loader.
     *
     * The loader's tag is cached along with the page, so the URL has to be the
     * same for everyone and has to reach whichever origin served the cached
     * copy, whatever scheme or host the render that produced it saw.
     *
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    public function sessionConfigUrl(): string
    {
        $parts = parse_url(UrlHelper::actionUrl('accessibility-audit/frontend-overlay/config'));
        $path = '/' . ltrim((string)($parts['path'] ?? ''), '/');

        return isset($parts['query']) ? $path . '?' . $parts['query'] : $path;
    }

    /**
     * Marks the browser on the current response as one an admin signed in
     * from, so the static loader knows a request for the overlay is worth
     * making.
     *
     * Not HttpOnly, since the loader has to read it, and it grants nothing:
     * the session config endpoint decides from the session alone. Domain and
     * Secure follow Craft's own cookie config, so the marker is seen on every
     * host the session cookie is.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    public function addMarkerCookie(): void
    {
        $response = Craft::$app->getResponse();

        if ($response instanceof WebResponse) {
            $response->getCookies()->add(new Cookie($this->_markerCookieConfig() + ['value' => '1']));
        }
    }

    /**
     * Whether the current request carries the admin marker cookie.
     *
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    public function hasMarkerCookie(): bool
    {
        $request = Craft::$app->getRequest();

        return !$request->getIsConsoleRequest() && $request->getCookies()->has(self::MARKER_COOKIE);
    }

    /**
     * Expires the admin marker cookie on the current response.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    public function removeMarkerCookie(): void
    {
        $response = Craft::$app->getResponse();

        if ($response instanceof WebResponse) {
            $response->getCookies()->remove(new Cookie($this->_markerCookieConfig()));
        }
    }

    /**
     * Whether the page the overlay would run on is excluded from scanning.
     *
     * A matched element is judged by its own URI, or its canonical's inside a
     * preview, since an unsaved draft may carry none. A page with no element
     * behind it is judged by its request path.
     *
     * @param ElementInterface|null $element The matched element, if any.
     * @param string $path The page's path relative to its site, used when there
     *                     is no element or the element has no URI.
     * @param int $siteId The site the page belongs to.
     * @return bool
     * @throws InvalidConfigException
     * @since 1.4.0
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function isPageExcluded(?ElementInterface $element, string $path, int $siteId): bool
    {
        $audit = AccessibilityAudit::getInstance()->getAudit();

        if ($element !== null) {
            $source = $element->getIsDerivative() ? $element->getCanonical() : $element;
            if ($source->uri !== null) {
                return $audit->isUriExcluded($source->uri, (int)$source->siteId);
            }
        }

        return $audit->isUriExcluded(trim($path, '/'), $siteId);
    }

    /**
     * Prefixes a root-relative URL with the current request's scheme and host,
     * so a cross-origin consumer calls back to the CMS rather than resolving
     * the path against its own origin. Site base URLs are no use here: on a
     * decoupled install they point at the front end, not at Craft.
     *
     * @param string $url A root-relative or already-absolute URL.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function absoluteFromRequest(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return Craft::$app->getRequest()->getHostInfo() . '/' . ltrim($url, '/');
    }

    // Private Methods
    // =========================================================================

    /**
     * Cookie config for the admin marker. SameSite Lax so it travels on a
     * top-level visit from another site, and readable by script.
     *
     * @return array<string, mixed>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    private function _markerCookieConfig(): array
    {
        return Craft::cookieConfig([
            'name' => self::MARKER_COOKIE,
            'path' => '/',
            'httpOnly' => false,
            'sameSite' => Cookie::SAME_SITE_LAX,
        ]);
    }

    /**
     * The scheme://host[:port] origin of a URL, or null when it has no host.
     *
     * @param string $url
     * @return string|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _origin(string $url): ?string
    {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            return null;
        }

        $origin = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'];
        if (!empty($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }

        return $origin;
    }

    /**
     * Finds a live element by URI on a site, restricted to the scanned element
     * types and honouring the excluded-URI patterns, mirroring the crawler's
     * own fences.
     *
     * @param string $uri The URI with no leading/trailing slashes; '' means home.
     * @param int $siteId
     * @return ElementInterface|null
     * @throws InvalidConfigException
     * @since 1.0.0
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    private function _findByUri(string $uri, int $siteId): ?ElementInterface
    {
        $plugin = AccessibilityAudit::getInstance();
        $lookup = $uri === '' ? Element::HOMEPAGE_URI : $uri;

        if ($plugin->getAudit()->isUriExcluded($lookup, $siteId)) {
            return null;
        }

        // One query, the same lookup Craft's own routing uses, instead of one
        // element query per scannable type: resolve fires on every page view
        // of an activated browser, so this path has to stay cheap.
        $element = Craft::$app->getElements()->getElementByUri($lookup, $siteId, true);

        if ($element === null || !in_array(get_class($element), $plugin->getSettings()->resolvedScannedElementTypes(), true)) {
            return null;
        }

        return $element;
    }

    /**
     * Every visible or accessible-name string the frontend overlay renders,
     * translated into `Craft::$app->language` (the same language reported
     * under the config's `lang` key).
     *
     * Placeholders such as `{count}` and `{label}` are left unresolved: the
     * values they carry (a live scan's issue count, a stored scan's date) are
     * only known client-side, so the overlay substitutes them itself. No
     * `$params` are passed to [[Craft::t()]] here, which is exactly what
     * keeps those tokens literal instead of triggering ICU formatting.
     *
     * @return array<string, string>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _overlayStrings(): array
    {
        return [
            'title' => Craft::t('accessibility-audit', 'Accessibility Audit'),
            'rescan' => Craft::t('accessibility-audit', 'Re-scan'),
            'scanning' => Craft::t('accessibility-audit', 'Scanning…'),
            'closePanel' => Craft::t('accessibility-audit', 'Close panel'),
            'resultsLabel' => Craft::t('accessibility-audit', 'Results'),
            'tabIssues' => Craft::t('accessibility-audit', 'Issues'),
            'tabPassed' => Craft::t('accessibility-audit', 'Passed'),
            'clickRescanHint' => Craft::t('accessibility-audit', 'Click "Re-scan" to analyse this page for WCAG issues.'),
            'previewNotSaved' => Craft::t('accessibility-audit', 'Preview: results are not saved'),
            'scannedPrefix' => Craft::t('accessibility-audit', 'Scanned {label}'),
            'openFullReport' => Craft::t('accessibility-audit', 'Open full report'),
            'axeNotFound' => Craft::t('accessibility-audit', 'axe-core could not be located.'),
            'runningAxe' => Craft::t('accessibility-audit', 'Running axe-core…'),
            'axeErrorPrefix' => Craft::t('accessibility-audit', 'axe-core error: {message}'),
            'noIssues' => Craft::t('accessibility-audit', 'No issues found on this page.'),
            'noPasses' => Craft::t('accessibility-audit', 'No passing checks recorded for this page.'),
            'highlight' => Craft::t('accessibility-audit', 'Highlight'),
            'loadingStored' => Craft::t('accessibility-audit', 'Loading stored results…'),
            'loadFailed' => Craft::t('accessibility-audit', 'Couldn\'t load the stored scan. Click "Re-scan" to run a live check.'),
            'hiddenTargetNotice' => Craft::t('accessibility-audit', 'The highlighted element is inside a collapsed menu or panel. Open it to see the flash.'),
            'openPanel' => Craft::t('accessibility-audit', 'Open Accessibility Audit panel'),
            'openPanelIssueSingular' => Craft::t('accessibility-audit', 'Open Accessibility Audit panel, {count} issue'),
            'openPanelIssuePlural' => Craft::t('accessibility-audit', 'Open Accessibility Audit panel, {count} issues'),
            'sevError' => Craft::t('accessibility-audit', 'Error'),
            'sevWarning' => Craft::t('accessibility-audit', 'Warning'),
            'sevNotice' => Craft::t('accessibility-audit', 'Notice'),
            'sevReview' => Craft::t('accessibility-audit', 'Review'),
            'bestPractice' => Craft::t('accessibility-audit', 'best practice'),
            'elementSingular' => Craft::t('accessibility-audit', '{count} element'),
            'elementPlural' => Craft::t('accessibility-audit', '{count} elements'),
            'contrastBelowMin' => Craft::t('accessibility-audit', 'Colour contrast below the minimum ratio'),
            'contrastNeedsReview' => Craft::t('accessibility-audit', 'Colour contrast needs manual review'),
            'scoreEstimated' => Craft::t('accessibility-audit', '/100 est.'),
            'scoreFinal' => Craft::t('accessibility-audit', '/100'),
        ];
    }
}
