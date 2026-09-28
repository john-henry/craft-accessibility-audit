<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\web\Controller;
use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\InLanguage;
use johnhenry\accessibilityaudit\helpers\OpenAcr;
use johnhenry\accessibilityaudit\models\VpatMetaModel;
use johnhenry\accessibilityaudit\services\VpatService;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * Manages VPAT product metadata, per-criterion overrides, and report exports.
 *
 * @phpstan-import-type VpatReport from VpatService
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class VpatController extends Controller
{
    // Traits
    // =========================================================================

    use AiRateLimitTrait;
    use OrganisationMetaTrait;
    use ProGateTrait;

    // Const Properties
    // =========================================================================

    /**
     * @var int The most remark drafts one user may ask for in a window.
     *
     * Each one is a request to the AI service against the site's own credit.
     * An author working down the report drafts one criterion after another, so
     * the cap is set well above that and only catches a loop.
     */
    public const DRAFT_RATE_LIMIT = 30;

    /**
     * @var int The drafting window, in seconds.
     */
    public const DRAFT_RATE_WINDOW = 60;

    /**
     * @var int The most characters of your own notes a draft request will send.
     *
     * The notes go into the prompt, and the prompt is paid for by the
     * character. The rate limit above bounds how often somebody can spend;
     * this bounds how much each one costs. Well past a couple of paragraphs,
     * which is what the field is for.
     */
    public const DRAFT_NOTES_MAX = 5000;

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionSaveMeta(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:manage-vpat');

        if (($refusal = $this->requireProJson('VPAT conformance reporting')) !== null) {
            return $refusal;
        }

        $siteId = (int) $this->request->getRequiredBodyParam('siteId');

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        // The form posts one flat set of fields; storage splits it in two, so
        // the shared half is populated alongside the VPAT's own.
        $shared = $this->organisationMetaFromRequest();

        $model = new VpatMetaModel();
        $model->productVersion = trim((string) $this->request->getBodyParam('productVersion', ''));
        $model->reportDate = $this->dateParamToYmd('reportDate');
        $model->reportPeriodFrom = $this->dateParamToYmd('reportPeriodFrom');
        $model->reportPeriodTo = $this->dateParamToYmd('reportPeriodTo');
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
        $plugin->getOrganisation()->saveMeta($siteId, $shared);
        $plugin->getVpat()->saveMeta($siteId, $model);

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionSaveCriterion(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:manage-vpat');

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
        $criteria = AccessibilityAudit::getInstance()->getVpat()->getCriteria();
        if (!isset($criteria[$criterion])) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Unknown criterion.')]);
        }

        $validLevels = ['', 'Supports', 'Partially Supports', 'Does Not Support', 'Not Applicable', 'Not Evaluated'];
        if (!in_array($level, $validLevels, true)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Invalid conformance level.')]);
        }

        AccessibilityAudit::getInstance()->getVpat()->saveOverride($siteId, $criterion, $level, $remarks);

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionDraftRemark(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:manage-vpat');

        if (($refusal = $this->requireProJson('VPAT conformance reporting')) !== null) {
            return $refusal;
        }

        // Drafting spends the site's AI credit on every call, and the
        // permission to draft is not permission to spend without limit.
        if ($this->aiRateLimitExceeded('vpat-draft', self::DRAFT_RATE_LIMIT, self::DRAFT_RATE_WINDOW)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Too many drafts at once. Wait a moment and try again.'),
            ]);
        }

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getRequiredBodyParam('siteId'));
        $criterion = trim((string) $this->request->getRequiredBodyParam('criterion'));
        $level = trim((string) $this->request->getBodyParam('level', ''));
        // Cut rather than refused: the notes are a prompt for a first draft,
        // not content being saved, so the long way round is to send what fits
        // and let the author edit what comes back.
        $notes = mb_substr(trim((string) $this->request->getBodyParam('notes', '')), 0, self::DRAFT_NOTES_MAX);

        return $this->asJson($plugin->getVpat()->draftRemark($siteId, $criterion, $level, $notes));
    }

    /**
     * Records the current answers as a revision of the report.
     *
     * Deliberate, and separate from exporting. Only the author knows when a
     * document was actually given to somebody, and hanging the history off the
     * export would have turned a record of issued documents into a record of
     * times the preview was opened.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws SiteNotFoundException
     * @throws \yii\db\Exception
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function actionRecordRevision(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:manage-vpat');

        if (($refusal = $this->requireProJson('VPAT conformance reporting')) !== null) {
            return $refusal;
        }

        $siteId = (int)$this->request->getRequiredBodyParam('siteId');

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        $recorded = AccessibilityAudit::getInstance()->getVpat()->recordRevision($siteId);

        return $this->asJson([
            'success' => true,
            'recorded' => $recorded,
            'message' => $recorded
                ? Craft::t('accessibility-audit', 'Recorded. This is now the latest revision of this report.')
                : Craft::t('accessibility-audit', 'Nothing has changed since the last revision, so nothing new was recorded.'),
        ]);
    }

    /**
     * Removes the most recently recorded revision.
     *
     * The undo for a button pressed to see what it did. Only the latest one
     * goes, so the history cannot be quietly rewritten from the middle.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws SiteNotFoundException
     * @throws \yii\db\Exception
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function actionDeleteLatestRevision(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:manage-vpat');

        if (($refusal = $this->requireProJson('VPAT conformance reporting')) !== null) {
            return $refusal;
        }

        $siteId = (int)$this->request->getRequiredBodyParam('siteId');

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        $removed = AccessibilityAudit::getInstance()->getVpat()->deleteLatestRevision($siteId);

        return $this->asJson([
            'success' => true,
            'removed' => $removed,
            'message' => $removed
                ? Craft::t('accessibility-audit', 'The latest revision was removed.')
                : Craft::t('accessibility-audit', 'There are no revisions recorded to remove.'),
        ]);
    }

    /**
     * Exports the full VPAT report as a standalone HTML page.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws Exception
     * @throws ForbiddenHttpException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionExport(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        if (($refusal = $this->requireProJson('VPAT conformance reporting')) !== null) {
            return $refusal;
        }

        $siteId = AccessibilityAudit::getInstance()->requestedSiteId();
        $report = AccessibilityAudit::getInstance()->getVpat()->getFullReport($siteId);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->content = $this->_renderExportHtml($report);

        return $response;
    }

    /**
     * Exports the full VPAT report as an OpenACR YAML file.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws \yii\db\Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.4.0
     */
    public function actionExportOpenAcr(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        if (($refusal = $this->requireProJson('VPAT conformance reporting')) !== null) {
            return $refusal;
        }

        $siteId = AccessibilityAudit::getInstance()->requestedSiteId();
        $report = AccessibilityAudit::getInstance()->getVpat()->getFullReport($siteId);

        // The schema requires the author's email, and a file a buyer's tooling
        // rejects is worse than being told what to fill in.
        if (!OpenAcr::canExport($report)) {
            throw new BadRequestHttpException(Craft::t('accessibility-audit', 'Add a contact email to the report information before exporting OpenACR. The format requires one.'));
        }

        $siteName = (string)Craft::$app->getSites()->getSiteById($siteId)?->getName();
        $yaml = $this->_inSiteLanguage(
            $siteId,
            static fn(): string => OpenAcr::toYaml(OpenAcr::document($report, $siteName)),
        );

        // Set directly rather than through sendContentAsFile(), which discards
        // an output buffer it did not open.
        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->content = $yaml;
        $response->setDownloadHeaders(
            OpenAcr::filename(OpenAcr::productName($report, $siteName)),
            'application/yaml',
        );

        return $response;
    }

    // Private Methods
    // =========================================================================

    /**
     * Runs a callback with the application language set to a site's own, and
     * puts it back afterwards.
     *
     * An exported report is written in the language of the site it describes,
     * not the language whoever exported it reads the control panel in. It is
     * handed to a buyer, and an Irish admin exporting the report for an English
     * site should not produce an Irish document.
     *
     * @template T
     * @param int $siteId The site whose language to use.
     * @param callable(): T $callback
     * @return T
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.4.0
     */
    private function _inSiteLanguage(int $siteId, callable $callback): mixed
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);

        return InLanguage::run($site->language ?? Craft::$app->language, $callback);
    }

    /**
     * Refuses a save that targets a site the current user may not edit. The
     * `manage-vpat` permission is install-wide, so the per-site fence has to be
     * enforced here: a Pro multi-site user must only write sites they can edit.
     * Returns a JSON refusal, or null when the site is allowed.
     *
     * @param int $siteId The posted site ID.
     * @return Response|null
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * Renders the export document HTML. When the vpatExportTemplate setting
     * points at an existing site template, that template takes over the whole
     * document (agency branding) and receives the same variables the built-in
     * export gets. Unset, or pointing at a template that doesn't exist, falls
     * back to the plugin's built-in print-ready document so a typo never
     * breaks the export.
     *
     * @param VpatReport $report The full report, from VpatService::getFullReport().
     * @return string The export document HTML.
     * @throws Exception
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _renderExportHtml(array $report): string
    {
        // Everything this produces is read by somebody outside the
        // organisation, which is why the export template carries no explanatory
        // comments of its own and the reasoning for how it is built sits here
        // instead:
        //
        //  - The toolbar is created in JavaScript rather than written into the
        //    markup, so a saved copy of the page, or one run through an HTML to
        //    PDF converter, holds no editor controls whether or not that
        //    converter honours the print stylesheet.
        //  - The CSRF token for the Record control is fetched when the button
        //    is pressed rather than printed into the page, so it does not
        //    travel with a document that is meant to be sent to a buyer.
        //  - The conformance table breaks across pages and repeats its header,
        //    because a table of fifty rows kept whole is pushed to a fresh page
        //    and leaves the one before it empty.
        return $this->_inSiteLanguage(
            $report['siteId'],
            fn(): string => $this->_renderExportTemplate($report),
        );
    }

    /**
     * Renders the export markup, from the site's own template where one is
     * configured and from the built-in report otherwise.
     *
     * @param VpatReport $report The full report, from VpatService::getFullReport().
     * @return string The rendered HTML.
     * @throws Exception
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    private function _renderExportTemplate(array $report): string
    {
        $view = Craft::$app->getView();
        $template = trim(AccessibilityAudit::getInstance()->getSettings()->vpatExportTemplate);

        // Handed to the template rather than read from craft.app.language
        // inside it. Twig resolves its globals once per environment, so that
        // one still reads the language the request started in and does not
        // follow the switch _inSiteLanguage() makes around this render. The
        // document would then declare English over German text, which is the
        // 3.1.1 failure the report itself reports on.
        $vars = [
            'report' => $report,
            'language' => Craft::$app->getSites()->getSiteById($report['siteId'])->language
                ?? Craft::$app->language,
        ];

        if ($template !== '' && $view->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
            return $view->renderTemplate($template, $vars, View::TEMPLATE_MODE_SITE);
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
            $vars,
            View::TEMPLATE_MODE_CP,
        );
    }
}
