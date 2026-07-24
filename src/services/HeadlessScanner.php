<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Runs axe-core against a live page in headless Chrome, server-side.
 *
 * This closes the coverage gap of the frontend overlay: instead of axe only
 * running when an admin happens to visit a page, every queued scan can carry
 * a full browser pass, so contrast, focus, and target-size findings stay
 * complete and fresh across the whole site.
 *
 * Requires a Chrome/Chromium binary on the server, pointed at by the
 * `chromePath` setting, and the Pro edition. When either is missing the
 * scanner reports unavailable and the pipeline falls back to overlay-only
 * axe coverage.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class HeadlessScanner extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var array<string, array{int, int}> Window size (width, height) per
     * viewport bucket. Desktop matches the Inspect preview's logical width;
     * mobile is a standard small-phone portrait size, so mobile-critical
     * criteria (target size, reflow-adjacent failures) are actually exercised
     * instead of inferred from a desktop render.
     */
    public const VIEWPORTS = [
        AuditService::VIEWPORT_DESKTOP => [1280, 900],
        AuditService::VIEWPORT_MOBILE => [375, 812],
    ];

    /**
     * @var int Hard ceiling on how long a single page's browser pass may take,
     * in milliseconds. Covers navigation, render settling, and the axe run.
     */
    private const PAGE_TIMEOUT_MS = 60000;

    /**
     * @var int Nodes stored per violation. Bounds the payload on pathological
     * pages (a broken template can fail one rule thousands of times). Public
     * because the Inspect preview's client-side axe pass must slim its payload
     * to the same shape (injected via window.AccessibilityAudit).
     */
    public const MAX_NODES_PER_VIOLATION = 50;

    /**
     * @var int Characters of a node's HTML snippet stored per occurrence.
     * Shared with the Inspect preview's client-side pass for the same reason
     * as MAX_NODES_PER_VIOLATION.
     */
    public const MAX_NODE_HTML_LENGTH = 300;

    // Private Properties
    // =========================================================================

    /**
     * @var string|null Memoized axe-core source, read once per request.
     */
    private ?string $_axeSource = null;

    // Public Methods
    // =========================================================================

    /**
     * Whether server-side browser scanning can run: Pro edition, a configured
     * Chrome binary that exists on disk, and the bundled axe-core source.
     *
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function isAvailable(): bool
    {
        if (!AccessibilityAudit::getInstance()->isPro()) {
            return false;
        }

        $chromePath = $this->chromePath();

        return $chromePath !== '' && file_exists($chromePath);
    }

    /**
     * Runs a full axe-core pass against the given URL in headless Chrome.
     *
     * Returns violations in the exact shape the frontend overlay posts, so the
     * results feed the same storage pipeline (`AuditService::storeAxeIssues`)
     * with the same cross-engine dedup and score recalculation. Returns null
     * on any failure (logged internally): a broken browser pass must never
     * fail the PHP scan it accompanies.
     *
     * @param string $url The absolute URL to scan.
     * @param string $viewport The viewport bucket to render at (a VIEWPORTS key).
     * @return array|null The axe violations, or null on failure.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function scanUrl(string $url, string $viewport = AuditService::VIEWPORT_DESKTOP): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $axeSource = $this->_loadAxeSource();
        if ($axeSource === null) {
            return null;
        }

        $browser = null;

        try {
            $settings = AccessibilityAudit::getInstance()->getSettings();

            $factory = new BrowserFactory($this->chromePath());
            $browserOptions = [
                'headless' => true,
                // Most containers and CI runners have no usable Chrome sandbox,
                // so this defaults on; hosts with a working sandbox can turn it
                // off in settings for defence in depth.
                'noSandbox' => (bool)$settings->chromeNoSandbox,
                // Same TLS policy as the PHP fetch (AuditService::_verifyTls):
                // self-signed certs pass in dev/ephemeral environments and are
                // verified everywhere else, so Chrome doesn't stop at its own
                // interstitial and audit that screen instead of the site.
                'ignoreCertificateErrors' => App::isEphemeral() || Craft::$app->getConfig()->getGeneral()->devMode,
                'keepAlive' => false,
                'startupTimeout' => 30,
                'windowSize' => self::VIEWPORTS[$viewport] ?? self::VIEWPORTS[AuditService::VIEWPORT_DESKTOP],
            ];

            // The browser pass identifies itself too, so one WAF rule allow-lists
            // it alongside the HTTP fetches. Default keeps a realistic Chrome UA
            // with the token appended; an admin override replaces it wholesale.
            $browserOptions['userAgent'] = $settings->getBrowserUserAgent();

            $browser = $factory->createBrowser($browserOptions);

            $page = $browser->createPage();
            $page->navigate($url)->waitForNavigation(Page::LOAD, self::PAGE_TIMEOUT_MS);

            // Chrome serves its own interstitial from chrome-error://chromewebdata
            // when navigation fails (bad cert, DNS, refused connection), and axe
            // would audit that screen instead. Certificate errors are ignored
            // above for local dev; this catches the rest, including an expired
            // certificate in production.
            $landed = (string) $page->evaluate('document.location.href')->getReturnValue(self::PAGE_TIMEOUT_MS);

            if (str_starts_with($landed, 'chrome-error://')) {
                Craft::warning(
                    "HeadlessScanner: {$url} did not load in Chrome (navigation failed, often a certificate " .
                    'or connection problem). Skipping rather than scanning the browser error page.',
                    'accessibility-audit',
                );

                return null;
            }

            // Give late-rendering JS the same settle time the overlay allows.
            usleep(2000000);

            $page->evaluate($axeSource)->waitForResponse(self::PAGE_TIMEOUT_MS);

            $result = $page->evaluate($this->_axeRunScript())->getReturnValue(self::PAGE_TIMEOUT_MS);
            $decoded = is_string($result) ? Json::decodeIfJson($result) : null;

            if (!is_array($decoded) || !isset($decoded['violations']) || !is_array($decoded['violations'])) {
                Craft::warning("HeadlessScanner: unexpected axe result for {$url}", 'accessibility-audit');
                return null;
            }

            return $decoded['violations'];
        } catch (Throwable $e) {
            Craft::error("HeadlessScanner: scan of {$url} failed: " . $e->getMessage(), 'accessibility-audit');
            return null;
        } finally {
            try {
                $browser?->close();
            } catch (Throwable) {
                // Chrome already gone: nothing to clean up.
            }
        }
    }

    /**
     * The resolved Chrome binary path from settings (env-var references
     * supported), or an empty string when unset.
     *
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function chromePath(): string
    {
        $settings = AccessibilityAudit::getInstance()->getSettings();

        return trim(App::parseEnv($settings->chromePath ?? ''));
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads the bundled axe-core source, memoized per request.
     *
     * @return string|null
     */
    private function _loadAxeSource(): ?string
    {
        if ($this->_axeSource !== null) {
            return $this->_axeSource;
        }

        $path = dirname(__DIR__) . '/resources/axe/axe.min.js';
        $source = @file_get_contents($path);

        if ($source === false || $source === '') {
            Craft::error("HeadlessScanner: bundled axe-core missing at {$path}", 'accessibility-audit');
            return null;
        }

        return $this->_axeSource = $source;
    }

    /**
     * Builds the in-page script that runs axe with the same tag list as the
     * frontend overlay and returns a slimmed, JSON-encoded violations payload.
     *
     * @return string
     * @throws InvalidConfigException
     */
    private function _axeRunScript(): string
    {
        $tagsJson = Json::encode(AccessibilityAudit::getInstance()->getAudit()->getAxeTags());
        $maxNodes = self::MAX_NODES_PER_VIOLATION;
        $maxHtml = self::MAX_NODE_HTML_LENGTH;

        // Slim each node to what storeAxeIssues() consumes: the html snippet,
        // the selector target, and the contrast data on any[0].
        return <<<JS
            axe.run(document, {
                runOnly: { type: 'tag', values: {$tagsJson} },
                resultTypes: ['violations'],
            }).then(function(r) {
                return JSON.stringify({
                    violations: r.violations.map(function(v) {
                        return {
                            id: v.id,
                            impact: v.impact,
                            tags: v.tags,
                            description: v.description,
                            help: v.help,
                            helpUrl: v.helpUrl,
                            nodes: v.nodes.slice(0, {$maxNodes}).map(function(n) {
                                return {
                                    html: (n.html || '').slice(0, {$maxHtml}),
                                    target: n.target,
                                    any: (n.any && n.any[0]) ? [{ data: n.any[0].data }] : [],
                                };
                            }),
                        };
                    }),
                });
            })
            JS;
    }
}
