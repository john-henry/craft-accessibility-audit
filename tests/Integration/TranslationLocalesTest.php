<?php

// ---------------------------------------------------------------------------
// A translated message file is only worth having if Craft actually resolves it.
// A file in the wrong place, or a category that does not match the plugin
// handle, fails silently: every string just stays English and nobody notices
// until a German customer opens the control panel.
// ---------------------------------------------------------------------------

describe('the translated message files', function() {
    it('is resolved by Craft for the locale', function(string $locale, string $source, string $translated) {
        expect(Craft::t('accessibility-audit', $source, [], $locale))->toBe($translated);
    })->with([
        'de: a plain label' => ['de', 'Settings', 'Einstellungen'],
        'de: an accented one' => ['de', 'Overview', 'Übersicht'],
        'de: a compound term' => ['de', 'Accessibility Statement', 'Barrierefreiheitserklärung'],
        'de: a VPAT conformance term' => ['de', 'Not Applicable', 'Nicht anwendbar'],
        'fr: a plain label' => ['fr', 'Settings', 'Paramètres'],
        'fr: an accented one' => ['fr', 'Overview', "Vue d'ensemble"],
        'fr: a compound term' => ['fr', 'Accessibility Statement', "Déclaration d'accessibilité"],
        'fr: a VPAT conformance term' => ['fr', 'Not Applicable', 'Non applicable'],
        'es: a plain label' => ['es', 'Settings', 'Ajustes'],
        'es: an accented one' => ['es', 'Overview', 'Resumen'],
        'es: a compound term' => ['es', 'Accessibility Statement', 'Declaración de accesibilidad'],
        'es: a VPAT conformance term' => ['es', 'Not Applicable', 'No aplicable'],
        'it: a plain label' => ['it', 'Settings', 'Impostazioni'],
        'it: an accented one' => ['it', 'Accessibility', 'Accessibilità'],
        'it: a compound term' => ['it', 'Accessibility Statement', 'Dichiarazione di accessibilità'],
        'it: a VPAT conformance term' => ['it', 'Not Applicable', 'Non applicabile'],
        'nl: a plain label' => ['nl', 'Settings', 'Instellingen'],
        'nl: an accented one' => ['nl', 'Pages', 'Pagina’s'],
        'nl: a compound term' => ['nl', 'Accessibility Statement', 'Toegankelijkheidsverklaring'],
        'nl: a VPAT conformance term' => ['nl', 'Not Applicable', 'Niet van toepassing'],
    ]);

    it('keeps every placeholder the English string uses', function(string $locale) {
        // A dropped or renamed placeholder renders the literal token to the
        // user, and Craft will not warn about it.
        $en = require dirname(__DIR__, 2) . '/src/translations/en/accessibility-audit.php';
        $de = require dirname(__DIR__, 2) . "/src/translations/{$locale}/accessibility-audit.php";

        $mismatched = [];

        foreach ($de as $source => $translated) {
            // ICU plural forms carry their own translated words inside braces
            // ({n, plural, =1{adverb} other{adverbs}}), which are meant to
            // differ. Only the plain {placeholder} tokens have to match.
            $strip = static fn(string $v): string => (string)preg_replace('/\{\w+, *plural,.*\}/', '', $v);

            preg_match_all('/\{(\w+)\}/', $strip((string)$source), $want);
            preg_match_all('/\{(\w+)\}/', $strip((string)$translated), $got);

            sort($want[1]);
            sort($got[1]);

            if ($want[1] !== $got[1]) {
                $mismatched[$source] = $translated;
            }
        }

        expect($mismatched)->toBe([]);
    })->with(['de', 'fr', 'es', 'it', 'nl']);

    it('covers every string the plugin translates', function(string $locale) {
        // The same discipline TranslationCoverageTest applies to English. A
        // German install falls back silently on a missing key, so without this
        // the file rots one new string at a time and nobody sees it.
        $en = require dirname(__DIR__, 2) . '/src/translations/en/accessibility-audit.php';
        $de = require dirname(__DIR__, 2) . "/src/translations/{$locale}/accessibility-audit.php";

        $missing = array_diff(array_keys($en), array_keys($de));

        expect($missing)->toBe([]);
    })->with(['de', 'fr', 'es', 'it', 'nl']);

    it('translates only strings the plugin actually uses', function(string $locale) {
        $en = require dirname(__DIR__, 2) . '/src/translations/en/accessibility-audit.php';
        $de = require dirname(__DIR__, 2) . "/src/translations/{$locale}/accessibility-audit.php";

        expect(array_diff(array_keys($de), array_keys($en)))->toBe([]);
    })->with(['de', 'fr', 'es', 'it', 'nl']);
});
