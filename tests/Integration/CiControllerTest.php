<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function setCiSettings(array $overrides): void
{
    $plugin = AccessibilityAudit::getInstance();
    $settings = $plugin->getSettings();

    foreach ($overrides as $attr => $value) {
        $settings->$attr = $value;
    }

    \Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
}

// CI/CD integration is a Pro-only feature: the token-auth and passing-logic
// tests below exercise the Standard-vs-Pro boundary's Pro side, so they force
// the Pro edition for the life of each test. The dedicated gate test further
// down covers the Standard refusal.
beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
});

// ---------------------------------------------------------------------------
// Token authentication
// ---------------------------------------------------------------------------

describe('CiController::actionCheck token auth', function() {
    it('returns 401 when no token is configured', function() {
        setCiSettings(['ciApiToken' => null]);

        $this->http('get', 'accessibility-audit/ci/check?ciToken=anything')
            ->addHeader('Accept', 'application/json')
            ->send()
            ->assertStatus(401);
    });

    it('returns 401 when the provided token is wrong', function() {
        setCiSettings(['ciApiToken' => hash('sha256', 'secret-token')]);

        $response = $this->http('get', 'accessibility-audit/ci/check?ciToken=wrong-token')
            ->addHeader('Accept', 'application/json')
            ->send();

        $response->assertStatus(401);
        expect($response->getJsonContent()['success'])->toBeFalse();
    });

    it('returns 401 when no token is provided', function() {
        setCiSettings(['ciApiToken' => hash('sha256', 'secret-token')]);

        $this->http('get', 'accessibility-audit/ci/check')
            ->addHeader('Accept', 'application/json')
            ->send()
            ->assertStatus(401);
    });

    it('accepts a valid token via query parameter', function() {
        setCiSettings(['ciApiToken' => hash('sha256', 'secret-token'), 'targetScore' => 0]);

        $response = $this->http('get', 'accessibility-audit/ci/check?ciToken=secret-token')
            ->addHeader('Accept', 'application/json')
            ->send();

        $response->assertStatus(200);
        expect($response->getJsonContent()['success'])->toBeTrue();
    });

    it('accepts a valid token via the Authorization bearer header', function() {
        setCiSettings(['ciApiToken' => hash('sha256', 'secret-token'), 'targetScore' => 0]);

        $response = $this->http('get', 'accessibility-audit/ci/check')
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer secret-token')
            ->send();

        $response->assertStatus(200);
        expect($response->getJsonContent()['success'])->toBeTrue();
    });

    it('authenticates a token generated through the settings action', function() {
        // End-to-end proof for the hashed-storage change: the generate action
        // stores only a SHA-256 hash in settings and hands back the plaintext
        // once via a flash, and that plaintext must still authenticate. Guards
        // against a regression where storage and comparison drift apart.
        $this->actingAs(\markhuot\craftpest\factories\User::factory()->admin(true)->create());

        setCiSettings(['ciApiToken' => null, 'targetScore' => 0]);

        $this->post('actions/accessibility-audit/settings/generate-ci-token');

        $token = Craft::$app->getSession()->getFlash('newCiApiToken');
        expect($token)->toBeString()->not->toBe('');

        // The persisted value is the hash, never the plaintext token.
        $stored = AccessibilityAudit::getInstance()->getSettings()->ciApiToken;
        expect($stored)->toBe(hash('sha256', $token))
            ->and($stored)->not->toBe($token);

        $response = $this->http('get', 'accessibility-audit/ci/check?ciToken=' . $token)
            ->addHeader('Accept', 'application/json')
            ->send();

        $response->assertStatus(200);
        expect($response->getJsonContent()['success'])->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Passing logic
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Pro-edition gating
// ---------------------------------------------------------------------------

describe('CiController::actionCheck Pro gating', function() {
    it('refuses on the Standard edition before validating the token', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
        setCiSettings(['ciApiToken' => hash('sha256', 'secret-token'), 'targetScore' => 0]);

        // A valid token would otherwise pass, but Standard is refused first so
        // the response must not leak that the token was accepted.
        $response = $this->http('get', 'accessibility-audit/ci/check?ciToken=secret-token')
            ->addHeader('Accept', 'application/json')
            ->send();

        $json = $response->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['proRequired'])->toBeTrue()
            ->and($json['error'])->toContain('Pro edition')
            ->and($json)->not->toHaveKey('passing');
    });
});

describe('CiController::actionCheck passing logic', function() {
    it('always passes when no target score is set', function() {
        setCiSettings(['ciApiToken' => hash('sha256', 'secret-token'), 'targetScore' => 0]);

        $response = $this->http('get', 'accessibility-audit/ci/check?ciToken=secret-token')
            ->addHeader('Accept', 'application/json')
            ->send();

        $response->assertStatus(200);
        $json = $response->getJsonContent();

        expect($json['passing'])->toBeTrue()
            ->and($json['threshold'])->toBe(0)
            ->and($json['score'])->toBeInt();
    });

    it('reports the configured threshold in the payload', function() {
        setCiSettings(['ciApiToken' => hash('sha256', 'secret-token'), 'targetScore' => 85]);

        $response = $this->http('get', 'accessibility-audit/ci/check?ciToken=secret-token')
            ->addHeader('Accept', 'application/json')
            ->send();

        $response->assertStatus(200);
        expect($response->getJsonContent()['threshold'])->toBe(85);
    });
});
