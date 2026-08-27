<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

// ---------------------------------------------------------------------------
// Every string this plugin translates has an entry in the message file.
//
// Craft falls back to the source message when a key is missing, so an English
// install looks perfect either way and the gap only shows up on a translated
// one, where a handful of strings quietly stay in English. There is no error
// and no warning. Nothing but this notices.
//
// The companion in JsTranslationsTest guards the JS side, which fails the same
// silent way for a different reason.
// ---------------------------------------------------------------------------

/**
 * Every message passed to Craft::t() or |t() for this plugin, read from the
 * source rather than from a hand-kept list.
 *
 * @return string[]
 */
function translatedMessages(): array
{
    $root = dirname(__DIR__, 2) . '/src';
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function(SplFileInfo $file): bool {
                // Vendored axe and built asset bundles are not ours to read.
                return !in_array($file->getFilename(), ['axe', 'dist', 'node_modules'], true);
            },
        ),
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'twig'], true)) {
            continue;
        }

        // The message file is the answer, not a question.
        if (str_contains(str_replace('\\', '/', $file->getPathname()), '/translations/')) {
            continue;
        }

        $source = (string)file_get_contents($file->getPathname());

        // Craft::t('accessibility-audit', 'message'
        preg_match_all(
            "/Craft::t\(\s*'accessibility-audit'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/s",
            $source,
            $php,
        );

        // 'message'|t('accessibility-audit') with or without a params array
        preg_match_all(
            "/'((?:[^'\\\\]|\\\\.)*)'\s*\|\s*t\(\s*'accessibility-audit'\s*[,)]/s",
            $source,
            $twig,
        );

        foreach ([...$php[1], ...$twig[1]] as $message) {
            $found[stripslashes($message)] = true;
        }
    }

    return array_keys($found);
}

it('has a translation entry for every message it translates', function() {
    $messages = translatedMessages();

    // A sanity floor: if the reader breaks and finds nothing, an empty diff
    // would otherwise read as a pass.
    expect($messages)->not->toBeEmpty()
        ->and(count($messages))->toBeGreaterThan(500);

    $translations = require dirname(__DIR__, 2) . '/src/translations/en/accessibility-audit.php';
    $missing = array_values(array_diff($messages, array_keys($translations)));

    expect($missing)->toBe([], sprintf(
        "These are translated in the source but missing from the message file, so they stay "
        . "English on a translated install:\n  - %s",
        implode("\n  - ", array_slice($missing, 0, 40)),
    ));
});

it('does not carry message-file entries for strings it no longer uses', function() {
    $root = dirname(__DIR__, 2) . '/src';
    $translations = require $root . '/translations/en/accessibility-audit.php';

    // Read every source file once, then look for each entry's text anywhere in
    // it. Deliberately looser than the patterns above: a string reached by a
    // shape this test does not model is still in use, and deleting it would be
    // the more expensive mistake.
    $blob = '';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $file): bool
                => !in_array($file->getFilename(), ['axe', 'dist', 'node_modules'], true),
        ),
    );

    foreach ($iterator as $file) {
        if ($file->isFile()
            && in_array($file->getExtension(), ['php', 'twig', 'js'], true)
            && !str_contains(str_replace('\\', '/', $file->getPathname()), '/translations/')
        ) {
            $blob .= file_get_contents($file->getPathname()) . "\n";
        }
    }

    // A message file key has already been through PHP's string parser, so its
    // apostrophes are bare where the source still escapes them. Without this
    // every message containing one reads as dead.
    $blob = str_replace(["\\'", '\\"'], ["'", '"'], $blob);

    $dead = [];

    foreach (array_keys($translations) as $message) {
        // Matched on a prefix: a long message may be wrapped across lines in
        // the source, so the whole of it never appears on one.
        if (!str_contains($blob, mb_substr((string)$message, 0, 45))) {
            $dead[] = $message;
        }
    }

    expect($dead)->toBe([], sprintf(
        "These are in the message file but appear nowhere in the source:\n  - %s",
        implode("\n  - ", array_slice($dead, 0, 40)),
    ));
});
