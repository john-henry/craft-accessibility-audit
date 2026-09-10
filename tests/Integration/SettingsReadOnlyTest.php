<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\helpers\Cp;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The settings tabs under allowAdminChanges = false.
//
// The tabs stay reachable so an admin can still read what is configured, and
// every control on them has to be inert. The templates do that with one
// disabled <fieldset> wrapping the tab body rather than a disabled flag on
// each field, so what is pinned down here is the result rather than the
// mechanism: nothing carrying a settings[...] name and no button posting to a
// settings action is left live. A field added outside the fieldset renders
// fine and would fail here.
//
// Helpers are prefixed sro: Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** The tabs that carry a form. */
const SRO_TABS = ['general', 'scanning', 'maintenance', 'tools', 'notifications'];

/**
 * Runs the callback with admin changes off, restoring both flags afterwards.
 *
 * Craft flips ProjectConfig into read-only at the start of a request when
 * admin changes are off, and that sticks on the shared instance for the rest
 * of the process, so a later test that saves plugin settings would die on
 * "project config is read-only".
 */
function sroWithAdminChangesOff(callable $fn): void
{
    $general = Craft::$app->getConfig()->getGeneral();
    $projectConfig = Craft::$app->getProjectConfig();
    $originalAllow = $general->allowAdminChanges;
    $originalReadOnly = $projectConfig->readOnly;
    $general->allowAdminChanges = false;

    try {
        $fn();
    } finally {
        $general->allowAdminChanges = $originalAllow;
        $projectConfig->readOnly = $originalReadOnly;
    }
}

/** Parses rendered control panel HTML for querying. */
function sroXpath(string $html): DOMXPath
{
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR);

    return new DOMXPath($dom);
}

/**
 * Every control on the page that would post a settings change.
 *
 * @return DOMElement[]
 */
function sroControls(DOMXPath $xpath): array
{
    $nodes = $xpath->query(
        '//input[starts-with(@name, "settings[")]'
        . ' | //select[starts-with(@name, "settings[")]'
        . ' | //textarea[starts-with(@name, "settings[")]'
        . ' | //button[contains(@formaction, "accessibility-audit/settings/")]',
    );

    $controls = [];

    foreach ($nodes ?: [] as $node) {
        if ($node instanceof DOMElement) {
            $controls[] = $node;
        }
    }

    return $controls;
}

/** Whether a control is disabled itself or sits inside a disabled fieldset. */
function sroIsInert(DOMElement $el): bool
{
    if ($el->hasAttribute('disabled')) {
        return true;
    }

    for ($node = $el->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
        if ($node->nodeName === 'fieldset' && $node->hasAttribute('disabled')) {
            return true;
        }
    }

    return false;
}

/** Names the controls a tab left live, for a failure message worth reading. */
function sroLiveControls(DOMXPath $xpath): array
{
    $live = [];

    foreach (sroControls($xpath) as $control) {
        if (!sroIsInert($control)) {
            $live[] = $control->getAttribute('name')
                ?: $control->getAttribute('formaction')
                ?: $control->nodeName;
        }
    }

    return $live;
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
});

describe('A settings tab with admin changes off', function() {
    it('leaves no control live', function(string $tab) {
        sroWithAdminChangesOff(function() use ($tab) {
            $response = $this->get("admin/accessibility-audit/settings/$tab")->assertOk();
            $xpath = sroXpath($response->content);

            // Without this the tab could pass by rendering no fields at all.
            expect(sroControls($xpath))->not->toBeEmpty();
            expect(sroLiveControls($xpath))->toBe([]);
        });
    })->with(SRO_TABS);

    it('renders no form to submit', function(string $tab) {
        sroWithAdminChangesOff(function() use ($tab) {
            $this->get("admin/accessibility-audit/settings/$tab")
                ->assertOk()
                ->assertDontSee('id="main-form"', false);
        });
    })->with(SRO_TABS);

    it('says why the screen cannot be edited', function(string $tab) {
        sroWithAdminChangesOff(function() use ($tab) {
            $this->get("admin/accessibility-audit/settings/$tab")
                ->assertOk()
                ->assertSee(Cp::readOnlyNoticeHtml(), false);
        });
    })->with(SRO_TABS);
});

describe('A settings tab with admin changes on', function() {
    it('leaves the controls live', function(string $tab) {
        $response = $this->get("admin/accessibility-audit/settings/$tab")->assertOk();
        $xpath = sroXpath($response->content);

        // Anything overridden in config/accessibility-audit.php is disabled on
        // purpose, so this only asserts the tab is not inert wholesale.
        expect(sroLiveControls($xpath))->not->toBeEmpty();
    })->with(SRO_TABS);

    it('renders the form', function(string $tab) {
        $this->get("admin/accessibility-audit/settings/$tab")
            ->assertOk()
            ->assertSee('id="main-form"', false);
    })->with(SRO_TABS);
});

describe('The support tab', function() {
    it('reads the same either way', function() {
        $this->get('admin/accessibility-audit/settings/support')->assertOk();

        sroWithAdminChangesOff(function() {
            $this->get('admin/accessibility-audit/settings/support')->assertOk();
        });
    });
});
