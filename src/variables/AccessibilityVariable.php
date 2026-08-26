<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\variables;

use Craft;
use craft\elements\Entry;
use craft\errors\SiteNotFoundException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\Icons;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Markup;
use yii\base\InvalidConfigException;
use yii\db\Exception;

/**
 * Exposes accessibility scan data to Twig templates via `craft.a11y`.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class AccessibilityVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Get the latest scan for an entry.
     * Usage: {{ craft.a11y.scan(entry) }}
     *
     * @param Entry $entry The entry to look up.
     * @return array|null
     */
    public function scan(Entry $entry): ?array
    {
        return AccessibilityAudit::getInstance()->audit->getLatestScan($entry->id, $entry->siteId);
    }

    /**
     * Get all issues for an entry.
     * Usage: {% for issue in craft.a11y.issues(entry) %}
     *
     * @param Entry $entry The entry to look up.
     * @return array
     */
    public function issues(Entry $entry): array
    {
        return AccessibilityAudit::getInstance()->audit->getElementIssues($entry->id, $entry->siteId);
    }

    /**
     * Whether the active edition is Pro.
     * Usage: {% if craft.a11y.isPro %}
     *
     * @return bool
     */
    public function isPro(): bool
    {
        return AccessibilityAudit::getInstance()->isPro();
    }

    /**
     * Site-wide summary stats.
     * Usage: {{ craft.a11y.summary().avgScore }}
     *
     * @param int|null $siteId The site to summarise, or null for the current site.
     * @return array
     * @throws SiteNotFoundException
     */
    public function summary(?int $siteId = null): array
    {
        if ($siteId === null) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }
        return AccessibilityAudit::getInstance()->audit->getSiteSummary($siteId);
    }

    /**
     * One of the plugin's shared inline SVG icons, as safe markup.
     * Usage: {{ craft.a11y.icon('check') }}
     *
     * The same markup is injected into window.AccessibilityAudit.icons for the
     * JS-rendered paths, so Twig and JS output cannot drift.
     *
     * @param string $name The icon handle (pass, fail, check).
     * @return Markup
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function icon(string $name): Markup
    {
        return new Markup(Icons::get($name), 'UTF-8');
    }

    /**
     * The decoupled overlay loader script tag, with the CMS origin resolved
     * from the current request rather than hardcoded, so one template works
     * across every environment.
     * Usage: {{ craft.a11y.overlayScriptTag() }}
     *
     * Renders nothing on the Standard edition or when the decoupled overlay
     * is disabled, so the tag can sit in a shared layout unconditionally.
     * Only useful in Craft-rendered templates (a hybrid install); a decoupled
     * frontend can't run Twig and uses the raw tag from the docs instead.
     *
     * @return Markup
     * @throws InvalidConfigException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function overlayScriptTag(): Markup
    {
        $plugin = AccessibilityAudit::getInstance();

        if (!$plugin->isPro() || !$plugin->getSettings()->decoupledOverlay) {
            return new Markup('', 'UTF-8');
        }

        $src = $plugin->getOverlay()->absoluteFromRequest('/accessibility-audit/overlay.js');

        return new Markup(
            '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '" defer></script>',
            'UTF-8',
        );
    }

    /**
     * The axe-core tag list the browser engines scan with, derived from the
     * plugin's WCAG level and EN 301 549 settings.
     * Usage: {% set axeTags = craft.a11y.axeTags() %}
     *
     * @return string[]
     * @throws InvalidConfigException
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function axeTags(): array
    {
        return AccessibilityAudit::getInstance()->getAudit()->getAxeTags();
    }

    /**
     * The full VPAT conformance report for a site, ready for rendering in a
     * template (an accessibility statement page, say). Returns the same
     * structure the CP editor and HTML export use: `meta`, `levelA`, `levelAA`
     * (each criterion carrying `effectiveLevel` / `effectiveRemarks`), and
     * `hasScanData`. Returns null on the Standard edition, where VPAT
     * reporting is not available.
     *
     * `meta` holds the editor's product details:
     * `productName`, `productDescription`, `productVersion`, `reportDate`,
     * `reportPeriodFrom`, `reportPeriodTo`, `contactName`, `contactEmail`,
     * `contactPhone`, `notes`, `evalMethodology`, plus `evalMethods` and
     * `scopePages` (both string lists) and `legalDisclaimer`. Any the editor
     * left blank are simply absent, so read them with a `??` fallback.
     *
     * Usage:
     *   {% set report = craft.a11y.vpatReport() %}
     *   {% if report %}
     *       {% for num, row in report.levelA %} ... {% endfor %}
     *   {% endif %}
     *
     * @param int|null $siteId The site to report on, or null for the current site.
     * @return array|null
     * @throws SiteNotFoundException
     * @throws Exception
     * @throws \Exception
     */
    public function vpatReport(?int $siteId = null): ?array
    {
        $plugin = AccessibilityAudit::getInstance();

        if (!$plugin->isPro()) {
            return null;
        }

        if ($siteId === null) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        return $plugin->vpat->getFullReport($plugin->resolveSiteId($siteId));
    }

    /**
     * The site's accessibility statement, as data.
     *
     * Use this when you want to lay the statement out yourself. For the
     * ready-made markup, see {@see accessibilityStatementHtml()}.
     *
     * @param int|null $siteId The site, or null for the current one.
     * @return array<string, mixed>|null
     * @throws SiteNotFoundException
     * @throws Exception
     * @throws \Exception
     */
    public function accessibilityStatement(?int $siteId = null): ?array
    {
        $plugin = AccessibilityAudit::getInstance();

        if ($siteId === null) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        return $plugin->statement->getFullStatement($plugin->resolveSiteId($siteId));
    }

    /**
     * The site's accessibility statement, rendered as markup ready to drop into
     * a page.
     *
     * A statement has to be publicly reachable to be worth anything, so this is
     * the path most sites will take: put it on an /accessibility page and link
     * it from the footer. The built-in markup is an unstyled fragment that
     * inherits the surrounding page; point the Statement Template setting at
     * your own template to replace it entirely.
     *
     * The statement's own title renders as an `h1` by default. Where it is
     * dropped into a page that already has one, pass a `headingLevel` so it
     * nests under the page's heading instead of competing with it; the
     * subheadings step down from whatever level you set. `title` replaces the
     * title text:
     *
     * ```twig
     * {{ craft.a11y.accessibilityStatementHtml(null, {
     *     headingLevel: 2,
     *     title: 'How accessible this site is',
     * }) }}
     * ```
     *
     * @param int|null $siteId The site, or null for the current one.
     * @param array<string, mixed> $options `headingLevel` (1 to 6) and `title`.
     * @return Markup|null
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws \yii\base\Exception
     * @throws \Exception
     */
    public function accessibilityStatementHtml(?int $siteId = null, array $options = []): ?Markup
    {
        $statement = $this->accessibilityStatement($siteId);

        if ($statement === null) {
            return null;
        }

        $plugin = AccessibilityAudit::getInstance();
        $resolved = $plugin->resolveSiteId($siteId ?? Craft::$app->getSites()->getCurrentSite()->id);

        return new Markup($plugin->statement->render($resolved, $options), Craft::$app->charset);
    }
}
