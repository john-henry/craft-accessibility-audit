<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\errors\SiteNotFoundException;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\models\Site;
use craft\services\Dashboard;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use johnhenry\accessibilityaudit\assets\AccessibilityAuditAsset;
use johnhenry\accessibilityaudit\assets\FrontendAxeAsset;
use johnhenry\accessibilityaudit\jobs\GenerateAltTextJob;
use johnhenry\accessibilityaudit\models\SettingsModel;
use johnhenry\accessibilityaudit\services\AssetScanner;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\ContentScanner;
use johnhenry\accessibilityaudit\services\HeadlessScanner;
use johnhenry\accessibilityaudit\services\NotificationService;
use johnhenry\accessibilityaudit\services\OrganisationService;
use johnhenry\accessibilityaudit\services\PotentialScanner;
use johnhenry\accessibilityaudit\services\ReadabilityService;
use johnhenry\accessibilityaudit\services\ReportService;
use johnhenry\accessibilityaudit\services\StatementService;
use johnhenry\accessibilityaudit\services\VerdictService;
use johnhenry\accessibilityaudit\services\VpatService;
use johnhenry\accessibilityaudit\twig\A11yTwigExtension;
use johnhenry\accessibilityaudit\variables\AccessibilityVariable;
use johnhenry\accessibilityaudit\widgets\AccessibilityScoreWidget;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use Throwable;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\InvalidRouteException;
use yii\web\View as ViewAlias;

