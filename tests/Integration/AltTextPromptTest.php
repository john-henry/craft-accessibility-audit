<?php

use craft\elements\Asset;
use johnhenry\accessibilityaudit\helpers\AltTextPrompt;
use johnhenry\accessibilityaudit\models\SettingsModel;

// ---------------------------------------------------------------------------
// AltTextPrompt
//
// A screenshot is a picture of an interface, and interfaces are usually full of
// other pictures. Left to itself the model describes whichever layer fills the
// frame, so a screenshot demonstrating a button gets alt text about the stock
// photo behind it: fluent, accurate about the pixels, and useless to the reader.
//
// Both generation paths build their prompt here so the Generate button and the
// queued job cannot describe the same image differently. They had already
// drifted once: the job fed the filename and title in as context and the
// button did not.
// ---------------------------------------------------------------------------

function promptAsset(string $filename = 'page-report-full.png', string $title = ''): Asset
{
    return new Asset(['filename' => $filename, 'title' => $title]);
}

it('tells the model to describe the interface, not what is pictured inside it', function() {
    expect(AltTextPrompt::build(promptAsset(), new SettingsModel()))
        ->toContain('screenshot of a user interface')
        ->toContain('ignore any photographs, artwork or sample content');
});

it('feeds the filename in, which is often the only sign it is a screenshot', function() {
    expect(AltTextPrompt::build(promptAsset('asset-edit-generate.png'), new SettingsModel()))
        ->toContain('Filename: asset-edit-generate.png');
});

it('includes the asset title when it has one', function() {
    expect(AltTextPrompt::build(promptAsset(title: 'Page report'), new SettingsModel()))
        ->toContain('Title: Page report');
});

it('leaves the title line out when there is none', function() {
    expect(AltTextPrompt::build(promptAsset(), new SettingsModel()))->not->toContain('Title:');
});

it('puts the site context first when one is configured', function() {
    $settings = new SettingsModel(['altTextContext' => 'Docs for a Craft plugin.']);

    expect(AltTextPrompt::build(promptAsset(), $settings))
        ->toStartWith('Site context: Docs for a Craft plugin.');
});

it('leaves the site context out when there is none', function() {
    expect(AltTextPrompt::build(promptAsset(), new SettingsModel()))->not->toContain('Site context:');
});

it('asks for the configured language', function() {
    $settings = new SettingsModel(['altTextLanguage' => 'Irish']);

    expect(AltTextPrompt::build(promptAsset(), $settings))->toContain('Respond in Irish.');
});

it('falls back to English when no language is set', function() {
    $settings = new SettingsModel(['altTextLanguage' => '  ']);

    expect(AltTextPrompt::build(promptAsset(), $settings))->toContain('Respond in English.');
});

it('keeps the length ceiling in step with the constant', function() {
    expect(AltTextPrompt::build(promptAsset(), new SettingsModel()))
        ->toContain('Maximum ' . AltTextPrompt::MAX_LENGTH . ' characters.');
});

it('is the only place either generation path builds a prompt', function() {
    // The two drifted before. If a prompt is ever assembled inline again, the
    // Generate button and the queued job can describe the same image
    // differently, and only one of them gets fixed next time.
    foreach ([
        dirname(__DIR__, 2) . '/src/controllers/AltController.php',
        dirname(__DIR__, 2) . '/src/jobs/GenerateAltTextJob.php',
    ] as $path) {
        $source = file_get_contents($path);

        expect($source)
            ->toContain('AltTextPrompt::build($asset, $settings)')
            ->not->toContain('Write concise, descriptive alt text');
    }
});

it('spots alt text that ran past the length it was asked for', function() {
    expect(AltTextPrompt::exceedsLimit(str_repeat('a', AltTextPrompt::MAX_LENGTH + 1)))->toBeTrue()
        ->and(AltTextPrompt::exceedsLimit(str_repeat('a', AltTextPrompt::MAX_LENGTH)))->toBeFalse();
});

it('names the overshoot when asking again', function() {
    $long = str_repeat('word ', 40);
    $retry = AltTextPrompt::retryPrompt('BASE PROMPT', $long);

    expect($retry)
        ->toStartWith('BASE PROMPT')
        ->toContain((string)mb_strlen(trim($long)))
        ->toContain('over the ' . AltTextPrompt::MAX_LENGTH . ' character limit');
});

it('trims back to the cap on a word boundary', function() {
    $trimmed = AltTextPrompt::trimToLimit(str_repeat('alpha ', 40));

    expect(mb_strlen($trimmed))->toBeLessThanOrEqual(AltTextPrompt::MAX_LENGTH)
        ->and($trimmed)->toEndWith('alpha')
        ->and(AltTextPrompt::exceedsLimit($trimmed))->toBeFalse();
});

it('leaves alt text within the cap untouched', function() {
    expect(AltTextPrompt::trimToLimit('A short, honest description.'))->toBe('A short, honest description.');
});

it('still cuts a single word longer than the cap', function() {
    // No boundary to cut on, so the hard cut has to stand rather than
    // returning an empty string.
    $trimmed = AltTextPrompt::trimToLimit(str_repeat('x', AltTextPrompt::MAX_LENGTH + 50));

    expect($trimmed)->not->toBe('')
        ->and(mb_strlen($trimmed))->toBeLessThanOrEqual(AltTextPrompt::MAX_LENGTH);
});

it('leaves no trailing punctuation after a trim', function() {
    expect(AltTextPrompt::trimToLimit(str_repeat('beta, ', 40)))->not->toEndWith(',');
});

it('has both generation paths retry before they trim', function() {
    // A trim on its own loses meaning; the retry is what usually saves it.
    foreach ([
        dirname(__DIR__, 2) . '/src/controllers/AltController.php',
        dirname(__DIR__, 2) . '/src/jobs/GenerateAltTextJob.php',
    ] as $path) {
        $source = file_get_contents($path);

        expect($source)
            ->toContain('AltTextPrompt::exceedsLimit(')
            ->toContain('AltTextPrompt::retryPrompt(')
            ->toContain('AltTextPrompt::trimToLimit(');
    }
});
