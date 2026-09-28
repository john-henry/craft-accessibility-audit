<?php

use johnhenry\accessibilityaudit\models\SettingsModel;

// ---------------------------------------------------------------------------
// A target of zero switches the gate off: the CI endpoint reports every scan as
// passing, whatever the score. A fresh install that defaults to zero therefore
// hands a pipeline a green light that stays green no matter how bad the site
// gets, and nothing in the response distinguishes that from a real pass. The
// default target and the targetConfigured flag both exist for that, so both are
// pinned here.
// ---------------------------------------------------------------------------

it('starts a fresh install on a usable target rather than a disabled one', function () {
    $settings = new SettingsModel();

    expect($settings->targetScore)->toBe(SettingsModel::RECOMMENDED_TARGET_SCORE);
    expect($settings->targetScore)->toBeGreaterThan(0);
});

it('keeps the recommended target inside the range the form validates', function () {
    $settings = new SettingsModel();
    $settings->targetScore = SettingsModel::RECOMMENDED_TARGET_SCORE;

    expect($settings->validate(['targetScore']))->toBeTrue();
});

it('still allows the target to be switched off deliberately', function () {
    $settings = new SettingsModel();
    $settings->targetScore = 0;

    expect($settings->validate(['targetScore']))->toBeTrue();
});
