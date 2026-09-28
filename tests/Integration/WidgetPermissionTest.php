<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\widgets\AccessibilityScoreWidget;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The dashboard widget shows the same site summary every report screen gates
// behind the view permission. Craft's own isSelectable() only asks whether a
// widget may appear more than once, so without a check of its own anybody with
// a control panel login could put the site's accessibility score on their
// dashboard.
//
// Checked twice on purpose: adding it, and rendering it. A widget outlives the
// permission of whoever placed it.
// ---------------------------------------------------------------------------

describe('the accessibility score widget', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    });

    it('cannot be added by someone without the view permission', function() {
        $this->actingAs(UserFactory::factory()->create());

        expect(AccessibilityScoreWidget::isSelectable())->toBeFalse();
    });

    it('can be added by someone who may view reports', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        expect(AccessibilityScoreWidget::isSelectable())->toBeTrue();
    });

    it('shows nothing once the permission is gone, even if already placed', function() {
        // The widget is on the dashboard from when they could see reports.
        $this->actingAs(UserFactory::factory()->create());

        expect((new AccessibilityScoreWidget())->getBodyHtml())->toBeNull();
    });

    it('renders the summary for someone who may view reports', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        expect((new AccessibilityScoreWidget())->getBodyHtml())->toBeString();
    });

    it('reports the primary site on Standard, whatever site the CP is on', function() {
        // Standard scans the primary site and nothing else, so a non-primary
        // current site put an empty score on the dashboard for a site the
        // plugin had never looked at. Craft's current site is not gated by
        // edition; the plugin's own resolution is.
        $sites = Craft::$app->getSites();

        if (count($sites->getAllSites()) < 2) {
            $this->markTestSkipped('Needs a second site.');
        }

        $plugin = AccessibilityAudit::getInstance();
        $edition = $plugin->edition;
        $plugin->edition = AccessibilityAudit::EDITION_STANDARD;
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $primaryId = (int) $sites->getPrimarySite()->id;
        $other = null;

        foreach ($sites->getAllSites() as $site) {
            if ((int) $site->id !== $primaryId) {
                $other = $site;
                break;
            }
        }

        $sites->setCurrentSite($other);

        try {
            expect($plugin->requestedSiteId())->toBe($primaryId);
        } finally {
            // Both are process-wide and outlive the database rollback.
            $sites->setCurrentSite($sites->getPrimarySite());
            $plugin->edition = $edition;
        }
    });

    it('keeps the whole body inside a catch, so it cannot take the dashboard down', function() {
        // Craft calls getBodyHtml() with no catch of its own
        // (DashboardController::_getWidgetInfo), and actionIndex() loops every
        // widget through it. Anything thrown here is the whole dashboard
        // refusing to render, for every widget on it, leaving the reader unable
        // to reach the page that would let them remove this one.
        //
        // Asserted from the source: making getSiteSummary() throw needs the
        // plugin's tables broken, and DDL does not roll back with the test.
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/widgets/AccessibilityScoreWidget.php',
        );

        preg_match('/public function getBodyHtml\(\).*?\n    \}/s', $source, $m);
        $body = $m[0] ?? '';

        expect($body)->not->toBeEmpty();

        $tryAt = strpos($body, 'try {');
        $queryAt = strpos($body, 'getSiteSummary(');
        $renderAt = strpos($body, 'renderTemplate(');

        expect($tryAt)->not->toBeFalse()
            ->and($tryAt)->toBeLessThan($queryAt)
            ->and($tryAt)->toBeLessThan($renderAt)
            ->and($body)->toContain('catch (Throwable');
    });

    it('does not read the site straight off Craft', function() {
        // The widget resolving its own site is what let the edition gate and
        // the reader's editable sites be skipped.
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/widgets/AccessibilityScoreWidget.php',
        );

        expect($source)->not->toContain('getCurrentSite()')
            ->and($source)->toContain('requestedSiteId()');
    });
});

// ---------------------------------------------------------------------------
// The tile is one number for one site. Which site that is cannot be read off
// the figure, and on Standard it is not even the site the control panel is on.
// ---------------------------------------------------------------------------

describe('the accessibility score widget subtitle', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $sites = Craft::$app->getSites();
        $this->primary = $sites->getPrimarySite();
        $this->other = null;

        foreach ($sites->getAllSites() as $site) {
            if ((int)$site->id !== (int)$this->primary->id) {
                $this->other = $site;
                break;
            }
        }

        if ($this->other === null) {
            $this->markTestSkipped('Needs a second site.');
        }
    });

    it('names the site the figure is for', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        Craft::$app->getSites()->setCurrentSite($this->other);

        expect((new AccessibilityScoreWidget())->getSubtitle())->toBe($this->other->getName());
    });

    it('names the primary site on Standard, which is the site it is showing', function() {
        // The number on the tile is the primary site's whatever site the
        // control panel is on, so the label has to follow the number rather
        // than the reader. Naming the site being looked at would make the tile
        // read as a figure for that site, which is the misreading worth
        // closing.
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
        Craft::$app->getSites()->setCurrentSite($this->other);

        $widget = new AccessibilityScoreWidget();

        expect($widget->getSubtitle())->toBe($this->primary->getName())
            ->and($widget->getSubtitle())->not->toBe($this->other->getName());
    });
});
