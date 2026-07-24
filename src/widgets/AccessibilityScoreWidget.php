<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\widgets;

use Craft;
use craft\base\Widget;
use craft\errors\SiteNotFoundException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\assets\AccessibilityAuditAsset;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Dashboard widget displaying the site's overall accessibility score.
 *
 * @property-read null|string $bodyHtml
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class AccessibilityScoreWidget extends Widget
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('accessibility-audit', 'Accessibility Score');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return '@johnhenry/accessibilityaudit/icon-mask.svg';
    }

    /**
     * @inheritdoc
     * @throws SiteNotFoundException
     * @throws Exception
     * @throws InvalidConfigException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function getBodyHtml(): ?string
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $summary = AccessibilityAudit::getInstance()->audit->getSiteSummary($siteId);

        Craft::$app->getView()->registerAssetBundle(
            AccessibilityAuditAsset::class
        );

        return Craft::$app->getView()->renderTemplate(
            'accessibility-audit/_widgets/score',
            ['summary' => $summary]
        );
    }

    /**
     * @inheritdoc
     */
    public static function maxColspan(): ?int
    {
        return 1;
    }
}
