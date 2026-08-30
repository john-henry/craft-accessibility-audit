<?php

use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The entry sidebar panel renders on every entry edit screen in the CP, so a
// Twig error here takes out editing entirely, not just this plugin's own pages.
// It is self-contained (no _layouts/cp), so unlike the dashboard templates it
// can be rendered directly here.
//
// This exists because the panel referenced a variable it was never given: the
// event handler passes `element`, the template asked for `entry`, and under
// strict_variables that is a fatal on a page nobody would think to blame on an
// accessibility plugin.
// ---------------------------------------------------------------------------

function renderSidebarPanel(array $overrides = []): string
{
    $entry = scannableEntry();

    return Craft::$app->getView()->renderTemplate(
        'accessibility-audit/_sidebar/accessibility-panel',
        array_merge([
            'element' => $entry,
            'scan' => null,
            'issues' => [],
            'hasApiKey' => false,
            'showReadabilityTab' => true,
            'readabilityPro' => false,
            'readabilityResult' => null,
        ], $overrides),
        View::TEMPLATE_MODE_CP,
    );
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

it('renders with exactly the variables the sidebar event passes it', function() {
    expect(renderSidebarPanel())->toBeString()->not->toBeEmpty();
});

it('renders for an entry that has never been scanned', function() {
    // The common case on a fresh install, and the one where a template is most
    // likely to reach for data that is not there.
    expect(renderSidebarPanel(['scan' => null, 'issues' => []]))->toBeString();
});

it('renders on both editions', function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;
    expect(renderSidebarPanel(['readabilityPro' => false]))->toBeString();

    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    expect(renderSidebarPanel(['readabilityPro' => true]))->toBeString();
});

it('lists a repeated rule once, with its occurrence count', function() {
    // The panel used to print one row per occurrence, so a page with four
    // link-new-window findings showed the same rule four times.
    $html = renderSidebarPanel([
        'scan' => ['id' => 1, 'score' => 75, 'dateScanned' => '2026-07-18 10:00:00', 'errorCount' => 0, 'warningCount' => 6, 'noticeCount' => 1],
        'issues' => [
            ['ruleId' => 'link-new-window', 'severity' => 'warning', 'occurrences' => 4, 'message' => 'Opens in a new tab.', 'wcagLevel' => 'A', 'wcagCriterion' => '3.2.2', 'helpUrl' => ''],
            ['ruleId' => 'skip-link', 'severity' => 'warning', 'occurrences' => 1, 'message' => 'No skip link.', 'wcagLevel' => 'A', 'wcagCriterion' => '2.4.1', 'helpUrl' => ''],
        ],
    ]);

    expect(substr_count($html, 'link-new-window'))->toBe(1)
        ->and($html)->toContain('>4<');
});

it('counts occurrences in the tab badge, not grouped rows', function() {
    // The badge sits beside the severity dots, so it has to agree with them:
    // two rows here, seven findings.
    $html = renderSidebarPanel([
        'scan' => ['id' => 1, 'score' => 75, 'dateScanned' => '2026-07-18 10:00:00', 'errorCount' => 0, 'warningCount' => 6, 'noticeCount' => 1],
        'issues' => [
            ['ruleId' => 'link-new-window', 'severity' => 'warning', 'occurrences' => 6, 'message' => '', 'wcagLevel' => 'A', 'wcagCriterion' => '', 'helpUrl' => ''],
            ['ruleId' => 'meta-description', 'severity' => 'notice', 'occurrences' => 1, 'message' => '', 'wcagLevel' => null, 'wcagCriterion' => '', 'helpUrl' => ''],
        ],
    ]);

    expect($html)->toContain('accessibility-audit-panel__badge">7<');
});

// The body of registerElementSidebarPanel(), for the source assertions below.
// Read from source rather than exercised, because the registration happens once
// at plugin init and cannot be re-run inside a test.
function sidebarPanelRegistration(): string
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/src/AccessibilityAudit.php');
    $start = strpos($source, 'registerElementSidebarPanel(): void');

    if ($start === false) {
        return '';
    }

    $end = strpos($source, "\n    private function ", $start);

    return substr($source, $start, $end === false ? null : $end - $start);
}

it('registers for every element type the scanner covers, not just entries', function() {
    // It was bound to Entry, so categories and Commerce products were scanned
    // and reported on while their edit screens showed no panel at all. The
    // guards have to mirror AuditService::getUrlElementsQuery().
    $body = sidebarPanelRegistration();

    expect($body)
        ->toContain('ScannableElementTypes::all()')
        ->toContain('Element::EVENT_DEFINE_SIDEBAR_HTML');
});

it('registers on the base Element too, as a net for unknown element types', function() {
    // A type registered by a plugin this one cannot see still gets a panel.
    expect(sidebarPanelRegistration())->toContain('$classes[] = Element::class;');
});

it('prepends its handler so the panel sits above other plugins panels', function() {
    // Without append: false the panel lands under SEOmatic and anything else
    // hooking the same event. It is a panel you act on, so it goes near the top.
    expect(sidebarPanelRegistration())->toContain('append: false');
});

it('draws the panel only once when both registrations fire', function() {
    // Concrete types and the base Element are both registered, so most types
    // are offered the same event twice. The markup check is what stops it.
    expect(sidebarPanelRegistration())->toContain("str_contains(\$e->html, 'accessibility-audit-panel')");
});

it('keeps assets out of the panel, as the scanner does', function() {
    // Assets are binary files: the scanner excludes them and they have their
    // own alt-text panel. Two panels on one screen would be worse than none.
    expect(sidebarPanelRegistration())->toContain('instanceof Asset');
});

it('asks for no variable the event handler does not supply', function() {
    // Guards the specific drift that broke it: the template and the handler are
    // in different files, and nothing but this notices when they disagree.
    $source = file_get_contents(
        dirname(__DIR__, 2) . '/src/templates/_sidebar/accessibility-panel.twig',
    );

    expect($source)->not->toContain('entry.');
});
