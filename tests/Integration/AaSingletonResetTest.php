<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// The plugin, its settings and its edition are process-level singletons, and
// RefreshesDatabase rolls back the database and nothing else. Without the
// restore in Pest.php, a test that changes one of those changes it for every
// test collected after it, in whatever order the files happen to land. A test
// that reads the edition to decide whether to skip then reports as a pass.
//
// The current site is the same kind of state: refreshSites() drops the site
// list and the editable-site answer but leaves it alone, so a test that
// switches site leaves every test after it on that site.
//
// The file is named to sort early, so the pairs below run before most of the
// suite: the first test makes a mess on purpose, and the second has to find it
// cleaned up.
// ---------------------------------------------------------------------------

describe('plugin singleton state between tests', function() {
    it('lets a test change the edition and the settings', function() {
        // Note for anyone tempted to remove the restore in Pest.php: it is not
        // only tidiness. SettingsController::actionSave() reads each field
        // with the model's current value as its default, so a settings-saving
        // test running after this one, in a process where these values were
        // still sitting in the model, would write them to project config on
        // disk. The restore below closes that window before the next test.
        $plugin = AccessibilityAudit::getInstance();

        $plugin->edition = AccessibilityAudit::EDITION_STANDARD;
        $plugin->getSettings()->chromePath = '/nonexistent/binary';
        $plugin->getSettings()->targetScore = 3;

        expect($plugin->edition)->toBe(AccessibilityAudit::EDITION_STANDARD)
            ->and($plugin->getSettings()->chromePath)->toBe('/nonexistent/binary')
            ->and($plugin->getSettings()->targetScore)->toBe(3);
    });

    it('hands the next test the state it started with', function() {
        // Nothing above is undone by a database rollback, so if this passes it
        // is the restore in Pest.php doing it.
        $plugin = AccessibilityAudit::getInstance();
        $pristine = pristinePluginState();

        expect($pristine)->not->toBeNull()
            ->and($plugin->edition)->toBe($pristine['edition'])
            ->and($plugin->getSettings()->chromePath)->toBe($pristine['settings']['chromePath'])
            ->and($plugin->getSettings()->targetScore)->toBe($pristine['settings']['targetScore'])
            ->and($plugin->getSettings()->chromePath)->not->toBe('/nonexistent/binary');
    });

    it('keeps the two deliberate normalisations on top of the restore', function() {
        // The dev project config is not the state a test should assume.
        $settings = AccessibilityAudit::getInstance()->getSettings();

        expect($settings->excludedVolumes)->toBe([])
            ->and($settings->scannedElementTypes)->toBeNull();
    });

    it('lets a test switch the current site, deliberately leaving it switched', function() {
        // No finally on purpose. Whether the next test starts clean is the
        // thing under test, and a test that tidies up after itself would prove
        // nothing about a test that forgets to.
        $sites = Craft::$app->getSites();
        $primary = (int) $sites->getPrimarySite()->id;
        $other = null;

        foreach ($sites->getAllSites() as $site) {
            if ((int) $site->id !== $primary) {
                $other = $site;
                break;
            }
        }

        if ($other === null) {
            $this->markTestSkipped('Needs a second site.');
        }

        $sites->setCurrentSite($other);

        expect((int) $sites->getCurrentSite()->id)->toBe((int) $other->id);
    });

    it('hands the next test the site it started on', function() {
        $pristine = pristinePluginState();

        expect((int) Craft::$app->getSites()->getCurrentSite()->id)->toBe($pristine['siteId']);
    });
});
