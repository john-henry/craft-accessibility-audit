<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// The export is rendered inside the site's own language: VpatController wraps
// it so a German site's report comes out in German. The document's lang
// attribute has to follow, or a screen reader reads German in an English
// voice and the report fails WCAG 3.1.1 Level A, a criterion it reports on
// a few hundred lines further down its own page.
//
// It is a standalone document. It writes its own <html> and never calls
// head(), so nothing else sets this for it. It cannot read the language out of
// craft.app.language either: Twig resolves its globals once per environment,
// so inside that switch craft.app.language is still whatever the request
// started in. The controller hands the language in instead, and this pins that
// it arrives and is used.
// ---------------------------------------------------------------------------

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

    $this->report = AccessibilityAudit::getInstance()->getVpat()->getFullReport(
        (int)Craft::$app->getSites()->getPrimarySite()->id,
    );
});

function renderExportWith(array $report, ?string $language): string
{
    $vars = ['report' => $report];

    if ($language !== null) {
        $vars['language'] = $language;
    }

    return Craft::$app->getView()->renderTemplate(
        'accessibility-audit/vpat-export',
        $vars,
        View::TEMPLATE_MODE_CP,
    );
}

it('declares the language it was handed', function() {
    expect(renderExportWith($this->report, 'de'))->toContain('<html lang="de">')
        ->and(renderExportWith($this->report, 'fr-FR'))->toContain('<html lang="fr-FR">');
});

it('does not declare English for a report rendered in another language', function() {
    // The assertion that matters. A hardcoded lang passes the test above on an
    // English install and fails nobody until a German buyer opens the file.
    expect(renderExportWith($this->report, 'de'))->not->toContain('lang="en"');
});

it('falls back to the app language when a custom template is given none', function() {
    // The override template is rendered with the same variables, but a
    // document built before this existed would not be passing one.
    expect(renderExportWith($this->report, null))
        ->toContain('<html lang="' . Craft::$app->language . '">');
});
