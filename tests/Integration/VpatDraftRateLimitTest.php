<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\controllers\VpatController;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Drafting a VPAT remark makes a paid call to the AI service, the same as
// generating alt text does, and for a long time only the alt-text one was
// capped. The permission to draft is not permission to spend without limit: a
// looped call, or a page left open with a script in it, runs the site's credit
// down with nothing in the way.
//
// The cap is generous by design, so these drive the counter directly rather
// than making thirty real calls. Helpers are uniquely named: Pest loads every
// test file into one process.
// ---------------------------------------------------------------------------

/** The counter's cache key for a user in the current window. */
function vpatDraftRateKey(int $userId): string
{
    $slot = (int) floor(time() / VpatController::DRAFT_RATE_WINDOW);

    return "accessibility-audit:vpat-draft-rate:{$userId}:{$slot}";
}

/**
 * Seeds a user's counter for the current window and the next one.
 *
 * The counter is keyed by a fixed window, so seeding only the window the test
 * starts in leaves it failing whenever the run crosses the boundary between
 * seeding and asking. Filling both costs nothing and cannot race.
 */
function seedVpatDraftRate(int $userId, int $count): void
{
    $slot = (int) floor(time() / VpatController::DRAFT_RATE_WINDOW);

    foreach ([$slot, $slot + 1] as $window) {
        Craft::$app->getCache()->set(
            "accessibility-audit:vpat-draft-rate:{$userId}:{$window}",
            $count,
            VpatController::DRAFT_RATE_WINDOW * 2,
        );
    }
}

describe('VpatController::actionDraftRemark rate limit', function() {
    beforeEach(function() {
        // Drafting is Pro-gated, and the gate answers before the counter does.
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

        $this->draftUser = UserFactory::factory()->admin(true)->create();
        $this->actingAs($this->draftUser);
        $this->draftSiteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    });

    afterEach(function() {
        // The counter lives in the cache, which no test transaction rolls back,
        // and the seeder fills the next window as well as this one.
        $slot = (int) floor(time() / VpatController::DRAFT_RATE_WINDOW);

        foreach ([$slot, $slot + 1] as $window) {
            Craft::$app->getCache()->delete(
                "accessibility-audit:vpat-draft-rate:{$this->draftUser->id}:{$window}"
            );
        }
    });

    it('refuses once the window allowance is spent', function() {
        seedVpatDraftRate((int) $this->draftUser->id, VpatController::DRAFT_RATE_LIMIT);

        $json = $this->postJson('actions/accessibility-audit/vpat/draft-remark', [
            'siteId' => $this->draftSiteId,
            'criterion' => '1.4.3',
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['error'])->toContain('Too many drafts');
    });

    it('lets a call under the allowance through the rate gate', function() {
        // One prior draft this window, well under the cap. The call clears the
        // gate and fails further on for want of an API key, never on the
        // counter, which is what proves an ordinary draft is not caught.
        seedVpatDraftRate((int) $this->draftUser->id, 1);

        $json = $this->postJson('actions/accessibility-audit/vpat/draft-remark', [
            'siteId' => $this->draftSiteId,
            'criterion' => '1.4.3',
        ])->getJsonContent();

        expect($json['error'] ?? '')->not->toContain('Too many drafts');
    });

    it('counts each draft, so a run of them reaches the cap', function() {
        // No seeding: the counter starts empty and the requests themselves
        // fill it, which is what pins that the gate increments rather than
        // only reading.
        $key = vpatDraftRateKey((int) $this->draftUser->id);
        Craft::$app->getCache()->delete($key);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('actions/accessibility-audit/vpat/draft-remark', [
                'siteId' => $this->draftSiteId,
                'criterion' => '1.4.3',
            ]);
        }

        expect((int) Craft::$app->getCache()->get($key))->toBe(2);
    });
});
