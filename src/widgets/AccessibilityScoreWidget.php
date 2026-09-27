<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\widgets;

use Craft;
use craft\base\Widget;
use craft\errors\SiteNotFoundException;
use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\assets\AccessibilityAuditAsset;
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Dashboard widget displaying the site's overall accessibility score.
 *
 * @property-read null|string $bodyHtml
 * @property-read null|string $subtitle
 *
 * @author John Henry Donovan <info@johnhenry.ie>
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
     *
     * Craft asks only whether the widget may appear more than once. The figures
     * this one shows are the same ones every report screen gates behind the
     * view permission, so it is asked for here too: without this, anybody with
     * a control panel login can put the site's accessibility score on their
     * dashboard.
     */
    public static function isSelectable(): bool
    {
        return parent::isSelectable()
            && Craft::$app->getUser()->checkPermission('accessibility-audit:view-reports');
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
        // Checked again at render: the widget outlives the permission. Someone
        // who placed it and later lost the permission still has it sitting on
        // their dashboard, and isSelectable() is only asked when adding one.
        if (!Craft::$app->getUser()->checkPermission('accessibility-audit:view-reports')) {
            return null;
        }

        // A dashboard is a page full of other people's widgets, and Craft calls
        // this without a catch of its own: anything thrown here is the whole
        // dashboard refusing to render, for every widget on it. Somebody whose
        // scan tables are half-built, mid-install or mid-migration, would be
        // left unable to reach the page that would let them remove this tile.
        try {
            // Resolved through the plugin rather than read off Craft directly,
            // the way every other control-panel screen does it. Craft's current
            // site is not gated by edition or by what the reader may edit: on
            // Standard, where only the primary site is ever scanned, a
            // non-primary current site put an empty score on the dashboard for
            // a site the plugin had never looked at.
            $plugin = AccessibilityAudit::getInstance();
            $summary = $plugin->getAudit()->getSiteSummary($plugin->requestedSiteId());

            Craft::$app->getView()->registerAssetBundle(
                AccessibilityAuditAsset::class
            );

            // The mode is stated rather than inherited, as the controllers
            // state it: a widget is rendered by whoever is drawing the
            // dashboard, and resolving a control-panel template depends on
            // being in that mode.
            return Craft::$app->getView()->renderTemplate(
                'accessibility-audit/_widgets/score',
                ['summary' => $summary],
                View::TEMPLATE_MODE_CP,
            );
        } catch (Throwable $e) {
            Craft::error(
                'A11y: the score widget could not be drawn: ' . $e->getMessage(),
                'accessibility-audit',
            );

            return null;
        }
    }

    /**
     * @inheritdoc
     *
     * The figure below is for one site, and on a multi-site install the tile
     * has nothing else on it that says which. It is not always the site the
     * control panel is on either: on Standard only the primary site is ever
     * scanned, so a reader sitting on a second site is shown the primary
     * site's score. Naming the site the number belongs to is the difference
     * between that and a wrong number.
     *
     * Guarded on its own account. Craft asks for this after the body, outside
     * the catch below, and a throw here takes the dashboard down with it.
     */
    public function getSubtitle(): ?string
    {
        if (!Craft::$app->getIsMultiSite()) {
            return null;
        }

        try {
            $plugin = AccessibilityAudit::getInstance();

            return Craft::$app->getSites()->getSiteById($plugin->requestedSiteId())?->getName();
        } catch (Throwable $e) {
            Craft::error(
                'A11y: the score widget could not name its site: ' . $e->getMessage(),
                'accessibility-audit',
            );

            return null;
        }
    }

    /**
     * @inheritdoc
     */
    public static function maxColspan(): ?int
    {
        return 1;
    }
}
