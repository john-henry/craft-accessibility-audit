<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\assets;

use craft\web\AssetBundle;

/**
 * Control panel asset bundle for the vpat screen.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class VpatAsset extends AssetBundle
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
            AccessibilityAuditAsset::class,
        ];

        $this->css = [
            'css/vpat.css',
        ];

        $this->js = [
            'js/vpat.js',
        ];

        parent::init();
    }
}
