<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\helpers\AltTextPrompt;
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

it('does not cap the field, because Craft does not either', function() {
    // Craft's AltField unsets maxlength outright. Capping the plugin's own
    // editing field stops an editor part-way through fixing a finding the
    // plugin raised, at a number the rule does not even use.
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/assets.twig');

    expect($twig)->not->toContain('maxlength');
});

it('counts the field against the number the report quotes', function() {
    // Two numbers for one idea drift, and the editor is then trimming to a
    // target the finding will not agree with.
    $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/src/templates/assets.twig');

    expect($twig)->toContain('PotentialScanner::MAX_ALT_LENGTH')
        ->and(PotentialScanner::MAX_ALT_LENGTH)->toBe(150);
});

it('keeps the generation ceiling separate from the guideline', function() {
    // 125 is what the model is asked to aim for, which is a different job
    // from the length a human is warned about.
    expect(AltTextPrompt::MAX_LENGTH)->toBe(125)
        ->and(PotentialScanner::MAX_ALT_LENGTH)->not->toBe(AltTextPrompt::MAX_LENGTH);
});
