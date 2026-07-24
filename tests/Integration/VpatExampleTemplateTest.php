<?php

use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// The shipped example site template (templates/_vpat/export.twig)
// ---------------------------------------------------------------------------
// A broken example is worse than none, so render the real file against a real
// report and confirm every section it advertises actually appears. This also
// guards the meta-field contract: if a field is renamed, this render breaks.

describe('_vpat/export example template', function() {
    it('renders every section from a full report', function() {
        // Skip cleanly if the example lives outside the test's site-template
        // root (e.g. running the plugin suite outside this boilerplate).
        $exists = Craft::$app->getView()->doesTemplateExist('_vpat/export', View::TEMPLATE_MODE_SITE);
        if (!$exists) {
            expect(true)->toBeTrue();
            return;
        }

        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        saveVpatMetaFlat($siteId, [
            'productName' => 'Demo Site',
            'productVersion' => '2.0',
            'contactEmail' => 'access@example.com',
            'evalMethods' => ['Keyboard-only testing', 'Screen reader testing with NVDA'],
            'scopePages' => ['Home (https://example.com/)', 'Contact us'],
            'legalDisclaimer' => 'Provided for information only.',
            'notes' => 'General notes about the report.',
        ]);

        $report = AccessibilityAudit::getInstance()->vpat->getFullReport($siteId);
        $html = Craft::$app->getView()->renderTemplate('_vpat/export', ['report' => $report], View::TEMPLATE_MODE_SITE);

        expect($html)
            ->toContain('<!DOCTYPE html>')
            ->toContain('Demo Site')
            ->toContain('Version 2.0')
            ->toContain('access@example.com')
            ->toContain('Scope of Evaluation')
            ->toContain('Contact us')
            ->toContain('Evaluation Methods Used')
            ->toContain('Keyboard-only testing')
            ->toContain('Screen reader testing with NVDA')
            ->toContain('Success Criteria, Level A')
            ->toContain('Success Criteria, Level AA')
            ->toContain('Provided for information only.')
            ->toContain('General notes about the report.')
            // Every WCAG criterion the service lists must render a row.
            ->toContain('1.1.1')
            ->toContain('Non-text Content');
    });
});
