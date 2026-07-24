<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Control panel asset bundle for the Accessibility Audit plugin.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class AccessibilityAuditAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@johnhenry/accessibilityaudit/resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/accessibility-audit-tokens.css',
            'css/accessibility-audit.css',
        ];
        $this->js = [
            'js/accessibility-audit-shared.js',
            'js/accessibility-audit-table.js',
            'js/cp.js',
        ];


        parent::init();
    }
}
