<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\controllers\VpatController;
use johnhenry\accessibilityaudit\services\VpatService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// How much a single draft request can spend.
//
// The notes go into the prompt and the prompt is paid for by the character.
// The rate limit on this action bounds how often somebody can spend; without a
// bound on the payload, each of those thirty calls a minute can carry as much
// text as the request cares to send.
// ---------------------------------------------------------------------------

describe('drafting a VPAT remark', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $this->draftSiteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    });

    it('sends no more of your notes than the cap allows', function() {
        $spy = new class extends VpatService {
            public ?string $seenNotes = null;

            public function draftRemark(int $siteId, string $criterion, string $level = '', string $notes = ''): array
            {
                $this->seenNotes = $notes;

                return ['success' => true, 'remark' => ''];
            }
        };

        AccessibilityAudit::getInstance()->set('vpat', $spy);

        $this->postJson('actions/accessibility-audit/vpat/draft-remark', [
            'siteId' => $this->draftSiteId,
            'criterion' => '1.4.3',
            'notes' => str_repeat('a', VpatController::DRAFT_NOTES_MAX + 5000),
        ]);

        expect($spy->seenNotes)->not->toBeNull()
            ->and(mb_strlen($spy->seenNotes))->toBe(VpatController::DRAFT_NOTES_MAX);
    });

    it('passes ordinary notes through untouched', function() {
        $spy = new class extends VpatService {
            public ?string $seenNotes = null;

            public function draftRemark(int $siteId, string $criterion, string $level = '', string $notes = ''): array
            {
                $this->seenNotes = $notes;

                return ['success' => true, 'remark' => ''];
            }
        };

        AccessibilityAudit::getInstance()->set('vpat', $spy);

        $this->postJson('actions/accessibility-audit/vpat/draft-remark', [
            'siteId' => $this->draftSiteId,
            'criterion' => '1.4.3',
            'notes' => 'Keyboard focus is lost after the modal closes.',
        ]);

        expect($spy->seenNotes)->toBe('Keyboard focus is lost after the modal closes.');
    });
});
