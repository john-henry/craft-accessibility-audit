<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Shared edition gating for the plugin's JSON action controllers.
 *
 * Every edition-gated mutation/JSON endpoint routes its refusal through this
 * trait so the shape (`success`, `error`, `editionRequired`) and wording stay
 * identical across the gated feature areas, and a client can key off
 * `editionRequired` to tell "locked" apart from "failed". A refusal for a
 * Pro-only feature also carries `proRequired`, which the CI endpoint's
 * clients already read.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
trait ProGateTrait
{
    // Protected Methods
    // =========================================================================

    /**
     * The message a refusal carries: which edition the feature needs.
     *
     * @param string $feature The human-readable feature name (e.g. "CI/CD
     *                        integration").
     * @param string $edition The edition handle the feature needs.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    protected function editionRequiredMessage(string $feature, string $edition): string
    {
        return Craft::t('accessibility-audit', '{feature} requires the {edition} edition.', [
            'feature' => $feature,
            'edition' => AccessibilityAudit::editionName($edition),
        ]);
    }

    /**
     * Returns a JSON refusal when the active edition is not Pro, or null when
     * the request may proceed.
     *
     * @param string $feature The human-readable feature name, used to build the
     *                        refusal message (e.g. "CI/CD integration").
     * @return Response|null A refusal response, or null when on the Pro edition.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function requireProJson(string $feature): ?Response
    {
        if (AccessibilityAudit::getInstance()->isPro()) {
            return null;
        }

        return $this->_editionRefusal($feature, AccessibilityAudit::EDITION_PRO);
    }

    /**
     * Refuses a page or download request when the active edition is not Pro.
     *
     * For plain GET requests such as file downloads, where a JSON refusal
     * would land in the browser as a file of its own.
     *
     * @param string $feature The human-readable feature name.
     * @return void
     * @throws ForbiddenHttpException Below Pro.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    protected function requirePro(string $feature): void
    {
        if (AccessibilityAudit::getInstance()->isPro()) {
            return;
        }

        throw new ForbiddenHttpException($this->editionRequiredMessage($feature, AccessibilityAudit::EDITION_PRO));
    }

    /**
     * Returns a JSON refusal on Lite, or null when the request may proceed.
     *
     * @param string $feature The human-readable feature name, used to build the
     *                        refusal message (e.g. "Readability analysis").
     * @return Response|null A refusal response, or null on Plus or Pro.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    protected function requirePlusJson(string $feature): ?Response
    {
        if (AccessibilityAudit::getInstance()->isPlus()) {
            return null;
        }

        return $this->_editionRefusal($feature, AccessibilityAudit::EDITION_PLUS);
    }

    // Private Methods
    // =========================================================================

    /**
     * The JSON refusal for a feature the active edition lacks.
     *
     * @param string $feature The human-readable feature name.
     * @param string $edition The edition handle the feature needs.
     * @return Response
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _editionRefusal(string $feature, string $edition): Response
    {
        $body = [
            'success' => false,
            'error' => $this->editionRequiredMessage($feature, $edition),
            'editionRequired' => $edition,
        ];

        if ($edition === AccessibilityAudit::EDITION_PRO) {
            $body['proRequired'] = true;
        }

        return $this->asJson($body);
    }
}
