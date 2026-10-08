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
use HeadlessChromium\Communication\Message;
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
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 *
 * @phpstan-type AxeNode array<string, mixed>
 * @phpstan-type FocusWalk array<string, mixed>
 * @phpstan-type AxeFindings array{violations: array<int, AxeNode>, incomplete: array<int, AxeNode>, focus?: FocusWalk|null}
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
     * @var int The Chrome round trips one viewport pass waits on, each bounded
     * by PAGE_TIMEOUT_MS. Counted from {@see self::_runViewportPass()}: the
     * viewport, the user agent, the navigation, reading the landed URL, and
     * the three evaluates that inject axe, probe the viewport and run the
     * rules.
     */
    private const AWAITS_PER_VIEWPORT = 7;

    /**
     * @var int Nodes stored per violation. Bounds the payload on pathological
     * pages (a broken template can fail one rule thousands of times). Public
     * because the Inspect preview's client-side axe pass must slim its payload
     * to the same shape (injected via window.AccessibilityAudit).
     */
    public const MAX_NODES_PER_VIOLATION = 50;

    /**
     * @var int The most focusable elements the keyboard walk visits on one
     * page.
     */
    public const FOCUS_WALK_MAX_ELEMENTS = 150;

    /**
     * @var int How long the walk may run inside the page, in milliseconds.
     * Kept under FOCUS_WALK_TIMEOUT_MS so the page stops itself and hands back
     * what it found before PHP stops waiting.
     */
    private const FOCUS_WALK_BUDGET_MS = 15000;

    /**
     * @var int The bound on each of the walk's setup round trips.
     */
    private const FOCUS_STEP_TIMEOUT_MS = 10000;

    /**
     * @var int The bound on the round trip that runs the walk itself.
     */
    private const FOCUS_WALK_TIMEOUT_MS = 30000;

    /**
     * @var int The setup round trips the walk waits on, each bounded by
     * FOCUS_STEP_TIMEOUT_MS. Counted from {@see self::_runFocusWalk()}: focus
     * emulation, the evaluate that injects and prepares the walk, and the Tab
     * key's down and up events.
     */
    private const FOCUS_WALK_AWAITS = 4;

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

    /**
     * @var string|null Memoized keyboard walk source, with the shared helpers
     * it relies on, read once per request.
     */
    private ?string $_focusWalkSource = null;

    // Public Methods
    // =========================================================================

    /**
     * The longest a full scan of one page can take, in seconds.
     *
     * Every Chrome call this makes is bounded, so the total is arithmetic
     * rather than a guess: one acquisition, then a pass per viewport, each of
     * which waits on {@see self::AWAITS_PER_VIEWPORT} round trips and sleeps
     * out the settle window, and the keyboard walk, which runs once, on the
     * desktop pass.
     *
     * A queue job that drives this needs it. Craft reserves a job for 300
     * seconds by default, which is shorter than this, and a job outliving its
     * reservation is handed to the next worker and restarted from the top:
     * Chrome launched again for the same page, for as long as the page stays
     * slow.
     *
     * @return int Seconds.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function worstCaseScanSeconds(): int
    {
        $perViewport = (self::AWAITS_PER_VIEWPORT * self::PAGE_TIMEOUT_MS) + self::MAX_SETTLE_MS;
        $focusWalk = (self::FOCUS_WALK_AWAITS * self::FOCUS_STEP_TIMEOUT_MS) + self::FOCUS_WALK_TIMEOUT_MS;

        return (int)ceil(
            (self::PAGE_TIMEOUT_MS + ($perViewport * count(self::VIEWPORTS)) + $focusWalk) / 1000
        );
    }

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * than counted against the score. A desktop pass also carries `focus`, the
     * keyboard walk's result, null where the walk could not run.
     *
     * Single-viewport convenience over [[scanUrlViewports()]]. Anything
     * scanning more than one viewport for the same URL must call that instead,
     * so the passes share one browser.
     *
     * @param string $url The absolute URL to scan.
     * @param string $viewport The viewport bucket to render at (a VIEWPORTS key).
     * @return AxeFindings|null The axe findings, or null on failure.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @return array<string, AxeFindings|null> Findings keyed by viewport, null
     *         for each failed pass.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function chromePath(): string
    {
        $settings = AccessibilityAudit::getInstance()->getSettings();

        return trim((string)App::parseEnv($settings->chromePath));
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function chromeWsEndpoint(): string
    {
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $uri = trim((string)App::parseEnv($settings->chromeWsEndpoint));

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
     * @return Browser|null The browser, or null where none could be had.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
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

            return (new BrowserFactory($this->chromePath()))->createBrowser([
                'headless' => true,
                // Most containers and CI runners have no usable Chrome sandbox,
                // so this defaults on; hosts with a working sandbox can turn it
                // off in settings for defence in depth.
                'noSandbox' => (bool)$settings->chromeNoSandbox,
                // Same TLS policy as the PHP fetch (AuditService::_verifyTls):
                // self-signed certs pass in dev/ephemeral environments and are
                // verified everywhere else, so Chrome doesn't stop at its own
                // interstitial and audit that screen instead of the site.
                'ignoreCertificateErrors' => Craft::$app->getConfig()->getGeneral()->devMode,
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
     * @return AxeFindings|null The axe findings, or null where the pass failed.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
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
            $page->evaluate(self::IN_VIEW_PROBE_JS)->waitForResponse(self::PAGE_TIMEOUT_MS);

            $result = $page->evaluate($this->_axeRunScript())->getReturnValue(self::PAGE_TIMEOUT_MS);
            $decoded = is_string($result) ? Json::decodeIfJson($result) : null;

            if (!is_array($decoded) || !isset($decoded['violations']) || !is_array($decoded['violations'])) {
                Craft::warning("HeadlessScanner: unexpected axe result for {$url}", 'accessibility-audit');
                return null;
            }

            $findings = [
                'violations' => $decoded['violations'],
                'incomplete' => is_array($decoded['incomplete'] ?? null) ? $decoded['incomplete'] : [],
            ];

            // Desktop only: the walk is the slowest part of the pass.
            if ($viewport === AuditService::VIEWPORT_DESKTOP && AccessibilityAudit::getInstance()->getAudit()->focusWalkApplies()) {
                $findings['focus'] = $this->_runFocusWalk($page, $url);
            }

            return $findings;
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
     * Walks keyboard focus through the page and reports what it could not see.
     *
     * One real Tab key press starts it, so the browser treats the focus that
     * follows as keyboard focus and `:focus-visible` styles apply. Without
     * focus emulation a tab that is not the active one, which is every tab on
     * a shared remote browser, never matches `:focus` at all; a browser that
     * refuses emulation is logged and the walk carries on, and the page then
     * reports itself unable to run rather than reporting every control.
     *
     * Its own failures stay here. The axe findings for the pass are already
     * in hand and a broken walk must not lose them.
     *
     * @param Page $page The page, loaded and already scanned by axe.
     * @param string $url The URL being scanned, for log context only.
     * @return FocusWalk|null The walk's result, or null where it failed.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    private function _runFocusWalk(Page $page, string $url): ?array
    {
        $source = $this->_loadFocusWalkSource();

        if ($source === null) {
            return null;
        }

        try {
            $session = $page->getSession();

            $emulation = $session->sendMessageSync(
                new Message('Emulation.setFocusEmulationEnabled', ['enabled' => true]),
                self::FOCUS_STEP_TIMEOUT_MS,
            );

            if (!$emulation->isSuccessful()) {
                Craft::warning(
                    "HeadlessScanner: the browser refused focus emulation for {$url}, so the keyboard walk " .
                    'may not be able to run: ' . $emulation->getErrorMessage(),
                    'accessibility-audit',
                );
            }

            $exclude = Json::encode(AccessibilityAudit::getInstance()->getSettings()->resolvedExcludedSelectors());

            $page->evaluate($source . "\nwindow.__aaFocusWalk.prepare({ exclude: {$exclude} });")
                ->waitForResponse(self::FOCUS_STEP_TIMEOUT_MS);

            // Sent raw: chrome-php's keyboard helper sends the key code of the
            // first letter of the key's name, which Chrome reads as "T".
            foreach (['rawKeyDown', 'keyUp'] as $type) {
                $session->sendMessageSync(new Message('Input.dispatchKeyEvent', [
                    'type' => $type,
                    'key' => 'Tab',
                    'code' => 'Tab',
                    'windowsVirtualKeyCode' => 9,
                    'nativeVirtualKeyCode' => 9,
                ]), self::FOCUS_STEP_TIMEOUT_MS);
            }

            $result = $page->callFunction(
                'function (config) { return window.__aaFocusWalk.run(config); }',
                [[
                    'max' => self::FOCUS_WALK_MAX_ELEMENTS,
                    'budgetMs' => self::FOCUS_WALK_BUDGET_MS,
                ]],
            )->getReturnValue(self::FOCUS_WALK_TIMEOUT_MS);

            if (!is_array($result) || !isset($result['ran'])) {
                Craft::warning("HeadlessScanner: unexpected keyboard walk result for {$url}", 'accessibility-audit');

                return null;
            }

            if ($result['ran'] !== true) {
                Craft::info(
                    "HeadlessScanner: keyboard walk did not run on {$url}: " . (string)($result['reason'] ?? 'unknown'),
                    'accessibility-audit',
                );
            } elseif (is_string($result['stopped'] ?? null)) {
                Craft::info(
                    "HeadlessScanner: keyboard walk on {$url} stopped early ({$result['stopped']}) after "
                    . (int)($result['checked'] ?? 0) . ' of ' . (int)($result['total'] ?? 0) . ' focusable elements',
                    'accessibility-audit',
                );
            }

            return $result;
        } catch (Throwable $e) {
            Craft::warning("HeadlessScanner: keyboard walk of {$url} failed: " . $e->getMessage(), 'accessibility-audit');

            return null;
        }
    }

    /**
     * Reads the keyboard walk's source, with the shared helpers it uses
     * prepended, memoized per request.
     *
     * @return string|null The source, or null where a bundled file is missing.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.6.0
     */
    private function _loadFocusWalkSource(): ?string
    {
        if ($this->_focusWalkSource !== null) {
            return $this->_focusWalkSource;
        }

        $source = '';

        foreach (['accessibility-audit-shared.js', 'focus-walk.js'] as $file) {
            $path = dirname(__DIR__) . '/resources/js/' . $file;
            $part = is_readable($path) ? file_get_contents($path) : false;

            if ($part === false || $part === '') {
                Craft::error("HeadlessScanner: bundled {$file} missing at {$path}", 'accessibility-audit');

                return null;
            }

            $source .= $part . "\n";
        }

        return $this->_focusWalkSource = $source;
    }

    /**
     * Reads the bundled axe-core source, memoized per request.
     *
     * @return string|null The source, or null where the bundled file is missing.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _loadAxeSource(): ?string
    {
        if ($this->_axeSource !== null) {
            return $this->_axeSource;
        }

        $path = dirname(__DIR__) . '/resources/axe/axe.min.js';
        $source = is_readable($path) ? file_get_contents($path) : false;

        if ($source === false || $source === '') {
            Craft::error("HeadlessScanner: bundled axe-core missing at {$path}", 'accessibility-audit');
            return null;
        }

        return $this->_axeSource = $source;
    }

    /**
     * @var string A function deciding whether an element was fully laid out
     *      inside the area the page was measured in.
     *
     *      axe samples an element's background at points on its rects. Where a
     *      rect runs past the edge of the viewport there is nothing under the
     *      part that ran off, and axe reports that as an overlap: "another
     *      element covers part of it". Nothing covers it. It was never
     *      measured, and the two read identically in the report while calling
     *      for completely different work.
     *
     *      Kept as a constant so the browser pass and its test run the same
     *      source rather than two copies that drift.
     */
    public const IN_VIEW_PROBE_JS = <<<'JS'
        window.__aaFullyInView = function (target) {
          var sel = Array.isArray(target) ? target[target.length - 1] : target;
          var el = null;

          try { el = document.querySelector(sel); } catch (e) { return true; }
          if (!el) return true;

          var rects = el.getClientRects();
          if (!rects.length) return false;

          // Content wider or taller than the box it sits in paints outside
          // that box, and axe samples on the box. A long identifier in a
          // heading, or a code block that scrolls sideways, keeps a rect that
          // fits perfectly while half the text is somewhere else.
          if (el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1) {
            return false;
          }

          var vw = window.innerWidth;
          var vh = window.innerHeight;

          for (var i = 0; i < rects.length; i++) {
            var r = rects[i];
            // A pixel of tolerance: sub-pixel layout puts rects a hair past an
            // edge constantly, and treating that as unmeasured would relabel
            // most of the page.
            if (r.left < -1 || r.top < -1 || r.right > vw + 1 || r.bottom > vh + 1) {
              return false;
            }
          }

          return true;
        };
        JS;

    /**
     * The in-page script that runs axe and hands back a slimmed payload.
     *
     * Each node is cut down to what storeAxeIssues() consumes: the markup
     * snippet, the selector target, and the contrast data on any[0]. Incomplete
     * results are filtered to contrast, the only rule whose "cannot tell"
     * answer is worth a person's time.
     *
     * @return string The JavaScript to evaluate in the page.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _axeRunScript(): string
    {
        $tagsJson = Json::encode(AccessibilityAudit::getInstance()->getAudit()->getAxeTags());
        // Consent banners and other excluded page furniture, in axe's context
        // shape, so the headless pass skips exactly what the other engines skip.
        $excludeJson = Json::encode(AccessibilityAudit::getInstance()->getAudit()->getAxeExclude());
        $maxNodes = self::MAX_NODES_PER_VIOLATION;
        $maxHtml = self::MAX_NODE_HTML_LENGTH;

        // Slim each node to what storeAxeIssues() consumes: the html snippet,
        // the selector target, and the contrast data on any[0]. Incomplete
        // results are filtered to contrast, the only rule whose "can't tell"
        // answer is worth a person's time (see _storeContrastNeedsReview).
        return <<<JS
            axe.run({ exclude: {$excludeJson} }, {
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
                                // Why this element failed, as opposed to what
                                // the rule checks. One target-size violation
                                // can mean too small, too close to its
                                // neighbours, or covered by something else,
                                // and those are three different jobs.
                                failureSummary: (n.failureSummary || '').slice(0, 400),
                            };
                        }),
                    };
                };

                // Undecided results about text the pass never had under a
                // sample point are dropped, not asked. "The scanner could not
                // see this" is not a question a person can settle by looking
                // at the page, and which elements it lands on shifts with
                // layout timing, so the same page yields a different one each
                // run: an answer never ends the queue, a new question just
                // takes its place. Violations are untouched, so nothing
                // measured and failing is ever hidden.
                var measured = function(v) {
                    v.nodes = v.nodes.filter(function(n) {
                        return window.__aaFullyInView(n.target);
                    });

                    return v;
                };

                return JSON.stringify({
                    violations: r.violations.map(slim),
                    incomplete: (r.incomplete || []).filter(function(v) {
                        return v.id === 'color-contrast';
                    }).map(slim).map(measured).filter(function(v) {
                        return v.nodes.length > 0;
                    }),
                });
            })
            JS;
    }
}
