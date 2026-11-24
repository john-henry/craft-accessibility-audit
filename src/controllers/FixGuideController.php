<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\web\Controller;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\ScanTarget;
use johnhenry\accessibilityaudit\services\FixGuideService;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Downloads the fix guides: Markdown files for working through what the scans
 * found, for the whole site, one rule, or one page.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class FixGuideController extends Controller
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
     * Downloads the guide to every open issue on a site.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionSite(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        if (($refusal = $this->requireStandardJson('Fix guides')) !== null) {
            return $refusal;
        }

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getParam('siteId'));
        $guides = $plugin->getFixGuides();

        return $this->_download(
            $guides->siteGuide($siteId),
            $guides->filename($siteId, FixGuideService::SCOPE_SITE),
        );
    }

    /**
     * Downloads the guide to one rule across a site.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionRule(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        if (($refusal = $this->requireStandardJson('Fix guides')) !== null) {
            return $refusal;
        }

        $ruleId = trim((string)$this->request->getParam('ruleId', ''));

        if ($ruleId === '') {
            throw new BadRequestHttpException('Missing ruleId.');
        }

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getParam('siteId'));
        $guides = $plugin->getFixGuides();

        return $this->_download(
            $guides->ruleGuide($ruleId, $siteId),
            $guides->filename($siteId, FixGuideService::SCOPE_RULE, $ruleId),
        );
    }

    /**
     * Downloads the guide to one scanned page, addressed by element or, for a
     * page with no element behind it, by scan.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws NotFoundHttpException
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function actionPage(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        if (($refusal = $this->requireStandardJson('Fix guides')) !== null) {
            return $refusal;
        }

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getParam('siteId'));
        $guides = $plugin->getFixGuides();
        $scan = $guides->findPageScan(
            (int)$this->request->getParam('elementId', 0),
            (int)$this->request->getParam('scanId', 0),
            $siteId,
        );

        if ($scan === null) {
            throw new NotFoundHttpException(Craft::t('accessibility-audit', 'That page has not been scanned.'));
        }

        $elementId = (int)($scan['elementId'] ?? 0);
        $element = $elementId !== 0
            ? Craft::$app->getElements()->getElementById($elementId, null, $siteId)
            : null;
        $markdown = $guides->pageGuide($scan, $element, $siteId);

        return $this->_download(
            $markdown,
            $guides->filename($siteId, FixGuideService::SCOPE_PAGE, ScanTarget::label($scan, $element)),
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * The download response for a finished guide.
     *
     * `nosniff` because the file carries markup copied off the site's pages,
     * which no browser should be left to guess a type for.
     *
     * @param string $markdown The guide.
     * @param string $filename The filename to offer, already safe to sit
     *                         inside a quoted header value.
     * @return Response
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _download(string $markdown, string $filename): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/markdown; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->content = $markdown;

        return $response;
    }
}
