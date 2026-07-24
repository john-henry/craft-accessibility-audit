<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\assets;

use craft\web\AssetBundle;

/**
 * Control panel asset bundle for the utilities screen.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class UtilitiesAsset extends AssetBundle
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

        $this->js = [
            'js/utilities.js',
        ];

        parent::init();
    }
}
