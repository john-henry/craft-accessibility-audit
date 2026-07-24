<?php

use johnhenry\accessibilityaudit\controllers\AltController;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Finding 2 (no rate limit on AI alt generation)
//
// actionGenerate makes a paid Anthropic call, so a per-user rolling counter
// caps how many generations one user can trigger within a window. The cap is
// generous by design (the sequential "Generate all" loop stays well under it),
// so these tests drive the counter directly rather than making 60 real calls.
//
// Helper is uniquely named: Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** The rolling-counter cache key for a user in the current window. */
function altRateKey(int $userId): string
{
    $bucket = (int) floor(time() / AltController::GENERATE_RATE_WINDOW);

    return "accessibility-audit:alt-generate-rate:$userId:$bucket";
}

describe('AltController::actionGenerate rate limit', function() {
    it('refuses once the per-user cap for the window is reached', function() {
        $user = UserFactory::factory()->admin(true)->create();
        $this->actingAs($user);

        // Spend the whole window's budget, so the next call is over the cap.
        Craft::$app->getCache()->set(
            altRateKey((int) $user->id),
            AltController::GENERATE_RATE_LIMIT,
            AltController::GENERATE_RATE_WINDOW,
        );

        $json = $this->postJson('actions/accessibility-audit/alt/generate', [
            'assetId' => 999999,
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['error'])->toContain('Too many');
    });

    it('lets a call under the cap through the rate gate', function() {
        $user = UserFactory::factory()->admin(true)->create();
        $this->actingAs($user);

        // Seed a single prior request this window (well under the cap): the call
        // clears the rate gate and fails later on a missing asset, never on the
        // throttle. Proves a normal call is not wrongly rate-limited.
        Craft::$app->getCache()->set(
            altRateKey((int) $user->id),
            1,
            AltController::GENERATE_RATE_WINDOW,
        );

        $json = $this->postJson('actions/accessibility-audit/alt/generate', [
            'assetId' => 999999,
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['error'])->not->toContain('Too many')
            ->and($json['error'])->toContain('not found');
    });
});
