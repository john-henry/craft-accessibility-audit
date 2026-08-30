<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\helpers\UrlHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Builds and authenticates the frontend axe-core overlay for pages Craft does
 * not serve itself.
 *
 * On a monolith site the overlay is injected server-side (the plugin's
 * EVENT_END_BODY listener), with its config built from the matched element and
 * the admin's session. A decoupled front end (Next, Nuxt, Astro, any headless
 * consumer) never triggers that event, so this service provides the same
 * pieces over HTTP instead: it verifies the overlay token, resolves the
 * requesting page's URL back to a Craft element, and builds the identical
 * config payload the injection path uses, so the two paths cannot drift.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class OverlayService extends Component
{
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
     * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
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
     * element types, and excluded URIs resolve to nothing, exactly as they do
     * for the crawler.
     *
     * @param string $url The full URL of the page the overlay is running on.
     * @return array{element: ElementInterface|null, siteId: int}
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function resolveElementFromUrl(string $url): array
    {
        $sites = Craft::$app->getSites();
        $primaryId = $sites->getPrimarySite()->id;

        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            return ['element' => null, 'siteId' => $primaryId];
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
                    $element = $this->_findByUri($uri, $siteId);
                    if ($element !== null) {
                        return ['element' => $element, 'siteId' => $siteId];
                    }
                }
            }
            // The origin belongs to a site but nothing matched the URI: stay on
            // that site so the overlay at least reports against the right one.
            $first = reset($candidates)[0];
            return ['element' => null, 'siteId' => $first[0]];
        }

        // No site claims the origin (dev server, preview deploy): try the URI
        // against every site, primary first.
        $uri = trim($path, '/');
        $siteIds = array_map(static fn($site) => $site->id, $sites->getAllSites());
        usort($siteIds, static fn(int $a, int $b) => ($b === $primaryId) <=> ($a === $primaryId));

        foreach ($siteIds as $siteId) {
            $element = $this->_findByUri($uri, $siteId);
            if ($element !== null) {
                return ['element' => $element, 'siteId' => (int)$siteId];
            }
        }

        return ['element' => null, 'siteId' => $primaryId];
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
     * @return array The config payload.
     * @throws InvalidConfigException
     * @author JohnHenry <info@johnhenry.ie>
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
        ];
    }

    /**
     * Prefixes a root-relative URL with the current request's scheme and host,
     * so a cross-origin consumer calls back to the CMS rather than resolving
     * the path against its own origin. Site base URLs are no use here: on a
     * decoupled install they point at the front end, not at Craft.
     *
     * @param string $url A root-relative or already-absolute URL.
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
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
     * The scheme://host[:port] origin of a URL, or null when it has no host.
     *
     * @param string $url
     * @return string|null
     * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
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
}
