<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\ScannableElementTypes;
use johnhenry\accessibilityaudit\services\HeadlessScanner;
use johnhenry\accessibilityaudit\services\ReadabilityService;

/**
 * Stores the plugin's settings.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 *
 * @property-read string $browserUserAgent
 * @property-read string $fetchUserAgent
 */
class SettingsModel extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * Resolved-issue retention: pruned along with old scans, so the retention
     * window applies to them too. The default.
     */
    public const RESOLVED_RETENTION_WITH_SCANS = 'withScans';

    /**
     * Resolved-issue retention: kept for the retention window past their own
     * resolution date, independent of the scan that originally found them.
     */
    public const RESOLVED_RETENTION_KEEP_DAYS = 'keepDays';

    /**
     * Resolved-issue retention: never pruned, kept as a permanent record.
     */
    public const RESOLVED_RETENTION_FOREVER = 'forever';

    /**
     * The target score a fresh install starts on, so the dashboard has a bar to
     * measure against and the CI endpoint has something to fail on before
     * anyone visits Settings. A page carrying no errors and a couple of
     * warnings still clears it, so it reads as a working site rather than a
     * perfect one. Set targetScore to 0 to turn the target off deliberately.
     */
    public const RECOMMENDED_TARGET_SCORE = 90;

    /**
     * The identifying token carried by the default scanner User-Agent on every
     * surface (both HTTP fetches and the headless Chrome pass), so a single
     * WAF or firewall rule matching this substring allow-lists the whole
     * scanner at once.
     */
    public const USER_AGENT_TOKEN = 'CraftAccessibilityAudit';

    /**
     * CSS selectors every scan surface skips by default: the mount points of
     * the common consent-management platforms. Their banners are third-party
     * UI injected at runtime — the site owner can neither fix their contrast
     * nor keep them still between scans, so findings inside them are noise
     * that buries the site's own issues. Merged with the admin's own
     * [[excludedSelectors]] by [[resolvedExcludedSelectors()]].
     *
     * @var string[]
     */
    public const DEFAULT_EXCLUDED_SELECTORS = [
        '#lanyard_root',                    // Ketch
        '#onetrust-consent-sdk',            // OneTrust
        '#CybotCookiebotDialog',            // Cookiebot
        '#usercentrics-root',               // Usercentrics
        '#didomi-host',                     // Didomi
        '#truste-consent-track',            // TrustArc
        '.truste_box_overlay',              // TrustArc (overlay variant)
        '.osano-cm-window',                 // Osano
        '#cmplz-cookiebanner-container',    // Complianz
        '.cky-consent-container',           // CookieYes
        '#iubenda-cs-banner',               // Iubenda
        '#termly-code-snippet-support',     // Termly
    ];

    /**
     * The information URL appended to the default fetch User-Agent, so a host
     * admin who spots the token in their logs can look up what it is.
     */
    private const _USER_AGENT_INFO_URL = 'https://plugins.craftcms.com/accessibility-audit';

    /**
     * The realistic desktop-Chrome string the browser-pass User-Agent extends,
     * so headless Chrome still presents as a real browser to UA-sniffing themes
     * while carrying the identifying token. The Chrome major only needs to stay
     * plausibly current; sites don't gate rendering on the exact build.
     */
    private const _BROWSER_UA_BASE = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    // Public Properties
    // =========================================================================

    /**
     * @var string The target WCAG conformance level (A, AA, AAA).
     */
    public string $wcagLevel = 'AA';

    /**
     * @var bool Whether to scan elements automatically when they are saved.
     */
    public bool $scanOnSave = true;

    /**
     * @var bool Whether to inject the frontend axe-core overlay for admins.
     */
    public bool $frontendAxe = true;

    /**
     * @var bool Whether the frontend overlay collapses to a small count badge
     * when idle, expanding again when the admin clicks the badge.
     */
    public bool $overlayCollapseWhenIdle = false;

    /**
     * @var string The screen corner the frontend overlay and its badge dock to:
     * one of bottom-right, bottom-left, top-right, top-left.
     */
    public string $overlayPosition = 'bottom-right';

    /**
     * @var int Seconds of inactivity before the frontend overlay collapses back
     * to its badge. Only applies when overlayCollapseWhenIdle is enabled.
     */
    public int $overlayIdleSeconds = 30;

    /**
     * @var string Path to a Chrome/Chromium binary for server-side axe scans,
     * or an environment variable reference. Empty disables headless scanning.
     */
    public string $chromePath = '';

    /**
     * @var string WebSocket URI of an already-running Chrome to connect to for
     * server-side axe scans, e.g. 'ws://chrome:3000' or a browserless endpoint
     * carrying a token. Takes precedence over [[chromePath]], and is the only
     * option on hosts that can't install a binary (Craft Cloud and other
     * ephemeral platforms). Supports environment variable references, which is
     * how a tokenised endpoint should be stored. Empty falls back to the local
     * binary.
     */
    public string $chromeWsEndpoint = '';

    /**
     * @var bool Whether headless Chrome launches with --no-sandbox. Required
     * in most containers and CI runners, where Chrome's sandbox can't
     * initialise; hosts running a dedicated user with a working sandbox can
     * turn it off for defence in depth. Only applies when launching a local
     * binary: a remote browser is launched by whoever runs it.
     */
    public bool $chromeNoSandbox = true;

    /**
     * @var int Milliseconds the headless browser pass waits after a page has
     * loaded before running the axe checks, so late-rendering JavaScript can
     * finish. Paid once per viewport pass, so on large sites it is one of the
     * biggest levers on total scan time. 0 skips the wait entirely.
     */
    public int $browserSettleMs = 2000;

    /**
     * @var string Overrides the User-Agent for every scanner request against
     * the site: the PHP scanner's HTML fetch, the readability fetch, and the
     * headless Chrome browser pass. Lets hosts allow-list the scanner in WAF
     * and firewall rules. Supports environment variable references. Empty
     * sends the plugin's own branded defaults, which already carry the
     * [[USER_AGENT_TOKEN]] on all three surfaces (see [[getFetchUserAgent()]]
     * and [[getBrowserUserAgent()]]).
     */
    public string $scannerUserAgent = '';

    /**
     * @var bool Whether to apply EN 301 549 reporting in addition to WCAG.
     */
    public bool $en301549 = false;

    /**
     * @var bool Whether elements the scanner covers get a Readability view in
     * their Preview menu, showing the text being written with its long
     * sentences marked. Pro only.
     */
    public bool $readabilityPreviewTarget = true;

    /**
     * @var string The reading target the Readability preview marks sentences
     * against for everyone who hasn't picked their own: 'accessible', 'default'
     * or 'technical'. Pro only.
     */
    public string $readabilityTarget = ReadabilityService::DEFAULT_TARGET;

    /**
     * @var string Path to a site Twig template that renders the VPAT export
     * in place of the plugin's built-in document, e.g. '_vpat/export'. The
     * template receives the same variables as the built-in export. Empty, or
     * pointing at a template that doesn't exist, falls back to the built-in
     * print-ready document.
     */
    public string $vpatExportTemplate = '';

    /**
     * @var string A site template that renders the published accessibility
     * statement in place of the built-in one. Receives the same `statement`
     * variable. Empty, or pointing at a template that doesn't exist, falls back
     * to the built-in markup.
     */
    public string $statementTemplate = '';

    /**
     * @var string[] Rule IDs to ignore during scanning.
     */
    public array $ignoreRules = [];

    /**
     * @var array<int, array{enabled?: bool, siteUid?: string, siteId?: int|string, uriPattern?: string}>
     * URI patterns whose matching pages are excluded from every scan. Each row
     * is a regular expression tested against a page's URI, optionally scoped to
     * one site by UID. The homepage is `^$`; a blank pattern matches nothing.
     * Shaped for the CP's editable-table field and settable from the config
     * file.
     */
    public array $excludedUriPatterns = [];

    /**
     * @var array<int, array{enabled?: bool, siteUid?: string, siteId?: int|string, url?: string}|string>
     * Extra pages to scan, for the ones Craft routes without backing them with
     * an element: search results, filtered listings, paginated archives. Each
     * row is one URL, absolute or site-relative, optionally scoped to a single
     * site; a query string is kept, so one named example of a dynamic page can
     * be audited. Shaped for the CP's editable-table field and settable from
     * the config file, where a list can hold a bare string in place of a row.
     */
    public array $customUrls = [];

    /**
     * @var string Extra CSS selectors excluded from every scan surface, one
     * per line, merged with [[DEFAULT_EXCLUDED_SELECTORS]]. For page furniture
     * whose markup the site does not control: chat widgets, embedded players,
     * A/B testing overlays.
     */
    public string $excludedSelectors = '';

    /**
     * @var string[] The UIDs of the asset volumes whose images are excluded
     * from the alt-text audit and its counts. Stored as volume UIDs (stable
     * across environments and project-config friendly), never ids or handles.
     * Handy for volumes that aren't public content: avatars, generated
     * thumbnails, and system or internal files.
     */
    public array $excludedVolumes = [];

    /**
     * @var class-string[]|null The fully-qualified class names of the element
     * types the content scanner covers. Null means "not configured": the
     * scanner then falls back to every native (Craft-core and first-party,
     * i.e. `craft\` namespace) URL-bearing type. A saved array is an explicit
     * allow-list, so a third-party plugin's custom element is only scanned once
     * it's ticked. Stored as class names (stable, project-config friendly).
     *
     * Resolve the effective list with {@see self::resolvedScannedElementTypes()},
     * never read this property raw.
     */
    public ?array $scannedElementTypes = null;

    /**
     * @var int The number of days to retain scan results.
     */
    public int $retainDays = 90;

    /**
     * @var string How resolved issues are retained when scan results are pruned:
     * self::RESOLVED_RETENTION_WITH_SCANS (pruned with old scans),
     * self::RESOLVED_RETENTION_KEEP_DAYS (kept the retention window past their
     * resolution date), or self::RESOLVED_RETENTION_FOREVER (kept permanently).
     */
    public string $resolvedRetention = self::RESOLVED_RETENTION_WITH_SCANS;

    /**
     * @var int The target accessibility score (0-100). 0 turns the target off:
     *      the dashboard stops showing progress against it and the CI endpoint
     *      reports every scan as passing.
     */
    public int $targetScore = self::RECOMMENDED_TARGET_SCORE;

    /**
     * @var string The handle of the field used to store alt text.
     */
    public string $altTextField = 'alt';

    /**
     * @var string The Anthropic API key, or an environment variable reference.
     */
    public string $anthropicApiKey = '';

    /**
     * @var string Site context passed to the AI alt-text prompt.
     */
    public string $altTextContext = '';

    /**
     * @var string The language used for generated alt text.
     */
    public string $altTextLanguage = 'English';

    /**
     * @var bool Whether to auto-generate alt text on image upload.
     */
    public bool $autoGenerateAlt = false;

    /**
     * @var bool Whether to email a notification when a scan crosses a threshold.
     */
    public bool $notifyEmailEnabled = false;

    /**
     * @var string Comma- or newline-separated list of email recipients.
     */
    public string $notifyEmailRecipients = '';

    /**
     * @var bool Whether to post a Slack notification when a scan crosses a threshold.
     */
    public bool $notifySlackEnabled = false;

    /**
     * @var string A Slack incoming webhook URL, or an environment variable reference.
     */
    public string $notifySlackWebhookUrl = '';

    /**
     * @var bool Whether to notify when a scan introduces a new error-severity issue.
     */
    public bool $notifyOnNewError = false;

    /**
     * @var bool Whether to notify when a scan's score drops sharply.
     */
    public bool $notifyOnScoreDrop = false;

    /**
     * @var int The number of points a score must drop before a notification fires.
     */
    public int $notifyScoreDropThreshold = 10;

    /**
     * @var ?string A token used to authenticate CI/CD webhook requests, or null.
     */
    public ?string $ciApiToken = null;

    /**
     * @var bool Whether the overlay may be served to decoupled (headless)
     * front ends via the token-authenticated overlay endpoints.
     */
    public bool $decoupledOverlay = false;

    /**
     * @var ?string SHA-256 hash of the decoupled overlay token, or null when
     * none has been generated. Only the hash is stored (settings land in
     * project config); the plaintext is shown once at generation.
     */
    public ?string $overlayApiToken = null;

    /**
     * @var string Extra origins allowed to call the overlay endpoints, one per
     * line. The origins of every site's base URL are always allowed; this
     * covers front ends served from origins Craft doesn't know about, such as
     * a local dev server.
     */
    public string $overlayAllowedOrigins = '';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Additional URLs were one newline-separated string up to 1.3.0. A site
     * that has not had its stored settings rewritten yet, and a config file
     * still written the old way, both hand a string to an array property,
     * which would be fatal. Converting here covers every route in, since
     * settings only ever reach the model through this method.
     *
     * @param array<string, mixed> $values The attribute values, keyed by name.
     * @param bool $safeOnly Whether to only assign safe attributes.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (isset($values['customUrls']) && is_string($values['customUrls'])) {
            $values['customUrls'] = self::customUrlRows($values['customUrls']);
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * The rows behind a newline-separated list of URLs.
     *
     * A line commented out with a `#` becomes an unticked row rather than
     * being dropped: it is a URL somebody kept but did not want scanned, which
     * is what unticking a row says.
     *
     * @param string $lines The URLs, one per line.
     * @return array<int, array{enabled: bool, siteUid: string, url: string}>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public static function customUrlRows(string $lines): array
    {
        $rows = [];

        foreach (preg_split('/\R/u', $lines) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $enabled = !str_starts_with($line, '#');
            $url = $enabled ? $line : trim(ltrim($line, '#'));

            if ($url === '') {
                continue;
            }

            $rows[] = ['enabled' => $enabled, 'siteUid' => '', 'url' => $url];
        }

        return $rows;
    }

    /**
     * The site a scoped settings row belongs to, or null for every site.
     *
     * Rows store the site by UID, which is the same in every environment. Rows
     * saved before 1.5.0 carry a numeric `siteId`, which is still read. A UID
     * that names no site on this install counts as every site.
     *
     * @param array<string, mixed> $row An excluded URI pattern or custom URL row.
     * @return int|null The site id, or null when the row covers every site.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function rowSiteId(array $row): ?int
    {
        $uid = trim((string)($row['siteUid'] ?? ''));

        if ($uid !== '') {
            foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
                if ($site->uid === $uid) {
                    return (int)$site->id;
                }
            }

            return null;
        }

        $id = trim((string)($row['siteId'] ?? ''));

        return $id !== '' ? (int)$id : null;
    }

    /**
     * The additional URLs a scan of a site should cover, de-duplicated.
     *
     * @param int|null $siteId The site being scanned. Rows scoped to another
     * site are left out. Null takes every row whatever its scope.
     * @return string[] The URLs as they were entered, absolute or site-relative.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function resolvedCustomUrls(?int $siteId = null): array
    {
        $urls = [];

        foreach ($this->customUrls as $row) {
            if (!is_array($row) || !($row['enabled'] ?? true)) {
                continue;
            }

            $rowSite = self::rowSiteId($row);

            if ($siteId !== null && $rowSite !== null && $rowSite !== $siteId) {
                continue;
            }

            $url = trim((string)($row['url'] ?? ''));

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The element types a scan actually covers.
     *
     * The stored choice is intersected with what is installed, so a type left
     * over from an uninstalled plugin cannot send the sweep looking for
     * elements that no longer exist.
     *
     * @return string[] The element classes to scan.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function resolvedScannedElementTypes(): array
    {
        $chosen = $this->scannedElementTypes ?? ScannableElementTypes::native();

        return array_values(array_intersect($chosen, array_keys(ScannableElementTypes::all())));
    }

    /**
     * The CSS selectors every scan surface excludes: the built-in
     * consent-platform defaults plus the admin's own lines. All four engines
     * (headless axe, the Inspect preview pass, the frontend overlay, and the
     * PHP scanner) read from here so an exclusion holds everywhere at once.
     *
     * @return string[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function resolvedExcludedSelectors(): array
    {
        $selectors = self::DEFAULT_EXCLUDED_SELECTORS;

        foreach (preg_split('/[\r\n]+/', $this->excludedSelectors) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $selectors[] = $line;
            }
        }

        return array_values(array_unique($selectors));
    }

    /**
     * The resolved scanner User-Agent (env-var references parsed), or an
     * empty string when unset. Every scanner HTTP surface reads it from here
     * so the value is resolved in exactly one place.
     *
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getScannerUserAgent(): string
    {
        return trim((string)App::parseEnv($this->scannerUserAgent));
    }

    /**
     * The User-Agent to send on the HTTP fetch surfaces (the PHP scan fetch
     * and the readability fetch). The configured [[getScannerUserAgent()]]
     * override when set, otherwise a branded default carrying the
     * [[USER_AGENT_TOKEN]], the plugin version, and an information URL.
     *
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getFetchUserAgent(): string
    {
        $override = $this->getScannerUserAgent();
        if ($override !== '') {
            return $override;
        }

        return self::USER_AGENT_TOKEN . '/' . $this->_pluginVersion()
            . ' (+' . self::_USER_AGENT_INFO_URL . ')';
    }

    /**
     * The User-Agent to send on the headless Chrome browser pass. The
     * configured [[getScannerUserAgent()]] override when set, otherwise a
     * realistic desktop-Chrome string with the [[USER_AGENT_TOKEN]] appended,
     * so the page renders as it would in a real browser while the same single
     * WAF rule that matches the fetch surfaces still matches this one.
     *
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBrowserUserAgent(): string
    {
        $override = $this->getScannerUserAgent();
        if ($override !== '') {
            return $override;
        }

        return self::_BROWSER_UA_BASE . ' ' . self::USER_AGENT_TOKEN . '/' . $this->_pluginVersion();
    }

    /**
     * The plugin's installed version, for stamping into the default scanner
     * User-Agent. Falls back to a bare major when the instance is somehow
     * unavailable (it always is at scan time).
     *
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _pluginVersion(): string
    {
        return AccessibilityAudit::getInstance()->version ?? '1.0';
    }

    /**
     * @inheritdoc
     *
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['wcagLevel'], 'in', 'range' => ['A', 'AA', 'AAA']],
            [['readabilityTarget'], 'in', 'range' => array_keys(ReadabilityService::TARGETS)],
            [['scanOnSave', 'frontendAxe', 'overlayCollapseWhenIdle', 'autoGenerateAlt', 'en301549', 'chromeNoSandbox', 'decoupledOverlay', 'readabilityPreviewTarget'], 'boolean'],
            [['resolvedRetention'], 'in', 'range' => [self::RESOLVED_RETENTION_WITH_SCANS, self::RESOLVED_RETENTION_KEEP_DAYS, self::RESOLVED_RETENTION_FOREVER]],
            [['overlayPosition'], 'in', 'range' => ['bottom-right', 'bottom-left', 'top-right', 'top-left']],
            [['overlayIdleSeconds'], 'integer', 'min' => 3, 'max' => 600],
            [['browserSettleMs'], 'integer', 'min' => 0, 'max' => HeadlessScanner::MAX_SETTLE_MS],
            [['notifyEmailEnabled', 'notifySlackEnabled', 'notifyOnNewError', 'notifyOnScoreDrop'], 'boolean'],
            [['retainDays'], 'integer', 'min' => 0],
            [['targetScore'], 'integer', 'min' => 0, 'max' => 100],
            [['notifyScoreDropThreshold'], 'integer', 'min' => 1, 'max' => 100],
            [['ignoreRules'], 'safe'],
            [['excludedUriPatterns', 'customUrls'], 'safe'],
            [['excludedUriPatterns'], 'validateUriPatterns'],
            [['excludedVolumes'], 'each', 'rule' => ['string']],
            [['scannedElementTypes'], 'each', 'rule' => ['string'], 'skipOnEmpty' => true],
            [['altTextField', 'anthropicApiKey', 'altTextContext', 'altTextLanguage', 'chromePath', 'chromeWsEndpoint', 'vpatExportTemplate'], 'string'],
            [['statementTemplate'], 'string'],
            [['notifyEmailRecipients', 'notifySlackWebhookUrl', 'ciApiToken', 'scannerUserAgent'], 'string'],
            [['overlayApiToken', 'overlayAllowedOrigins', 'excludedSelectors'], 'string'],
            [['overlayAllowedOrigins'], 'validateAllowedOrigins'],
        ]);
    }

    /**
     * Checks that every excluded-URI pattern is a regular expression that
     * compiles.
     *
     * A pattern that does not compile never matches, and the matcher swallows
     * the warning so a scan is not derailed by one bad row. That is right at
     * scan time and wrong here: without this the save goes through, the
     * pattern quietly matches nothing, and the pages it was written to keep
     * out carry on being scanned and counted with nothing to say why.
     *
     * @param string $attribute The attribute being validated.
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function validateUriPatterns(string $attribute): void
    {
        foreach ((array)$this->$attribute as $row) {
            $pattern = trim((string)($row['uriPattern'] ?? ''));

            // A blank pattern matches nothing, so there is nothing to compile.
            if ($pattern === '') {
                continue;
            }

            set_error_handler(static fn(): bool => true);

            try {
                $compiles = preg_match('~' . str_replace('~', '\~', $pattern) . '~', '') !== false;
            } finally {
                restore_error_handler();
            }

            if (!$compiles) {
                $this->addError($attribute, Craft::t(
                    'accessibility-audit',
                    '“{pattern}” is not a valid pattern, so it would never match anything.',
                    ['pattern' => $pattern],
                ));
            }
        }
    }

    /**
     * Checks that every extra allowed origin is an origin and nothing more.
     *
     * The allow-list is compared to the Origin header exactly, so a line
     * carrying a path, a trailing wildcard or a bare hostname matches nothing a
     * browser will ever send. Same failure as an uncompilable URI pattern: the
     * save goes through, the entry quietly matches nothing, and the overlay is
     * refused on the very origin it was added for with nothing to say why.
     *
     * @param string $attribute The attribute being validated.
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function validateAllowedOrigins(string $attribute): void
    {
        foreach (preg_split('/[\r\n,]+/', (string)$this->$attribute) ?: [] as $line) {
            $origin = rtrim(trim($line), '/');

            if ($origin === '') {
                continue;
            }

            $parts = parse_url($origin);

            $valid = is_array($parts)
                && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                && self::_isHost($parts['host'] ?? '')
                && !isset($parts['path'])
                && !isset($parts['query'])
                && !isset($parts['fragment'])
                && !isset($parts['user'])
                && !isset($parts['pass']);

            if (!$valid) {
                $this->addError($attribute, Craft::t(
                    'accessibility-audit',
                    '“{origin}” is not an origin, so it would never match. Write it as scheme://host, e.g. https://example.com:3000.',
                    ['origin' => $origin],
                ));
            }
        }
    }

    /**
     * Whether a string is a hostname or IP literal and nothing else.
     *
     * parse_url() takes any character it is given as a host, so `*.example.com`
     * comes back looking like a perfectly good one. A wildcard is the mistake
     * worth catching here: allow-lists elsewhere expand them and this one does
     * not, it compares the whole string.
     *
     * @param string $host The host portion of a parsed origin.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private static function _isHost(string $host): bool
    {
        // A bracketed IPv6 literal, as it is written in a URL.
        if (str_starts_with($host, '[')) {
            return preg_match('/^\[[0-9a-fA-F:.]+\]$/', $host) === 1;
        }

        return preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $host) === 1;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'wcagLevel' => Craft::t('accessibility-audit', 'Target WCAG Level'),
            'scanOnSave' => Craft::t('accessibility-audit', 'Scan on Entry Save'),
            'frontendAxe' => Craft::t('accessibility-audit', 'Frontend axe-core Overlay'),
            'overlayCollapseWhenIdle' => Craft::t('accessibility-audit', 'Collapse to Badge When Idle'),
            'overlayPosition' => Craft::t('accessibility-audit', 'Overlay Position'),
            'overlayIdleSeconds' => Craft::t('accessibility-audit', 'Idle Collapse Delay (seconds)'),
            'decoupledOverlay' => Craft::t('accessibility-audit', 'Decoupled Frontend Overlay'),
            'overlayAllowedOrigins' => Craft::t('accessibility-audit', 'Additional Allowed Origins'),
            'chromePath' => Craft::t('accessibility-audit', 'Chrome Binary Path'),
            'chromeWsEndpoint' => Craft::t('accessibility-audit', 'Remote Chrome Endpoint'),
            'chromeNoSandbox' => Craft::t('accessibility-audit', 'Launch Chrome Without Sandbox'),
            'browserSettleMs' => Craft::t('accessibility-audit', 'Browser Settle Time (ms)'),
            'scannerUserAgent' => Craft::t('accessibility-audit', 'Scanner User-Agent'),
            'customUrls' => Craft::t('accessibility-audit', 'Additional URLs'),
            'ignoreRules' => Craft::t('accessibility-audit', 'Ignored Rules'),
            'excludedUriPatterns' => Craft::t('accessibility-audit', 'Excluded URI Patterns'),
            'excludedSelectors' => Craft::t('accessibility-audit', 'Excluded Elements (CSS selectors)'),
            'excludedVolumes' => Craft::t('accessibility-audit', 'Excluded Volumes'),
            'scannedElementTypes' => Craft::t('accessibility-audit', 'Scanned Element Types'),
            'retainDays' => Craft::t('accessibility-audit', 'Retain Scan Results (days)'),
            'resolvedRetention' => Craft::t('accessibility-audit', 'Resolved Issues'),
            'altTextField' => Craft::t('accessibility-audit', 'Alt Text Field'),
            'vpatExportTemplate' => Craft::t('accessibility-audit', 'VPAT Export Template'),
        ];
    }
}
