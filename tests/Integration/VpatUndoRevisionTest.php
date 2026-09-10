<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\VpatService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Undoing a recorded revision.
//
// Without it, recording is a one-way door: press the button to see what it
// does and the report carries a revision nobody was ever given. Only the
// latest one can go, and that is the line worth pinning down. A history
// somebody can lift a row out of the middle of is not a history.
//
// Helpers are prefixed vur: Pest loads every test file into one process, and
// these deliberately do not lean on the ones in the sibling revision test.
// ---------------------------------------------------------------------------

/** The VPAT service. */
function vurVpat(): VpatService
{
    return AccessibilityAudit::getInstance()->vpat;
}

/** The primary site's id. */
function vurSiteId(): int
{
    return (int)Craft::$app->getSites()->getPrimarySite()->id;
}

/** Clears every revision held for the site. */
function vurClear(int $siteId): void
{
    Craft::$app->getDb()->createCommand()
        ->delete('{{%accessibilityaudit_vpat_revisions}}', ['siteId' => $siteId])
        ->execute();
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

    resetVpat(vurSiteId());
    vurClear(vurSiteId());
});

describe('VpatService::deleteLatestRevision', function() {
    it('removes the one just recorded', function() {
        vurVpat()->saveOverride(vurSiteId(), '1.1.1', 'Supports', '');
        vurVpat()->recordRevision(vurSiteId());

        expect(vurVpat()->countRevisions(vurSiteId()))->toBe(1)
            ->and(vurVpat()->deleteLatestRevision(vurSiteId()))->toBeTrue()
            ->and(vurVpat()->countRevisions(vurSiteId()))->toBe(0);
    });

    it('takes the newest and leaves the ones before it', function() {
        vurVpat()->saveOverride(vurSiteId(), '1.1.1', 'Supports', '');
        vurVpat()->recordRevision(vurSiteId());

        vurVpat()->saveOverride(vurSiteId(), '1.3.3', 'Does Not Support', '');
        vurVpat()->recordRevision(vurSiteId());

        expect(vurVpat()->countRevisions(vurSiteId()))->toBe(2);

        vurVpat()->deleteLatestRevision(vurSiteId());

        expect(vurVpat()->countRevisions(vurSiteId()))->toBe(1);

        // The survivor is the first one, so its answers are what a fresh
        // recording is compared against: unchanged, and refused.
        vurVpat()->saveOverride(vurSiteId(), '1.3.3', '', '');
        expect(vurVpat()->recordRevision(vurSiteId()))->toBeFalse();
    });

    it('says so plainly when there is nothing to remove', function() {
        expect(vurVpat()->countRevisions(vurSiteId()))->toBe(0)
            ->and(vurVpat()->deleteLatestRevision(vurSiteId()))->toBeFalse();
    });

    it('lets the same answers be recorded again once the revision is gone', function() {
        vurVpat()->saveOverride(vurSiteId(), '1.1.1', 'Supports', '');

        expect(vurVpat()->recordRevision(vurSiteId()))->toBeTrue()
            // Nothing changed in between, so a second press records nothing.
            ->and(vurVpat()->recordRevision(vurSiteId()))->toBeFalse();

        vurVpat()->deleteLatestRevision(vurSiteId());

        expect(vurVpat()->recordRevision(vurSiteId()))->toBeTrue();
    });
});

describe('The undo action', function() {
    it('removes the latest revision', function() {
        vurVpat()->saveOverride(vurSiteId(), '1.1.1', 'Supports', '');
        vurVpat()->recordRevision(vurSiteId());

        $this->post('actions/accessibility-audit/vpat/delete-latest-revision', [
            'siteId' => vurSiteId(),
        ])->assertOk()->assertJson(['success' => true, 'removed' => true]);

        expect(vurVpat()->countRevisions(vurSiteId()))->toBe(0);
    });

    it('reports nothing removed rather than failing', function() {
        $this->post('actions/accessibility-audit/vpat/delete-latest-revision', [
            'siteId' => vurSiteId(),
        ])->assertOk()->assertJson(['success' => true, 'removed' => false]);
    });

    it('refuses somebody without the VPAT permission', function() {
        $user = UserFactory::factory()->create();

        Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, [
            'accesscp',
            'accessplugin-accessibility-audit',
            'accessibility-audit:viewReports',
        ]);

        $this->actingAs($user);

        expect(fn() => $this->post('actions/accessibility-audit/vpat/delete-latest-revision', [
            'siteId' => vurSiteId(),
        ]))->toThrow(yii\web\ForbiddenHttpException::class);
    });
});

describe('Addressing a revision by id', function() {
    it('lists them newest first', function() {
        vurVpat()->saveOverride(vurSiteId(), '1.1.1', 'Supports', '');
        vurVpat()->recordRevision(vurSiteId());

        vurVpat()->saveOverride(vurSiteId(), '1.3.3', 'Does Not Support', '');
        vurVpat()->recordRevision(vurSiteId());

        $revisions = vurVpat()->getRevisions(vurSiteId());

        expect($revisions)->toHaveCount(2)
            ->and($revisions[0]['id'])->toBeGreaterThan($revisions[1]['id'])
            ->and($revisions[0]['answers'])->toBeGreaterThan(0);
    });

    it('removes the one named and leaves the rest', function() {
        vurVpat()->saveOverride(vurSiteId(), '1.1.1', 'Supports', '');
        vurVpat()->recordRevision(vurSiteId());

        vurVpat()->saveOverride(vurSiteId(), '1.3.3', 'Does Not Support', '');
        vurVpat()->recordRevision(vurSiteId());

        $oldest = vurVpat()->getRevisions(vurSiteId())[1]['id'];

        expect(vurVpat()->deleteRevision($oldest, vurSiteId()))->toBeTrue()
            ->and(vurVpat()->countRevisions(vurSiteId()))->toBe(1)
            ->and(vurVpat()->getRevisions(vurSiteId())[0]['id'])->not->toBe($oldest);
    });

    it('will not reach a revision belonging to another site', function() {
        vurVpat()->saveOverride(vurSiteId(), '1.1.1', 'Supports', '');
        vurVpat()->recordRevision(vurSiteId());

        $id = vurVpat()->getRevisions(vurSiteId())[0]['id'];

        // A real id, the wrong site: guessing at numbers gets you nowhere.
        expect(vurVpat()->deleteRevision($id, vurSiteId() + 999))->toBeFalse()
            ->and(vurVpat()->countRevisions(vurSiteId()))->toBe(1);
    });

    it('clears the lot and leaves the report itself alone', function() {
        vurVpat()->saveOverride(vurSiteId(), '1.1.1', 'Supports', 'Checked by hand.');
        vurVpat()->recordRevision(vurSiteId());

        vurVpat()->saveOverride(vurSiteId(), '1.3.3', 'Does Not Support', '');
        vurVpat()->recordRevision(vurSiteId());

        expect(vurVpat()->deleteAllRevisions(vurSiteId()))->toBe(2)
            ->and(vurVpat()->countRevisions(vurSiteId()))->toBe(0);

        $overrides = vurVpat()->getRecord(vurSiteId())['overrides'];

        expect($overrides['1.1.1']['level'] ?? null)->toBe('Supports')
            ->and($overrides['1.1.1']['remarks'] ?? null)->toBe('Checked by hand.');
    });

    it('reports nothing removed when the site has none', function() {
        expect(vurVpat()->deleteAllRevisions(vurSiteId()))->toBe(0);
    });
});
