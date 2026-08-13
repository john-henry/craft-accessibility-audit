<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\models;

use craft\base\Model;
use craft\helpers\App;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\ScannableElementTypes;
use johnhenry\accessibilityaudit\services\HeadlessScanner;

/**
 * Stores the plugin's settings.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 *
 * @property-write mixed $pruneResolvedIssues
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
     * The identifying token carried by the default scanner User-Agent on every
     * surface (both HTTP fetches and the headless Chrome pass), so a single
     * WAF or firewall rule matching this substring allow-lists the whole
     * scanner at once.
     */
    public const USER_AGENT_TOKEN = 'CraftAccessibilityAudit';

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
     * @var array<int, array{enabled?: bool, siteId?: int|string, uriPattern?: string}>
     * URI patterns whose matching pages are excluded from every scan. Each row
     * is a regular expression tested against a page's URI, optionally scoped to
     * one site; an empty pattern matches the homepage. Shaped for the CP's
     * editable-table field and settable from the config file.
     */
    public array $excludedUriPatterns = [];

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
     * @var int The target accessibility score (0-100).
     */
    public int $targetScore = 0;

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

    // Public Methods
    // =========================================================================

    /**
     * The effective list of element-type class names the content scanner
     * covers: the configured allow-list, or every native type when
     * [[scannedElementTypes]] is null (unconfigured). The result is always
     * intersected with the currently-registered scannable types, so a class
     * left behind by an uninstalled plugin can never leak into a scan query.
     *
     * @return class-string[]
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function resolvedScannedElementTypes(): array
    {
        $chosen = $this->scannedElementTypes ?? ScannableElementTypes::native();

        return array_values(array_intersect($chosen, array_keys(ScannableElementTypes::all())));
    }

    /**
     * The resolved scanner User-Agent (env-var references parsed), or an
     * empty string when unset. Every scanner HTTP surface reads it from here
     * so the value is resolved in exactly one place.
     *
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getScannerUserAgent(): string
    {
        return trim(App::parseEnv($this->scannerUserAgent ?? ''));
    }

    /**
     * The User-Agent to send on the HTTP fetch surfaces (the PHP scan fetch
     * and the readability fetch). The configured [[getScannerUserAgent()]]
     * override when set, otherwise a branded default carrying the
     * [[USER_AGENT_TOKEN]], the plugin version, and an information URL.
     *
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _pluginVersion(): string
    {
        return AccessibilityAudit::getInstance()?->version ?? '1.0';
    }

    /**
     * Back-compat: earlier versions stored a boolean `pruneResolvedIssues`.
     * Maps a stored legacy value onto the three-way [[resolvedRetention]] when
     * settings load, so upgrading installs keep their behaviour: true pruned
     * resolved issues with old scans, false kept them on their resolution-date
     * clock (self::RESOLVED_RETENTION_KEEP_DAYS).
     *
     * @param mixed $prune The legacy boolean value from stored settings.
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function setPruneResolvedIssues(mixed $prune): void
    {
        $this->resolvedRetention = (bool)$prune
            ? self::RESOLVED_RETENTION_WITH_SCANS
            : self::RESOLVED_RETENTION_KEEP_DAYS;
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['wcagLevel'], 'in', 'range' => ['A', 'AA', 'AAA']],
            [['scanOnSave', 'frontendAxe', 'overlayCollapseWhenIdle', 'autoGenerateAlt', 'en301549', 'chromeNoSandbox'], 'boolean'],
            [['resolvedRetention'], 'in', 'range' => [self::RESOLVED_RETENTION_WITH_SCANS, self::RESOLVED_RETENTION_KEEP_DAYS, self::RESOLVED_RETENTION_FOREVER]],
            [['overlayPosition'], 'in', 'range' => ['bottom-right', 'bottom-left', 'top-right', 'top-left']],
            [['overlayIdleSeconds'], 'integer', 'min' => 3, 'max' => 600],
            [['browserSettleMs'], 'integer', 'min' => 0, 'max' => HeadlessScanner::MAX_SETTLE_MS],
            [['notifyEmailEnabled', 'notifySlackEnabled', 'notifyOnNewError', 'notifyOnScoreDrop'], 'boolean'],
            [['retainDays'], 'integer', 'min' => 0],
            [['targetScore'], 'integer', 'min' => 0, 'max' => 100],
            [['notifyScoreDropThreshold'], 'integer', 'min' => 1, 'max' => 100],
            [['ignoreRules'], 'safe'],
            [['excludedUriPatterns'], 'safe'],
            [['excludedVolumes'], 'each', 'rule' => ['string']],
            [['scannedElementTypes'], 'each', 'rule' => ['string'], 'skipOnEmpty' => true],
            [['altTextField', 'anthropicApiKey', 'altTextContext', 'altTextLanguage', 'chromePath', 'chromeWsEndpoint', 'vpatExportTemplate'], 'string'],
            [['statementTemplate'], 'string'],
            [['notifyEmailRecipients', 'notifySlackWebhookUrl', 'ciApiToken', 'scannerUserAgent'], 'string'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'wcagLevel' => 'Target WCAG Level',
            'scanOnSave' => 'Scan on Entry Save',
            'frontendAxe' => 'Frontend axe-core Overlay',
            'overlayCollapseWhenIdle' => 'Collapse to Badge When Idle',
            'overlayPosition' => 'Overlay Position',
            'overlayIdleSeconds' => 'Idle Collapse Delay (seconds)',
            'chromePath' => 'Chrome Binary Path',
            'chromeWsEndpoint' => 'Remote Chrome Endpoint',
            'chromeNoSandbox' => 'Launch Chrome Without Sandbox',
            'browserSettleMs' => 'Browser Settle Time (ms)',
            'scannerUserAgent' => 'Scanner User-Agent',
            'ignoreRules' => 'Ignored Rules',
            'excludedUriPatterns' => 'Excluded URI Patterns',
            'excludedVolumes' => 'Excluded Volumes',
            'scannedElementTypes' => 'Scanned Element Types',
            'retainDays' => 'Retain Scan Results (days)',
            'resolvedRetention' => 'Resolved Issues',
            'altTextField' => 'Alt Text Field',
            'vpatExportTemplate' => 'VPAT Export Template',
        ];
    }
}
