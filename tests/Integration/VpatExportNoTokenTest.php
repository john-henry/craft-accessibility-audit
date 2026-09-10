<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// The exported report is meant to be saved and sent to somebody outside the
// organisation. Nothing belonging to the session that produced it may travel
// with the file.
// ---------------------------------------------------------------------------

function renderExport(int $siteId): string
{
    $report = AccessibilityAudit::getInstance()->vpat->getFullReport($siteId);

    return Craft::$app->getView()->renderTemplate(
        'accessibility-audit/vpat-export',
        ['report' => $report],
        View::TEMPLATE_MODE_CP,
    );
}

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
});

it('carries no CSRF token', function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $html = renderExport($siteId);

    expect($html)->not->toContain(Craft::$app->getRequest()->getCsrfToken())
        ->and($html)->not->toContain(Craft::$app->getConfig()->getGeneral()->csrfTokenName);
});

it('asks for the token at the time it is needed instead', function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    // Only rendered for someone who may record a revision, so this asserts the
    // mechanism exists rather than that it is always present.
    $html = renderExport($siteId);

    if (!str_contains($html, 'record-revision')) {
        expect($html)->not->toContain('csrfTokenValue');

        return;
    }

    expect($html)->toContain('users/session-info');
});

it('holds no static buttons for a converter to pick up', function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    expect(renderExport($siteId))->not->toContain('<button');
});
