<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use Exception;
use johnhenry\accessibilityaudit\services\AuditService;

/**
 * Shared handling for the two surfaces that post browser findings back.
 *
 * The control panel's own store endpoint and the token-authenticated overlay
 * one receive the same axe payload and answer with the same repainted summary.
 * They differ in how the caller is authorised and nowhere else, so the storing
 * and the bucketing live here: a desktop finding filed under mobile by one
 * surface and not the other is a difference nobody would go looking for.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
trait AxeResultsTrait
{
    // Protected Methods
    // =========================================================================

    /**
     * Stores a browser pass's findings and hands back the scan's new summary.
     *
     * The summary goes back with the response so the overlay repaints with the
     * authoritative combined score rather than its own axe-only estimate.
     *
     * @param AuditService $audit The audit service.
     * @param int $scanId The scan to write against, 0 where there is none.
     * @param array<int, array<string, mixed>> $violations Axe's violations.
     * @param array<int, array<string, mixed>> $incomplete Axe's incomplete results.
     * @return array<string, mixed>|null The repainted summary, or null where
     *         there was no scan to write to.
     * @throws \yii\db\Exception
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    protected function storeAxeResults(AuditService $audit, int $scanId, array $violations, array $incomplete): ?array
    {
        if ($scanId <= 0) {
            return null;
        }

        $audit->storeAxeIssues($scanId, $violations, $this->resolveViewport(), $incomplete);
        $scan = $audit->getScanSummary($scanId);

        if ($scan === null) {
            return null;
        }

        return [
            'score' => (int)$scan['score'],
            'errorCount' => (int)$scan['errorCount'],
            'warningCount' => (int)$scan['warningCount'],
            'noticeCount' => (int)$scan['noticeCount'],
            'scannedLabel' => Craft::$app->getFormatter()->asDatetime($scan['dateScanned'], 'short'),
        ];
    }

    /**
     * The viewport bucket a browser-sourced store request belongs to.
     *
     * Fixed-width engines (the Inspect preview) post an explicit `viewport`
     * bucket; the overlay posts its actual window width as `viewportWidth`
     * and the bucket is derived from it. Anything else defaults to desktop,
     * matching the pre-multi-viewport behaviour.
     *
     * @return string One of the AuditService::VIEWPORT_* constants.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    protected function resolveViewport(): string
    {
        $viewport = (string)$this->request->getBodyParam('viewport', '');

        if (in_array($viewport, [AuditService::VIEWPORT_DESKTOP, AuditService::VIEWPORT_MOBILE], true)) {
            return $viewport;
        }

        return AuditService::viewportForWidth((int)$this->request->getBodyParam('viewportWidth', 0));
    }
}
