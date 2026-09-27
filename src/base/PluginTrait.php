<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\base;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\errors\SiteNotFoundException;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterPreviewTargetsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\queue\Queue as CraftQueue;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Request as WebRequest;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\assets\AccessibilityAuditAsset;
use johnhenry\accessibilityaudit\assets\FrontendAxeAsset;
use johnhenry\accessibilityaudit\helpers\ScannableElementTypes;
use johnhenry\accessibilityaudit\jobs\GenerateAltTextJob;
use johnhenry\accessibilityaudit\jobs\RecordReadability;
use johnhenry\accessibilityaudit\models\SettingsModel;
use johnhenry\accessibilityaudit\services\HeadlessScanner;
use johnhenry\accessibilityaudit\services\PotentialScanner;
use johnhenry\accessibilityaudit\services\RuleRegistry;
use johnhenry\accessibilityaudit\twig\A11yTemplateNodeVisitor;
use johnhenry\accessibilityaudit\twig\A11yTwigExtension;
use johnhenry\accessibilityaudit\variables\AccessibilityVariable;
use johnhenry\accessibilityaudit\widgets\AccessibilityScoreWidget;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use Throwable;
use WeakMap;
use yii\base\Event;
use yii\base\InvalidRouteException;
use yii\console\Response as ConsoleResponse;
use yii\web\Response as WebResponse;
use yii\web\View as ViewAlias;

