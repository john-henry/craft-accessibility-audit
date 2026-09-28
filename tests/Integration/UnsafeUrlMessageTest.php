<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;

// ---------------------------------------------------------------------------
// The guard throws plain strings, and the places that surface them translate
// with Craft::t('accessibility-audit', $e->getMessage()). A variable, not a
// literal: TranslationCoverageTest reads the source for literals, so it cannot
// see these, and a message added without an entry renders in English on a
// translated install with nothing to say so. Two of them already had.
//
// So the throw sites are read instead, and every message one of them carries
// has to be in the message file.
// ---------------------------------------------------------------------------

/** Every string thrown as an UnsafeUrlException anywhere in the plugin. */
function unsafeUrlMessages(): array
{
    $src = dirname(__DIR__, 2) . '/src';
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all(
            "/new UnsafeUrlException\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
            (string)file_get_contents($file->getPathname()),
            $m,
        );

        foreach ($m[1] as $message) {
            $found[] = str_replace("\\'", "'", $message);
        }
    }

    return array_values(array_unique($found));
}

it('finds the throw sites at all', function() {
    // Guards the regex above: a pattern that matched nothing would make the
    // next test pass without checking anything.
    expect(unsafeUrlMessages())->toHaveCount(6);
});

it('has a message-file entry for every message it can throw', function() {
    $translations = require dirname(__DIR__, 2) . '/src/translations/en/accessibility-audit.php';

    $missing = array_values(array_filter(
        unsafeUrlMessages(),
        static fn(string $message): bool => !array_key_exists($message, $translations),
    ));

    expect($missing)->toBe([], sprintf(
        "These guard messages have no translation entry, so they stay English on a "
        . "translated install:\n  - %s",
        implode("\n  - ", $missing),
    ));
});

it('is still the exception the guard throws', function() {
    // The list above is only meaningful while these are the same class.
    expect(new UnsafeUrlException('x'))->toBeInstanceOf(yii\base\Exception::class)
        ->and((new UnsafeUrlException('x'))->getName())->toBe('Unsafe URL');
});
