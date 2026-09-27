<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\assets;

use craft\web\AssetBundle;
use yii\web\View;

/**
 * The floating scan panel, injected on the live frontend for logged-in admins.
 *
 * axe-core is not in the bundle. It is 580KB, and an admin browsing the site is
 * not necessarily scanning it, so the panel fetches it on demand from the
 * plugin's own published copy: same origin, so a site's CSP needs no external
 * script-src. The address comes in with the rest of the overlay payload that
 * [[OverlayService]] builds.
 *
 * Nothing here depends on [[craft\web\assets\cp\CpAsset]], unlike the
 * plugin's other bundles: this one loads on the site, where the control
 * panel's stylesheet has no business being.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class FrontendAxeAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@johnhenry/accessibilityaudit/resources';

        $this->css = [
            'css/frontend-axe.css',
        ];

        $this->jsOptions = [
            'position' => View::POS_END,
        ];

        $this->js = [
            'js/accessibility-audit-shared.js',
            'js/frontend-axe.js',
        ];


        parent::init();
    }
}
