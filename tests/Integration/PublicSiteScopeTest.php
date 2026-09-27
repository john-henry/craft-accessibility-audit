<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\variables\AccessibilityVariable;

// ---------------------------------------------------------------------------
// The front-end variables resolve their site differently from the control
// panel, and they have to.
//
// resolveSiteId() checks the requested site against the reader's editable
// sites, which only means something to somebody logged into the control panel.
// A visitor has none, so that check answered "none of them" and handed back the
// primary site for every request: a multi-site install published the primary
// site's accessibility statement on every one of its sites. The statement is a
// legal declaration about the site a visitor is actually reading, so that is
// the wrong document everywhere but one.
// ---------------------------------------------------------------------------

/** The second site, or null where the install has only one. */
function secondSiteId(): ?int
{
    $sites = Craft::$app->getSites();
    $primary = (int) $sites->getPrimarySite()->id;

    foreach ($sites->getAllSites() as $site) {
        if ((int) $site->id !== $primary) {
            return (int) $site->id;
        }
    }

    return null;
}

beforeEach(function() {
    $this->editionBefore = AccessibilityAudit::getInstance()->edition;
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    Craft::$app->getUser()->switchIdentity(null);
});

afterEach(function() {
    // Process-wide, so it outlives the database rollback.
    AccessibilityAudit::getInstance()->edition = $this->editionBefore;
});

it('gives a visitor the site they asked for, not the primary one', function() {
    $other = secondSiteId();

    if ($other === null) {
        $this->markTestSkipped('Needs a second site.');
    }

    expect(AccessibilityAudit::getInstance()->publicSiteId($other))->toBe($other);
});

it('falls back to the site being viewed when none is named', function() {
    $other = secondSiteId();

    if ($other === null) {
        $this->markTestSkipped('Needs a second site.');
    }

    $sites = Craft::$app->getSites();
    $sites->setCurrentSite($sites->getSiteById($other));

    try {
        expect(AccessibilityAudit::getInstance()->publicSiteId())->toBe($other);
    } finally {
        $sites->setCurrentSite($sites->getPrimarySite());
    }
});

it('refuses a site id that names nothing', function() {
    expect(AccessibilityAudit::getInstance()->publicSiteId(999999))
        ->toBe((int) Craft::$app->getSites()->getCurrentSite()->id);
});

it('still holds Standard to the primary site', function() {
    $other = secondSiteId();

    if ($other === null) {
        $this->markTestSkipped('Needs a second site.');
    }

    $plugin = AccessibilityAudit::getInstance();
    $plugin->edition = AccessibilityAudit::EDITION_STANDARD;

    // Standard scans the primary site and nothing else, so another site has
    // nothing of its own to publish.
    expect($plugin->publicSiteId($other))->toBe((int) Craft::$app->getSites()->getPrimarySite()->id);
});

it('reports the statement of the site being read, not the primary one', function() {
    $other = secondSiteId();

    if ($other === null) {
        $this->markTestSkipped('Needs a second site.');
    }

    $plugin = AccessibilityAudit::getInstance();
    $variable = new AccessibilityVariable();

    // Distinct product names, so the one that comes back names its own site.
    saveVpatMetaFlat((int) Craft::$app->getSites()->getPrimarySite()->id, ['productName' => 'Primary Site']);
    saveVpatMetaFlat($other, ['productName' => 'Second Site']);

    $statement = $variable->accessibilityStatement($other);

    expect($statement['meta']['productName'] ?? null)->toBe('Second Site');
});

it('keeps the control panel resolver on editable sites', function() {
    // The two are deliberately different. This one still refuses a site the
    // reader may not edit, which is what it is for.
    $other = secondSiteId();

    if ($other === null) {
        $this->markTestSkipped('Needs a second site.');
    }

    expect(AccessibilityAudit::getInstance()->resolveSiteId($other))
        ->toBe((int) Craft::$app->getSites()->getPrimarySite()->id);
});
