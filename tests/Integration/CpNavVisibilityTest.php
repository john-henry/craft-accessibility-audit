<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Craft decides whether to draw a plugin's control-panel section from two
// things: its own "Access <plugin>" permission, and whether getCpNavItem()
// hands back an item at all. That access permission is separate from anything
// this plugin registers and is the first box an admin ticks, so it is granted
// on its own routinely. On its own it entitles the reader to none of these
// screens: every one of them is gated on view-reports, and the section's own
// link lands on the Overview, which refuses them.
//
// Craft drops the section for a null. Returning an item with an empty subnav
// instead puts a heading in the sidebar whose every route 403s.
// ---------------------------------------------------------------------------

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
});

it('offers no section to someone who may see none of its screens', function() {
    $user = UserFactory::factory()->create();

    Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, [
        'accesscp',
        'accessplugin-accessibility-audit',
    ]);

    $this->actingAs($user);

    expect(AccessibilityAudit::getInstance()->getCpNavItem())->toBeNull();
});

it('offers the section to someone who may view reports', function() {
    $user = UserFactory::factory()->create();

    Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, [
        'accesscp',
        'accessplugin-accessibility-audit',
        'accessibility-audit:view-reports',
    ]);

    $this->actingAs($user);

    $item = AccessibilityAudit::getInstance()->getCpNavItem();

    expect($item)->not->toBeNull()
        ->and($item['subnav'])->toHaveKey('overview')
        // Settings is an admin screen, so it stays off a reader's subnav.
        ->and($item['subnav'])->not->toHaveKey('settings');
});

it('offers an admin the settings screen alongside the reports', function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());

    $item = AccessibilityAudit::getInstance()->getCpNavItem();

    expect($item)->not->toBeNull()
        ->and($item['subnav'])->toHaveKey('overview')
        ->and($item['subnav'])->toHaveKey('settings');
});
