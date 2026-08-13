<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Connection;
use HeadlessChromium\Communication\Socket\Wrench as WrenchSocket;
use HeadlessChromium\Page;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\RemoteChromeClient;
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
 * Needs the Pro edition and a browser to drive, which can come from either of
 * two places: a Chrome/Chromium binary on the server (`chromePath`), or an
 * already-running Chrome reached over a WebSocket (`chromeWsEndpoint`), which
 * covers hosts that can't install a binary at all. When neither is configured
 * the scanner reports unavailable and the pipeline falls back to overlay-only
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
     * @var int Upper bound on the configurable render-settle wait, in
     * milliseconds. Shared with the settings model's validation rule, and
     * enforced again at scan time because config-file overrides bypass model
     * validation entirely. Keeps a stray value from eating most of
     * PAGE_TIMEOUT_MS before axe even runs.
     */
    public const MAX_SETTLE_MS = 15000;

    /**
     * @var string Origin sent on the WebSocket opening handshake to a remote
     * browser. The DevTools protocol ignores it, but the handshake is invalid
     * without one.
     */
    private const HANDSHAKE_ORIGIN = 'http://localhost';

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
     * Whether server-side browser scanning can run: the Pro edition plus a
     * browser to drive, either a remote endpoint or a local binary that exists
     * on disk.
     *
     * A remote endpoint is taken at face value: reachability can't be proven
     * without opening a socket, and this is called on every settings render.
     * An unreachable endpoint surfaces as a logged scan failure instead.
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

        if ($this->chromeWsEndpoint() !== '') {
            return true;
        }

        $chromePath = $this->chromePath();

        return $chromePath !== '' && file_exists($chromePath);
    }

    /**
     * Runs a full axe-core pass against the given URL in headless Chrome.
     *
     * Returns findings in the exact shape the frontend overlay posts, so the
     * results feed the same storage pipeline (`AuditService::storeAxeIssues`)
     * with the same cross-engine dedup and score recalculation. Returns null
     * on any failure (logged internally): a broken browser pass must never
     * fail the PHP scan it accompanies.
     *
     * The `incomplete` bucket carries only contrast results, the nodes axe
     * could measure neither way, which are stored as needs-review items rather
     * than counted against the score.
     *
     * Single-viewport convenience over [[scanUrlViewports()]]. Anything
     * scanning more than one viewport for the same URL must call that instead,
     * so the passes share one browser.
     *
     * @param string $url The absolute URL to scan.
     * @param string $viewport The viewport bucket to render at (a VIEWPORTS key).
     * @return array{violations: array, incomplete: array}|null The axe findings, or null on failure.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function scanUrl(string $url, string $viewport = AuditService::VIEWPORT_DESKTOP): ?array
    {
        return $this->scanUrlViewports($url, [$viewport])[$viewport] ?? null;
    }

    /**
     * Runs axe-core passes against the given URL at several viewports, sharing
     * one browser across all of them.
     *
     * The browser (local launch or remote connection) is acquired once and
     * reused for every viewport, halving Chrome cold starts compared to one
     * `scanUrl()` call per viewport. On multi-thousand-page sites that launch
     * cost dominates the whole scan, so batch callers (HeadlessScanJob) must
     * come through here rather than looping `scanUrl()`.
     *
     * Each viewport renders in its own fresh tab, so a failed pass (logged
     * internally) yields null for that key only: the other viewports' findings
     * stand on their own. A browser that can't be acquired at all yields null
     * for every key.
     *
     * @param string $url The absolute URL to scan.
     * @param string[] $viewports The viewport buckets to render at (VIEWPORTS keys).
     * @return array<string, array{violations: array, incomplete: array}|null> Findings keyed by viewport, null per failed pass.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function scanUrlViewports(string $url, array $viewports): array
    {
        $results = array_fill_keys($viewports, null);

        if ($viewports === [] || !$this->isAvailable()) {
            return $results;
        }

        $axeSource = $this->_loadAxeSource();
        if ($axeSource === null) {
            return $results;
        }

        $isRemote = $this->chromeWsEndpoint() !== '';
        $browser = $this->_acquireBrowser($url);

        if ($browser === null) {
            return $results;
        }

        try {
            foreach ($viewports as $viewport) {
                $results[$viewport] = $this->_runViewportPass($browser, $url, $viewport, $axeSource);
            }
        } finally {
            try {
                if ($isRemote) {
                    // Browser::close() sends Browser.close, which would shut the
                    // remote Chrome down under every other client sharing it.
                    // Hang up instead; the per-pass tabs are already closed.
                    $browser->getConnection()->disconnect();
                } else {
                    $browser->close();
                }
            } catch (Throwable) {
                // Chrome already gone: nothing to clean up.
            }
        }

        return $results;
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

    /**
     * The resolved WebSocket URI of a remote Chrome from settings (env-var
     * references supported), or an empty string when unset. When set it takes
     * precedence over the local binary.
     *
     * The URI is normalised to carry a path, because the WebSocket client
     * rejects one without ('wss://host?token=…' throws "Invalid path"), and
     * that pathless form is exactly what browserless and similar services hand
     * out. Fixing it here beats every admin having to notice a missing slash.
     *
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function chromeWsEndpoint(): string
    {
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $uri = trim(App::parseEnv($settings->chromeWsEndpoint ?? ''));

        if ($uri === '' || (string)parse_url($uri, PHP_URL_PATH) !== '') {
            return $uri;
        }

        $queryPosition = strpos($uri, '?');

        if ($queryPosition === false) {
            return $uri . '/';
        }

        return substr($uri, 0, $queryPosition) . '/' . substr($uri, $queryPosition);
    }

    // Private Methods
    // =========================================================================

    /**
     * Acquires a browser to scan with: a connection to the remote endpoint
     * when one is configured, otherwise a freshly launched local binary.
     * Returns null on any failure (logged internally).
     *
     * The local launch uses the desktop window size regardless of which
     * viewports will run: every pass sets its real viewport per page anyway
     * (see _runViewportPass), and per-page is the only way a remote browser
     * can be sized at all.
     *
     * @param string $url The URL being scanned, for log context only.
     * @return Browser|null
     */
    private function _acquireBrowser(string $url): ?Browser
    {
        $endpoint = $this->chromeWsEndpoint();

        try {
            if ($endpoint !== '') {
                // Connecting to a browser someone else launched, so none of the
                // launch flags below are ours to set: sandboxing and certificate
                // policy belong to whoever runs the endpoint.
                //
                // Assembled here rather than via BrowserFactory::connectToBrowser(),
                // which cannot complete a handshake against a remote server.
                // See RemoteChromeClient.
                $connection = new Connection(
                    new WrenchSocket(new RemoteChromeClient($endpoint, self::HANDSHAKE_ORIGIN)),
                    null,
                    self::PAGE_TIMEOUT_MS,
                );

                if (!$connection->connect()) {
                    Craft::warning(
                        "HeadlessScanner: could not connect to the remote Chrome endpoint for {$url}. " .
                        'Check the endpoint is reachable from the machine running the queue.',
                        'accessibility-audit',
                    );

                    return null;
                }

                return new Browser($connection);
            }

            $settings = AccessibilityAudit::getInstance()->getSettings();
            $factory = new BrowserFactory($this->chromePath());

            return $factory->createBrowser([
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
                'windowSize' => self::VIEWPORTS[AuditService::VIEWPORT_DESKTOP],
                'customFlags' => [
                    // Chrome renders into /dev/shm, which containers and
                    // starved queue workers often cap far below what a page
                    // render needs; this moves shared memory to /tmp so the
                    // browser doesn't crash mid-scan when it fills.
                    '--disable-dev-shm-usage',
                ],
            ]);
        } catch (Throwable $e) {
            Craft::error("HeadlessScanner: could not start a browser for {$url}: " . $e->getMessage(), 'accessibility-audit');

            return null;
        }
    }

    /**
     * Renders the URL at one viewport in a fresh tab on an already-acquired
     * browser and runs the axe pass there. Returns null on any failure (logged
     * internally); the tab is closed either way so passes never pile pages
     * onto a shared browser.
     *
     * @param Browser $browser The browser to open the tab on.
     * @param string $url The absolute URL to scan.
     * @param string $viewport The viewport bucket to render at (a VIEWPORTS key).
     * @param string $axeSource The axe-core source to inject.
     * @return array{violations: array, incomplete: array}|null
     */
    private function _runViewportPass(Browser $browser, string $url, string $viewport, string $axeSource): ?array
    {
        $page = null;

        try {
            $settings = AccessibilityAudit::getInstance()->getSettings();
            $size = self::VIEWPORTS[$viewport] ?? self::VIEWPORTS[AuditService::VIEWPORT_DESKTOP];

            $page = $browser->createPage();

            // Both applied per page rather than as launch options, because a
            // remote browser accepts neither at connect time. The viewport
            // override is also what makes each pass on the shared browser
            // genuinely desktop or mobile.
            $page->setViewport($size[0], $size[1])->await(self::PAGE_TIMEOUT_MS);

            // The browser pass identifies itself too, so one WAF rule allow-lists
            // it alongside the HTTP fetches. Default keeps a realistic Chrome UA
            // with the token appended; an admin override replaces it wholesale.
            $page->setUserAgent($settings->getBrowserUserAgent())->await(self::PAGE_TIMEOUT_MS);

            $page->navigate($url)->waitForNavigation(Page::LOAD, self::PAGE_TIMEOUT_MS);

            // Chrome serves its own interstitial from chrome-error://chromewebdata
            // when navigation fails (bad cert, DNS, refused connection), and axe
            // would audit that screen instead. Certificate errors are ignored
            // at launch for local dev; this catches the rest, including an
            // expired certificate in production.
            $landed = (string) $page->evaluate('document.location.href')->getReturnValue(self::PAGE_TIMEOUT_MS);

            if (str_starts_with($landed, 'chrome-error://')) {
                Craft::warning(
                    "HeadlessScanner: {$url} did not load in Chrome (navigation failed, often a certificate " .
                    'or connection problem). Skipping rather than scanning the browser error page.',
                    'accessibility-audit',
                );

                return null;
            }

            // Give late-rendering JS time to settle before axe runs. Paid on
            // every pass, so it dominates large scans; the admin-tuned setting
            // is clamped here as well as in validation because config-file
            // overrides skip the model rules.
            $settleMs = min(max($settings->browserSettleMs, 0), self::MAX_SETTLE_MS);

            if ($settleMs > 0) {
                usleep($settleMs * 1000);
            }

            $page->evaluate($axeSource)->waitForResponse(self::PAGE_TIMEOUT_MS);

            $result = $page->evaluate($this->_axeRunScript())->getReturnValue(self::PAGE_TIMEOUT_MS);
            $decoded = is_string($result) ? Json::decodeIfJson($result) : null;

            if (!is_array($decoded) || !isset($decoded['violations']) || !is_array($decoded['violations'])) {
                Craft::warning("HeadlessScanner: unexpected axe result for {$url}", 'accessibility-audit');
                return null;
            }

            return [
                'violations' => $decoded['violations'],
                'incomplete' => is_array($decoded['incomplete'] ?? null) ? $decoded['incomplete'] : [],
            ];
        } catch (Throwable $e) {
            Craft::error("HeadlessScanner: {$viewport} scan of {$url} failed: " . $e->getMessage(), 'accessibility-audit');

            return null;
        } finally {
            try {
                $page?->close();
            } catch (Throwable) {
                // Page already gone with its browser: nothing to clean up.
            }
        }
    }

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
     * frontend overlay and returns a slimmed, JSON-encoded payload of both the
     * violations and the undecided contrast results.
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
        // the selector target, and the contrast data on any[0]. Incomplete
        // results are filtered to contrast, the only rule whose "can't tell"
        // answer is worth a person's time (see _storeContrastNeedsReview).
        return <<<JS
            axe.run(document, {
                runOnly: { type: 'tag', values: {$tagsJson} },
                resultTypes: ['violations', 'incomplete'],
            }).then(function(r) {
                var slim = function(v) {
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
                };

                return JSON.stringify({
                    violations: r.violations.map(slim),
                    incomplete: (r.incomplete || []).filter(function(v) {
                        return v.id === 'color-contrast';
                    }).map(slim),
                });
            })
            JS;
    }
}
