<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\assets;

use craft\web\AssetBundle;
use yii\web\View;

/**
 * Injected on the live frontend for logged-in admins.
 * Loads axe-core and the floating scan panel.
 *
 * @author JohnHenry <info@johnhenry.ie>
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
