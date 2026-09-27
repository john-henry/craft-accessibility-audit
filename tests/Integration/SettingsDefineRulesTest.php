<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\base\Model;
use craft\events\DefineRulesEvent;
use johnhenry\accessibilityaudit\models\SettingsModel;
use yii\base\Event;

// ---------------------------------------------------------------------------
// Craft's Model::rules() is what fires EVENT_DEFINE_RULES; the rules themselves
// belong in defineRules(). A model that declares its rules by overriding
// rules() outright still validates, so nothing looks wrong, but the event never
// fires and the documented way to extend a model's validation silently does
// nothing to it. Every other model in the plugin defines its rules the way
// Craft expects, and this pins the settings model to the same contract.
// ---------------------------------------------------------------------------

it('still validates its own rules', function() {
    $settings = new SettingsModel();
    $settings->wcagLevel = 'nonsense';

    expect($settings->validate(['wcagLevel']))->toBeFalse();

    $settings = new SettingsModel();
    $settings->wcagLevel = 'AA';

    expect($settings->validate(['wcagLevel']))->toBeTrue();
});

it('lets a module add a rule through defineRules', function() {
    $handler = static function(DefineRulesEvent $event): void {
        $event->rules[] = [['wcagLevel'], 'in', 'range' => ['A']];
    };

    Event::on(SettingsModel::class, Model::EVENT_DEFINE_RULES, $handler);

    try {
        $settings = new SettingsModel();
        $settings->wcagLevel = 'AA';

        // Valid against the plugin's own rules, refused by the added one: the
        // event reached the set the validator actually ran.
        expect($settings->validate(['wcagLevel']))->toBeFalse();
    } finally {
        Event::off(SettingsModel::class, Model::EVENT_DEFINE_RULES, $handler);
    }

    $settings = new SettingsModel();
    $settings->wcagLevel = 'AA';

    expect($settings->validate(['wcagLevel']))->toBeTrue();
});
