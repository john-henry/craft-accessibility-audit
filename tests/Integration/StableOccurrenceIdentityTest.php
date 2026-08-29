<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\helpers\Json;
use johnhenry\accessibilityaudit\services\VerdictService;

// ---------------------------------------------------------------------------
// Keeping an occurrence the same occurrence between scans.
//
// Some form builders mint a fresh token into every id on the page at each
// render. Formie does: the same textarea comes back as
// "fui-support-kqjyvj-fields-details", then "-jbuoir-", then "-jhaqor-", with
// the name "fields[details]" steady throughout. Keyed on anything carrying
// that token, an occurrence is a new occurrence every scan and every ruling
// made on it is orphaned, so a dismissed question comes straight back.
//
// No attempt is made to spot a generated id. "kqjyvj" and "submit" are both
// six lowercase letters, and guessing wrong the other way is worse than the
// problem: two separate occurrences would collapse into one and a ruling on
// either would answer both. So no id is trusted, and identity comes from what
// is left.
// ---------------------------------------------------------------------------

/** The same field as three separate renders would store it. */
function formieRenders(): array
{
    return array_map(
        static fn(string $token): string => '<textarea class="fui-input" id="fui-pluginSupport-' . $token
            . '-fields-details" name="fields[details]" aria-describedby="fui-pluginSupport-' . $token
            . '-fields-details-instructions">Tell us what went wrong</textarea>',
        ['kqjyvj', 'jbuoir', 'jhaqor'],
    );
}

beforeEach(function() {
    $this->verdicts = new VerdictService();
});

it('gives one field the same key on every render', function() {
    $hashes = array_map(fn(string $html): string => $this->verdicts->stableContextHash($html), formieRenders());

    expect(array_unique($hashes))->toHaveCount(1);
});

it('keeps two different fields apart', function() {
    // The collision that would matter. They differ by name, which is what
    // identity leans on once the ids are gone.
    $details = '<textarea class="fui-input" id="fui-a-kqjyvj-details" name="fields[details]">x</textarea>';
    $subject = '<textarea class="fui-input" id="fui-a-kqjyvj-subject" name="fields[subject]">x</textarea>';

    expect($this->verdicts->stableContextHash($details))
        ->not->toBe($this->verdicts->stableContextHash($subject));
});

it('still notices a real change to the markup', function() {
    // Only ids are ignored. A class change, or different text, is a different
    // occurrence and has to be asked about again.
    $before = '<a class="btn" id="x-kqjyvj-1" href="/a">Read more</a>';

    expect($this->verdicts->stableContextHash($before))
        ->not->toBe($this->verdicts->stableContextHash('<a class="btn" id="x-jbuoir-1" href="/b">Read more</a>'))
        ->and($this->verdicts->stableContextHash($before))
        ->not->toBe($this->verdicts->stableContextHash('<a class="btn" id="x-jbuoir-1" href="/a">Read less</a>'));
});

it('leaves attributes that merely end in id alone', function() {
    // data-id and similar are content, not element identity.
    $a = '<div data-id="7" id="gen-kqjyvj">x</div>';
    $b = '<div data-id="8" id="gen-jbuoir">x</div>';

    expect($this->verdicts->stableContextHash($a))->not->toBe($this->verdicts->stableContextHash($b));
});

it('drops the id-built css path from a contrast finding', function() {
    // Contrast findings carry a selector for highlighting. It is built from
    // ids, so it changes every render and must not be part of identity.
    $make = static fn(string $token): string => Json::encode([
        'html' => '<label id="fui-' . $token . '-label">Details</label>',
        'fg' => '#767676', 'bg' => '#ffffff', 'ratio' => 4.1, 'expected' => 4.5,
        'selector' => '#fui-' . $token . '-fields-details',
    ]);

    expect($this->verdicts->stableContextHash($make('kqjyvj')))
        ->toBe($this->verdicts->stableContextHash($make('jbuoir')));
});

it('still separates two contrast findings with different colours', function() {
    $make = static fn(string $fg): string => Json::encode([
        'html' => '<p>Text</p>', 'fg' => $fg, 'bg' => '#ffffff',
        'ratio' => 4.1, 'expected' => 4.5, 'selector' => '#a',
    ]);

    expect($this->verdicts->stableContextHash($make('#767676')))
        ->not->toBe($this->verdicts->stableContextHash($make('#999999')));
});

it('does not care which order the json keys arrived in', function() {
    $a = Json::encode(['html' => '<p>x</p>', 'fg' => '#111', 'bg' => '#fff']);
    $b = Json::encode(['bg' => '#fff', 'fg' => '#111', 'html' => '<p>x</p>']);

    expect($this->verdicts->stableContextHash($a))->toBe($this->verdicts->stableContextHash($b));
});

describe('rulings made before the key changed', function() {
    it('still finds one stored under the raw context', function() {
        // The migration path. Nothing anybody has already dismissed comes back
        // because the key moved underneath them.
        $context = formieRenders()[0];
        $map = ['potential:contrast-unmeasurable|' . $this->verdicts->contextHash($context) => 'dismissed'];

        expect($this->verdicts->lookup($map, 'potential:contrast-unmeasurable', $context))->toBe('dismissed');
    });

    it('still finds one stored under the newer stable key', function() {
        $context = formieRenders()[0];
        $map = ['potential:contrast-unmeasurable|' . $this->verdicts->stableContextHash($context) => 'dismissed'];

        expect($this->verdicts->lookup($map, 'potential:contrast-unmeasurable', $context))->toBe('dismissed');
    });

    it('finds a raw-keyed ruling from a later render of the same field', function() {
        // The one that proves the fallback is worth having: dismissed against
        // one render, asked again after another.
        [$first, $second] = formieRenders();
        $map = ['potential:contrast-unmeasurable|' . $this->verdicts->stableContextHash($first) => 'dismissed'];

        expect($this->verdicts->lookup($map, 'potential:contrast-unmeasurable', $second))->toBe('dismissed');
    });

    it('writes new rulings under the stable key', function() {
        $source = (string) file_get_contents((new ReflectionClass(VerdictService::class))->getFileName());

        expect($source)->toContain('$hash = $this->stableContextHash($context);');
    });
});

it('normalises line endings before hashing', function() {
    // The browser posts a multi-line snippet back with CRLF where the scanner
    // stored LF. Without this the ruling saves against a key no stored row
    // could match, and the question comes straight back.
    $stored = "<text fill=" . '"' . "rgba(0,0,0,0.1)" . '"' . ">" . PHP_EOL . "    625" . PHP_EOL . "</text>";
    $posted = str_replace(PHP_EOL, "" . chr(13) . chr(10), $stored);

    expect($this->verdicts->stableContextHash($stored))
        ->toBe($this->verdicts->stableContextHash($posted));
});
