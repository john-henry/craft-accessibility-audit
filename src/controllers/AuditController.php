<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\errors\MissingComponentException;
use craft\errors\SiteNotFoundException;
use craft\helpers\Json;
use craft\web\Controller;
use DateTime;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\Csv;
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
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class AuditController extends Controller
{
    // Traits
    // =========================================================================

    use AxeResultsTrait;

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * POST /accessibility-audit/scan-entry
     * Triggers an immediate scan of an entry and returns JSON results.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws MethodNotAllowedHttpException
     * @throws ForbiddenHttpException|Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionScanEntry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        $elementId = (int) ($this->request->getBodyParam('entryId') ?: $this->request->getBodyParam('elementId'));
        $siteId = (int) ($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        $entry = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        // Scanning fetches the page and stores what is on it for anyone with
        // the reports to read, so it is limited to elements this user can view.
        // One answer for both, so the refusal doesn't say the element exists.
        if (!$entry || !Craft::$app->getElements()->canView($entry)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Element not found.')]);
        }

        // The Inspect page sends skipHeadless: its preview runs the browser
        // pass itself, so queueing the headless job too would set up a race
        // where two engines overwrite each other's findings on one scan.
        $withHeadless = !$this->request->getBodyParam('skipHeadless', false);

        $result = AccessibilityAudit::getInstance()->getAudit()->scanElement($entry, $withHeadless);

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

        // Nothing was scanned, so there is no result to reload into. Flagged
        // so the JS can say why rather than refreshing an unchanged page.
        if (!empty($result['excluded'])) {
            return $this->asJson([
                'success' => false,
                'excluded' => true,
                'error' => Craft::t('accessibility-audit', 'This page is excluded from scanning under Settings → Scanning → Excluded Pages.'),
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
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function actionScanUrl(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        $url = trim((string) $this->request->getBodyParam('url', ''));
        $siteId = (int) ($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        $audit = AccessibilityAudit::getInstance()->getAudit();

        if ($url === '' || !$audit->isKnownScanUrl($url, $siteId)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'That URL is not one this site scans.'),
            ]);
        }

        // Same reason as scan-entry: the Inspect page runs the browser pass
        // itself, so queueing the headless job too would have two engines
        // overwriting each other on the one scan.
        $withHeadless = !$this->request->getBodyParam('skipHeadless', false);

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
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionScanAll(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        // Multi-site is Pro: on Standard, a batch scan only ever covers the
        // primary site, whatever siteId is posted.
        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getBodyParam('siteId'));
        $audit = $plugin->getAudit();

        // A second sweep of the same site does the same work twice, competing
        // for the same pages and the same Chrome, and the reader gets no more
        // out of it than the first was already going to give them. Refused the
        // way a second readability run is.
        if ($audit->isSweepRunning($siteId)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Every page on this site is already being scanned. The report fills as it goes.'),
            ]);
        }

        // The configured URLs are swept alongside the elements, so they count
        // towards what was queued.
        $count = (int) $audit->getUrlElementsQuery($siteId)->count()
            + count($plugin->getSettings()->resolvedCustomUrls($siteId));

        // Flagged here rather than waiting for the job to start: the queue may
        // not pick it up for a while, and a second press in that gap would
        // otherwise queue a second sweep.
        Craft::$app->getCache()->set(AuditService::sweepKey($siteId), true, ScanElements::SWEEP_TTL);

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
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws Exception
     * @throws InvalidConfigException
     * @throws MissingComponentException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionScanAssets(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        $total = (int) AccessibilityAudit::getInstance()->getAssets()->imageQuery()->count();

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
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionStoreAxeResults(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        $audit = AccessibilityAudit::getInstance()->getAudit();
        $violations = $this->_arrayBodyParam('violations');
        // Contrast nodes axe couldn't measure. Optional: an older cached overlay
        // script posts violations alone and still works.
        $incomplete = $this->_arrayBodyParam('incomplete');

        ['scanId' => $scanId, 'elementId' => $elementId, 'siteId' => $siteId] = $this->_scanTarget();

        if (($refusal = $this->_refuseUnlessTargetAllowed($siteId, $scanId, $elementId, $element)) !== null) {
            return $refusal;
        }

        if ($scanId === 0 && $element !== null) {
            $scanId = $audit->ensureScan($elementId, $element::class, $siteId);
        }

        $summary = $this->storeAxeResults($audit, $scanId, $violations, $incomplete);

        return $this->asJson(['success' => true, 'scanId' => $scanId, 'scan' => $summary]);
    }

    /**
     * POST /accessibility-audit/store-contrast-results
     * Accepts client-side colour-contrast occurrences from the page-report iframe,
     * and the stylesheet rules it found removing the focus outline.
     *
     * The page always posts `focusRules`, as an empty list when it skipped the
     * check, so the stored rules are replaced each time. A request without it
     * comes from a report page cached before the check existed, and leaves the
     * stored rules alone.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionStoreContrastResults(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        $audit = AccessibilityAudit::getInstance()->getAudit();
        $occurrences = $this->_arrayBodyParam('occurrences');

        ['scanId' => $scanId, 'elementId' => $elementId, 'siteId' => $siteId] = $this->_scanTarget();

        if (($refusal = $this->_refuseUnlessTargetAllowed($siteId, $scanId, $elementId, $element)) !== null) {
            return $refusal;
        }

        if ($scanId === 0 && $element !== null) {
            $scanId = $audit->ensureScan($elementId, $element::class, $siteId);
        }

        if ($scanId === 0) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'No scan found.')]);
        }

        $viewport = $this->resolveViewport();
        $count = $audit->storeContrastIssues($scanId, $occurrences, $viewport);

        if ($this->request->getBodyParam('focusRules') !== null) {
            $count += $audit->storeFocusOutlineIssues($scanId, $this->_arrayBodyParam('focusRules'), $viewport);
        }

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionRestoreVerdicts(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        $ids = $this->request->getBodyParam('ids');
        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->resolveSiteId($this->request->getBodyParam('siteId'));

        // Which ids this may touch is decided by the service, not here: the
        // scoping is the authorisation, and it belongs with the operation so
        // every surface that restores a ruling is held to the same one.
        $restored = $plugin->getVerdicts()->restoreDismissedPotentials(
            $siteId,
            is_array($ids) ? $ids : [],
        );

        return $this->asJson(['success' => true, 'restored' => $restored]);
    }

    /**
     * Records the author's ruling on one potential issue.
     *
     * Dismissing keeps it out of the review queue; confirming promotes it to a
     * real failure that counts against the score. Passing an empty verdict
     * clears the ruling and puts the question back.
     *
     * Gated on run-scans rather than view-reports: this changes the score, so it
     * is an editorial act, not a read. `run-scans` is install-wide, so the
     * element is fenced as well, the same way as a store request.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws MethodNotAllowedHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionSetVerdict(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        $elementId = (int) $this->request->getBodyParam('elementId', 0);
        $ruleId = trim((string) $this->request->getBodyParam('ruleId', ''));
        $context = $this->request->getBodyParam('context');
        $note = trim((string) $this->request->getBodyParam('note', '')) ?: null;
        $siteId = (int) ($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_refuseUnlessTargetAllowed($siteId, 0, $elementId)) !== null) {
            return $refusal;
        }

        // A page with no element behind it names itself by URL, checked
        // against the scans table so a ruling cannot be filed against an
        // address this site never scanned.
        $url = $this->_reviewableUrl($siteId);

        // Only potential rules are reviewable: a definite failure is not a
        // question, and letting one be dismissed here would be a quiet way to
        // hide a real problem from the score.
        if (($elementId === 0 && $url === null) || !str_starts_with($ruleId, 'potential:')) {
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

        AccessibilityAudit::getInstance()->getVerdicts()->setVerdict(
            $siteId,
            $elementId ?: null,
            $ruleId,
            is_string($context) ? $context : null,
            $verdict !== '' ? $verdict : null,
            $note,
            $url,
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
     * Same gates as the single action: run-scans (an editorial act, not a
     * read), a page the user can view, potential rules only, and a verdict
     * from the known set. Clearing in bulk is what actionRestoreVerdicts() is
     * for, so an empty verdict is refused here.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws MethodNotAllowedHttpException
     * @throws SiteNotFoundException
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionSetVerdictsBulk(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

        $elementId = (int) $this->request->getBodyParam('elementId', 0);
        $siteId = (int) ($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        if (($refusal = $this->_refuseUnlessTargetAllowed($siteId, 0, $elementId)) !== null) {
            return $refusal;
        }

        $url = $this->_reviewableUrl($siteId);
        $verdict = trim((string) $this->request->getBodyParam('verdict', ''));

        if (($elementId === 0 && $url === null) || !in_array($verdict, VerdictService::VERDICTS, true)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Unknown verdict.'),
            ]);
        }

        $items = $this->_arrayBodyParam('items');

        $plugin = AccessibilityAudit::getInstance();
        $verdicts = $plugin->getVerdicts();
        $applied = 0;

        // Keyed by scan ID so repeats collapse as they arrive, rather than
        // growing a list that has to be merged and deduplicated after.
        $needScoring = [];

        // A ruling is a small write; the expensive part is working the scan's
        // score out again, and every occurrence in a group shares one scan. So
        // the scoring is held back and done once at the end rather than fifty
        // times over.
        try {
            foreach ($items as $item) {
                $ruleId = trim((string) ($item['ruleId'] ?? ''));

                if (!str_starts_with($ruleId, 'potential:')) {
                    continue;
                }

                $context = $item['context'] ?? null;
                $scanIds = $verdicts->setVerdict(
                    $siteId,
                    $elementId ?: null,
                    $ruleId,
                    is_string($context) ? $context : null,
                    $verdict,
                    null,
                    $url,
                    deferScoring: true,
                );

                foreach ($scanIds as $scanId) {
                    $needScoring[$scanId] = true;
                }

                $applied++;
            }

            foreach (array_keys($needScoring) as $scanId) {
                $plugin->getAudit()->recalculateScoreForScan($scanId);
            }
        } catch (Throwable $e) {
            // Whatever went wrong, the reader gets a sentence rather than a
            // blank error page. The detail goes to the log, where it can
            // actually be read, and the rulings written before the failure
            // stand: this is not one transaction and pretending otherwise
            // would lose the work that did land.
            Craft::error(
                'A11y: bulk verdict failed after ' . $applied . ' of ' . count($items) . ': '
                . $e->getMessage(),
                'accessibility-audit',
            );

            return $this->asJson([
                'success' => false,
                'applied' => $applied,
                'error' => Craft::t(
                    'accessibility-audit',
                    'Saved {applied} of {total} before something went wrong. The details are in the logs.',
                    ['applied' => $applied, 'total' => count($items)],
                ),
            ]);
        }

        return $this->asJson(['success' => true, 'applied' => $applied]);
    }

    /**
     * GET /accessibility-audit/export
     * Downloads CSV report.
     *
     * @return Response
     * @throws SiteNotFoundException
     * @throws ForbiddenHttpException
     * @throws HttpException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionExport(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $siteId = AccessibilityAudit::getInstance()->resolveSiteId($this->request->getQueryParam('siteId'));
        $csv = AccessibilityAudit::getInstance()->getReport()->exportCsv($siteId);

        return Csv::download(
            $csv,
            'accessibility-audit-' . (new DateTime())->format('Y-m-d') . '.csv',
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionScanEntries(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:run-scans');

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
            if ($element === null || !$elements->canView($element)) {
                continue;
            }
            AccessibilityAudit::getInstance()->getAudit()->scanElement($element);
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
     * A body param that arrives either as an array or as a JSON string.
     *
     * FormData cannot carry an array of objects, so the browser posts these
     * encoded. Json::decodeIfJson() hands back the original string when it is
     * not valid JSON, so is_array() is the real check rather than a truthiness
     * one, and anything unreadable comes back as an empty array.
     *
     * @param string $name The body param to read.
     * @return array<int|string, mixed> The decoded array, empty when unusable.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _arrayBodyParam(string $name): array
    {
        $raw = $this->request->getBodyParam($name, []);
        $decoded = is_array($raw) ? $raw : Json::decodeIfJson($raw);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The scan a store request is writing to, as posted.
     *
     * The scan may be named outright or reached through the element it belongs
     * to, and the site falls back to the primary one. Nothing here is trusted
     * yet: run it through _refuseUnlessTargetAllowed() before writing. No
     * element type is read, since the posted one could name any class; the
     * loaded element's own class is used instead.
     *
     * @return array{scanId: int, elementId: int, siteId: int}
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _scanTarget(): array
    {
        return [
            'scanId' => (int) $this->request->getBodyParam('scanId', 0),
            'elementId' => (int) $this->request->getBodyParam('elementId', 0),
            'siteId' => (int) ($this->request->getBodyParam('siteId')
                ?: Craft::$app->getSites()->getPrimarySite()->id),
        ];
    }

    /**
     * Refuses a store request or a ruling aimed at a site the edition or user
     * cannot write, or at an element the user cannot view.
     *
     * Both the posted site and, when a scan is named outright, the scan's own
     * site are fenced. A raw scanId writes against the scan's site rather than
     * the posted one, so checking only the posted site would let a user pair
     * their own allowed siteId with another site's scanId and slip findings
     * onto a site they cannot edit. The element is fenced the same way: the
     * scan's own element when a scan is named, the posted one otherwise.
     * `run-scans` is install-wide, so viewing the element is what scopes it,
     * as it does for scanning an entry.
     *
     * @param int $siteId The posted site.
     * @param int $scanId The posted scan, 0 when none was named.
     * @param int $elementId The posted element, 0 when none was named.
     * @param ElementInterface|null $element Set to the element the write
     *        targets once it has passed the fence, null when there is none.
     * @param-out ElementInterface|null $element
     * @return Response|null A JSON refusal, or null when the write may proceed.
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.1
     */
    private function _refuseUnlessTargetAllowed(int $siteId, int $scanId, int $elementId = 0, ?ElementInterface &$element = null): ?Response
    {
        $element = null;

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        if ($scanId > 0 && ($refusal = $this->_requireAllowedScanSite($scanId)) !== null) {
            return $refusal;
        }

        $audit = AccessibilityAudit::getInstance()->getAudit();
        $targetId = $scanId > 0 ? ($audit->getScanElementId($scanId) ?? 0) : $elementId;

        if ($targetId === 0) {
            return null;
        }

        $targetSiteId = $scanId > 0 ? ($audit->getScanSiteId($scanId) ?? $siteId) : $siteId;
        $elements = Craft::$app->getElements();
        $target = $elements->getElementById($targetId, null, $targetSiteId);

        if ($target === null || !$elements->canView($target)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('accessibility-audit', 'Element not found.')]);
        }

        $element = $target;

        return null;
    }

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.1
     */
    private function _requireAllowedScanSite(int $scanId): ?Response
    {
        $scanSiteId = AccessibilityAudit::getInstance()->getAudit()->getScanSiteId($scanId);

        if ($scanSiteId === null) {
            return null;
        }

        return $this->_requireAllowedSite($scanSiteId);
    }

    /**
     * The posted URL, if it names a page this site has actually scanned.
     *
     * A ruling filed against an arbitrary address would sit in the verdicts
     * table forever, matching nothing and answering nothing, so the address is
     * checked against the scans table before it is allowed to key one. Returns
     * null when nothing usable was posted, which leaves the element path to
     * the caller's own checks.
     *
     * @param int $siteId The site the ruling belongs to.
     * @return string|null The scanned URL, or null.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _reviewableUrl(int $siteId): ?string
    {
        $url = trim((string) $this->request->getBodyParam('url', ''));

        if ($url === '') {
            return null;
        }

        return AccessibilityAudit::getInstance()->getAudit()->isKnownScanUrl($url, $siteId) ? $url : null;
    }

    /**
     * Refuses a request aimed at a site the edition or the user cannot touch.
     *
     * Standard is primary-site only whatever is posted. On Pro the site must be
     * one the user may edit: the `run-scans` permission is install-wide, so a
     * crafted siteId would otherwise reach sites outside their permissions, and
     * the per-site fence has to happen here.
     *
     * @param int $siteId The posted site.
     * @return Response|null A JSON refusal, or null when the request may go on.
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.1
     */
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
        // outside their permissions. `run-scans` is install-wide, so the
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