/**
 * The plugin's lifecycle overrides and every event, route and asset it
 * registers.
 *
 * Kept apart from the plugin class so that class stays a short read: what the
 * plugin is, and the order it boots in. What each registration actually hooks
 * is here.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
trait PluginTrait
{
    // Const Properties
    // =========================================================================

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
        "This occurrence couldn't be located in the preview. The page may have changed since the scan, or the element is added by a script that doesn't run here.",
        'AI draft: review and edit before moving on. ',
        'Accessibility score by date',
        'Accessibility score over time',
        'Affected pages',
        'Alt text generated, save the asset to apply it.',
        'Analysed',
        'Analysing…',
        'Analysing {n, plural, =1{# page} other{# pages}} in the background.',
        'Analysis failed. Check Craft logs for details.',
        'Analysis request failed. Check your connection and try again.',
        'Conformance',
        'Chart updated.',
        'Check',
        'Checking desktop…',
        'Checking mobile…',
        'Could not load the trend for that range.',
        'Could not queue the scan.',
        'Could not save that. Try again.',
        'Count',
        'Counted the next time this page is analysed.',
        'Cumulative resolved issues by date',
        'Date',
        'Dismiss selected',
        'Draft with AI',
        'Drafting…',
        'Element type',
        'Error, please try again.',
        'Error, retry',
        'Export CSV',
        'Failed to load.',
        'Failed to load: {error}',
        'Fail',
        'First detected',
        'Found in the Desktop view. Switch to Desktop to see it.',
        'Found in the Mobile view. Switch to Mobile to see it.',
        'Generate Alt Text',
        'Generating…',
        'Grade',
        'Hard',
        'Highlighted, but the element is inside a collapsed menu or panel. Open it on the page to see it.',
        'Issues',
        'Issues resolved over time, cumulative',
        'Last scanned',
        'Level A',
        'Level AA',
        'Limit reached',
        'Excluded',
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
        'Pages',
        'Pass',
        'Points gained',
        'Potential issue',
        'Queued {n} scans, check back soon',
        'Queuing…',
        'Re-analyse',
        'Re-analyse selected',
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
        'Rule',
        'SC',
        'Saving…',
        'Scan failed',
        'Scanning…',
        'Score',
        'Show issues on this page',
        'That could not be recorded. Try again.',
        'That could not be removed. Try again.',
        'Remove the most recently recorded revision? This cannot be undone.',
        'Target {n}',
        'Verification failed.',
        'Verifying…',
        'Very hard',
        'View report',
        'WCAG',
        'Words',
        'Marking images decorative hides missing-alt warnings. Only mark images that carry no information. Mark {n} images decorative?',
        '{n} characters',
        '{n} characters, {over} over',
        '{n} images marked decorative.',
        '{n} images no longer decorative.',
        '{n} selected',
        'What was dismissed',
        'errors',
        'notices',
        'opens in new tab',
        'warnings',
        'Page',
    ];

    /**
     * @var string[] Messages JavaScript translates from a value rather than a
     * literal: the rule category and reading-ease labels, which arrive in data
     * stored in English. Registered with JS_TRANSLATIONS, but kept apart from
     * it because no Craft.t() call names them.
     */
    public const JS_DYNAMIC_TRANSLATIONS = [
        'Buttons',
        'Forms',
        'Headings',
        'Iframes',
        'Images',
        'Interactive',
        'Links',
        'Lists',
        'Media',
        'Navigation',
        'Other',
        'Page structure',
        'Page',
        'Tables',
        'Text',
        'Very easy',
        'Easy',
        'Fairly easy',
        'Standard',
        'Fairly difficult',
        'Difficult',
        'Very difficult',
    ];

    /**
     * @var string The namespace that marks a queue row as one of this plugin's.
     *
     * A queue row holds a serialised job and nothing else that names its owner,
     * so the uninstall sweep looks for this in those bytes. Written out rather
     * than derived, and pinned by a test against the job classes themselves: a
     * job moved out from under it would be found by nothing, and the sweep
     * would report having cleared none without saying why.
     */
    public const JOB_NAMESPACE = 'johnhenry\\accessibilityaudit\\';

    /**
     * @var int How many queue rows are read at a time while looking for them.
     */
    private const UNINSTALL_BATCH_SIZE = 200;

    // Private Properties
    // =========================================================================

    /**
     * @var WeakMap<View, true>|null The views the CP assets are already
     * registered on. Weak, so an entry goes with its view and a later request
     * can never be mistaken for one that was already handled.
     */
    private ?WeakMap $_cpAssetsViews = null;

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
     * The plugin's control-panel nav item, with its subnav built from the
     * current user's permissions and the active edition.
     *
     * @return array<string, mixed>|null The nav item, or null where the user
     *         may see nothing at all.
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
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

        if ($user->checkPermission('accessibility-audit:view-reports')) {
            $subnav['overview'] = ['label' => Craft::t('accessibility-audit', 'Overview'),         'url' => 'accessibility-audit' . $suffix];
            $subnav['issues'] = ['label' => Craft::t('accessibility-audit', 'Issues'),            'url' => 'accessibility-audit/issues' . $suffix];
            $subnav['potential'] = ['label' => Craft::t('accessibility-audit', 'Potential Issues'),  'url' => 'accessibility-audit/potential' . $suffix];
            $subnav['assets'] = ['label' => Craft::t('accessibility-audit', 'Assets'),            'url' => 'accessibility-audit/assets' . $suffix];
            $subnav['readability'] = ['label' => Craft::t('accessibility-audit', 'Readability'), 'url' => 'accessibility-audit/readability' . $suffix];
            $subnav['vpat'] = ['label' => Craft::t('accessibility-audit', 'VPAT'),        'url' => 'accessibility-audit/vpat' . $suffix];
            $subnav['statement'] = ['label' => Craft::t('accessibility-audit', 'Statement'), 'url' => 'accessibility-audit/statement' . $suffix];
            $subnav['color-tools'] = ['label' => Craft::t('accessibility-audit', 'Colour Tools'), 'url' => 'accessibility-audit/colour-tools' . $suffix];
        }
        // Admins keep the link even when admin changes are disabled: the
        // settings pages render read-only there, matching Craft's own
        // settings behaviour, rather than disappearing.
        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('app', 'Settings'), 'url' => 'accessibility-audit/settings'];
        }

        // Craft grants "Access Accessibility Audit" on its own, separately from
        // anything this plugin registers, and it is the first box an admin
        // ticks. Ticked by itself it left the section in the sidebar with
        // nothing under it, and its own link goes to the Overview, which then
        // refuses them. Craft drops the section entirely for a null, so a
        // reader who may see none of these pages is not offered them.
        if ($subnav === []) {
            return null;
        }

        $item['subnav'] = $subnav;
        return $item;
    }

    /**
     * The plugin's settings, narrowed to this plugin's own model.
     *
     * @return SettingsModel The settings.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSettings(): SettingsModel
    {
        $settings = parent::getSettings();
        assert($settings instanceof SettingsModel);
        return $settings;
    }

    /**
     * A blank settings model, for a first install.
     *
     * @return SettingsModel The new model.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function createSettingsModel(): SettingsModel
    {
        return new SettingsModel();
    }

    /**
     * @return ConsoleResponse|WebResponse A redirect to the plugin's own
     *         settings screen. craft\web\Response extends the Yii one, so the
     *         two named here cover every response Craft hands back.
     * @throws InvalidRouteException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSettingsResponse(): ConsoleResponse|WebResponse
    {
        return Craft::$app->getResponse()->redirect(
            UrlHelper::cpUrl('accessibility-audit/settings')
        );
    }

    /**
     * Same destination as the editable response: the settings pages detect
     * allowAdminChanges themselves and render read-only (notice shown, save
     * and token-generation controls withheld, mutating actions refused).
     *
     * @return ConsoleResponse|WebResponse The same redirect the editable
     *         response gives.
     * @throws InvalidRouteException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getReadOnlySettingsResponse(): ConsoleResponse|WebResponse
    {
        return $this->getSettingsResponse();
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Clears what dropping the plugin's tables does not reach.
     *
     * A dashboard tile names the class that draws it, and Craft answers a class
     * it cannot load with its Missing Widget box: an error on the dashboard of
     * every person who added the score widget, saying nothing they can act on
     * and removable only by hand. Queued jobs are the same shape: a scan, an
     * asset sweep or an alt-text draft still waiting runs after the plugin has
     * gone and fails on a class that is no longer there.
     *
     * Nothing in here is allowed to stop an uninstall. Craft runs this inside
     * the transaction that removes the plugin, so a throw here is a plugin that
     * cannot be uninstalled at all, which is a worse thing to be than one that
     * left a row behind.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    protected function beforeUninstall(): void
    {
        parent::beforeUninstall();

        try {
            $widgets = Db::delete(Table::WIDGETS, ['type' => AccessibilityScoreWidget::class]);
            $jobs = self::_releaseQueuedJobs();

            Craft::info(
                "A11y: uninstall cleared {$widgets} dashboard tile(s) and {$jobs} queued job(s).",
                'accessibility-audit',
            );
        } catch (Throwable $e) {
            Craft::error(
                'A11y: the uninstall cleanup did not finish: ' . $e->getMessage(),
                'accessibility-audit',
            );
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Routes everything the plugin logs (the 'accessibility-audit' category)
     * into its own storage/logs/accessibility-audit-*.log file, so scan and
     * notification activity can be read (and sent to support) without fishing
     * through web.log and queue.log.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerLogTarget(): void
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

    /**
     * Hangs the scan-history prune off Craft's garbage collection, so the
     * Retain Scan Results setting applies without anything to schedule.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.1
     */
    private function _registerScanPruning(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, static function(): void {
            $plugin = self::$plugin;
            $days = (int)$plugin->getSettings()->retainDays;

            // Keeping history for good is a deliberate choice, and a Pro one.
            // On Standard, pruneScanResults() clamps a zero to the edition cap
            // rather than treating it as "keep everything", so it still runs.
            if ($days <= 0 && $plugin->isPro()) {
                return;
            }

            // Housekeeping is never worth taking a request down for.
            try {
                $deleted = $plugin->getAudit()->pruneScanResults($days);

                if ($deleted > 0) {
                    Craft::info(
                        "A11y: pruned {$deleted} scan(s) older than {$days} days.",
                        'accessibility-audit',
                    );
                }
            } catch (Throwable $e) {
                Craft::error(
                    'A11y: scan prune failed during garbage collection: ' . $e->getMessage(),
                    'accessibility-audit',
                );
            }
        });
    }

    /**
     * Maps the plugin's control-panel routes to their controllers.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $e) {
                $e->rules = array_merge([
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
                    'accessibility-audit/vpat/export-openacr' => 'accessibility-audit/vpat/export-open-acr',
                    'accessibility-audit/statement' => 'accessibility-audit/dashboard/statement',
                    'accessibility-audit/statement/save-meta' => 'accessibility-audit/statement/save-meta',
                    'accessibility-audit/statement/suggestions' => 'accessibility-audit/statement/suggestions',
                    'accessibility-audit/statement/preview' => 'accessibility-audit/statement/preview',
                ], $e->rules);
            }
        );
    }

    /**
     * Maps the plugin's front-end routes to their controllers.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerSiteUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $e) {
                $e->rules['accessibility-audit/ci/check'] = 'accessibility-audit/ci/check';
                $e->rules['accessibility-audit/overlay.js'] = 'accessibility-audit/overlay/script';
                $e->rules['accessibility-audit/overlay/resolve'] = 'accessibility-audit/overlay/resolve';
                $e->rules['accessibility-audit/overlay/store-axe-results'] = 'accessibility-audit/overlay/store-axe-results';
                $e->rules['accessibility-audit/overlay/page-issues'] = 'accessibility-audit/overlay/page-issues';
            }
        );
    }

    /**
     * Registers the plugin's dashboard widgets.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerWidgets(): void
    {
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $e) {
                $e->types[] = AccessibilityScoreWidget::class;
            }
        );
    }

    /**
     * Registers the plugin's user permissions.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $e) {
                $e->permissions[] = [
                    'heading' => Craft::t('accessibility-audit', 'Accessibility Audit'),
                    'permissions' => [
                        'accessibility-audit:view-reports' => [
                            'label' => Craft::t('accessibility-audit', 'View accessibility reports'),
                        ],
                        'accessibility-audit:run-scans' => [
                            'label' => Craft::t('accessibility-audit', 'Run accessibility scans'),
                        ],
                        'accessibility-audit:manage-vpat' => [
                            'label' => Craft::t('accessibility-audit', 'Manage the VPAT conformance report'),
                        ],
                        // Separate from manage-vpat: a VPAT answers a procurement
                        // question, a statement is a public (and in the EU/UK,
                        // legal) declaration, so they can have different owners.
                        'accessibility-audit:manage-statement' => [
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
     * site. The published accessibility statement renders into a site page,
     * so its template has to be one a site request can find.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerSiteTemplateRoots(): void
    {
        // The plugin's own directory, not this trait's: __DIR__ resolves to the
        // file the code is written in, which for a trait is one level down from
        // the templates it is pointing at.
        $root = $this->getBasePath() . '/templates/public';

        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            static function(RegisterTemplateRootsEvent $e) use ($root): void {
                $e->roots['accessibility-audit'] = $root;
            }
        );
    }

    /**
     * Exposes the plugin on `craft.a11y` for templates.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerTwigVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT,
            static function(Event $e) {
                /** @var CraftVariable $variable */
                $variable = $e->sender;
                $variable->set('a11y', AccessibilityVariable::class);
            }
        );
    }

    /**
     * Loads the plugin's control-panel JavaScript and CSS.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerCpAssets(): void
    {
        Event::on(View::class, View::EVENT_BEFORE_RENDER_TEMPLATE,
            function() {
                if (!Craft::$app->getUser()->checkPermission('accessibility-audit:view-reports')) {
                    return;
                }

                $view = Craft::$app->getView();

                // This fires for every template rendered, partials included, so
                // the work is done once per view rather than once per render.
                $this->_cpAssetsViews ??= new WeakMap();

                if (isset($this->_cpAssetsViews[$view])) {
                    return;
                }

                $this->_cpAssetsViews[$view] = true;
                $settings = $this->getSettings();
                $hasApiKey = !empty(trim(App::parseEnv($settings->anthropicApiKey)));

                $view->registerAssetBundle(AccessibilityAuditAsset::class);

                $view->registerTranslations(
                    'accessibility-audit',
                    array_merge(self::JS_TRANSLATIONS, self::JS_DYNAMIC_TRANSLATIONS),
                );

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
                        // The same number the long-alt check reports on, so the
                        // count beside the field agrees with the finding.
                        'altGuideline' => PotentialScanner::MAX_ALT_LENGTH,
                        'pageIssuesUrl' => UrlHelper::cpUrl('accessibility-audit/page-issues'),
                        'pageRuleOccurrencesUrl' => UrlHelper::cpUrl('accessibility-audit/page-rule-occurrences'),
                        'storeContrastUrl' => UrlHelper::actionUrl('accessibility-audit/audit/store-contrast-results'),
                        'setVerdictUrl' => UrlHelper::actionUrl('accessibility-audit/audit/set-verdict'),
                        'setVerdictsBulkUrl' => UrlHelper::actionUrl('accessibility-audit/audit/set-verdicts-bulk'),
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
                        // The responsibility badges, label and class modifier both,
                        // shared with the client-rendered table builders so a row
                        // drawn in JavaScript reads the same as one drawn by the
                        // Twig macro, in the same language.
                        'responsibilities' => RuleRegistry::responsibilities(),
                        // Single sources shared with client-side renderers so
                        // Twig- and JS-built output cannot drift: the axe tag
                        // list and payload caps (Inspect preview pass).
                        'axeTags' => $this->getAudit()->getAxeTags(),
                        'axeExclude' => $this->getAudit()->getAxeExclude(),
                        'axeMaxNodes' => HeadlessScanner::MAX_NODES_PER_VIOLATION,
                        'axeMaxHtmlLength' => HeadlessScanner::MAX_NODE_HTML_LENGTH,
                    ]) . ');',
                    ViewAlias::POS_HEAD
                );
            }
        );
    }

    /**
     * Adds the alt-text control to an asset's edit screen.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerAssetAltButton(): void
    {
        Event::on(Element::class, Element::EVENT_DEFINE_SIDEBAR_HTML,
            static function(DefineHtmlEvent $e) {
                /** @var Element $element */
                $element = $e->sender;

                if (!($element instanceof Asset) || !$element->id) {
                    return;
                }

                $settings = self::$plugin->getSettings();
                $hasApiKey = !empty(trim(App::parseEnv($settings->anthropicApiKey)));

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
     * Adds a Readability view to the Preview menu of every element the scanner
     * covers, on Pro with the setting on.
     *
     * The target is an action URL on the Craft host rather than a site URL, so
     * it still opens where a site's front end is served by something else.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _registerReadabilityPreviewTarget(): void
    {
        Event::on(Element::class, Element::EVENT_REGISTER_PREVIEW_TARGETS,
            static function(RegisterPreviewTargetsEvent $event) {
                /** @var Element $element */
                $element = $event->sender;
                $plugin = self::$plugin;

                if (!$element->id || !$plugin->isPro() || !$plugin->getSettings()->readabilityPreviewTarget) {
                    return;
                }

                // The same pages the sidebar panel reports on: a draft is judged
                // by the entry behind it, which is what has the page.
                $canonical = $element->getIsDerivative() ? $element->getCanonical() : $element;

                if (!$canonical instanceof Element || !$canonical->getUrl() || $plugin->getAudit()->isElementExcluded($canonical)) {
                    return;
                }

                // A site action URL rather than a control panel one, so a
                // shared preview link does not depend on reaching the CP. It is
                // built on the host serving this request, which is Craft's own:
                // the site's base URL can point at a separate front end (a
                // headless install) or another domain with no session on it.
                $path = Craft::$app->getConfig()->getGeneral()->actionTrigger . '/accessibility-audit/readability/preview';
                $params = ['elementId' => $element->getCanonicalId(), 'siteId' => $element->siteId];
                $request = Craft::$app->getRequest();

                $event->previewTargets[] = [
                    'label' => Craft::t('accessibility-audit', 'Readability'),
                    'url' => $request instanceof WebRequest
                        ? $request->getHostInfo() . rtrim($request->getBaseUrl(), '/') . '/' . $path . '?' . http_build_query($params)
                        : UrlHelper::siteUrl($path, $params, null, $element->siteId),
                    'refresh' => true,
                ];
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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerElementSidebarPanel(): void
    {
        $handler = static function(DefineHtmlEvent $e) {
            /** @var Element $editing */
            $editing = $e->sender;

            // A provisional draft is the entry itself with unsaved edits, and
            // it is what the editor loads once someone has started typing. The
            // panel reports on the entry behind it, since that is what gets
            // scanned.
            /** @var Element $element */
            $element = $editing->isProvisionalDraft ? $editing->getCanonical() : $editing;

            // Registered on the concrete types and on Element both, so most
            // types are offered the same event twice. Its own markup is the memo.
            if (str_contains($e->html, 'accessibility-audit-panel')) {
                return;
            }

            // The scanner covers anything with a public URL (categories,
            // Commerce products, etc), so the guards below mirror
            // AuditService::getUrlElementsQuery() to keep the panel in step
            // with what actually gets scanned. Assets are excluded too:
            // they're binary files with their own alt-text panel.
            if ($element instanceof Asset || !$element->id || !$element->getUrl()) {
                return;
            }

            // A draft or revision is not what gets scanned, and its panel
            // would report the canonical element's findings as its own.
            if ($element->getIsDerivative()) {
                return;
            }

            // An element type left out of the scan set, or a page matched by
            // an excluded URI pattern, gets no panel: every scan path skips
            // it, so any score shown would be one nothing will ever update.
            if (self::$plugin->getAudit()->isElementExcluded($element)) {
                return;
            }

            $plugin = self::$plugin;
            $view = Craft::$app->getView();

            // Load the panel's CSS/JS regardless of the permission-gated
            // global CP asset registration, mirroring the field.
            $view->registerAssetBundle(AccessibilityAuditAsset::class);

            $scan = $plugin->getAudit()->getLatestScan($element->id, $element->siteId);
            // Grouped by rule, not one row per occurrence, so repeated
            // findings collapse into a count instead of filling the panel.
            // Same query the page report's issue list uses.
            $issues = $scan ? $plugin->getAudit()->getIssuesGroupedByScan((int) $scan['id']) : [];
            $hasApiKey = trim(App::parseEnv($plugin->getSettings()->anthropicApiKey)) !== '';
            $readabilityPro = $plugin->isPro();

            $readabilityResult = null;
            if ($readabilityPro) {
                $readabilityResult = $plugin->getReadability()->getResults(1, $element->id, $element->siteId)[0] ?? null;
                // The early return above guarantees a public URL, so the
                // URL-keyed fallback needs no further guard.
                if (!$readabilityResult) {
                    $readabilityResult = $plugin->getReadability()->getResults(limit: 1, url: $element->getUrl())[0] ?? null;
                }
            }

            $e->html .= $view->renderTemplate('accessibility-audit/_sidebar/accessibility-panel', [
                'element' => $element,
                'scan' => $scan,
                'issues' => $issues,
                'hasApiKey' => $hasApiKey,
                'readabilityPro' => $readabilityPro,
                'readabilitySupported' => $plugin->getReadability()->supportsSite((int)$element->siteId),
                'readabilityUnsupportedMessage' => $plugin->getReadability()->unsupportedMessage(),
                'readabilityResult' => $readabilityResult,
                // Stored as naive UTC, so it is read as UTC before it is shown.
                'readabilityDate' => !empty($readabilityResult['dateAnalysed'])
                    ? DateTimeHelper::toDateTime($readabilityResult['dateAnalysed'])
                    : null,
                'readabilityPreview' => $readabilityPro && $plugin->getSettings()->readabilityPreviewTarget,
            ]);
        };

        // Registered on every scannable type and on the base Element, each
        // prepended so the panel sits above other plugins' panels but below
        // Craft's own status and meta. The concrete registrations win that
        // slot: Yii fires a concrete class's handlers before its parents', and
        // other plugins hook the concrete classes. The base registration is
        // the net for a type this plugin cannot see, and the markup check
        // above keeps the pair from drawing twice. Deferred to onInit so the
        // element type registry is complete when read.
        Craft::$app->onInit(static function() use ($handler): void {
            $classes = array_keys(ScannableElementTypes::all());
            $classes[] = Element::class;

            foreach ($classes as $class) {
                Event::on($class, Element::EVENT_DEFINE_SIDEBAR_HTML, $handler, append: false);
            }
        });
    }

    /**
     * Registers the Twig node visitor that injects <!-- accessibility-audit-tpl:filename.twig -->
     * comment markers around every template's output, and strips them from any
     * response that isn't HTML. Only active in devMode.
     * Allows the page-report JS scanner to identify which template rendered an element.
     *
     * Note: Twig caches compiled templates. Clear the template cache after first enabling
     * devMode so the comments appear in already-cached templates.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _maybeRegisterTemplateDebug(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            return;
        }

        Craft::$app->getView()->registerTwigExtension(new A11yTwigExtension());

        // Only an HTML page is read for markers; anything else would be broken by them.
        Event::on(WebResponse::class, WebResponse::EVENT_AFTER_PREPARE, static function(Event $event) {
            /** @var WebResponse $response */
            $response = $event->sender;
            $type = (string)$response->getHeaders()->get('content-type', '');

            if ($type === '' || str_contains($type, 'html') || !is_string($response->content)) {
                return;
            }

            $response->content = A11yTemplateNodeVisitor::stripMarkers($response->content);
        });
    }

    /**
     * Injects the front-end axe overlay, where the setting asks for it.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _maybeInjectFrontendAxe(): void
    {
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

                // An excluded page is left alone by every scan path, and the
                // overlay could store nothing it found there.
                $element = Craft::$app->getUrlManager()->getMatchedElement() ?: null;
                $siteId = (int)$sites->getCurrentSite()->id;
                if ($this->getOverlay()->isPageExcluded($element, Craft::$app->getRequest()->getPathInfo(), $siteId)) {
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

                // The payload (element resolution, stored-scan hydration, the
                // resolved axe tag list, overlay settings) is built by the
                // shared OverlayService builder — the same one the decoupled
                // resolve endpoint uses — so the two delivery paths can't
                // drift. Only the session's CSRF pair is added here: it's this
                // path's credential, where the decoupled loader appends its
                // bearer token client-side instead.
                $config = $this->getOverlay()->buildConfig($element, $siteId);
                $config['csrfName'] = Craft::$app->getConfig()->getGeneral()->csrfTokenName;
                $config['csrfValue'] = Craft::$app->getRequest()->getCsrfToken();

                $view->registerJs(
                    'window.__accessibilityAudit = ' . Json::encode($config) . ';',
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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerAssetAuditSync(): void
    {
        Event::on(Element::class, Element::EVENT_AFTER_PROPAGATE,
            static function(Event $e) {
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
            static function(Event $e) {
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

    /**
     * Queues a scan when an element is saved, where the setting asks for it.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerScanOnSave(): void
    {
        Event::on(Element::class, Element::EVENT_AFTER_PROPAGATE,
            static function(Event $e) {
                /** @var Element $element */
                $element = $e->sender;

                if ($element instanceof Asset) {
                    return;
                }

                // The whole step is inside the catch, getUrl() included: it
                // fires two events, so a third-party handler on one entry
                // throws here as readily as the queueing does, and this runs on
                // every save.
                try {
                    if (!$element->id || !$element->enabled || !$element->getUrl()) {
                        return;
                    }

                    // Drafts AND revisions: every manual save creates a
                    // revision whose EVENT_AFTER_PROPAGATE also fires, with the
                    // same URL and its own element id. Scanning it would queue
                    // a duplicate browser check per save and store scans
                    // against revision ids.
                    if (ElementHelper::isDraftOrRevision($element)) {
                        return;
                    }

                    // Programmatic bulk resaves (the resave commands,
                    // migrations, a plugin touching every entry) would queue a
                    // scan per element: a full sweep nobody asked for, plus a
                    // browser check per page. Content edited by a person still
                    // scans; use Scan All for a deliberate sweep after
                    // site-wide changes.
                    if ($element->resaving) {
                        return;
                    }

                    // Don't queue a scan for an excluded page: scanElement
                    // would skip it anyway, but not queueing keeps the excluded
                    // page out of the queue entirely.
                    if (self::$plugin->getAudit()->isElementExcluded($element)) {
                        return;
                    }

                    self::$plugin->getAudit()->queueScan($element);

                    // Queued rather than run here: this event fires inside the
                    // save's transaction, and the job covers every site the
                    // element is on, not only the one being saved.
                    if (self::$plugin->isPro()) {
                        Craft::$app->getQueue()->push(new RecordReadability([
                            'elementId' => (int)$element->id,
                            'siteId' => (int)$element->siteId,
                        ]));
                    }
                } catch (Throwable $err) {
                    // Queuing a scan must never break somebody's save, the same
                    // way the asset sync must not. A scan that was not queued
                    // comes back on the next sweep; a refused save does not.
                    Craft::error('Scan on save failed: ' . $err->getMessage(), 'accessibility-audit');
                }
            }
        );
    }

    /**
     * Queues alt-text drafting for a newly uploaded image, where the setting asks for it.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerAutoGenerateAlt(): void
    {
        Event::on(Element::class, Element::EVENT_AFTER_SAVE,
            static function(Event $e) {
                /** @var Element $element */
                $element = $e->sender;

                if (!($element instanceof Asset)) {
                    return;
                }

                if (!$element->firstSave || $element->kind !== Asset::KIND_IMAGE) {
                    return;
                }

                // An upload must not be refused because the alt-text job
                // could not be queued. Nothing is lost that the Images screen
                // cannot draft again on request.
                try {
                    Craft::$app->getQueue()->push(
                        new GenerateAltTextJob([
                            'assetId' => $element->id,
                        ])
                    );
                } catch (Throwable $err) {
                    Craft::error('Alt text queueing failed: ' . $err->getMessage(), 'accessibility-audit');
                }
            }
        );
    }

    /**
     * Takes this plugin's own jobs out of the queue.
     *
     * The queue row holds a serialised job and nothing else that says whose it
     * is, so they are found by the plugin's namespace appearing in those bytes.
     * The column comes back differently depending on the driver: a string on
     * MySQL, a stream on Postgres, and on some Postgres clients a hexadecimal
     * rendering with an `x` in front of it, which is the same set of three
     * Craft normalises before it unserialises a job.
     *
     * A queue that is not the database one cannot be read this way, and is left
     * alone rather than guessed at.
     *
     * @return int How many jobs were taken out.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private static function _releaseQueuedJobs(): int
    {
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof CraftQueue) {
            return 0;
        }

        $released = 0;
        $rows = (new Query())->select(['id', 'job'])->from($queue->tableName);

        foreach ($rows->batch(self::UNINSTALL_BATCH_SIZE) as $batch) {
            foreach ($batch as $row) {
                if (!str_contains(self::_jobPayload($row['job']), self::JOB_NAMESPACE)) {
                    continue;
                }

                $queue->release((string)$row['id']);
                $released++;
            }
        }

        return $released;
    }

    /**
     * A queued job's stored bytes as a string.
     *
     * @param mixed $job The stored column value.
     * @return string The bytes, or an empty string where they cannot be read.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private static function _jobPayload(mixed $job): string
    {
        if (is_resource($job)) {
            $job = stream_get_contents($job);
        }

        if (!is_string($job)) {
            return '';
        }

        if (str_starts_with($job, 'x') && StringHelper::isHexadecimal(substr($job, 1))) {
            return (string)hex2bin(substr($job, 1));
        }

        return $job;
    }
}
