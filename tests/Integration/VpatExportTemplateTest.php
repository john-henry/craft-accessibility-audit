<?php

use craft\helpers\FileHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Directory inside the project's site templates root where these tests plant
 * a disposable export template. Removed again in afterEach().
 */
function vpatTestTemplateDir(): string
{
    return Craft::getAlias('@templates') . '/_a11y-vpat-test';
}

/**
 * Writes a disposable site template the vpatExportTemplate setting can point
 * at, so the tests exercise the real site-template resolution rather than a
 * mocked view layer.
 */
function writeVpatTestTemplate(string $contents): void
{
    FileHelper::writeToFile(vpatTestTemplateDir() . '/export.twig', $contents);
}

/**
 * Requests the VPAT export as a logged-in admin and returns the response.
 */
function requestVpatExport(mixed $testCase): mixed
{
    return $testCase->http('get', 'actions/accessibility-audit/vpat/export')->send();
}

beforeEach(function() {
    // The plugin instance (and its settings model) is a singleton that lives
    // across tests, so both the edition and the setting under test are reset
    // to a known state every time.
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    AccessibilityAudit::getInstance()->getSettings()->vpatExportTemplate = '';

    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

afterEach(function() {
    if (is_dir(vpatTestTemplateDir())) {
        FileHelper::removeDirectory(vpatTestTemplateDir());
    }
});

// ---------------------------------------------------------------------------
// Custom export template
// ---------------------------------------------------------------------------

describe('VpatController::actionExport custom template', function() {
    it('renders the configured site template with the report variable', function() {
        writeVpatTestTemplate(
            'CUSTOM-VPAT-EXPORT levelA:{{ report.levelA|length }} levelAA:{{ report.levelAA|length }}'
        );
        AccessibilityAudit::getInstance()->getSettings()->vpatExportTemplate = '_a11y-vpat-test/export';

        $response = requestVpatExport($this);
        $response->assertOk();

        // The custom template gets the same criteria rows the built-in export
        // renders, so the counts it prints must match the service's own list.
        $criteria = AccessibilityAudit::getInstance()->vpat->getCriteria();
        $levelACount = count(array_filter($criteria, fn(array $c): bool => $c['level'] === 'A'));
        $levelAACount = count($criteria) - $levelACount;

        expect($response->content)
            ->toContain("CUSTOM-VPAT-EXPORT levelA:{$levelACount} levelAA:{$levelAACount}")
            ->not->toContain('Accessibility Conformance Report');
    });

    it('falls back to the built-in document when the configured template is missing', function() {
        AccessibilityAudit::getInstance()->getSettings()->vpatExportTemplate = '_a11y-vpat-test/does-not-exist';

        $response = requestVpatExport($this);
        $response->assertOk();

        expect($response->content)->toContain('Accessibility Conformance Report');
    });

    it('uses the built-in document when the setting is empty', function() {
        $response = requestVpatExport($this);
        $response->assertOk();

        expect($response->content)
            ->toContain('Accessibility Conformance Report')
            ->not->toContain('CUSTOM-VPAT-EXPORT');
    });

    it('renders ticked methods, scope pages, and the disclaimer as their own sections', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        saveVpatMetaFlat($siteId, [
            'productName' => 'Acme Website',
            'evalMethods' => ['Keyboard-only testing', 'Screen reader testing with NVDA'],
            'scopePages' => ['Home (https://example.com/)', 'Checkout flow'],
            'legalDisclaimer' => 'Provided for information only.',
        ]);

        $content = requestVpatExport($this)->content;

        expect($content)
            ->toContain('Keyboard-only testing')
            ->toContain('Screen reader testing with NVDA')
            ->toContain('Scope of Evaluation')
            ->toContain('Checkout flow')
            ->toContain('Legal Disclaimer')
            ->toContain('Provided for information only.');
    });

    it('claims only automated scanning when no methods were recorded', function() {
        // Explicitly blank meta, overriding whatever the dev database holds.
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        saveVpatMetaFlat($siteId, ['productName' => 'Acme Website']);

        $content = requestVpatExport($this)->content;

        // The old fallback claimed manual keyboard and screen reader testing
        // nobody had recorded; the report must never over-claim by default.
        expect($content)
            ->toContain('Automated scanning with axe-core and server-side HTML analysis.')
            ->not->toContain('Manual evaluation of keyboard navigation');
    });

    it('stays behind the Pro gate even when a custom template is configured', function() {
        writeVpatTestTemplate('CUSTOM-VPAT-EXPORT');
        AccessibilityAudit::getInstance()->getSettings()->vpatExportTemplate = '_a11y-vpat-test/export';
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        $json = requestVpatExport($this)->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['proRequired'])->toBeTrue();
    });
});
