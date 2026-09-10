<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\helpers\ProjectConfig;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\migrations\m260910_120000_custom_url_rows;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The migration that rewrites Additional URLs from a string into rows.
//
// It runs once, against whatever a site happens to have stored, and there is
// no second chance to notice it went wrong: a migration that throws leaves the
// update half-applied, and one that writes the wrong shape puts URLs out of
// the scan silently. Both are worth a test given the setting is read on every
// sweep.
//
// Helpers are prefixed cum: Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** What is stored for the plugin, straight out of project config. */
function cumStored(): array
{
    return ProjectConfig::unpackAssociativeArrays(
        (array)Craft::$app->getProjectConfig()->get('plugins.accessibility-audit.settings'),
    );
}

/**
 * Writes the setting the way a site running 1.2.x has it stored.
 *
 * Straight into project config, because saving through the plugin would take
 * the string past the settings model, which converts it to rows on the way in
 * and so leaves nothing for the migration to find.
 */
function cumStoreString(string $value): void
{
    Craft::$app->getProjectConfig()
        ->set('plugins.accessibility-audit.settings.customUrls', $value);
}

beforeEach(function() {
    Craft::$app->getProjectConfig()->writeYamlAutomatically = false;
    Craft::$app->getConfig()->getGeneral()->allowAdminChanges = true;
    Craft::$app->getProjectConfig()->readOnly = false;

    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

describe('m260910_120000_custom_url_rows', function() {
    it('rewrites a stored string as rows', function() {
        cumStoreString("/search/results?q=craft\n# /parked\n/paginated/2");

        expect((new m260910_120000_custom_url_rows())->safeUp())->toBeTrue();

        expect(cumStored()['customUrls'])->toBe([
            ['enabled' => true, 'siteId' => '', 'url' => '/search/results?q=craft'],
            ['enabled' => false, 'siteId' => '', 'url' => '/parked'],
            ['enabled' => true, 'siteId' => '', 'url' => '/paginated/2'],
        ]);
    });

    it('leaves the sweep reaching the same URLs it did before', function() {
        cumStoreString("/a\n/b");

        (new m260910_120000_custom_url_rows())->safeUp();

        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        expect(AccessibilityAudit::getInstance()->getSettings()->resolvedCustomUrls($siteId))
            ->toBe(['/a', '/b']);
    });

    it('does nothing to a site that has none', function() {
        cumStoreString('');

        // Project config keeps no key for an empty value, so what matters is
        // that nothing is left stored as a string and no URL appears.
        expect((new m260910_120000_custom_url_rows())->safeUp())->toBeTrue()
            ->and(cumStored()['customUrls'] ?? [])->toBe([])
            ->and(AccessibilityAudit::getInstance()->getSettings()->resolvedCustomUrls())->toBe([]);
    });

    it('runs a second time without doing harm', function() {
        cumStoreString('/a');

        $migration = new m260910_120000_custom_url_rows();
        $migration->safeUp();
        $migration->safeUp();

        expect(cumStored()['customUrls'])->toBe([
            ['enabled' => true, 'siteId' => '', 'url' => '/a'],
        ]);
    });

    it('steps aside where project config cannot be written', function() {
        cumStoreString('/a');
        Craft::$app->getConfig()->getGeneral()->allowAdminChanges = false;

        try {
            expect((new m260910_120000_custom_url_rows())->safeUp())->toBeTrue()
                // Left as it was, and still read as a row by the model.
                ->and(cumStored()['customUrls'])->toBe('/a');
        } finally {
            Craft::$app->getConfig()->getGeneral()->allowAdminChanges = true;
            Craft::$app->getProjectConfig()->readOnly = false;
        }
    });
});
