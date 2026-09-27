<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Both scanning tables carry an "On" lightswitch, and every row they hold is
// skipped unless it is on. Craft's editable table builds a new row from
// `defaultValues`, so without an entry there the switch on a row somebody just
// added starts off: the pattern or URL they typed saves, reads back, and is
// then passed over by the scanner with nothing said about it.
//
// A lightswitch also posts when it is off, as an empty string, so the row is
// there either way and its presence proves nothing about whether anyone filled
// it in. Only the switch itself distinguishes them.
// ---------------------------------------------------------------------------

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    $this->stored = [
        'excludedUriPatterns' => AccessibilityAudit::getInstance()->getSettings()->excludedUriPatterns,
        'customUrls' => AccessibilityAudit::getInstance()->getSettings()->customUrls,
    ];
});

afterEach(function() {
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $settings->excludedUriPatterns = $this->stored['excludedUriPatterns'];
    $settings->customUrls = $this->stored['customUrls'];
});

it('offers every lightswitch column a default, so an added row starts on', function() {
    // Craft core does the same for its preview-target "Auto-refresh" column.
    // Pinned across the template rather than on the two tables that have one
    // today, so a third table cannot be added without it.
    $template = file_get_contents(dirname(__DIR__, 2) . '/src/templates/_settings/scanning/index.twig');

    $switches = substr_count((string)$template, "type: 'lightswitch'");
    $defaults = substr_count((string)$template, 'defaultValues: { enabled: true }');

    expect($switches)->toBeGreaterThan(0)
        ->and($defaults)->toBe($switches);
});

it('keeps a row whose switch posted on', function() {
    saveSettings([
        // '1' and '' are what a lightswitch posts on and off.
        'excludedUriPatterns' => [['enabled' => '1', 'siteUid' => '', 'uriPattern' => '^checkout']],
        'customUrls' => [['enabled' => '1', 'siteUid' => '', 'url' => '/search']],
    ]);

    $settings = AccessibilityAudit::getInstance()->getSettings();

    expect($settings->excludedUriPatterns)->toBe([['enabled' => true, 'siteUid' => '', 'uriPattern' => '^checkout']])
        ->and($settings->customUrls)->toBe([['enabled' => true, 'siteUid' => '', 'url' => '/search']]);
});

it('drops a blank pattern, whatever else the row carries', function() {
    // The pattern is the row. Without one there is nothing to match, and a
    // blank reaching the matcher would be the expression `~~`, which matches
    // every URI on the site.
    saveSettings([
        'excludedUriPatterns' => [
            ['enabled' => '1', 'siteUid' => '', 'uriPattern' => '^checkout'],
            ['enabled' => '1', 'siteUid' => '', 'uriPattern' => ''],
            ['enabled' => '', 'siteUid' => '', 'uriPattern' => ''],
        ],
    ]);

    expect(AccessibilityAudit::getInstance()->getSettings()->excludedUriPatterns)
        ->toBe([['enabled' => true, 'siteUid' => '', 'uriPattern' => '^checkout']]);
});

it('excludes the homepage on ^$ and nothing else', function() {
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $settings->excludedUriPatterns = [['enabled' => true, 'siteId' => '', 'uriPattern' => '^$']];

    $audit = AccessibilityAudit::getInstance()->getAudit();
    $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

    expect($audit->isUriExcluded('__home__', $siteId))->toBeTrue()
        ->and($audit->isUriExcluded(null, $siteId))->toBeTrue()
        ->and($audit->isUriExcluded('about', $siteId))->toBeFalse();
});

it('matches nothing on a blank pattern that reached storage anyway', function() {
    // A config file is the source for its own settings and is never rewritten,
    // so a blank can still arrive from one. It must not fall through to the
    // matcher, where it would exclude the whole site.
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $settings->excludedUriPatterns = [['enabled' => true, 'siteId' => '', 'uriPattern' => '']];

    $audit = AccessibilityAudit::getInstance()->getAudit();
    $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

    expect($audit->isUriExcluded('about', $siteId))->toBeFalse()
        ->and($audit->isUriExcluded('__home__', $siteId))->toBeFalse();
});
