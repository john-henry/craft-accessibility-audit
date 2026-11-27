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
use HeadlessChromium\Exception\OperationTimedOut;
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
 * Needs the Plus edition or higher and a browser to drive, which can come
 * from either of two places: a Chrome/Chromium binary on the server (`chromePath`), or an
 * already-running Chrome reached over a WebSocket (`chromeWsEndpoint`), which
 * covers hosts that can't install a binary at all. When neither is configured
 * the scanner reports unavailable and the pipeline falls back to overlay-only
 * axe coverage.
 *
 * The same browser reads PDF assets with pdf.js for the structure part of the
 * PDF checks; see {@see self::checkPdf()}.
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
     * the four evaluates that steady the page, inject axe, load the viewport
     * and pseudo-element probes, and run the rules.
     */
    private const AWAITS_PER_VIEWPORT = 8;

    /**
     * @var int The longest the pass waits for web fonts, in milliseconds. A
     * font that never arrives should cost a scan this much, not the whole
     * PAGE_TIMEOUT_MS.
     */
    public const MAX_FONT_WAIT_MS = 3000;

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

    /**
     * @var int The longest one PDF's browser pass may take once Chrome is in
     * hand, in milliseconds: loading pdf.js, handing the file over and
     * reading it. Every round trip waits on what is left of this, not a
     * timeout of its own, so the total holds however many chunks a file takes.
     */
    private const PDF_TIMEOUT_MS = 120000;

    /**
     * @var int How long the page walk may run inside the tab, in milliseconds.
     * Under PDF_TIMEOUT_MS so the tab stops itself and hands back the pages it
     * read before PHP stops waiting.
     */
    private const PDF_BUDGET_MS = 90000;

    /**
     * @var int Characters per message when handing over pdf.js and the file,
     * so no single DevTools message is large enough for a remote endpoint to
     * refuse.
     */
    private const PDF_CHUNK_CHARS = 1048576;

    /**
     * @var int Structure nodes, figures and headings the walk keeps, so a
     * pathological file cannot return a payload larger than the file.
     */
    private const PDF_MAX_NODES = 100000;

    /**
     * @var int Figures and headings returned per document.
     */
    private const PDF_MAX_ITEMS = 2000;

    // Private Properties
    // =========================================================================

    /**
     * @var array{lib: string, worker: string, check: string}|null Memoized
     * pdf.js, its worker and the check script, read once per request.
     */
    private ?array $_pdfSources = null;

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
     * Whether server-side browser scanning can run: Plus or higher, plus
     * a browser to drive, either a remote endpoint or a local binary that
     * exists on disk.
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
        if (!AccessibilityAudit::getInstance()->isPlus()) {
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
            $this->_releaseBrowser($browser, $isRemote);
        }

        return $results;
    }

    /**
     * The longest one PDF's browser pass can take, in seconds: acquiring the
     * browser, then the pass itself.
     *
     * @return int Seconds.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function worstCasePdfSeconds(): int
    {
        return (int)ceil((self::PAGE_TIMEOUT_MS + self::PDF_TIMEOUT_MS) / 1000);
    }

    /**
     * Reads a PDF with pdf.js in a blank tab, for the structure checks the
     * PHP layer does not make.
     *
     * pdf.js, its worker and the file are handed over through the DevTools
     * protocol, the way axe is, and loaded as blob URL modules. Nothing is
     * fetched, so no URL has to be reachable from the browser and a remote
     * Chrome works the same as a local one. One tab per document, closed
     * after; pdf.js destroys the document itself before handing back.
     *
     * Returns what the tab read, for PdfChecker::applyBrowserLayer() to judge,
     * `['ok' => false, 'error' => 'timeout']` where it ran out of time, and
     * null where the pass could not run at all (logged).
     *
     * @param string $path A local copy of the PDF.
     * @return array<string, mixed>|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function checkPdf(string $path): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $sources = $this->_loadPdfSources();
        $data = is_readable($path) ? file_get_contents($path) : false;

        if ($sources === null || $data === false) {
            return null;
        }

        $label = 'PDF ' . basename($path);
        $isRemote = $this->chromeWsEndpoint() !== '';
        $browser = $this->_acquireBrowser($label);

        if ($browser === null) {
            return null;
        }

        $page = null;
        $deadline = microtime(true) * 1000 + self::PDF_TIMEOUT_MS;

        try {
            $page = $browser->createPage();
            $page->evaluate($sources['check'])->waitForResponse(self::_remainingMs($deadline));

            $this->_handOver($page, 'lib', $sources['lib'], $deadline);
            $this->_handOver($page, 'worker', $sources['worker'], $deadline);
            $page->callFunction('function () { return window.__aaPdfCheck.load(); }')
                ->getReturnValue(self::_remainingMs($deadline));

            $this->_handOver($page, 'pdf', base64_encode($data), $deadline);
            unset($data);

            $result = $page->callFunction('function (config) { return window.__aaPdfCheck.run(config); }', [[
                'maxPages' => PdfChecker::PAGE_SAMPLE,
                'budgetMs' => self::PDF_BUDGET_MS,
                'maxNodes' => self::PDF_MAX_NODES,
                'maxFigures' => self::PDF_MAX_ITEMS,
                'maxHeadings' => self::PDF_MAX_ITEMS,
            ]])->getReturnValue(self::_remainingMs($deadline));

            if (!is_array($result)) {
                Craft::warning("HeadlessScanner: unexpected result reading {$label}", 'accessibility-audit');

                return null;
            }

            return $result;
        } catch (OperationTimedOut) {
            Craft::warning("HeadlessScanner: reading {$label} timed out", 'accessibility-audit');

            return ['ok' => false, 'error' => 'timeout'];
        } catch (Throwable $e) {
            Craft::error("HeadlessScanner: reading {$label} failed: " . $e->getMessage(), 'accessibility-audit');

            return null;
        } finally {
            try {
                $page?->close();
            } catch (Throwable) {
                // Page already gone with its browser: nothing to clean up.
            }

            $this->_releaseBrowser($browser, $isRemote);
        }
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
     * Lets go of a browser once a scan is done with it.
     *
     * Browser::close() sends Browser.close, which would shut a remote Chrome
     * down under every other client sharing it, so a remote one is hung up on
     * instead; its tabs are already closed.
     *
     * @param Browser $browser The browser.
     * @param bool $isRemote Whether it was reached over the remote endpoint.
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _releaseBrowser(Browser $browser, bool $isRemote): void
    {
        try {
            if ($isRemote) {
                $browser->getConnection()->disconnect();
            } else {
                $browser->close();
            }
        } catch (Throwable) {
            // Chrome already gone: nothing to clean up.
        }
    }

    /**
     * Hands a string to the PDF check script in the tab, a chunk at a time.
     *
     * @param Page $page The tab.
     * @param string $key Which value this is: lib, worker or pdf.
     * @param string $source The string.
     * @param float $deadline When the whole pass must be done, in epoch milliseconds.
     * @return void
     * @throws OperationTimedOut
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _handOver(Page $page, string $key, string $source, float $deadline): void
    {
        $length = strlen($source);

        for ($offset = 0; $offset < $length; $offset += self::PDF_CHUNK_CHARS) {
            $page->callFunction(
                'function (key, part) { return window.__aaPdfCheck.put(key, part); }',
                [$key, substr($source, $offset, self::PDF_CHUNK_CHARS)],
            )->waitForResponse(self::_remainingMs($deadline));
        }
    }

    /**
     * What is left of a deadline, never less than a second so the last
     * round trip is not given nothing to work with.
     *
     * @param float $deadline The deadline, in epoch milliseconds.
     * @return int Milliseconds.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _remainingMs(float $deadline): int
    {
        return max(1000, (int)($deadline - microtime(true) * 1000));
    }

    /**
     * Reads pdf.js, its worker and the check script, memoized per request.
     *
     * pdf.js is the minified build exactly as published, so its licence
     * header travels with it.
     *
     * @return array{lib: string, worker: string, check: string}|null The
     *         sources, or null where a bundled file is missing.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _loadPdfSources(): ?array
    {
        if ($this->_pdfSources !== null) {
            return $this->_pdfSources;
        }

        $resources = dirname(__DIR__) . '/resources';
        $sources = [];

        foreach ([
            'lib' => '/pdfjs/pdf.min.mjs',
            'worker' => '/pdfjs/pdf.worker.min.mjs',
            'check' => '/js/pdf-check.js',
        ] as $key => $file) {
            $path = $resources . $file;
            $source = is_readable($path) ? file_get_contents($path) : false;

            if ($source === false || $source === '') {
                Craft::error("HeadlessScanner: bundled {$file} missing at {$path}", 'accessibility-audit');

                return null;
            }

            $sources[$key] = $source;
        }

        return $this->_pdfSources = $sources;
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

            $page->evaluate($this->_steadyPageScript())->getReturnValue(self::PAGE_TIMEOUT_MS);
            $page->evaluate($axeSource)->waitForResponse(self::PAGE_TIMEOUT_MS);
            $page->evaluate(self::IN_VIEW_PROBE_JS . "\n" . self::PSEUDO_REMEASURE_JS)->waitForResponse(self::PAGE_TIMEOUT_MS);

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
     * @var string A function that measures again the text axe gave up on
     *      because an ancestor has a `::before` or `::after`.
     *
     *      axe stops at any ancestor pseudo-element with a background, wherever
     *      that pseudo-element sits, because it cannot read the pseudo-element's
     *      box. A one-pixel rule along the top of a fixed header puts every nav
     *      link in the review queue. This works the box out from the computed
     *      style instead, and where no pseudo-element on any of those ancestors
     *      overlaps the text, it removes them and runs the contrast rule again
     *      on just those elements. An absolutely positioned pseudo-element
     *      takes no part in layout and does not cover the text, so the colour
     *      behind the text is the same with it gone, and the second answer is a
     *      measurement: a pass leaves the queue and a failure becomes a
     *      violation.
     *
     *      Anything it cannot place exactly stays as axe left it: a
     *      pseudo-element in normal flow or positioned against some other
     *      ancestor, one that is transformed, clipped, masked, filtered or
     *      shadowed, an ancestor that is transformed or split across lines, and
     *      a target inside a shadow root or a frame.
     *
     *      Kept as a constant so the browser pass and its test run the same
     *      source.
     */
    public const PSEUDO_REMEASURE_JS = <<<'JS'
        window.__aaRemeasurePseudo = function (results, exclude) {
          var ATTR = 'data-aa-pseudo-off';
          var TOLERANCE = 1;

          var px = function (value) {
            var n = parseFloat(value);
            return /px$/.test(value) && isFinite(n) ? n : null;
          };

          // The border box of an absolutely positioned pseudo-element, in
          // viewport coordinates, or null where it cannot be worked out.
          var pseudoBox = function (host, s) {
            if (s.position !== 'absolute') return null;
            if (s.transform !== 'none' || s.clipPath !== 'none' || s.filter !== 'none') return null;
            if ((s.maskImage || s.webkitMaskImage || 'none') !== 'none') return null;
            if (s.boxShadow !== 'none' || s.outlineStyle !== 'none') return null;

            var hs = getComputedStyle(host);
            if (hs.position === 'static' || hs.transform !== 'none') return null;

            var rects = host.getClientRects();
            if (rects.length !== 1) return null;

            var cbLeft = rects[0].left + host.clientLeft;
            var cbTop = rects[0].top + host.clientTop;
            var cbRight = cbLeft + host.clientWidth;
            var cbBottom = cbTop + host.clientHeight;

            var w = px(s.width);
            var h = px(s.height);
            if (w === null || h === null) return null;

            if (s.boxSizing !== 'border-box') {
              w += (px(s.paddingLeft) || 0) + (px(s.paddingRight) || 0)
                + (px(s.borderLeftWidth) || 0) + (px(s.borderRightWidth) || 0);
              h += (px(s.paddingTop) || 0) + (px(s.paddingBottom) || 0)
                + (px(s.borderTopWidth) || 0) + (px(s.borderBottomWidth) || 0);
            }

            var left = px(s.left);
            var right = px(s.right);
            var top = px(s.top);
            var bottom = px(s.bottom);
            var x;
            var y;

            if (left !== null) {
              x = cbLeft + left + (px(s.marginLeft) || 0);
            } else if (right !== null) {
              x = cbRight - right - (px(s.marginRight) || 0) - w;
            } else {
              return null;
            }

            if (top !== null) {
              y = cbTop + top + (px(s.marginTop) || 0);
            } else if (bottom !== null) {
              y = cbBottom - bottom - (px(s.marginBottom) || 0) - h;
            } else {
              return null;
            }

            return { left: x, top: y, right: x + w, bottom: y + h };
          };

          var overlaps = function (a, b) {
            return a.left < b.right - TOLERANCE && b.left < a.right - TOLERANCE
              && a.top < b.bottom - TOLERANCE && b.top < a.bottom - TOLERANCE;
          };

          // Whether every pseudo-element on the host can be placed and none of
          // them reaches the text.
          var clearOf = function (host, textRects) {
            var pseudos = ['::before', '::after'];

            for (var i = 0; i < pseudos.length; i++) {
              var s = getComputedStyle(host, pseudos[i]);

              if (s.content === 'none' || s.content === 'normal' || s.display === 'none') continue;

              var box = pseudoBox(host, s);
              if (!box) return false;

              for (var j = 0; j < textRects.length; j++) {
                if (overlaps(box, textRects[j])) return false;
              }
            }

            return true;
          };

          var one = function (target) {
            if (!Array.isArray(target) || target.length !== 1 || typeof target[0] !== 'string') return null;
            try { return document.querySelector(target[0]); } catch (e) { return null; }
          };

          var contrast = (results.incomplete || []).filter(function (v) { return v.id === 'color-contrast'; })[0];
          if (!contrast) return Promise.resolve(results);

          var candidates = [];
          var hosts = [];

          contrast.nodes.forEach(function (node) {
            var check = node.any && node.any[0];
            if (!check || !check.data || check.data.messageKey !== 'pseudoContent') return;

            var el = one(node.target);
            var related = (check.relatedNodes || []).map(function (r) { return one(r.target); });
            if (!el || !related.length || related.indexOf(null) !== -1) return;

            var textRects = Array.prototype.slice.call(el.getClientRects());
            if (!textRects.length) return;

            for (var i = 0; i < related.length; i++) {
              if (!clearOf(related[i], textRects)) return;
            }

            candidates.push({ node: node, el: el });
            related.forEach(function (host) { if (hosts.indexOf(host) === -1) hosts.push(host); });
          });

          if (!candidates.length) return Promise.resolve(results);

          var style = document.createElement('style');
          style.textContent = '[' + ATTR + ']::before, [' + ATTR + ']::after { content: none !important; }';
          (document.head || document.documentElement).appendChild(style);
          hosts.forEach(function (host) { host.setAttribute(ATTR, ''); });

          var restore = function () {
            hosts.forEach(function (host) { host.removeAttribute(ATTR); });
            style.remove();
          };

          var context = {
            include: candidates.map(function (c) { return [c.node.target[0]]; }),
            exclude: exclude || [],
          };

          return axe.run(context, {
            runOnly: { type: 'rule', values: ['color-contrast'] },
            resultTypes: ['violations', 'incomplete'],
          }).then(function (again) {
            restore();

            var els = candidates.map(function (c) { return c.el; });
            var ours = function (n) { return els.indexOf(one(n.target)) !== -1; };
            var pick = function (list) {
              var hit = (list || []).filter(function (v) { return v.id === 'color-contrast'; })[0];
              return hit ? { rule: hit, nodes: hit.nodes.filter(ours) } : null;
            };

            contrast.nodes = contrast.nodes.filter(function (n) {
              return candidates.every(function (c) { return c.node !== n; });
            });

            var stillUnsure = pick(again.incomplete);
            if (stillUnsure) contrast.nodes = contrast.nodes.concat(stillUnsure.nodes);

            var failing = pick(again.violations);
            if (failing && failing.nodes.length) {
              var existing = results.violations.filter(function (v) { return v.id === 'color-contrast'; })[0];
              if (existing) {
                existing.nodes = existing.nodes.concat(failing.nodes);
              } else {
                failing.rule.nodes = failing.nodes;
                results.violations.push(failing.rule);
              }
            }

            return results;
          }, function () {
            restore();
            return results;
          });
        };
        JS;

    /**
     * The in-page script that holds the page still before axe measures it.
     *
     * Contrast is read off computed colours and glyph sizes, so text still
     * drawn in a fallback font, or partway through a fade, gives a different
     * answer on every run. The script finishes CSS animations and transitions
     * on the spot, then waits for web fonts up to MAX_FONT_WAIT_MS.
     *
     * Animations are cut to zero length rather than removed: with
     * `animation: none` an element that fades in with `forwards` would drop
     * back to its starting style, often invisible. Transforms are left alone
     * because layout can depend on them (a modal centred with translate).
     *
     * @return string The JavaScript to evaluate in the page.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _steadyPageScript(): string
    {
        $fontWaitMs = self::MAX_FONT_WAIT_MS;

        return <<<JS
            (function() {
                var style = document.createElement('style');
                style.textContent = '*, *::before, *::after {'
                    + ' animation-duration: 0s !important;'
                    + ' animation-delay: 0s !important;'
                    + ' animation-iteration-count: 1 !important;'
                    + ' transition: none !important; }';
                (document.head || document.documentElement).appendChild(style);

                if (!document.fonts || !document.fonts.ready) {
                    return Promise.resolve(true);
                }

                return Promise.race([
                    document.fonts.ready.then(function() { return true; }),
                    new Promise(function(resolve) { setTimeout(function() { resolve(false); }, {$fontWaitMs}); }),
                ]);
            })()
            JS;
    }

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
                return window.__aaRemeasurePseudo
                    ? window.__aaRemeasurePseudo(r, {$excludeJson})
                    : r;
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
