<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\helpers\App;
use johnhenry\accessibilityaudit\base\PluginTrait;
use johnhenry\accessibilityaudit\base\SiteScopeTrait;
use johnhenry\accessibilityaudit\models\SettingsModel;
use johnhenry\accessibilityaudit\services\ServicesTrait;

/**
 * Accessibility Audit plugin.
 *
 * Scans Craft content for WCAG accessibility issues, surfaces potential
 * problems, generates AI-assisted alt text, analyses readability, and
 * produces VPAT conformance reports.
 *
 * @property-read mixed $settingsResponse
 * @property-read null|array<string, mixed> $cpNavItem
 * @property-read mixed $readOnlySettingsResponse
 * @property-read SettingsModel $settings
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class AccessibilityAudit extends BasePlugin
{
    // Traits
    // =========================================================================

    use PluginTrait;
    use ServicesTrait;
    use SiteScopeTrait;

    // Constants
    // =========================================================================

    /**
     * @var string The free Lite edition handle, and the lowest edition: a
     * fresh install starts here. Scanning is capped at self::LITE_SCAN_LIMIT
     * pages and history at self::LITE_RETENTION_DAYS days.
     */
    public const EDITION_LITE = 'lite';

    /**
     * @var string The Plus edition handle. Scanning is unlimited and
     * history is kept for up to self::PLUS_RETENTION_DAYS days.
     */
    public const EDITION_PLUS = 'plus';

    /**
     * @var string The Pro edition handle. Scanning and history retention are
     * both unlimited, and every site can be scanned.
     */
    public const EDITION_PRO = 'pro';

    /**
     * @var int The most distinct pages a Lite install may scan. Re-scanning a
     * page already scanned never counts against it. An edition boundary, not
     * a user setting.
     */
    public const LITE_SCAN_LIMIT = 25;

    /**
     * @var int The longest scan-history retention, in days, on Lite.
     */
    public const LITE_RETENTION_DAYS = 30;

    /**
     * @var int The longest scan-history retention, in days, on Plus.
     */
    public const PLUS_RETENTION_DAYS = 90;

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
     * @inheritdoc
     */
    public bool $hasReadOnlyCpSettings = true;

    /**
     * @var bool Whether the plugin has its own section in the CP.
     */
    public bool $hasCpSection = true;

    /**
     * @var string The plugin's schema version, used to track migrations.
     */
    public string $schemaVersion = '2.0.2';

    // Public Methods
    // =========================================================================

    /**
     * The display name of an edition, for messages and upgrade prompts.
     *
     * Edition names are product names, the same in every language the
     * Plugin Store shows them in, so they are not translated.
     *
     * @param string $edition One of the EDITION_* handles.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function editionName(string $edition): string
    {
        return match ($edition) {
            self::EDITION_LITE => 'Lite',
            self::EDITION_PLUS => 'Plus',
            default => 'Pro',
        };
    }

    /**
     * Whether AI alt text can run: Plus or higher, with an Anthropic API
     * key set.
     *
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function canGenerateAltText(): bool
    {
        return $this->isPlus()
            && trim((string)App::parseEnv($this->getSettings()->anthropicApiKey)) !== '';
    }

    /**
     * Whether the active edition is Pro.
     *
     * The check every Pro-only feature routes through, so the edition
     * comparison lives in one place.
     *
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    /**
     * Whether the active edition is Plus or higher.
     *
     * The check every feature Lite lacks routes through. Pro passes it too.
     *
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function isPlus(): bool
    {
        return $this->is(self::EDITION_PLUS, '>=');
    }

    /**
     * The longest scan history the active edition keeps, in days.
     *
     * @return int|null The cap in days, or null where retention is unlimited.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function retentionCap(): ?int
    {
        return match (true) {
            $this->isPro() => null,
            $this->isPlus() => self::PLUS_RETENTION_DAYS,
            default => self::LITE_RETENTION_DAYS,
        };
    }

    /**
     * The most distinct pages the active edition may scan.
     *
     * @return int|null The cap, or null where scanning is unlimited.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function scanLimit(): ?int
    {
        return $this->isPlus() ? null : self::LITE_SCAN_LIMIT;
    }

    /**
     * Boots the plugin: registers services, events and routes.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'johnhenry\\accessibilityaudit\\console\\controllers';
        }

        $this->_registerLogTarget();
        $this->_registerTwigVariable();
        $this->_registerSiteTemplateRoots();
        $this->_registerAssetAuditSync();
        $this->_registerScanPruning();

        // Attached on every web request rather than control panel ones alone.
        // The event only fires while the URL manager is building control panel
        // rules, so nothing else pays for it, and gating it on the request
        // leaves the control panel routes unreachable from a test.
        $this->_registerCpUrlRules();

        // Every request: the Preview menu is built on the element, and it
        // only fires where the editor asks for it.
        $this->_registerReadabilityPreviewTarget();

        // Not gated on the request. Craft filters a permission it has never
        // been told about out of any set being saved or validated, so a
        // permission registered only on control-panel requests does not exist
        // as far as a console command or an impersonation check is concerned,
        // and is dropped without a word.
        $this->_registerPermissions();

        // Every request: the HTTP server answers on a site URL, and the stdio
        // server is a console command.
        $this->_registerMcpTools();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerWidgets();
            $this->_registerCpAssets();
            $this->_registerAssetAltButton();
            $this->_registerElementSidebarPanel();
        }

        // Sign-in, sign-out and control panel requests all keep the marker in
        // step, so this can't wait for a site request.
        if (!Craft::$app instanceof ConsoleApplication) {
            $this->_registerOverlayMarker();
        }

        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            $this->_registerSiteUrlRules();
            $this->_registerFreshRenderGuard();
            $this->_maybeInjectFrontendAxe();
            $this->_maybeRegisterTemplateDebug();
        }

        $settings = $this->getSettings();
        if ($settings->scanOnSave) {
            $this->_registerScanOnSave();
        }
        if ($settings->autoGenerateAlt && $this->canGenerateAltText()) {
            $this->_registerAutoGenerateAlt();
        }
    }
}
