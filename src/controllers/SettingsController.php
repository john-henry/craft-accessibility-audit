<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\errors\MissingComponentException;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\ScannableElementTypes;
use johnhenry\accessibilityaudit\models\SettingsModel;
use johnhenry\accessibilityaudit\services\AuditService;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * Renders and persists the plugin's tabbed settings pages.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class SettingsController extends Controller
{
    // Traits
    // =========================================================================

    use ProGateTrait;

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * @throws ForbiddenHttpException
     */
    public function actionIndex(): Response
    {
        $this->requireAdmin(false);

        return $this->redirect('accessibility-audit/settings/general');
    }

    /**
     * @throws ForbiddenHttpException
     */
    public function actionEditGeneral(): Response
    {
        $this->requireAdmin();

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('accessibility-audit/_settings/general', [
            'plugin' => $plugin,
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('accessibility-audit'),
            'isPro' => $plugin->isPro(),
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     */
    public function actionEditScanning(): Response
    {
        $this->requireAdmin();

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('accessibility-audit/_settings/scanning', [
            'plugin' => $plugin,
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('accessibility-audit'),
            'isPro' => $plugin->isPro(),
            'headlessAvailable' => $plugin->headless->isAvailable(),
            'elementTypeOptions' => ScannableElementTypes::all(),
            'scannedElementTypes' => $settings->resolvedScannedElementTypes(),
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     */
    public function actionEditMaintenance(): Response
    {
        $this->requireAdmin();

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('accessibility-audit/_settings/maintenance', [
            'plugin' => $plugin,
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('accessibility-audit'),
            'isPro' => $plugin->isPro(),
            'retentionCap' => AuditService::STANDARD_RETENTION_CAP,
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws MissingComponentException
     */
    public function actionEditTools(): Response
    {
        $this->requireAdmin();

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('accessibility-audit/_settings/tools', [
            'plugin' => $plugin,
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('accessibility-audit'),
            'isPro' => $plugin->isPro(),
            // Set once by actionGenerateCiToken() and read here so the full
            // token can be shown with a copy button right on the page,
            // instead of buried in a growl notice with no way to copy it
            // before it disappears.
            'newCiApiToken' => Craft::$app->getSession()->getFlash('newCiApiToken'),
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     */
    public function actionEditNotifications(): Response
    {
        $this->requireAdmin();

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('accessibility-audit/_settings/notifications', [
            'plugin' => $plugin,
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('accessibility-audit'),
            'isPro' => $plugin->isPro(),
        ]);
    }

    /**
     * @throws MissingComponentException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \yii\db\Exception
     */
    public function actionSaveGeneral(): Response
    {
        return $this->_saveSettings();
    }

    /**
     * @throws MissingComponentException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \yii\db\Exception
     */
    public function actionSaveScanning(): Response
    {
        return $this->_saveSettings();
    }

    /**
     * @throws MissingComponentException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \yii\db\Exception
     */
    public function actionSaveMaintenance(): Response
    {
        return $this->_saveSettings();
    }

    /**
     * @throws MissingComponentException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \yii\db\Exception
     */
    public function actionSaveTools(): Response
    {
        return $this->_saveSettings();
    }

    /**
     * @throws MissingComponentException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \yii\db\Exception
     */
    public function actionSaveNotifications(): Response
    {
        if (($refusal = $this->requireProJson('Notifications')) !== null) {
            return $refusal;
        }

        return $this->_saveSettings();
    }

    /**
     * Generates a fresh CI/CD API token, saves it, and redirects back so it can
     * be shown to the admin once.
     *
     * @throws MissingComponentException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \Exception
     */
    public function actionGenerateCiToken(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        if (($refusal = $this->requireProJson('CI/CD integration')) !== null) {
            return $refusal;
        }

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        // Only the SHA-256 hash is stored: settings land in project config,
        // which is committed, so plaintext would hand the CI credential to
        // anyone with repo access. CiController hashes the presented token to
        // compare, and the plaintext is shown once via the flash below.
        $token = StringHelper::UUID();
        $settings->ciApiToken = hash('sha256', $token);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError(Craft::t('app', 'Could not save plugin settings.'));
            return $this->redirectToPostedUrl();
        }

        // The token itself goes in a flash var (read once by actionEditTools()
        // and shown with a copy button), not the growl notice text. A toast
        // that vanishes with no way to copy it is a poor way to hand someone a
        // secret they need to paste elsewhere. This is the only moment the
        // plaintext exists; only its hash is persisted.
        Craft::$app->getSession()->setFlash('newCiApiToken', $token);
        Craft::$app->getSession()->setNotice(Craft::t(
            'accessibility-audit',
            'A new CI/CD token was generated. Copy it now, it won\'t be shown in full again.'
        ));
        return $this->redirectToPostedUrl();
    }

    /**
     * Sends a test message through whichever notification channels are
     * currently ticked on the submitted form, without requiring the settings
     * to be saved first: the values come straight off the request, applied
     * to the in-memory settings instance only for the life of this request,
     * never persisted.
     *
     * The `preview` body param picks what gets sent:
     * - `connection` (default): a canned "your settings work" message.
     * - `new-error` / `score-drop`: dummy content rendered through the real
     *   message builders, so the team sees exactly what production sends.
     * - `both`: both dummies, delivered as two separate messages, mirroring
     *   a real scan that trips both triggers at once.
     *
     * Dummy subjects are prefixed with "[Test]" so nobody mistakes a preview
     * for a live regression.
     *
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws MissingComponentException
     * @throws InvalidConfigException
     * @throws \yii\base\Exception
     */
    public function actionTestNotification(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        if (($refusal = $this->requireProJson('Notifications')) !== null) {
            return $refusal;
        }

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        $settings->notifyEmailEnabled = (bool) $this->request->getBodyParam('settings[notifyEmailEnabled]', $settings->notifyEmailEnabled);
        $settings->notifyEmailRecipients = (string) $this->request->getBodyParam('settings[notifyEmailRecipients]', $settings->notifyEmailRecipients);
        $settings->notifySlackEnabled = (bool) $this->request->getBodyParam('settings[notifySlackEnabled]', $settings->notifySlackEnabled);
        $settings->notifySlackWebhookUrl = (string) $this->request->getBodyParam('settings[notifySlackWebhookUrl]', $settings->notifySlackWebhookUrl);

        if (!$settings->notifyEmailEnabled && !$settings->notifySlackEnabled) {
            Craft::$app->getSession()->setError(Craft::t(
                'accessibility-audit',
                'Turn on email or Slack notifications above before sending a test.'
            ));
            return $this->redirectToPostedUrl();
        }

        $notifications = $plugin->getNotifications();
        $preview = (string) $this->request->getBodyParam('preview', 'connection');
        $testPrefix = Craft::t('accessibility-audit', '[Test]') . ' ';
        $dummyLabel = Craft::t('accessibility-audit', 'Example page (https://example.com/sample-page)');
        // A dummy page has no report to open, so the preview's button goes to
        // the plugin overview: a real destination, same visual treatment.
        $dummyReportUrl = UrlHelper::cpUrl('accessibility-audit');

        if (in_array($preview, ['new-error', 'both'], true)) {
            $message = $notifications->buildNewErrorNotification($dummyLabel, ['img-alt', 'link-name']);
            $notifications->dispatch($testPrefix . $message['subject'], $message['body'], $message['color'], $dummyReportUrl);
        }

        if (in_array($preview, ['score-drop', 'both'], true)) {
            $message = $notifications->buildScoreDropNotification($dummyLabel, 92, 74);
            $notifications->dispatch($testPrefix . $message['subject'], $message['body'], $message['color'], $dummyReportUrl);
        }

        if (!in_array($preview, ['new-error', 'score-drop', 'both'], true)) {
            $notifications->dispatch(
                Craft::t('accessibility-audit', 'Accessibility Audit test notification'),
                Craft::t(
                    'accessibility-audit',
                    'This is a test notification from the Accessibility Audit plugin. If you received this, your notification settings are working.'
                )
            );
        }

        Craft::$app->getSession()->setNotice(Craft::t(
            'accessibility-audit',
            'Test notification sent. Check your configured channels, if nothing arrives, check storage/logs/accessibility-audit-{date}.log for delivery errors.'
        ));
        return $this->redirectToPostedUrl();
    }

    /**
     * Legacy generic save, kept for backward compat.
     *
     * @throws MissingComponentException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \yii\db\Exception
     */
    public function actionSave(): Response
    {
        return $this->_saveSettings();
    }

    /**
     * @throws MissingComponentException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \yii\db\Exception
     */
    private function _saveSettings(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = AccessibilityAudit::getInstance();
        $settings = $plugin->getSettings();

        $settings->wcagLevel = (string) $this->request->getBodyParam('settings[wcagLevel]',       $settings->wcagLevel);
        $settings->scanOnSave = (bool)   $this->request->getBodyParam('settings[scanOnSave]',      $settings->scanOnSave);
        $settings->frontendAxe = (bool)   $this->request->getBodyParam('settings[frontendAxe]',     $settings->frontendAxe);
        $settings->overlayCollapseWhenIdle = (bool)   $this->request->getBodyParam('settings[overlayCollapseWhenIdle]', $settings->overlayCollapseWhenIdle);
        $settings->overlayPosition = (string) $this->request->getBodyParam('settings[overlayPosition]', $settings->overlayPosition);
        $settings->overlayIdleSeconds = (int)    $this->request->getBodyParam('settings[overlayIdleSeconds]', $settings->overlayIdleSeconds);
        // Server-side browser scanning is a Pro feature, and its settings aren't
        // rendered on Standard, so leave the stored values alone there rather
        // than reading whatever was posted. Skipping the read matters on a
        // downgrade: saving this page on Standard must not wipe the endpoint or
        // binary path a licence might get back.
        if ($plugin->isPro()) {
            $settings->chromePath = (string) $this->request->getBodyParam('settings[chromePath]', $settings->chromePath);
            $settings->chromeWsEndpoint = (string) $this->request->getBodyParam('settings[chromeWsEndpoint]', $settings->chromeWsEndpoint);
            $settings->chromeNoSandbox = (bool)   $this->request->getBodyParam('settings[chromeNoSandbox]', $settings->chromeNoSandbox);
            $settings->browserSettleMs = (int)    $this->request->getBodyParam('settings[browserSettleMs]', $settings->browserSettleMs);
        }
        $settings->scannerUserAgent = (string) $this->request->getBodyParam('settings[scannerUserAgent]', $settings->scannerUserAgent);
        $settings->en301549 = (bool)   $this->request->getBodyParam('settings[en301549]',        $settings->en301549);
        $settings->vpatExportTemplate = (string) $this->request->getBodyParam('settings[vpatExportTemplate]', $settings->vpatExportTemplate);
        $settings->statementTemplate = (string) $this->request->getBodyParam('settings[statementTemplate]', $settings->statementTemplate);
        $settings->retainDays = (int)    $this->request->getBodyParam('settings[retainDays]',      $settings->retainDays);
        // Retention beyond STANDARD_RETENTION_CAP days (and "never", i.e. 0) is a
        // Pro feature, so clamp the saved value on Standard.
        if (!$plugin->isPro() && ($settings->retainDays <= 0 || $settings->retainDays > AuditService::STANDARD_RETENTION_CAP)) {
            $settings->retainDays = AuditService::STANDARD_RETENTION_CAP;
        }
        $settings->resolvedRetention = (string) $this->request->getBodyParam('settings[resolvedRetention]', $settings->resolvedRetention);
        // Keeping resolved issues forever (unbounded retention) is a Pro feature,
        // in line with the retainDays cap above; fall back to keepDays on Standard.
        if (!$plugin->isPro() && $settings->resolvedRetention === SettingsModel::RESOLVED_RETENTION_FOREVER) {
            $settings->resolvedRetention = SettingsModel::RESOLVED_RETENTION_KEEP_DAYS;
        }
        $settings->targetScore = (int)    $this->request->getBodyParam('settings[targetScore]',     $settings->targetScore);
        // Remember the field alt text is read from before this save, so a change
        // can invalidate the stored asset audit below (it was computed against
        // the old field).
        $previousAltField = $settings->altTextField;
        $settings->altTextField = (string) $this->request->getBodyParam('settings[altTextField]',    $settings->altTextField);
        $settings->anthropicApiKey = (string) $this->request->getBodyParam('settings[anthropicApiKey]', $settings->anthropicApiKey);
        $settings->altTextContext = (string) $this->request->getBodyParam('settings[altTextContext]',  $settings->altTextContext);
        $settings->altTextLanguage = (string) $this->request->getBodyParam('settings[altTextLanguage]', $settings->altTextLanguage);
        $settings->autoGenerateAlt = (bool)   $this->request->getBodyParam('settings[autoGenerateAlt]', $settings->autoGenerateAlt);

        $settings->notifyEmailEnabled = (bool)   $this->request->getBodyParam('settings[notifyEmailEnabled]',    $settings->notifyEmailEnabled);
        $settings->notifyEmailRecipients = (string) $this->request->getBodyParam('settings[notifyEmailRecipients]', $settings->notifyEmailRecipients);
        $settings->notifySlackEnabled = (bool)   $this->request->getBodyParam('settings[notifySlackEnabled]',    $settings->notifySlackEnabled);
        $settings->notifySlackWebhookUrl = (string) $this->request->getBodyParam('settings[notifySlackWebhookUrl]', $settings->notifySlackWebhookUrl);
        $settings->notifyOnNewError = (bool)   $this->request->getBodyParam('settings[notifyOnNewError]',      $settings->notifyOnNewError);
        $settings->notifyOnScoreDrop = (bool)   $this->request->getBodyParam('settings[notifyOnScoreDrop]',     $settings->notifyOnScoreDrop);
        $settings->notifyScoreDropThreshold = (int)    $this->request->getBodyParam('settings[notifyScoreDropThreshold]', $settings->notifyScoreDropThreshold);

        $ignoreRulesRaw = $this->request->getBodyParam('settings[ignoreRules]', '');
        if (is_string($ignoreRulesRaw)) {
            $settings->ignoreRules = array_filter(array_map('trim', explode("\n", $ignoreRulesRaw)));
        }

        // A config-file value takes precedence and renders the table static, so
        // only read the posted rows when the file isn't overriding them.
        if (!array_key_exists('excludedUriPatterns', Craft::$app->getConfig()->getConfigFromFile('accessibility-audit'))) {
            $rows = $this->request->getBodyParam('settings[excludedUriPatterns]', []);
            $clean = [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $pattern = trim((string)($row['uriPattern'] ?? ''));
                    $siteId = trim((string)($row['siteId'] ?? ''));
                    // Keep an empty pattern only when it is deliberately scoped
                    // or toggled off: on its own an empty row is just an unfilled
                    // "add row", not a homepage exclusion.
                    if ($pattern === '' && $siteId === '' && !array_key_exists('enabled', $row)) {
                        continue;
                    }
                    $clean[] = [
                        'enabled' => (bool)($row['enabled'] ?? true),
                        'siteId' => $siteId === '' ? '' : (int)$siteId,
                        'uriPattern' => $pattern,
                    ];
                }
            }
            $settings->excludedUriPatterns = $clean;
        }

        // Remember the resolved scan set before this save, so stored scans for
        // any element type newly excluded below can be pruned.
        $previousScannedTypes = $settings->resolvedScannedElementTypes();
        // A config-file value takes precedence and renders the control static,
        // so only read the posted types when the file isn't overriding them.
        if (!array_key_exists('scannedElementTypes', Craft::$app->getConfig()->getConfigFromFile('accessibility-audit'))) {
            $posted = $this->request->getBodyParam('settings[scannedElementTypes]', null);
            // A checkboxSelectField posts an empty-string sentinel when nothing
            // is ticked; filtering it leaves an explicit empty allow-list, which
            // resolves to "scan no content" rather than falling back to null.
            if (is_array($posted)) {
                $settings->scannedElementTypes = array_values(array_filter(array_map('strval', $posted)));
            }
        }

        // Remember which volumes were excluded before this save, so the stored
        // asset findings for any newly-excluded volume can be cleared below.
        $previousExcludedVolumes = $settings->excludedVolumes;
        // A config-file value takes precedence and renders the control static,
        // so only read the posted volumes when the file isn't overriding them.
        if (!array_key_exists('excludedVolumes', Craft::$app->getConfig()->getConfigFromFile('accessibility-audit'))) {
            $posted = $this->request->getBodyParam('settings[excludedVolumes]', $settings->excludedVolumes);
            $settings->excludedVolumes = is_array($posted)
                ? array_values(array_filter(array_map('strval', $posted)))
                : [];
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError(Craft::t('app', 'Could not save plugin settings.'));
            return $this->redirectToPostedUrl();
        }

        // Newly-excluded volumes shed their stored asset findings now, so the
        // Assets page counts and the dashboard's Asset alt text panel drop them
        // immediately rather than after the next sweep.
        $this->_clearNewlyExcludedVolumes($previousExcludedVolumes, $settings->excludedVolumes);

        // Element types dropped from the scan set shed their stored scans and
        // issues now, so reports and the dashboard stop counting pages the
        // scanner will no longer visit.
        $newlyExcludedTypes = array_values(array_diff($previousScannedTypes, $settings->resolvedScannedElementTypes()));
        if ($newlyExcludedTypes !== []) {
            $plugin->audit->pruneScansForElementTypes($newlyExcludedTypes);
        }

        // Changing which field stores alt text makes every stored asset finding
        // stale, so clear the asset audit and tell the editor to re-scan.
        if ($settings->altTextField !== $previousAltField) {
            $plugin->assets->clearAssetAudit();
            Craft::$app->getSession()->setNotice(Craft::t(
                'accessibility-audit',
                'Settings saved. The alt text field changed, so run “Scan all assets” to refresh the asset report against the new field.',
            ));

            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(Craft::t('app', 'Settings saved.'));
        return $this->redirectToPostedUrl();
    }

    public function actionSupport(): Response
    {
        return $this->renderTemplate('accessibility-audit/_settings/support');
    }

    /**
     * Clears the stored asset findings for any volume that is in the new
     * excluded list but was not in the previous one, resolving the volume UIDs
     * to ids once. Volumes dropped from the list are left alone: their images
     * are back in the audit and the next save or sweep repopulates them.
     *
     * @param string[] $previous The excluded volume UIDs before the save.
     * @param string[] $current The excluded volume UIDs after the save.
     * @throws \yii\db\Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _clearNewlyExcludedVolumes(array $previous, array $current): void
    {
        $newlyExcluded = array_values(array_diff($current, $previous));
        if (empty($newlyExcluded)) {
            return;
        }

        $volumes = Craft::$app->getVolumes();
        $ids = [];
        foreach ($newlyExcluded as $uid) {
            $volume = $volumes->getVolumeByUid((string)$uid);
            if ($volume !== null) {
                $ids[] = (int)$volume->id;
            }
        }

        if (!empty($ids)) {
            AccessibilityAudit::getInstance()->getAssets()->clearAssetAuditForVolumes($ids);
        }
    }
}
