<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// allowAdminChanges gating (.claude/rules/security.md): mutating settings
// actions write project config and must refuse cleanly when admin changes are
// disabled, while the view actions stay reachable so settings render
// read-only rather than 403ing.
//
// Helpers are uniquely named (acg*): Pest loads every file into one process.
// ---------------------------------------------------------------------------

function acgWithAdminChangesOff(callable $fn): void
{
    $general = Craft::$app->getConfig()->getGeneral();
    $projectConfig = Craft::$app->getProjectConfig();
    $originalAllow = $general->allowAdminChanges;
    // Craft flips ProjectConfig into read-only at the start of a request when
    // admin changes are off, and that sticks on the shared instance for the
    // rest of the process — restore both or every later test that saves
    // plugin settings dies on "project config is read-only".
    $originalReadOnly = $projectConfig->readOnly;
    $general->allowAdminChanges = false;
    try {
        $fn();
    } finally {
        $general->allowAdminChanges = $originalAllow;
        $projectConfig->readOnly = $originalReadOnly;
    }
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
});

describe('allowAdminChanges off', function() {
    it('refuses a settings save', function() {
        acgWithAdminChangesOff(function() {
            expect(fn() => $this->post('actions/accessibility-audit/settings/save-general', [
                'settings[wcagLevel]' => 'AA',
            ]))->toThrow(\yii\web\ForbiddenHttpException::class);
        });
    });

    it('refuses overlay token generation', function() {
        acgWithAdminChangesOff(function() {
            expect(fn() => $this->post('actions/accessibility-audit/settings/generate-overlay-token'))
                ->toThrow(\yii\web\ForbiddenHttpException::class);
        });
    });

    it('refuses CI token generation', function() {
        acgWithAdminChangesOff(function() {
            expect(fn() => $this->post('actions/accessibility-audit/settings/generate-ci-token'))
                ->toThrow(\yii\web\ForbiddenHttpException::class);
        });
    });

    it('still allows saves when admin changes are enabled', function() {
        $this->post('actions/accessibility-audit/settings/save-general', [
            'settings[wcagLevel]' => 'AA',
        ])->assertStatus(302);
    });
});
