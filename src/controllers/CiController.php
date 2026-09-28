<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\web\Controller;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Exposes a token-authenticated endpoint that CI/CD pipelines can poll to gate
 * a deploy on a site's current accessibility score.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class CiController extends Controller
{
    // Traits
    // =========================================================================

    use ProGateTrait;

    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['check'];

    // Public Methods
    // =========================================================================

    /**
     * Returns the current accessibility summary for a site and whether it meets
     * the configured target score. Authenticated with a bearer token so it can
     * run from a CI pipeline without a CP session.
     *
     * @return Response
     * @throws BadRequestHttpException|SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionCheck(): Response
    {
        $this->requireAcceptsJson();

        // Check the edition before touching the token so we never leak whether a
        // token would otherwise be valid: on Standard this is simply unavailable.
        if (($refusal = $this->requireProJson('CI/CD integration')) !== null) {
            return $refusal;
        }

        $settings = AccessibilityAudit::getInstance()->getSettings();
        // Settings stores only the SHA-256 hash of the token (see
        // SettingsController::actionGenerateCiToken), so hash the presented
        // token and compare digests. No App::parseEnv: the stored value is a
        // raw hash the plugin generated, never an environment reference.
        $configuredHash = trim((string)($settings->ciApiToken ?? ''));
        $providedToken = $this->_resolveToken();

        if ($configuredHash === '' || $providedToken === '' || !hash_equals($configuredHash, hash('sha256', $providedToken))) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Invalid or missing token'),
            ])->setStatusCode(401);
        }

        $siteId = (int) $this->request->getQueryParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id;

        // A site that does not exist summarises as nothing scanned, which
        // scores zero, which fails the target. A pipeline reading that sees a
        // failing audit when what it actually has is a wrong site id in its
        // own configuration. Answered as the bad request it is, so the two
        // cannot be mistaken for each other.
        if (Craft::$app->getSites()->getSiteById($siteId) === null) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('accessibility-audit', 'Unknown site.'),
            ])->setStatusCode(400);
        }

        $summary = AccessibilityAudit::getInstance()->getAudit()->getSiteSummary($siteId);
        $score = (int) $summary['avgScore'];
        $targetScore = (int) $settings->targetScore;

        // A target of 0 turns the gate off: nothing to fail against, so the
        // check always passes but still reports the score. targetConfigured
        // separates that from a genuine pass, since a pipeline wired to a
        // disabled target is green no matter how bad the site gets.
        $passing = $targetScore === 0 || $score >= $targetScore;

        return $this->asJson([
            'success' => true,
            'passing' => $passing,
            'targetConfigured' => $targetScore > 0,
            'score' => $score,
            'threshold' => $targetScore,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads the CI token from the Authorization bearer header (preferred) or the
     * `ciToken` query parameter (fallback).
     *
     * The param is deliberately NOT named `token`: Craft reserves that query
     * param (generalConfig tokenParam) for its own preview/asset tokens and
     * validates it during application boot, so a `?token=` request 400s with
     * Craft's "Invalid token" before this controller ever runs.
     *
     * @return string
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _resolveToken(): string
    {
        $header = (string) $this->request->getHeaders()->get('Authorization', '');

        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return trim((string) $this->request->getQueryParam('ciToken', ''));
    }
}
