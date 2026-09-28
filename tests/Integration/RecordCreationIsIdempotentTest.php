<?php

use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\OrganisationMetaModel;
use johnhenry\accessibilityaudit\services\StatementService;
use johnhenry\accessibilityaudit\services\VpatService;

// ---------------------------------------------------------------------------
// The statement, VPAT and organisation records are created on demand, and the
// read paths create them too. Two requests arriving together both find the row
// missing, and siteId is unique on all three tables, so the second insert used
// to fail in front of whoever happened to be second.
//
// Writing twice in a row is the same shape as two writers racing, minus the
// timing. If these ever go back to checking and then inserting, the second
// call throws here.
// ---------------------------------------------------------------------------

/** Rows held for a site in one of the plugin's per-site tables. */
function rowCountFor(string $table, int $siteId): int
{
    return (int) (new Query())->from($table)->where(['siteId' => $siteId])->count();
}

describe('creating a per-site record', function() {
    beforeEach(function() {
        $this->recSiteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    });

    it('can be asked for twice without the second attempt failing', function(string $service, string $table) {
        $method = new ReflectionMethod($service, '_createRecord');
        $method->setAccessible(true);
        $instance = new $service();

        $method->invoke($instance, $this->recSiteId);
        $method->invoke($instance, $this->recSiteId);

        expect(rowCountFor($table, $this->recSiteId))->toBe(1);
    })->with([
        'statement' => [StatementService::class, '{{%accessibilityaudit_statement}}'],
        'vpat' => [VpatService::class, '{{%accessibilityaudit_vpat}}'],
    ]);

    it('keeps one organisation row however often it is saved', function() {
        $organisation = AccessibilityAudit::getInstance()->getOrganisation();

        $first = new OrganisationMetaModel();
        $first->contactEmail = 'first@example.com';
        $organisation->saveMeta($this->recSiteId, $first);

        $second = new OrganisationMetaModel();
        $second->contactEmail = 'second@example.com';
        $organisation->saveMeta($this->recSiteId, $second);

        expect(rowCountFor('{{%accessibilityaudit_organisation}}', $this->recSiteId))->toBe(1);

        // The later save has to win: an upsert that inserted but did not
        // update would leave the first address in place and pass the count.
        expect($organisation->getMeta($this->recSiteId)['contactEmail'] ?? null)->toBe('second@example.com');
    });
});
