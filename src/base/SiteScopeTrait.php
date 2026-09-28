<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\base;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\helpers\Cp;
use craft\models\Site;

/**
 * Which sites this install may act on.
 *
 * Standard is primary-site only, so a posted site id is never taken at face
 * value: every screen and endpoint resolves it through here, and a site the
 * edition or the user cannot reach is refused in one place rather than in each
 * caller. The edition check itself stays on the plugin class, which is what
 * carries the edition.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
trait SiteScopeTrait
{
    // Public Methods
    // =========================================================================

    /**
     * The sites the plugin may operate on for the active edition. Multi-site is
     * a Pro feature, so the Standard edition is limited to the primary site.
     *
     * Editable sites, not all sites: a user without permission for a site must
     * not be offered it in a switcher or shown its scan data. This mirrors
     * craft\helpers\Cp::siteMenuItems(), which defaults to the same list.
     *
     * @return Site[]
     * @throws SiteNotFoundException
     * @since 1.0.0
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function allowedSites(): array
    {
        $sites = Craft::$app->getSites();

        if ($this->isPro()) {
            return $sites->getEditableSites();
        }

        return [$sites->getPrimarySite()];
    }

    /**
     * The site the control panel is currently working with, gated by edition.
     *
     * Reads Craft's own `site` query param via Cp::requestedSite(), which
     * validates the handle against the user's editable sites and falls back to
     * the current site. Handles are used rather than a private `siteId` param
     * because site IDs are per-install auto-increments: a URL carrying one
     * means a different site on another environment, and Craft's own CP chrome
     * (Craft.siteId, the `requestedSite` Twig global) reads the `site` handle.
     *
     * Standard is primary-site only, so a `site` handle in the URL can't reach
     * another site's data on that edition.
     *
     * @return Site
     * @throws SiteNotFoundException
     * @since 1.0.0
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function requestedSite(): Site
    {
        $sites = Craft::$app->getSites();

        if (!$this->isPro()) {
            return $sites->getPrimarySite();
        }

        return Cp::requestedSite() ?? $sites->getPrimarySite();
    }

    /**
     * The ID of the site the control panel is currently working with.
     *
     * @return int
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     * @see requestedSite()
     */
    public function requestedSiteId(): int
    {
        return $this->requestedSite()->id;
    }

    /**
     * Resolves a requested site ID to one this edition is allowed to use.
     *
     * For callers that genuinely hold an integer: the AJAX endpoints and action
     * posts, whose URLs are rebuilt on every page load and never bookmarked.
     * Navigable CP pages use requestedSiteId() and the `site` handle instead.
     *
     * On Standard (single-site) this is always the primary site, so a
     * non-primary `siteId` in a request can't reach another site's data. On Pro
     * the requested site is honoured when it exists and the user may edit it,
     * otherwise the primary site is returned.
     *
     * @param mixed $requested The requested site ID (query/body param or int).
     * @return int
     * @throws SiteNotFoundException
     * @since 1.0.0
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function resolveSiteId(mixed $requested): int
    {
        $sites = Craft::$app->getSites();
        $primaryId = $sites->getPrimarySite()->id;

        if (!$this->isPro()) {
            return $primaryId;
        }

        $requestedId = (int) $requested;
        if ($requestedId === 0) {
            return $primaryId;
        }

        return in_array($requestedId, $sites->getEditableSiteIds(), true) ? $requestedId : $primaryId;
    }

    /**
     * Resolves a site for a front-end template.
     *
     * {@see resolveSiteId()} is for the control panel: on Pro it checks the
     * requested site against the reader's editable sites, which is a control
     * panel idea. A front-end visitor has no editable sites at all, so that
     * check answers "none of them" and hands back the primary site for every
     * request. A multi-site install then publishes the primary site's
     * accessibility statement on every one of its sites, which is the wrong
     * legal document on all but one.
     *
     * So the edition gate stands here, and the editable-sites check does not.
     * There is nothing to authorise: whoever wrote the template already decides
     * what the page says, and the site asked for is checked only for existing.
     *
     * @param mixed $requested A site ID, or null for the site being viewed.
     * @return int
     * @throws SiteNotFoundException
     * @since 1.5.0
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function publicSiteId(mixed $requested = null): int
    {
        $sites = Craft::$app->getSites();

        // Standard covers the primary site and nothing else, so a statement
        // asked for by any other site has nothing of its own to show.
        if (!$this->isPro()) {
            return $sites->getPrimarySite()->id;
        }

        $requestedId = (int)$requested;

        if ($requestedId > 0 && $sites->getSiteById($requestedId) !== null) {
            return $requestedId;
        }

        return $sites->getCurrentSite()->id;
    }

    /**
     * Whether the active edition and the current user are allowed to operate on
     * a given site.
     *
     * The boolean counterpart to resolveSiteId(): where that clamps an unknown
     * request back to the primary site, this reports a plain yes/no so a caller
     * can refuse outright rather than silently retarget. On Standard only the
     * primary site is allowed (multi-site is Pro); on Pro the site must be one
     * the current user may actually edit, so a crafted ID can't reach a site
     * outside their permissions.
     *
     * @param int $siteId The site ID to test.
     * @return bool
     * @throws SiteNotFoundException
     * @since 1.0.1
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     */
    public function isSiteAllowed(int $siteId): bool
    {
        $sites = Craft::$app->getSites();

        if (!$this->isPro()) {
            return $siteId === $sites->getPrimarySite()->id;
        }

        return in_array($siteId, $sites->getEditableSiteIds(), true);
    }
}
