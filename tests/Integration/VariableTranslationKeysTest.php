<?php

use johnhenry\accessibilityaudit\services\StatementProfiles;

// ---------------------------------------------------------------------------
// Strings translated through a variable.
//
// TranslationCoverageTest reads the source for literal Craft::t() arguments,
// so anything passed as a variable is invisible to it. StatementProfiles does
// exactly that: the jurisdiction labels live in an array and are translated on
// the way out, and they sat untranslated in every locale because nothing could
// see them.
//
// Every string reaching Craft::t() by that route belongs here.
// ---------------------------------------------------------------------------

describe('strings translated through a variable', function() {
    it('has an entry for every jurisdiction label', function(string $locale) {
        $messages = require dirname(__DIR__, 2) . "/src/translations/{$locale}/accessibility-audit.php";

        $missing = [];

        foreach (StatementProfiles::handles() as $handle) {
            $label = StatementProfiles::get($handle)['label'];

            if (!array_key_exists($label, $messages)) {
                $missing[] = $label;
            }
        }

        expect($missing)->toBe([]);
    })->with(['en', 'de', 'fr', 'es', 'it', 'nl']);

    it('actually renders them in the chosen language', function() {
        expect(Craft::t('accessibility-audit', StatementProfiles::get(StatementProfiles::PROFILE_EU)['label'], [], 'de'))
            ->toBe('Europäische Union (Richtlinie über den barrierefreien Zugang zu Websites)');
    });
});
