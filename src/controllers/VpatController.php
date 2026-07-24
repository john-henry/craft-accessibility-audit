<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\OrganisationMetaModel;
use johnhenry\accessibilityaudit\models\VpatMetaModel;
use yii\base\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * Manages VPAT product metadata, per-criterion overrides, and report exports.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class VpatController extends Controller
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

    // Public Methods
    // =========================================================================

    /**
     * Saves the VPAT product / report information for a site.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws SiteNotFoundException
     * @throws \yii\db\Exception
     * @throws \Exception
     */
    public function actionSaveMeta(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:manageVpat');

        if (($refusal = $this->requireProJson('VPAT conformance reporting')) !== null) {
            return $refusal;
        }

        $siteId = (int) $this->request->getRequiredBodyParam('siteId');

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        // The form posts one flat set of fields; storage splits it in two, so
        // the shared half is populated alongside the VPAT's own.
        $shared = new OrganisationMetaModel();
        $shared->productName = trim((string) $this->request->getBodyParam('productName', ''));
        $shared->productDescription = trim((string) $this->request->getBodyParam('productDescription', ''));
        $shared->contactName = trim((string) $this->request->getBodyParam('contactName', ''));
        $shared->contactEmail = trim((string) $this->request->getBodyParam('contactEmail', ''));
        $shared->contactPhone = trim((string) $this->request->getBodyParam('contactPhone', ''));
        $shared->evalMethodology = trim((string) $this->request->getBodyParam('evalMethodology', ''));
        $shared->evalMethods = $this->_splitLines((string) $this->request->getBodyParam('evalMethods', ''));
        $shared->scopePages = $this->_splitLines((string) $this->request->getBodyParam('scopePages', ''));

        $model = new VpatMetaModel();
        $model->productVersion = trim((string) $this->request->getBodyParam('productVersion', ''));
        $model->reportDate = $this->_dateParamToYmd('reportDate');
        $model->reportPeriodFrom = $this->_dateParamToYmd('reportPeriodFrom');
        $model->reportPeriodTo = $this->_dateParamToYmd('reportPeriodTo');
        $model->notes = trim((string) $this->request->getBodyParam('notes', ''));
        $model->legalDisclaimer = trim((string) $this->request->getBodyParam('legalDisclaimer', ''));

        // Both are validated before either is written: a half-saved form would
        // leave the editor showing values that never reached the database.
        $sharedValid = $shared->validate();
        $modelValid = $model->validate();

        if (!$sharedValid || !$modelValid) {
            return $this->asJson([
                'success' => false,
                'errors' => array_merge($shared->getErrors(), $model->getErrors()),
            ]);
        }

        $plugin = AccessibilityAudit::getInstance();
        $plugin->organisation->saveMeta($siteId, $shared);
        $plugin->vpat->saveMeta($siteId, $model);

        return $this->asJson(['success' => true]);
    }

    /**
     * Saves or clears a single criterion conformance override.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws SiteNotFoundException
     * @throws \yii\db\Exception
     * @throws \Exception
     */
    public function actionSaveCriterion(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:manageVpat');

        if (($refusal = $this->requireProJson('VPAT conformance reporting')) !== null) {
            return $refusal;
        }

        $siteId = (int)    $this->request->getRequiredBodyParam('siteId');

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }
        $criterion = trim((string) $this->request->getRequiredBodyParam('criterion'));
        $level = trim((string) $this->request->getBodyParam('level', ''));
        $remarks = trim((string) $this->request->getBodyParam('remarks', ''));

        // Validate the criterion exists in our list
        $criteria = AccessibilityAudit::getInstance()->vpat->getCriteria();
        if (!isset($criteria[$criterion])) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Unknown criterion.')]);
        }

        $validLevels = ['', 'Supports', 'Partially Supports', 'Does Not Support', 'Not Applicable', 'Not Evaluated'];
        if (!in_array($level, $validLevels, true)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Invalid conformance level.')]);
        }

        AccessibilityAudit::getInstance()->vpat->saveOverride($siteId, $criterion, $level, $remarks);

        return $this->asJson(['success' => true]);
    }

    /**
     * Drafts the remarks text for one criterion with AI, grounded in the
     * site's scan findings. The draft is returned to the editor for the admin
     * to review and edit; nothing is saved here.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws SiteNotFoundException
     */
    public function actionDraftRemark(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:manageVpat');

        if (($refusal = $this->requireProJson('VPAT conformance reporting')) !== null) {
            return $refusal;
        }

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getRequiredBodyParam('siteId'));
        $criterion = trim((string) $this->request->getRequiredBodyParam('criterion'));
        $level = trim((string) $this->request->getBodyParam('level', ''));
        $notes = trim((string) $this->request->getBodyParam('notes', ''));

        return $this->asJson($plugin->vpat->draftRemark($siteId, $criterion, $level, $notes));
    }

    /**
     * Exports the full VPAT report as a standalone HTML page.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws Exception
     * @throws ForbiddenHttpException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \Exception
     */
    public function actionExport(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        if (($refusal = $this->requireProJson('VPAT conformance reporting')) !== null) {
            return $refusal;
        }

        $siteId = AccessibilityAudit::getInstance()->requestedSiteId();
        $report = AccessibilityAudit::getInstance()->vpat->getFullReport($siteId);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->content = $this->_renderExportHtml($report);

        return $response;
    }

    // Private Methods
    // =========================================================================

    /**
     * Refuses a save that targets a site the current user may not edit. The
     * `manageVpat` permission is install-wide, so the per-site fence has to be
     * enforced here: a Pro multi-site user must only write sites they can edit.
     * Returns a JSON refusal, or null when the site is allowed.
     *
     * @param int $siteId The posted site ID.
     * @return Response|null
     * @throws SiteNotFoundException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.1
     */
    private function _requireAllowedSite(int $siteId): ?Response
    {
        if (AccessibilityAudit::getInstance()->isSiteAllowed($siteId)) {
            return null;
        }

        return $this->asJson([
            'success' => false,
            'error' => Craft::t('accessibility-audit', 'You do not have permission to edit that site.'),
        ]);
    }

    /**
     * Splits a newline-separated body param into a trimmed, de-duplicated
     * list with empties dropped. The editor posts its list fields
     * (evaluation methods, scope pages) one entry per line.
     *
     * @param string $value The raw newline-separated value.
     * @return string[]
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _splitLines(string $value): array
    {
        $lines = array_map('trim', explode("\n", $value));

        return array_values(array_unique(array_filter($lines, static fn(string $line): bool => $line !== '')));
    }

    /**
     * Normalises a Craft date-field body param to a flat `Y-m-d` string.
     *
     * The editor posts these as Craft's native date-field payload
     * (`name[date]` plus hidden locale/timezone), so the value arrives as an
     * array in the request's locale format. It is parsed back to the flat
     * `Y-m-d` string the model validates and the export consumes; an empty or
     * unparseable value yields an empty string.
     *
     * @param string $param The body param name (e.g. `reportDate`).
     * @return string The `Y-m-d` date, or an empty string.
     * @throws \Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _dateParamToYmd(string $param): string
    {
        $value = $this->request->getBodyParam($param);

        if (empty($value) || (is_array($value) && empty($value['date']))) {
            return '';
        }

        $date = DateTimeHelper::toDateTime($value);

        return $date !== false ? $date->format('Y-m-d') : '';
    }

    /**
     * Renders the export document HTML. When the vpatExportTemplate setting
     * points at an existing site template, that template takes over the whole
     * document (agency branding) and receives the same variables the built-in
     * export gets. Unset, or pointing at a template that doesn't exist, falls
     * back to the plugin's built-in print-ready document so a typo never
     * breaks the export.
     *
     * @param array $report The full report structure from VpatService::getFullReport().
     * @return string
     * @throws Exception
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _renderExportHtml(array $report): string
    {
        $view = Craft::$app->getView();
        $template = trim(AccessibilityAudit::getInstance()->getSettings()->vpatExportTemplate);

        if ($template !== '' && $view->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
            return $view->renderTemplate($template, ['report' => $report], View::TEMPLATE_MODE_SITE);
        }

        if ($template !== '') {
            Craft::warning(
                "VPAT export template \"{$template}\" not found; falling back to the built-in export.",
                'accessibility-audit',
            );
        }

        // Render as a standalone page (no CP chrome); user prints to PDF
        return $view->renderTemplate(
            'accessibility-audit/vpat-export',
            ['report' => $report],
            View::TEMPLATE_MODE_CP,
        );
    }
}