/**
 * Accessibility Audit plugin.
 *
 * Scans Craft content for WCAG accessibility issues, surfaces potential
 * problems, generates AI-assisted alt text, analyses readability, and
 * produces VPAT conformance reports.
 *
 * @property-read AuditService $audit
 * @property-read ContentScanner $content
 * @property-read HeadlessScanner $headless
 * @property-read PotentialScanner $potential
 * @property-read AssetScanner $assets
 * @property-read ReportService $report
 * @property-read OrganisationService $organisation
 * @property-read StatementService $statement
 * @property-read VpatService $vpat
 * @property-read ReadabilityService $readability
 * @property-read NotificationService $notifications
 * @property-read VerdictService $verdicts
 * @property-read mixed $settingsResponse
 * @property-read null|array $cpNavItem
 * @property-read SettingsModel $settings
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class AccessibilityAudit extends BasePlugin
{
    // Constants
    // =========================================================================

    /**
     * @var string The Standard edition handle. The scan-count cap applies to
     * this edition.
     */
    public const EDITION_STANDARD = 'standard';

    /**
     * @var string The Pro edition handle. Scanning is unlimited on this edition.
     */
    public const EDITION_PRO = 'pro';

    /**
     * @var string[] Every message passed to Craft.t('accessibility-audit', …)
     * in JavaScript, whether from a .js file or an inline {% js %} block.
     *
     * Craft.t() reads Craft.translations, which is only populated by
     * View::registerTranslations(). Without this list a translated install
     * silently renders these strings in English, because Craft.t() falls back
     * to the source message. Nothing is emitted on an English install:
     * registerTranslations() skips any message whose translation matches the
     * source, so this costs those installs nothing.
     *
     * JsTranslationsTest keeps this in sync with the code: add a Craft.t()
     * call without adding it here and that test fails.
     */
    public const JS_TRANSLATIONS = [
        'AI draft: review and edit before moving on. ',
        'Accessibility score by date',
        'Accessibility score over time',
        'Affected pages',
        'Alt text generated, save the asset to apply it.',
        'Analysed',
        'Analysing…',
        'Analysis request failed. Check your connection and try again.',
        'Conformance',
        'Chart updated.',
        'Check',
        'Content writing',
        'Could not load the trend for that range.',
        'Could not queue the scan.',
        'Could not save that. Try again.',
        'Count',
        'Cumulative resolved issues by date',
        'Date',
        'Development',
        'Draft with AI',
        'Drafting…',
        'Ease',
        'Element type',
        'Error, please try again.',
        'Error, retry',
        'Export CSV',
        'Failed to load.',
        'Failed to load: {error}',
        'Fail',
        'First detected',
        'Generate Alt Text',
        'Generating…',
        'Grade',
        'Issues',
        'Issues resolved over time, cumulative',
        'Last scanned',
        'Limit reached',
        'Loading…',
        'Marked decorative, no alt text needed.',
        'You can change that on the Assets page.',
        'No pages analysed yet.',
        'No pages found.',
        'No pages with potential issues found.',
        'No resolved issues yet. Fixed issues appear here after the next scan.',
        'No scanned pages yet.',
        'Nothing has been dismissed yet.',
        'Occurrences',
        'Occurrences and affected pages by date',
        'Occurrences and affected pages over time',
        'Owner',
        'Page',
        'Pages',
        'Pass',
        'Points gained',
        'Potential issue',
        'Queued {n} scans, check back soon',
        'Queuing…',
        'Re-analyse',
        'Re-scan',
        'Re-scan selected',
        'Regenerate',
        'Resolved',
        'Resolved (running total)',
        'Resolved issue',
        'Responsibility',
        'Restore',
        'Dismissed by',
        'Restored to Needs review.',
        'Restore selected',
        'Restore your notes',
        'Retry',
        'SC',
        'Saving…',
        'Scan failed',
        'Scanning…',
        'Score',
        'Show issues on this page',
        'Target {n}',
        'Technical',
        'Verification failed.',
        'Verifying…',
        'View report',
        'Visual design',
        'WCAG',
        'WCAG 3.1.5',
        'Words',
        'Marking images decorative hides missing-alt warnings. Only mark images that carry no information. Mark {n} images decorative?',
        '{n} images marked decorative.',
        '{n} images no longer decorative.',
        '{n} selected',
        '~age {n}',
        'What was dismissed',
        'errors',
        'notices',
        'opens in new tab',
        'warnings',
        'words',
    ];

    // Static Properties
    // =========================================================================

    /**
     * @var AccessibilityAudit Static reference to the plugin instance.
     */
    public static AccessibilityAudit $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether the plugin has a settings page in the CP.
     */
    public bool $hasCpSettings = true;

    /**
     * @var bool Whether the plugin has its own section in the CP.
     */
    public bool $hasCpSection = true;

    /**
     * @var string The plugin's schema version, used to track migrations.
     */
    public string $schemaVersion = '1.0.0';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [
            self::EDITION_STANDARD,
            self::EDITION_PRO,
        ];
    }

    /**
     * Whether the active edition is Pro.
     *
     * The single check the whole plugin routes its Pro-only feature gating
     * through, so the edition comparison lives in one place rather than being
     * repeated as `->is(self::EDITION_PRO)` across every controller and service.
     *
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    /**
     * The sites the plugin may operate on for the active edition. Multi-site is
     * a Pro feature, so the Standard edition is limited to the primary site.
     *
     * Editable sites, not all sites: a user without permission for a site must
     * not be offered it in a switcher or shown its scan data. This mirrors
     * craft\helpers\Cp::siteMenuItems(), which defaults to the same list.
     *
     * @return Site[]
     * @throws SiteNotFoundException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function allowedSites(): array
    {
        $sites = Craft::$app->getSites();

        if ($this->isPro()) {
            return $sites->getEditableSites();
        }

        return [$sites->getPrimarySite()];
    }

    /**
     * The site the control panel is currently working with, gated by edition.
     *
     * Reads Craft's own `site` query param via Cp::requestedSite(), which
     * validates the handle against the user's editable sites and falls back to
     * the current site. Handles are used rather than a private `siteId` param
     * because site IDs are per-install auto-increments: a URL carrying one
     * means a different site on another environment, and Craft's own CP chrome
     * (Craft.siteId, the `requestedSite` Twig global) reads the `site` handle.
     *
     * Standard is primary-site only, so a `site` handle in the URL can't reach
     * another site's data on that edition.
     *
     * @return Site
     * @throws SiteNotFoundException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function requestedSite(): Site
    {
        $sites = Craft::$app->getSites();

        if (!$this->isPro()) {
            return $sites->getPrimarySite();
        }

        return Cp::requestedSite() ?? $sites->getPrimarySite();
    }

    /**
     * The ID of the site the control panel is currently working with.
     *
     * @return int
     * @throws SiteNotFoundException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     * @see requestedSite()
     */
    public function requestedSiteId(): int
    {
        return $this->requestedSite()->id;
    }

    /**
     * Resolves a requested site ID to one this edition is allowed to use.
     *
     * For callers that genuinely hold an integer: the AJAX endpoints and action
     * posts, whose URLs are rebuilt on every page load and never bookmarked.
     * Navigable CP pages use requestedSiteId() and the `site` handle instead.
     *
     * On Standard (single-site) this is always the primary site, so a
     * non-primary `siteId` in a request can't reach another site's data. On Pro
     * the requested site is honoured when it exists and the user may edit it,
     * otherwise the primary site is returned.
     *
     * @param mixed $requested The requested site ID (query/body param or int).
     * @return int
     * @throws SiteNotFoundException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function resolveSiteId(mixed $requested): int
    {
        $sites = Craft::$app->getSites();
        $primaryId = $sites->getPrimarySite()->id;

        if (!$this->isPro()) {
            return $primaryId;
        }

        $requestedId = (int) $requested;
        if ($requestedId === 0) {
            return $primaryId;
        }

        return in_array($requestedId, $sites->getEditableSiteIds(), true) ? $requestedId : $primaryId;
    }

    /**
     * Whether the active edition and the current user are allowed to operate on
     * a given site.
     *
     * The boolean counterpart to resolveSiteId(): where that clamps an unknown
     * request back to the primary site, this reports a plain yes/no so a caller
     * can refuse outright rather than silently retarget. On Standard only the
     * primary site is allowed (multi-site is Pro); on Pro the site must be one
     * the current user may actually edit, so a crafted ID can't reach a site
     * outside their permissions.
     *
     * @param int $siteId The site ID to test.
     * @return bool
     * @throws SiteNotFoundException
     * @since 1.0.1
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function isSiteAllowed(int $siteId): bool
    {
        $sites = Craft::$app->getSites();

        if (!$this->isPro()) {
            return $siteId === $sites->getPrimarySite()->id;
        }

        return in_array($siteId, $sites->getEditableSiteIds(), true);
    }

    public static function config(): array
    {
        return [
            'components' => [
                'audit' => AuditService::class,
                'content' => ContentScanner::class,
                'headless' => HeadlessScanner::class,
                'potential' => PotentialScanner::class,
                'assets' => AssetScanner::class,
                'report' => ReportService::class,
                'vpat' => VpatService::class,
                'organisation' => OrganisationService::class,
                'statement' => StatementService::class,
                'readability' => ReadabilityService::class,
                'notifications' => NotificationService::class,
                'verdicts' => VerdictService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'johnhenry\\accessibilityaudit\\console\\controllers';
        }

        $this->registerLogTarget();
        $this->registerTwigVariable();
        $this->registerSiteTemplateRoots();
        $this->registerAssetAuditSync();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerCpUrlRules();
            $this->registerWidgets();
            $this->registerPermissions();
            $this->registerCpAssets();
            $this->registerAssetAltButton();
            $this->registerElementSidebarPanel();
        }

        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            $this->registerSiteUrlRules();
            $this->maybeInjectFrontendAxe();
            $this->maybeRegisterTemplateDebug();
        }

        /** @var SettingsModel $settings */
        $settings = $this->getSettings();
        if ($settings->scanOnSave) {
            $this->registerScanOnSave();
        }
        if ($settings->autoGenerateAlt && !empty(trim(App::parseEnv($settings->anthropicApiKey ?? '')))) {
            $this->registerAutoGenerateAlt();
        }
    }

    /**
     * @throws SiteNotFoundException
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('accessibility-audit', 'Accessibility Audit');

        $user = Craft::$app->getUser();
        $subnav = [];

        // Carry the active site through the subnav. These URLs are otherwise
        // static, so every click would drop the `site` param and fall back to
        // the primary site, silently undoing the switcher. Only appended off
        // the primary site, so single-site installs keep clean URLs.
        $suffix = '';
        if ($this->isPro()) {
            $site = Cp::requestedSite();
            if ($site !== null && $site->id !== Craft::$app->getSites()->getPrimarySite()->id) {
                $suffix = '?site=' . $site->handle;
            }
        }

        if ($user->checkPermission('accessibility-audit:viewReports')) {
            $subnav['overview'] = ['label' => Craft::t('accessibility-audit', 'Overview'),         'url' => 'accessibility-audit' . $suffix];
            $subnav['issues'] = ['label' => Craft::t('accessibility-audit', 'Issues'),            'url' => 'accessibility-audit/issues' . $suffix];
            $subnav['potential'] = ['label' => Craft::t('accessibility-audit', 'Potential Issues'),  'url' => 'accessibility-audit/potential' . $suffix];
            $subnav['assets'] = ['label' => Craft::t('accessibility-audit', 'Assets'),            'url' => 'accessibility-audit/assets' . $suffix];
            $subnav['readability'] = ['label' => Craft::t('accessibility-audit', 'Readability'), 'url' => 'accessibility-audit/readability' . $suffix];
            $subnav['vpat'] = ['label' => Craft::t('accessibility-audit', 'VPAT'),        'url' => 'accessibility-audit/vpat' . $suffix];
            $subnav['statement'] = ['label' => Craft::t('accessibility-audit', 'Statement'), 'url' => 'accessibility-audit/statement' . $suffix];
            $subnav['color-tools'] = ['label' => Craft::t('accessibility-audit', 'Colour Tools'), 'url' => 'accessibility-audit/colour-tools' . $suffix];
        }
        $editableSettings = true;
        $general = Craft::$app->getConfig()->getGeneral();
        if (!$general->allowAdminChanges) {
            $editableSettings = false;
        }
        if ($editableSettings && $user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('app', 'Settings'), 'url' => 'accessibility-audit/settings'];
        }

        $item['subnav'] = $subnav;
        return $item;
    }

    public function getSettings(): SettingsModel
    {
        $settings = parent::getSettings();
        assert($settings instanceof SettingsModel);
        return $settings;
    }

    protected function createSettingsModel(): SettingsModel
    {
        return new SettingsModel();
    }

    /**
     * @throws InvalidRouteException
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            UrlHelper::cpUrl('accessibility-audit/settings')
        );
    }

    /** @throws InvalidConfigException */
    public function getAudit(): AuditService
    {
        $component = $this->get('audit');
        assert($component instanceof AuditService);
        return $component;
    }

    /** @throws InvalidConfigException */
    public function getContent(): ContentScanner
    {
        $component = $this->get('content');
        assert($component instanceof ContentScanner);
        return $component;
    }

    /** @throws InvalidConfigException */
    public function getPotential(): PotentialScanner
    {
        $component = $this->get('potential');
        assert($component instanceof PotentialScanner);
        return $component;
    }

    /** @throws InvalidConfigException */
    public function getAssets(): AssetScanner
    {
        $component = $this->get('assets');
        assert($component instanceof AssetScanner);
        return $component;
    }

    /** @throws InvalidConfigException */
    public function getReport(): ReportService
    {
        $component = $this->get('report');
        assert($component instanceof ReportService);
        return $component;
    }

    /** @throws InvalidConfigException */
    public function getVpat(): VpatService
    {
        $component = $this->get('vpat');
        assert($component instanceof VpatService);
        return $component;
    }

    /** @throws InvalidConfigException */
    public function getOrganisation(): OrganisationService
    {
        $component = $this->get('organisation');
        assert($component instanceof OrganisationService);
        return $component;
    }

    /** @throws InvalidConfigException */
    public function getStatement(): StatementService
    {
        $component = $this->get('statement');
        assert($component instanceof StatementService);
        return $component;
    }

    /** @throws InvalidConfigException */
    public function getReadability(): ReadabilityService
    {
        $component = $this->get('readability');
        assert($component instanceof ReadabilityService);
        return $component;
    }

    /** @throws InvalidConfigException */
    public function getNotifications(): NotificationService
    {
        $component = $this->get('notifications');
        assert($component instanceof NotificationService);
        return $component;
    }

    // Private Methods
    // =========================================================================

    /**
     * Routes everything the plugin logs (the 'accessibility-audit' category)
     * into its own storage/logs/accessibility-audit-*.log file, so scan and
     * notification activity can be read (and sent to support) without fishing
     * through web.log and queue.log.
     *
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function registerLogTarget(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => 'accessibility-audit',
            'categories' => ['accessibility-audit'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
            'formatter' => new LineFormatter(
                format: "%datetime% [%level_name%] %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $e) {
                $e->rules = array_merge([
                    'settings/plugins/accessibility-audit' => 'accessibility-audit/settings/edit',
                    'accessibility-audit' => 'accessibility-audit/dashboard/index',
                    'accessibility-audit/issues' => 'accessibility-audit/dashboard/issues',
                    'accessibility-audit/issues/detail' => 'accessibility-audit/dashboard/issue-detail',
                    'accessibility-audit/page-report' => 'accessibility-audit/dashboard/page-report',
                    'accessibility-audit/page-issues' => 'accessibility-audit/dashboard/page-issues',
                    'accessibility-audit/page-rule-occurrences' => 'accessibility-audit/dashboard/page-rule-occurrences',
                    'accessibility-audit/potential' => 'accessibility-audit/dashboard/potential',
                    'accessibility-audit/assets' => 'accessibility-audit/dashboard/assets',
                    'accessibility-audit/settings' => 'accessibility-audit/settings',
                    'accessibility-audit/settings/tools' => 'accessibility-audit/settings/edit-tools',
                    'accessibility-audit/settings/notifications' => 'accessibility-audit/settings/edit-notifications',
                    'accessibility-audit/settings/general' => 'accessibility-audit/settings/edit-general',
                    'accessibility-audit/settings/scanning' => 'accessibility-audit/settings/edit-scanning',
                    'accessibility-audit/settings/maintenance' => 'accessibility-audit/settings/edit-maintenance',
                    'accessibility-audit/settings/support' => 'accessibility-audit/settings/support',
                    'accessibility-audit/scan-entry' => 'accessibility-audit/audit/scan-entry',
                    'accessibility-audit/scan-all' => 'accessibility-audit/audit/scan-all',
                    'accessibility-audit/store-axe-results' => 'accessibility-audit/audit/store-axe-results',
                    'accessibility-audit/store-contrast-results' => 'accessibility-audit/audit/store-contrast-results',
                    'accessibility-audit/set-verdict' => 'accessibility-audit/audit/set-verdict',
                    'accessibility-audit/restore-verdicts' => 'accessibility-audit/audit/restore-verdicts',
                    'accessibility-audit/export' => 'accessibility-audit/audit/export',
                    'accessibility-audit/alt/generate' => 'accessibility-audit/alt/generate',
                    'accessibility-audit/alt/save' => 'accessibility-audit/alt/save',
                    'accessibility-audit/alt/set-decorative' => 'accessibility-audit/alt/set-decorative',
                    'accessibility-audit/alt/set-decorative-bulk' => 'accessibility-audit/alt/set-decorative-bulk',
                    'accessibility-audit/alt/verify-key' => 'accessibility-audit/alt/verify-key',
                    'accessibility-audit/colour-tools' => 'accessibility-audit/dashboard/utilities',
                    'accessibility-audit/readability' => 'accessibility-audit/readability/index',
                    'accessibility-audit/readability-table' => 'accessibility-audit/dashboard/readability-table',
                    'accessibility-audit/dismissed-table' => 'accessibility-audit/dashboard/dismissed-table',
                    'accessibility-audit/readability/analyse' => 'accessibility-audit/readability/analyse',
                    'accessibility-audit/readability/analyse-entry' => 'accessibility-audit/readability/analyse-entry',
                    'accessibility-audit/vpat' => 'accessibility-audit/dashboard/vpat',
                    'accessibility-audit/vpat/save-meta' => 'accessibility-audit/vpat/save-meta',
                    'accessibility-audit/vpat/save-criterion' => 'accessibility-audit/vpat/save-criterion',
                    'accessibility-audit/vpat/export' => 'accessibility-audit/vpat/export',
                    'accessibility-audit/statement' => 'accessibility-audit/dashboard/statement',
                    'accessibility-audit/statement/save-meta' => 'accessibility-audit/statement/save-meta',
                    'accessibility-audit/statement/suggestions' => 'accessibility-audit/statement/suggestions',
                    'accessibility-audit/statement/preview' => 'accessibility-audit/statement/preview',
                ], $e->rules);
            }
        );
    }

    private function registerSiteUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $e) {
                $e->rules['accessibility-audit/ci/check'] = 'accessibility-audit/ci/check';
            }
        );
    }

    private function registerWidgets(): void
    {
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $e) {
                $e->types[] = AccessibilityScoreWidget::class;
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $e) {
                $e->permissions[] = [
                    'heading' => Craft::t('accessibility-audit', 'Accessibility Audit'),
                    'permissions' => [
                        'accessibility-audit:viewReports' => [
                            'label' => Craft::t('accessibility-audit', 'View accessibility reports'),
                        ],
                        'accessibility-audit:runScans' => [
                            'label' => Craft::t('accessibility-audit', 'Run accessibility scans'),
                        ],
                        'accessibility-audit:manageVpat' => [
                            'label' => Craft::t('accessibility-audit', 'Manage the VPAT conformance report'),
                        ],
                        // Separate from manageVpat: a VPAT answers a procurement
                        // question, a statement is a public (and in the EU/UK,
                        // legal) declaration, so they can have different owners.
                        'accessibility-audit:manageStatement' => [
                            'label' => Craft::t('accessibility-audit', 'Manage the accessibility statement'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Exposes the plugin's public-facing templates to the front end.
     *
     * Only `src/templates/public` is registered, never the whole template
     * directory: the CP templates have no business being renderable on the
     * site. This exists because the published accessibility statement is
     * rendered into a site page, and a control-panel template cannot be
     * rendered from a site request (switching template mode mid-request wedges
     * the worker rather than erroring, which is a miserable thing to debug).
     *
     * @return void
     */
    private function registerSiteTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            static function(RegisterTemplateRootsEvent $e): void {
                $e->roots['accessibility-audit'] = __DIR__ . '/templates/public';
            }
        );
    }

    private function registerTwigVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT,
            function(Event $e) {
                /** @var CraftVariable $variable */
                $variable = $e->sender;
                $variable->set('a11y', AccessibilityVariable::class);
            }
        );
    }

    private function registerCpAssets(): void
    {
        Event::on(View::class, View::EVENT_BEFORE_RENDER_TEMPLATE,
            function() {
                if (!Craft::$app->getUser()->checkPermission('accessibility-audit:viewReports')) {
                    return;
                }

                $view = Craft::$app->getView();
                $settings = $this->getSettings();
                $hasApiKey = !empty(trim(App::parseEnv($settings->anthropicApiKey ?? '')));

                $view->registerAssetBundle(AccessibilityAuditAsset::class);

                $view->registerTranslations('accessibility-audit', self::JS_TRANSLATIONS);

                // Merged, never assigned: this fires on EVENT_BEFORE_RENDER_TEMPLATE,
                // so it lands after any per-page config a controller registered
                // before calling renderTemplate() (see DashboardController's
                // pageReport and vpat keys). A plain `=` would wipe those.
                $view->registerJs(
                    'window.AccessibilityAudit=Object.assign(window.AccessibilityAudit||{},' . Json::encode([
                        'scanEntryUrl' => UrlHelper::actionUrl('accessibility-audit/audit/scan-entry'),
                        'scanAllUrl' => UrlHelper::actionUrl('accessibility-audit/audit/scan-all'),
                        'generateAltUrl' => UrlHelper::actionUrl('accessibility-audit/alt/generate'),
                        // The configured field the button attaches to and writes,
                        // so it follows the Alt Text Field setting instead of
                        // always targeting Craft's native "alt" field.
                        'altField' => $settings->altTextField ?: 'alt',
                        'pageIssuesUrl' => UrlHelper::cpUrl('accessibility-audit/page-issues'),
                        'pageRuleOccurrencesUrl' => UrlHelper::cpUrl('accessibility-audit/page-rule-occurrences'),
                        'storeContrastUrl' => UrlHelper::actionUrl('accessibility-audit/audit/store-contrast-results'),
                        'setVerdictUrl' => UrlHelper::actionUrl('accessibility-audit/audit/set-verdict'),
                        'issueDetailUrl' => UrlHelper::cpUrl('accessibility-audit/issues/detail'),
                        'readabilityAnalyseUrl' => UrlHelper::actionUrl('accessibility-audit/readability/analyse'),
                        'readabilityAnalyseEntryUrl' => UrlHelper::actionUrl('accessibility-audit/readability/analyse-entry'),
                        'readabilityUrl' => UrlHelper::cpUrl('accessibility-audit/readability'),
                        'verifyKeyUrl' => UrlHelper::actionUrl('accessibility-audit/alt/verify-key'),
                        'hasApiKey' => $hasApiKey,
                        // Whether the plugin is operating across more than one site
                        // for this edition (Standard is primary-site only), so the
                        // "limit reached" message only says "across all sites" when
                        // that framing actually applies.
                        'isMultiSite' => count(AccessibilityAudit::getInstance()->allowedSites()) > 1,
                        // Translated responsibility labels, shared with the client-rendered
                        // badge builders in cp.js so JS-rendered rows use the same locale
                        // as the server-rendered `responsibility` Twig macro instead of a
                        // separate hardcoded English map.
                        'responsibilityLabels' => [
                            'content' => Craft::t('accessibility-audit', 'Content writing'),
                            'design' => Craft::t('accessibility-audit', 'Visual design'),
                            'development' => Craft::t('accessibility-audit', 'Development'),
                            'technical' => Craft::t('accessibility-audit', 'Technical'),
                        ],
                        // Single sources shared with client-side renderers so
                        // Twig- and JS-built output cannot drift: the axe tag
                        // list and payload caps (Inspect preview pass).
                        'axeTags' => $this->getAudit()->getAxeTags(),
                        'axeMaxNodes' => HeadlessScanner::MAX_NODES_PER_VIOLATION,
                        'axeMaxHtmlLength' => HeadlessScanner::MAX_NODE_HTML_LENGTH,
                    ]) . ');',
                    ViewAlias::POS_HEAD
                );
            }
        );
    }

    private function registerAssetAltButton(): void
    {
        Event::on(Element::class, Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $e) {
                /** @var Element $element */
                $element = $e->sender;

                if (!($element instanceof Asset) || !$element->id) {
                    return;
                }

                $settings = self::$plugin->getSettings();
                $hasApiKey = !empty(trim(App::parseEnv($settings->anthropicApiKey ?? '')));

                // A decorative image correctly carries an empty alt, so the
                // edit page shows a note instead of a Generate button that
                // would invite writing alt text the flag says isn't wanted.
                // The note is worth showing whether or not an API key is set;
                // the Generate button still needs one.
                $isDecorative = $element->kind === Asset::KIND_IMAGE
                    && self::$plugin->getAssets()->isDecorative((int)$element->id);

                if (!$hasApiKey && !$isDecorative) {
                    return;
                }

                // Tell the CP JS which asset is currently being edited.
                // POS_END works in both full-page renders and slideout AJAX responses.
                Craft::$app->getView()->registerJs(
                    'window.AccessibilityAudit=Object.assign(window.AccessibilityAudit||{},{assetId:' . (int)$element->id . ',assetDecorative:' . ($isDecorative ? 'true' : 'false') . '});' .
                    'if(typeof AccessibilityAuditInjectAltBtn==="function")AccessibilityAuditInjectAltBtn();',
                    ViewAlias::POS_END
                );
            }
        );
    }

    /**
     * Renders the compact accessibility + readability panel into an element
     * editor's right-hand sidebar (like the SEO preview), for anything the
     * scanner covers: entries, categories, Commerce products, any custom
     * element type with a public URL. Reuses the field template and its cp.js
     * behaviour, so the sub-tab switching, re-scan and readability flows work
     * unchanged.
     *
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function registerElementSidebarPanel(): void
    {
        Event::on(Element::class, Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $e) {
                /** @var Element $element */
                $element = $e->sender;

                // Registered on Element, not Entry: the scanner covers anything
                // with a public URL (categories, Commerce products, etc), so the
                // guards below mirror AuditService::getUrlElementsQuery() to keep
                // the panel in sync with what actually gets scanned. Assets are
                // excluded too: they're binary files with their own alt-text panel.
                if ($element instanceof Asset || !$element->id || !$element->getUrl()) {
                    return;
                }

                // A draft or revision is not what gets scanned, and its panel
                // would report the canonical element's findings as its own.
                if (
                    (property_exists($element, 'draftId') && $element->draftId) ||
                    (property_exists($element, 'revisionId') && $element->revisionId)
                ) {
                    return;
                }

                // An element type left out of the scan set gets no panel, in
                // step with getUrlElementsQuery() and isElementExcluded(): its
                // Re-scan button would only ever report "excluded".
                if (!in_array($element::class, self::$plugin->getSettings()->resolvedScannedElementTypes(), true)) {
                    return;
                }

                $plugin = self::$plugin;
                $view = Craft::$app->getView();

                // Load the panel's CSS/JS regardless of the permission-gated
                // global CP asset registration, mirroring the field.
                $view->registerAssetBundle(AccessibilityAuditAsset::class);

                $scan = $plugin->audit->getLatestScan($element->id, $element->siteId);
                // Grouped by rule, not one row per occurrence, so repeated
                // findings collapse into a count instead of filling the panel.
                // Same query the page report's issue list uses.
                $issues = $scan ? $plugin->audit->getIssuesGroupedByScan((int) $scan['id']) : [];
                $hasApiKey = trim(App::parseEnv($plugin->getSettings()->anthropicApiKey ?? '')) !== '';
                $readabilityPro = $plugin->isPro();

                $readabilityResult = null;
                if ($readabilityPro) {
                    $readabilityResult = $plugin->readability->getResults(1, $element->id, $element->siteId)[0] ?? null;
                    // The early return above guarantees a public URL, so the
                    // URL-keyed fallback needs no further guard.
                    if (!$readabilityResult) {
                        $readabilityResult = $plugin->readability->getResults(limit: 1, url: $element->getUrl())[0] ?? null;
                    }
                }

                $e->html .= $view->renderTemplate('accessibility-audit/_sidebar/accessibility-panel', [
                    'element' => $element,
                    'scan' => $scan,
                    'issues' => $issues,
                    'hasApiKey' => $hasApiKey,
                    'showReadabilityTab' => true,
                    'readabilityPro' => $readabilityPro,
                    'readabilityResult' => $readabilityResult,
                ]);
            }
        );
    }

    /**
     * Registers the Twig node visitor that injects <!-- accessibility-audit-tpl:filename.twig -->
     * comment markers around every template's output. Only active in devMode.
     * Allows the page-report JS scanner to identify which template rendered an element.
     *
     * Note: Twig caches compiled templates. Clear the template cache after first enabling
     * devMode so the comments appear in already-cached templates.
     */
    private function maybeRegisterTemplateDebug(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            return;
        }

        Craft::$app->getView()->registerTwigExtension(new A11yTwigExtension());
    }

    private function maybeInjectFrontendAxe(): void
    {
        /** @var SettingsModel $settings */
        $settings = $this->getSettings();

        if (!$settings->frontendAxe) {
            return;
        }

        Event::on(View::class, ViewAlias::EVENT_END_BODY,
            function() {
                // Resolve the identity here, not during init(): calling it at
                // bootstrap caches the User element before Craft Commerce
                // registers CustomerBehavior, stripping it off currentUser.
                if (!Craft::$app->getUser()->getIsAdmin()) {
                    return;
                }

                // Multi-site is a Pro feature: on Standard the overlay only runs
                // on the primary site, so it never appears on a site whose scans
                // the plugin won't store anyway.
                $sites = Craft::$app->getSites();
                if (!self::$plugin->isPro() && $sites->getCurrentSite()->id !== $sites->getPrimarySite()->id) {
                    return;
                }

                // The overlay is per-admin markup carrying this session's CSRF
                // token. A full-page cache that stores this render would serve
                // both to every visitor, so mark the response uncacheable and,
                // when Blitz is installed, keep this render out of its cache.
                Craft::$app->getResponse()->setNoCacheHeaders();
                if (class_exists(\putyourlightson\blitz\Blitz::class)) {
                    \putyourlightson\blitz\Blitz::$plugin->generateCache->options->cachingEnabled = false;
                }

                $view = Craft::$app->getView();
                $view->registerAssetBundle(FrontendAxeAsset::class);

                // Served from the plugin's own published copy, not a CDN, so it
                // needs no external script-src in a site's CSP and works on
                // air-gapped installs. Same bundled 4.9.1 as the headless
                // scanner and Inspect preview, so all three engines match.
                $axeSrc = Craft::$app->getAssetManager()->getPublishedUrl(
                    '@johnhenry/accessibilityaudit/resources',
                    true,
                    'axe/axe.min.js',
                );

                // Resolve the Craft element for the current page so axe results
                // can be stored against the right scan record automatically.
                $element = Craft::$app->getUrlManager()->getMatchedElement();
                $scanId = 0;
                $elementId = 0;
                $elementType = '';
                $siteId = Craft::$app->getSites()->getCurrentSite()->id;
                $storedScan = null;

                if ($element && $element->id) {
                    $elementId = $element->id;
                    $elementType = get_class($element);
                    $siteId = $element->siteId;

                    // Surface the latest stored scan so the overlay can hydrate
                    // from it on load (mirroring the CP), rather than only ever
                    // running a fresh in-browser axe scan.
                    $latestScan = self::$plugin->audit->getLatestScan($element->id, $element->siteId);
                    if ($latestScan) {
                        $scanId = (int) $latestScan['id'];
                        $storedScan = [
                            'score' => (int) $latestScan['score'],
                            'errorCount' => (int) $latestScan['errorCount'],
                            'warningCount' => (int) $latestScan['warningCount'],
                            'noticeCount' => (int) $latestScan['noticeCount'],
                            'scannedLabel' => Craft::$app->getFormatter()->asDatetime($latestScan['dateScanned'], 'short'),
                        ];
                    }
                }

                $settings = self::$plugin->getSettings();
                $reportUrl = $elementId > 0
                    ? UrlHelper::cpUrl('accessibility-audit/page-report', ['elementId' => $elementId, 'siteId' => $siteId])
                    : UrlHelper::cpUrl('accessibility-audit');

                // Mirror this admin's "Use shapes to represent statuses" CP
                // preference (Craft's own resolution: preference, then the
                // accessibilityDefaults config, then off) so the overlay's
                // colour-only severity dots also carry a shape.
                $identity = Craft::$app->getUser()->getIdentity();
                $a11yDefaults = Craft::$app->getConfig()->getGeneral()->accessibilityDefaults;
                $useShapes = (bool)($identity?->getPreference('useShapes') ?? ($a11yDefaults['useShapes'] ?? false));

                $view->registerJs(
                    'window.__accessibilityAudit = ' . Json::encode([
                        'axeSrc' => $axeSrc,
                        'storeUrl' => UrlHelper::actionUrl('accessibility-audit/audit/store-axe-results'),
                        'csrfName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
                        'csrfValue' => Craft::$app->getRequest()->getCsrfToken(),
                        'scanId' => $scanId,
                        'elementId' => $elementId,
                        'elementType' => $elementType,
                        'siteId' => $siteId,
                        // The resolved axe tag list, not the raw settings: the
                        // overlay scans with exactly the same tags as the
                        // Inspect preview and the headless scanner.
                        'axeTags' => $this->getAudit()->getAxeTags(),
                        'collapseWhenIdle' => (bool) $settings->overlayCollapseWhenIdle,
                        'position' => $settings->overlayPosition ?: 'bottom-right',
                        'idleMs' => max(3, (int) $settings->overlayIdleSeconds) * 1000,
                        'reportUrl' => $reportUrl,
                        'storedScan' => $storedScan,
                        'pageIssuesUrl' => UrlHelper::actionUrl('accessibility-audit/dashboard/page-issues'),
                        'useShapes' => $useShapes,
                    ]) . ';',
                    ViewAlias::POS_HEAD
                );
            }
        );
    }

    /**
     * Keeps the stored asset audit current between sweeps: every image asset
     * save (a fresh upload, an alt text edit, an accepted AI suggestion)
     * re-syncs that asset's audit rows, so the dashboard's asset panel never
     * waits for the next full sweep to reflect a fix.
     *
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function registerAssetAuditSync(): void
    {
        Event::on(Element::class, Element::EVENT_AFTER_PROPAGATE,
            function(Event $e) {
                /** @var Element $element */
                $element = $e->sender;

                if (!($element instanceof Asset) || !$element->id || $element->kind !== Asset::KIND_IMAGE) {
                    return;
                }

                // An audit-row sync must never break an asset save: the table
                // may not exist mid-install, and a failed sync is recoverable
                // by the next sweep anyway.
                try {
                    self::$plugin->getAssets()->syncAssetAudit($element);
                } catch (Throwable $err) {
                    Craft::error('Asset audit sync failed: ' . $err->getMessage(), 'accessibility-audit');
                }
            }
        );

        // Hard delete: clear the asset's stored findings so they don't linger as
        // orphans. Only on hard delete, not soft: a trashed asset is excluded
        // from the counts already (they skip deleted elements) and may still be
        // restored, in which case the next save re-syncs its rows.
        Event::on(Element::class, Element::EVENT_AFTER_DELETE,
            function(Event $e) {
                /** @var Element $element */
                $element = $e->sender;

                if (!($element instanceof Asset) || !$element->id || !$element->hardDelete) {
                    return;
                }

                try {
                    self::$plugin->getAssets()->clearStoredIssues($element->id);
                } catch (Throwable $err) {
                    Craft::error('Asset audit cleanup failed: ' . $err->getMessage(), 'accessibility-audit');
                }
            }
        );
    }

    private function registerScanOnSave(): void
    {
        Event::on(Element::class, Element::EVENT_AFTER_PROPAGATE,
            function(Event $e) {
                /** @var Element $element */
                $element = $e->sender;

                if ($element instanceof Asset) {
                    return;
                }

                if (!$element->id || !$element->enabled || !$element->getUrl()) {
                    return;
                }

                if (property_exists($element, 'draftId') && $element->draftId) {
                    return;
                }

                // Don't queue a scan for an excluded page: scanElement would
                // skip it anyway, but not queueing keeps the excluded page out
                // of the queue entirely.
                if (self::$plugin->audit->isElementExcluded($element)) {
                    return;
                }

                self::$plugin->audit->queueScan($element);
            }
        );
    }

    private function registerAutoGenerateAlt(): void
    {
        Event::on(Element::class, Element::EVENT_AFTER_SAVE,
            function(Event $e) {
                /** @var Element $element */
                $element = $e->sender;

                if (!($element instanceof Asset)) {
                    return;
                }

                if (!$element->firstSave || $element->kind !== Asset::KIND_IMAGE) {
                    return;
                }

                Craft::$app->getQueue()->push(
                    new GenerateAltTextJob([
                        'assetId' => $element->id,
                    ])
                );
            }
        );
    }
}
