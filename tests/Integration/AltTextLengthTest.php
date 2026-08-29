<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\models\IssueModel;
use johnhenry\accessibilityaudit\services\PotentialScanner;

// ---------------------------------------------------------------------------
// Alt text that runs long.
//
// Knowing it is too long is half an answer. Trimming to a limit means knowing
// how far over it is, and the report shows a truncated preview, so counting
// the characters back by eye is not on.
// ---------------------------------------------------------------------------

/** The long-alt findings for an image with the given alt text. */
function longAltIssues(string $alt): array
{
    return array_values(array_filter(
        (new PotentialScanner())->scan('<img src="/x.jpg" alt="' . $alt . '">'),
        static fn(IssueModel $i): bool => $i->ruleId === 'potential:long-alt',
    ));
}

it('says how far over the guideline it runs', function() {
    $issues = longAltIssues(str_repeat('a', 187));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->message)->toContain('187 characters')
        ->and($issues[0]->message)->toContain('37 over the 150');
});

it('leaves alt text at the guideline alone', function() {
    expect(longAltIssues(str_repeat('a', 150)))->toBeEmpty();
});

it('counts characters, not bytes', function() {
    // 160 curly quotes are 160 characters and 480 bytes. Counting bytes would
    // report a number three times the one the editor has to act on.
    $issues = longAltIssues(str_repeat('’', 160));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->message)->toContain('160 characters')
        ->and($issues[0]->message)->toContain('10 over');
});

it('keeps the counter and the field cap as one number', function() {
    // Two numbers for one limit drift, and the counter is then telling the
    // editor something the field will not honour.
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/assets.twig');

    expect($twig)->toContain('const ALT_MAX = 125;')
        ->and($twig)->toContain("maxlength=\"' + ALT_MAX + '\"")
        ->and($twig)->not->toContain('maxlength="125"');
});
