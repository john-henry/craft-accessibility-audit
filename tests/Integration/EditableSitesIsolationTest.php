<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Which sites a test may edit.
//
// Craft works out the editable site ids once and holds them for the rest of
// the request, reading them off whoever is logged in when the question is
// first asked. A suite is one request, so without a reset between tests the
// first one to ask fixes the answer for every test after it, whatever user
// those act as, and anything gated on editable sites passes or fails on the
// order the files happened to load in.
// ---------------------------------------------------------------------------

it('starts each test with the editable sites unanswered', function() {
    // Deliberately poisons the memo under a user with no site permissions.
    // The test below is the one that proves it did not carry over.
    $this->actingAs(UserFactory::factory()->create());

    Craft::$app->getSites()->getEditableSiteIds();

    expect(true)->toBeTrue();
});

it('does not inherit the answer from the test before it', function() {
    // Would read the previous test's empty list if the memo survived, and an
    // admin on Pro can edit every site there is.
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

    $allowed = AccessibilityAudit::getInstance()->allowedSites();

    expect($allowed)->not->toBeEmpty();
});

it('clears the memo in the suite bootstrap, not in individual tests', function() {
    // Belongs in one place: a reset each test has to remember is a reset that
    // gets forgotten by the next test somebody writes.
    $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/Pest.php');

    expect($bootstrap)->toContain('Craft::$app->getSites()->refreshSites();');
});
