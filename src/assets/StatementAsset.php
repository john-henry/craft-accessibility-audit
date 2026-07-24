<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\assets;

use craft\web\AssetBundle;

/**
 * Asset bundle for the accessibility statement editor.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class StatementAsset extends AssetBundle
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
            'js/statement.js',
        ];

        parent::init();
    }
}
