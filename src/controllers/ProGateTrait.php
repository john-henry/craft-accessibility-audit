<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use yii\web\Response;

/**
 * Shared Pro-edition gating for the plugin's JSON action controllers.
 *
 * Every Pro-only mutation/JSON endpoint routes its refusal through this trait
 * so the shape (`success`, `error`, `proRequired`) and wording stay identical
 * across the five gated feature areas, and the client JS can key off
 * `proRequired` to tell "locked" apart from "failed".
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
trait ProGateTrait
{
    // Protected Methods
    // =========================================================================

    /**
     * Returns a JSON refusal when the active edition is not Pro, or null when
     * the request may proceed.
     *
     * @param string $feature The human-readable feature name, used to build the
     *                        refusal message (e.g. "CI/CD integration").
     * @return Response|null A refusal response, or null when on the Pro edition.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function requireProJson(string $feature): ?Response
    {
        if (AccessibilityAudit::getInstance()->isPro()) {
            return null;
        }

        return $this->asJson([
            'success' => false,
            'error' => Craft::t('accessibility-audit', '{feature} requires the Pro edition.', ['feature' => $feature]),
            'proRequired' => true,
        ]);
    }
}
