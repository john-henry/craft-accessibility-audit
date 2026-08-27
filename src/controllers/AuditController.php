<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\errors\MissingComponentException;
use craft\errors\SiteNotFoundException;
use craft\helpers\Json;
use craft\web\Controller;
use DateTime;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\jobs\AuditAssets;
use johnhenry\accessibilityaudit\jobs\ScanElements;
use johnhenry\accessibilityaudit\services\AuditService;
use johnhenry\accessibilityaudit\services\VerdictService;
use Throwable;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * Handles on-demand and queued accessibility scans, and stores client-side
 * axe-core and contrast results.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class AuditController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * POST /accessibility-audit/scan-entry
     * Triggers an immediate scan of an entry and returns JSON results.
     *
     * @throws SiteNotFoundException
     * @throws MethodNotAllowedHttpException
     * @throws ForbiddenHttpException
     */
    public function actionScanEntry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:runScans');

        $elementId = (int) ($this->request->getBodyParam('entryId') ?: $this->request->getBodyParam('elementId'));
        $siteId = (int) ($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        $entry = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
        if (!$entry) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Element not found.')]);
        }

        // The Inspect page sends skipHeadless: its preview runs the browser
        // pass itself, so queueing the headless job too would set up a race
        // where two engines overwrite each other's findings on one scan.
        $withHeadless = !(bool) $this->request->getBodyParam('skipHeadless', false);

        $result = AccessibilityAudit::getInstance()->audit->scanElement($entry, $withHeadless);

        // Standard edition hit the distinct-page cap for a brand-new page.
        // Surface it distinctly so the sidebar JS can show an upgrade message
        // instead of a generic failure.
        if (!empty($result['limitReached'])) {
            return $this->asJson([
                'success' => true,
                'limitReached' => true,
                'limit' => AuditService::STANDARD_SCAN_LIMIT,
            ]);
        }

        return $this->asJson([
            'success' => true,
            'scanId' => $result['scanId'],
            'score' => $result['score'],
            'issueCount' => count($result['issues']),
            'errorCount' => count(array_filter($result['issues'], static fn($i) => $i->severity === 'error')),
            'warningCount' => count(array_filter($result['issues'], static fn($i) => $i->severity === 'warning')),
            'noticeCount' => count(array_filter($result['issues'], static fn($i) => $i->severity === 'notice')),
            'issues' => array_map(static fn($i) => [
                'ruleId' => $i->ruleId,
                'severity' => $i->severity,
                'message' => $i->message,
                'wcagCriterion' => $i->wcagCriterion,
                'wcagLevel' => $i->wcagLevel,
                'context' => $i->context,
                'helpUrl' => $i->helpUrl,
            ], $result['issues']),
        ]);
    }

    /**
     * POST /accessibility-audit/scan-url
     * Re-scans a page that has no element behind it and returns JSON results.
     *
     * The URL is not taken on trust. This action fetches server-side, so an
     * arbitrary posted address would make the site a proxy for reaching
     * whatever the server can reach. Only a URL the admin already listed under
     * Settings, or one already scanned for this site, is accepted; anything
     * else is refused rather than fetched.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws MethodNotAllowedHttpException
     * @throws ForbiddenHttpException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function actionScanUrl(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:runScans');

        $url = trim((string) $this->request->getBodyParam('url', ''));
        $siteId = (int) ($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        $audit = AccessibilityAudit::getInstance()->audit;

        if ($url === '' || !$audit->isKnownScanUrl($url, $siteId)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'That URL is not one this site scans.'),
            ]);
        }

        // Same reason as scan-entry: the Inspect page runs the browser pass
        // itself, so queueing the headless job too would have two engines
        // overwriting each other on the one scan.
        $withHeadless = !(bool) $this->request->getBodyParam('skipHeadless', false);

        $result = $audit->scanUrl($url, $siteId, $withHeadless);

        if (!empty($result['limitReached'])) {
            return $this->asJson([
                'success' => true,
                'limitReached' => true,
                'limit' => AuditService::STANDARD_SCAN_LIMIT,
            ]);
        }

        if (!empty($result['error'])) {
            return $this->asJson(['success' => false, 'error' => $result['error']]);
        }

        return $this->asJson([
            'success' => true,
            'scanId' => $result['scanId'],
            'score' => $result['score'],
        ]);
    }

    /**
     * POST /accessibility-audit/scan-all
     * Queues a single batched background scan for all published elements with URLs.
     *
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionScanAll(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:runScans');

        // Multi-site is Pro: on Standard, a batch scan only ever covers the
        // primary site, whatever siteId is posted.
        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getBodyParam('siteId'));
        $audit = AccessibilityAudit::getInstance()->audit;
        $count = (int) $audit->getUrlElementsQuery($siteId)->count();

        // Single batched job: the batch runner walks the result set in
        // memory-safe chunks instead of spawning one job per element.
        Craft::$app->getQueue()->push(new ScanElements([
            'siteId' => $siteId,
        ]));

        return $this->asJson([
            'success' => true,
            'queued' => $count,
        ]);
    }

    /**
     * POST /accessibility-audit/scan-assets
     * Queues a batched sweep of every image asset's alt text.
     *
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws Exception
     * @throws InvalidConfigException
     * @throws MissingComponentException
     */
    public function actionScanAssets(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:runScans');

        $total = (int) Asset::find()->kind(Asset::KIND_IMAGE)->count();

        // Clear rows left behind by hard-deleted or bulk-removed images before
        // the fresh sweep repopulates, so the stored table stays tidy.
        AccessibilityAudit::getInstance()->getAssets()->pruneOrphanedAssetIssues();

        Craft::$app->getQueue()->push(new AuditAssets());

        Craft::$app->getSession()->setNotice(Craft::t(
            'accessibility-audit',
            'Asset scan queued: {n} images will be audited as the queue runs.',
            ['n' => $total]
        ));

        return $this->redirectToPostedUrl();
    }

    /**
     * POST /accessibility-audit/store-axe-results
     * Accepts axe-core violations from the frontend overlay.
     *
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \Exception
     */
    public function actionStoreAxeResults(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:runScans');

        $audit = AccessibilityAudit::getInstance()->audit;
        $scanId = (int) $this->request->getBodyParam('scanId', 0);
        // The overlay posts violations as a JSON string (FormData can't carry an
        // array of objects). decodeIfJson() returns the original string when it
        // isn't valid JSON, so guard with is_array(), not a truthiness check.
        $rawViolations = $this->request->getBodyParam('violations', []);
        $decodedViolations = is_array($rawViolations) ? $rawViolations : Json::decodeIfJson($rawViolations);
        $violations = is_array($decodedViolations) ? $decodedViolations : [];
        // Contrast nodes axe couldn't measure, posted the same way. Optional:
        // an older cached overlay script posts violations alone and still works.
        $rawIncomplete = $this->request->getBodyParam('incomplete', []);
        $decodedIncomplete = is_array($rawIncomplete) ? $rawIncomplete : Json::decodeIfJson($rawIncomplete);
        $incomplete = is_array($decodedIncomplete) ? $decodedIncomplete : [];
        $elementId = (int) $this->request->getBodyParam('elementId', 0);
        $elementType = (string) $this->request->getBodyParam('elementType', '');
        $siteId = (int) ($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        // A raw scanId writes against the scan's own site, not the posted one, so
        // fence that site too: otherwise a user could post their own allowed
        // siteId alongside another site's scanId and slip findings onto a site
        // they can't edit.
        if ($scanId > 0 && ($refusal = $this->_requireAllowedScanSite($scanId)) !== null) {
            return $refusal;
        }

        if ($scanId === 0 && $elementId > 0) {
            $scanId = $audit->ensureScan($elementId, $elementType, $siteId);
        }

        // Return the recalculated summary so the overlay can repaint with the
        // authoritative combined score rather than its own axe-only estimate.
        $summary = null;
        if ($scanId > 0) {
            $audit->storeAxeIssues($scanId, $violations, $this->_resolveViewport(), $incomplete);
            $scan = $audit->getScanSummary($scanId);
            if ($scan !== null) {
                $summary = [
                    'score' => (int)$scan['score'],
                    'errorCount' => (int)$scan['errorCount'],
                    'warningCount' => (int)$scan['warningCount'],
                    'noticeCount' => (int)$scan['noticeCount'],
                    'scannedLabel' => Craft::$app->getFormatter()->asDatetime($scan['dateScanned'], 'short'),
                ];
            }
        }

        return $this->asJson(['success' => true, 'scanId' => $scanId, 'scan' => $summary]);
    }

    /**
     * POST /accessibility-audit/store-contrast-results
     * Accepts client-side colour-contrast occurrences from the page-report iframe.
     *
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \Exception
     */
    public function actionStoreContrastResults(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:runScans');

        $audit = AccessibilityAudit::getInstance()->audit;
        $scanId = (int) $this->request->getBodyParam('scanId', 0);
        $elementId = (int) $this->request->getBodyParam('elementId', 0);
        $elementType = (string) $this->request->getBodyParam('elementType', '');
        $siteId = (int) ($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        // A raw scanId writes against the scan's own site, not the posted one,
        // so fence that site too (see actionStoreAxeResults).
        if ($scanId > 0 && ($refusal = $this->_requireAllowedScanSite($scanId)) !== null) {
            return $refusal;
        }
        // Same decode-and-guard as store-axe-results: decodeIfJson() hands back
        // the original string for invalid JSON, so is_array() is the real check.
        $raw = $this->request->getBodyParam('occurrences', '[]');
        $decodedOccurrences = is_array($raw) ? $raw : Json::decodeIfJson($raw);
        $occurrences = is_array($decodedOccurrences) ? $decodedOccurrences : [];

        if ($scanId === 0 && $elementId > 0) {
            $scanId = $audit->ensureScan($elementId, $elementType, $siteId);
        }

        if ($scanId === 0) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'No scan found.')]);
        }

        $count = $audit->storeContrastIssues($scanId, $occurrences, $this->_resolveViewport());

        return $this->asJson(['success' => true, 'scanId' => $scanId, 'stored' => $count]);
    }

    /**
     * Clears the verdict on a set of dismissed potential issues at once.
     *
     * Takes issue IDs, because that is what the table's checkboxes select, and
     * resolves each back to the element, rule and markup the verdict is keyed
     * to. Restoring one at a time from a per-page report is fine for a stray
     * decision; it is no use at all when a check turns out to have been
     * over-firing and forty of them need taking back.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws SiteNotFoundException
     * @throws Exception
     * @throws \Exception
     */
    public function actionRestoreVerdicts(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:runScans');

        $ids = $this->request->getBodyParam('ids');
        $ids = is_array($ids) ? array_map('intval', $ids) : [];

        if (empty($ids)) {
            return $this->asJson(['success' => true, 'restored' => 0]);
        }

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getBodyParam('siteId'));

        // Scoped to the resolved site and to dismissed potentials: an id from
        // another site, or one that is not a dismissal, has no business being
        // cleared by this action whatever the request asks for.
        $rows = (new Query())
            ->select(['elementId', 'ruleId', 'context'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where([
                'id' => $ids,
                'siteId' => $siteId,
                'verdict' => VerdictService::VERDICT_DISMISSED,
            ])
            ->andWhere(['like', 'ruleId', 'potential:%', false])
            ->all();

        $verdicts = AccessibilityAudit::getInstance()->verdicts;

        foreach ($rows as $row) {
            $verdicts->setVerdict(
                $siteId,
                (int) $row['elementId'],
                (string) $row['ruleId'],
                $row['context'] !== null ? (string) $row['context'] : null,
                // Null clears the ruling and puts the question back.
                null,
            );
        }

        return $this->asJson(['success' => true, 'restored' => count($rows)]);
    }

    /**
     * Records the author's ruling on one potential issue.
     *
     * Dismissing keeps it out of the review queue; confirming promotes it to a
     * real failure that counts against the score. Passing an empty verdict
     * clears the ruling and puts the question back.
     *
     * Gated on runScans rather than viewReports: this changes the score, so it
     * is an editorial act, not a read.
     *
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws MethodNotAllowedHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     */
    public function actionSetVerdict(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:runScans');

        $elementId = (int) $this->request->getBodyParam('elementId', 0);
        $ruleId = trim((string) $this->request->getBodyParam('ruleId', ''));
        $context = $this->request->getBodyParam('context');
        $note = trim((string) $this->request->getBodyParam('note', '')) ?: null;
        $siteId = (int) ($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        // Only potential rules are reviewable: a definite failure is not a
        // question, and letting one be dismissed here would be a quiet way to
        // hide a real problem from the score.
        if ($elementId === 0 || !str_starts_with($ruleId, 'potential:')) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'That issue cannot be reviewed.'),
            ]);
        }

        $verdict = trim((string) $this->request->getBodyParam('verdict', ''));
        if ($verdict !== '' && !in_array($verdict, VerdictService::VERDICTS, true)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Unknown verdict.'),
            ]);
        }

        AccessibilityAudit::getInstance()->verdicts->setVerdict(
            $siteId,
            $elementId,
            $ruleId,
            is_string($context) ? $context : null,
            $verdict !== '' ? $verdict : null,
            $note,
        );

        return $this->asJson(['success' => true, 'verdict' => $verdict !== '' ? $verdict : null]);
    }

    /**
     * Records the same ruling on a set of potential issues at once.
     *
     * Exists for the page whose review queue is one judgment repeated: fifty
     * sticky-nav links flagged for the same unmeasurable background deserve
     * one decision and one click, not fifty page reloads. Takes rule and
     * context pairs rather than issue ids because that is what the review
     * cards carry, and it is the pair a ruling is keyed to.
     *
     * Same gates as the single action: runScans (an editorial act, not a
     * read), potential rules only, and a verdict from the known set. Clearing
     * in bulk is what actionRestoreVerdicts() is for, so an empty verdict is
     * refused here.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws MethodNotAllowedHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     */
    public function actionSetVerdictsBulk(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:runScans');

        $elementId = (int) $this->request->getBodyParam('elementId', 0);
        $siteId = (int) ($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        $verdict = trim((string) $this->request->getBodyParam('verdict', ''));
        if ($elementId === 0 || !in_array($verdict, VerdictService::VERDICTS, true)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Unknown verdict.'),
            ]);
        }

        // Same decode-and-guard as the store endpoints: decodeIfJson() hands
        // back the original string for invalid JSON, so is_array() is the check.
        $raw = $this->request->getBodyParam('items', '[]');
        $decoded = is_array($raw) ? $raw : Json::decodeIfJson($raw);
        $items = is_array($decoded) ? $decoded : [];

        $verdicts = AccessibilityAudit::getInstance()->verdicts;
        $applied = 0;

        foreach ($items as $item) {
            $ruleId = trim((string) ($item['ruleId'] ?? ''));
            if (!str_starts_with($ruleId, 'potential:')) {
                continue;
            }

            $context = $item['context'] ?? null;
            $verdicts->setVerdict(
                $siteId,
                $elementId,
                $ruleId,
                is_string($context) ? $context : null,
                $verdict,
            );
            $applied++;
        }

        return $this->asJson(['success' => true, 'applied' => $applied]);
    }

    /**
     * GET /accessibility-audit/export
     * Downloads CSV report.
     *
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws HttpException
     */
    public function actionExport(): Response
    {
        $this->requirePermission('accessibility-audit:viewReports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getQueryParam('siteId'));
        $csv = AccessibilityAudit::getInstance()->report->exportCsv($siteId);

        return $this->response->sendContentAsFile(
            $csv,
            'accessibility-audit-' . (new DateTime())->format('Y-m-d') . '.csv',
            ['mimeType' => 'text/csv']
        );
    }

    /**
     * Re-scans every selected page for Craft's VueAdminTable bulk action.
     *
     * The table posts `{ids: [...], siteId: n}` in one request and reloads
     * itself as soon as it returns, so the scans run inline here rather than
     * being queued: a queued batch would come back before anything had changed
     * and the table would refresh showing the old scores. Selection is capped at
     * the table's page size, so the inline loop stays short.
     *
     * The tables register this as a menu sub-action rather than a lone action
     * button: sub-actions are the only ones whose `param`/`value` Craft
     * forwards, which is how `siteId` gets here, and the menu branch binds the
     * click reliably across supported Craft versions.
     *
     * See the matching note in resources/js/accessibility-audit-table.js.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws SiteNotFoundException
     * @throws Throwable
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionScanEntries(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:runScans');

        $siteId = (int) ($this->request->getParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        $ids = $this->request->getBodyParam('ids');
        $ids = is_array($ids) ? $ids : [];

        $scanned = 0;
        $elements = Craft::$app->getElements();

        foreach ($ids as $id) {
            $element = $elements->getElementById((int) $id, null, $siteId);
            if ($element === null) {
                continue;
            }
            AccessibilityAudit::getInstance()->audit->scanElement($element);
            $scanned++;
        }

        return $this->asJson([
            'success' => true,
            'scanned' => $scanned,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves the viewport bucket a browser-sourced store request belongs to.
     *
     * Fixed-width engines (the Inspect preview) post an explicit `viewport`
     * bucket; the overlay posts its actual window width as `viewportWidth`
     * and the bucket is derived from it. Anything else defaults to desktop,
     * matching the pre-multi-viewport behaviour.
     *
     * @return string One of the AuditService::VIEWPORT_* constants.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _resolveViewport(): string
    {
        $viewport = (string) $this->request->getBodyParam('viewport', '');

        if (in_array($viewport, [AuditService::VIEWPORT_DESKTOP, AuditService::VIEWPORT_MOBILE], true)) {
            return $viewport;
        }

        return AuditService::viewportForWidth((int) $this->request->getBodyParam('viewportWidth', 0));
    }

    /**
     * Refuses a scan/store request that targets a non-primary site on the
     * Standard edition, where multi-site is a Pro feature. Returns a JSON
     * refusal to send back, or null when the site is allowed. Refusing (rather
     * than silently re-targeting the primary site) avoids attaching one site's
     * results to another site's scan record.
     *
     * @param int $siteId The requested site ID.
     * @return Response|null
     * @throws SiteNotFoundException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    /**
     * Refuses a store request whose `scanId` targets a site the edition or user
     * is not allowed to write. Loads the scan's own site and runs it through the
     * same editable-site fence as the posted siteId. Returns a JSON refusal to
     * send back, null when the scan's site is allowed (or the scan is unknown,
     * in which case the store call itself is a no-op).
     *
     * @param int $scanId The requested scan ID.
     * @return Response|null
     * @throws SiteNotFoundException
     * @since 1.0.1
     * @author JohnHenry <info@johnhenry.ie>
     */
    private function _requireAllowedScanSite(int $scanId): ?Response
    {
        $scanSiteId = AccessibilityAudit::getInstance()->audit->getScanSiteId($scanId);

        if ($scanSiteId === null) {
            return null;
        }

        return $this->_requireAllowedSite($scanSiteId);
    }

    private function _requireAllowedSite(int $siteId): ?Response
    {
        $plugin = AccessibilityAudit::getInstance();
        $sites = Craft::$app->getSites();

        // Standard is primary-site only, whatever siteId is posted.
        if (!$plugin->isPro() && $siteId !== $sites->getPrimarySite()->id) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Multi-site scanning requires the Pro edition.'),
            ]);
        }

        // On Pro, the posted site must be one the user may actually edit, so a
        // crafted siteId can't trigger a scan of, or write findings to, a site
        // outside their permissions. `runScans` is install-wide, so the
        // per-site fence has to be enforced here.
        if ($plugin->isPro() && !in_array($siteId, $sites->getEditableSiteIds(), true)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'You do not have permission to scan that site.'),
            ]);
        }

        return null;
    }
}
