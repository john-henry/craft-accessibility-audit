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
     * @var string The Standard edition handle. The scan-count cap applies to
     * this edition.
     */
    public const EDITION_STANDARD = 'standard';

    /**
     * @var string The Pro edition handle. Scanning is unlimited on this edition.
     */
    public const EDITION_PRO = 'pro';

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
    public string $schemaVersion = '1.3.2';

    // Public Methods
    // =========================================================================

    /**
     * Whether the active edition is Pro.
     *
     * The single check the whole plugin routes its Pro-only feature gating
     * through, so the edition comparison lives in one place rather than being
     * repeated as `->is(self::EDITION_PRO)` across every controller and service.
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

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerWidgets();
            $this->_registerCpAssets();
            $this->_registerAssetAltButton();
            $this->_registerElementSidebarPanel();
        }

        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            $this->_registerSiteUrlRules();
            $this->_maybeInjectFrontendAxe();
            $this->_maybeRegisterTemplateDebug();
        }

        $settings = $this->getSettings();
        if ($settings->scanOnSave) {
            $this->_registerScanOnSave();
        }
        if ($settings->autoGenerateAlt && !empty(trim(App::parseEnv($settings->anthropicApiKey)))) {
            $this->_registerAutoGenerateAlt();
        }
    }
}
