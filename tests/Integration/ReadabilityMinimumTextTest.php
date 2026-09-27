<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\ReadabilityService;

// ---------------------------------------------------------------------------
// The minimum is a character count: the constant is named for characters and
// the message shown says characters. Measured in bytes, accented and non-Latin
// text reached the threshold on half the characters or fewer, and got scored
// anyway. Flesch-Kincaid on sixty characters is noise presented as a number.
// ---------------------------------------------------------------------------

describe('the minimum text a readability score needs', function() {
    it('turns away text that is short in characters even where it is long in bytes', function() {
        // Sixty accented characters: a hundred and twenty bytes, so this
        // cleared a byte-counted threshold of one hundred and was scored.
        $text = str_repeat('é', 60);

        expect(mb_strlen($text))->toBeLessThan(ReadabilityService::MIN_CHARACTERS)
            ->and(strlen($text))->toBeGreaterThan(ReadabilityService::MIN_CHARACTERS);

        $result = AccessibilityAudit::getInstance()->getReadability()->analyseText($text);

        expect($result)->toHaveKey('error');
    });

    it('still scores text that genuinely clears the minimum', function() {
        $text = str_repeat('Der Aufzug ist außer Betrieb. ', 12);

        expect(mb_strlen($text))->toBeGreaterThan(ReadabilityService::MIN_CHARACTERS);

        expect(AccessibilityAudit::getInstance()->getReadability()->analyseText($text))
            ->not->toHaveKey('error');
    });
});
