<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\assets;

use craft\web\AssetBundle;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\OverlayService;
use yii\web\View;

/**
 * The static loader that brings the front-end overlay to pages served from a
 * full-page cache.
 *
 * Registered on every site page while the overlay setting is on, for every
 * visitor, so the tag is identical whoever the page was rendered for and a
 * cached copy of it is harmless. Its only boot data, the session config
 * endpoint and the marker cookie's name, ride on the script tag as data
 * attributes and are the same for everyone.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.6.0
 */
class FrontendOverlayLoaderAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@johnhenry/accessibilityaudit/resources';

        $this->js = [
            'js/frontend-overlay-loader.js',
        ];

        $this->jsOptions = [
            'position' => View::POS_END,
            'data-config-url' => AccessibilityAudit::getInstance()->getOverlay()->sessionConfigUrl(),
            'data-marker' => OverlayService::MARKER_COOKIE,
        ];

        parent::init();
    }
}
