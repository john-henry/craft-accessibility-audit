<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\controllers\DashboardController;
use johnhenry\accessibilityaudit\services\AuditService;

// ---------------------------------------------------------------------------
// How the focus questions reach a reader.
//
// A rule without its own question falls back to its bare id on the Potential
// page, and one without a selector cannot be shown on the page.
// ---------------------------------------------------------------------------

function focusQuestionFor(string $ruleId): string
{
    $method = new ReflectionMethod(DashboardController::class, '_potentialQuestion');
    $method->setAccessible(true);

    return $method->invoke(new DashboardController('dashboard', AccessibilityAudit::getInstance()), $ruleId, null);
}

function focusPageReportSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/src/resources/js/page-report.js');
}

it('asks each focus rule its own question', function(string $ruleId, string $expected) {
    expect(focusQuestionFor($ruleId))->toBe($expected);
})->with([
    [AuditService::RULE_POTENTIAL_FOCUS_OUTLINE, 'Does something replace the focus outline this stylesheet removes?'],
    [AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE, 'Can you see where keyboard focus is on this control?'],
    [AuditService::RULE_POTENTIAL_FOCUS_OBSCURED, 'Does this fixed or sticky element hide controls when they take keyboard focus?'],
]);

it('can show every focus question on the page', function(string $ruleId) {
    expect(focusPageReportSource())->toMatch("/'" . preg_quote($ruleId, '/') . "':\s+'[^']+'/");
})->with([
    AuditService::RULE_POTENTIAL_FOCUS_OUTLINE,
    AuditService::RULE_POTENTIAL_FOCUS_NOT_VISIBLE,
    AuditService::RULE_POTENTIAL_FOCUS_OBSCURED,
]);

it('posts the stylesheet rules on every visit, empty where the browser pass walks focus', function() {
    $source = focusPageReportSource();

    expect($source)->toContain("var focusRules = CFG.headlessAvailable")
        ->and($source)->toContain("fd.append('focusRules', JSON.stringify(focusRules));")
        ->and((string) file_get_contents(dirname(__DIR__, 2) . '/src/controllers/DashboardController.php'))
        ->toContain("'headlessAvailable' => \$plugin->getHeadless()->isAvailable(),");
});

it('lists the focus rules among the ones that can be ignored', function() {
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/_settings/scanning/index.twig');

    foreach (AuditService::FOCUS_WALK_RULES as $ruleId) {
        expect($twig)->toContain("<code>{$ruleId}</code>");
    }

    expect($twig)->toContain('<code>' . AuditService::RULE_POTENTIAL_FOCUS_OUTLINE . '</code>');
});
