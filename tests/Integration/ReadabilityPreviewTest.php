<?php

use craft\elements\Entry;
use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\controllers\ReadabilityController;
use markhuot\craftpest\factories\User as UserFactory;
use yii\web\NotFoundHttpException;

// ---------------------------------------------------------------------------
// The Readability preview target.
//
// It lives in Craft's Preview menu and shows the text being written. Craft's
// preview checks the token and swaps the draft in first, which is how an
// anonymous shared preview link reaches that draft and nothing else. Without a
// token, a signed-in user who can view the element gets its saved version.
//
// Helpers are uniquely named (rp*): Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** The preview URL for an entry. */
function rpUrl(Entry $entry): string
{
    return "actions/accessibility-audit/readability/preview?elementId={$entry->id}&siteId={$entry->siteId}";
}

/** The labels of an entry's preview targets. */
function rpTargetLabels(Entry $entry): array
{
    return array_column($entry->getPreviewTargets(), 'label');
}

beforeEach(function() {
    $this->rpEdition = AccessibilityAudit::getInstance()->edition;
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    AccessibilityAudit::getInstance()->getSettings()->readabilityPreviewTarget = true;
    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

afterEach(function() {
    AccessibilityAudit::getInstance()->edition = $this->rpEdition;
});

describe('The Preview menu', function() {
    it('offers a Readability view for an element the scanner covers', function() {
        $entry = scannableEntry();
        $targets = array_values(array_filter(
            $entry->getPreviewTargets(),
            static fn(array $target): bool => $target['label'] === 'Readability',
        ));

        expect($targets)->toHaveCount(1)
            ->and($targets[0]['url'])->toContain('accessibility-audit/readability/preview')
            ->toContain('elementId=' . $entry->id)
            ->and($targets[0]['refresh'])->toBeTrue();
    });

    it('offers nothing on the Standard edition', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        expect(rpTargetLabels(scannableEntry()))->not->toContain('Readability');
    });

    it('offers nothing with the setting off', function() {
        AccessibilityAudit::getInstance()->getSettings()->readabilityPreviewTarget = false;

        expect(rpTargetLabels(scannableEntry()))->not->toContain('Readability');
    });

    it('offers nothing for a page on Excluded Pages', function() {
        $entry = scannableEntry();
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $original = $settings->excludedUriPatterns;
        $settings->excludedUriPatterns = [['uriPattern' => '^' . preg_quote((string)$entry->uri) . '$']];

        try {
            expect(rpTargetLabels($entry))->not->toContain('Readability');
        } finally {
            $settings->excludedUriPatterns = $original;
        }
    });
});

describe('The preview page', function() {
    it('shows nothing outside Craft preview to someone signed out', function() {
        // No preview token, so no draft swapped in, and no session to vouch
        // for anything else: the id alone gets nothing.
        $entry = scannableEntry();
        Craft::$app->getUser()->logout(false);

        expect(fn() => $this->get(rpUrl($entry)))->toThrow(NotFoundHttpException::class);
    });

    it('shows the saved version outside Craft preview to someone who can view it', function() {
        // The edit screen's View menu opens the target with no preview token.
        $entry = scannableEntry('Saved version fixture');

        $content = $this->get(rpUrl($entry))->assertOk()->content;

        expect($content)->toContain('Saved version fixture')
            ->toContain('The readability of the saved text');
    });

    it('shows nothing outside Craft preview to someone who cannot view the element', function() {
        $entry = scannableEntry();
        $this->actingAs(UserFactory::factory()->create());

        expect(fn() => $this->get(rpUrl($entry)))->toThrow(NotFoundHttpException::class);
    });

    it('shows a signed-out visitor the draft Craft preview hands it', function() {
        $entry = scannableEntry();
        Craft::$app->getElements()->setPlaceholderElement($entry);
        Craft::$app->getUser()->logout(false);

        $this->get(rpUrl($entry))->assertOk();
    });

    it('shows a signed-out visitor nothing for an element other than the one previewed', function() {
        $previewed = scannableEntry();
        $other = scannableEntry();
        Craft::$app->getElements()->setPlaceholderElement($previewed);
        Craft::$app->getUser()->logout(false);

        expect(fn() => $this->get(rpUrl($other)))->toThrow(NotFoundHttpException::class);
    });

    it('shows nothing on the Standard edition', function() {
        $entry = scannableEntry();
        Craft::$app->getElements()->setPlaceholderElement($entry);
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        expect(fn() => $this->get(rpUrl($entry)))->toThrow(NotFoundHttpException::class);
    });

    it('shows nothing with the setting off', function() {
        $entry = scannableEntry();
        Craft::$app->getElements()->setPlaceholderElement($entry);
        AccessibilityAudit::getInstance()->getSettings()->readabilityPreviewTarget = false;

        expect(fn() => $this->get(rpUrl($entry)))->toThrow(NotFoundHttpException::class);
    });

    it('renders the element Craft preview hands it', function() {
        $entry = scannableEntry();
        Craft::$app->getElements()->setPlaceholderElement($entry);

        $response = $this->get(rpUrl($entry));
        $response->assertOk();

        expect($response->content)
            ->toContain('noindex')
            ->toContain('more characters');
    });

    it('carries no CSS or JS registered for other pages', function() {
        // An admin gets the frontend overlay's assets registered on every
        // rendered page; injected here, they would restyle the preview.
        $entry = scannableEntry();
        Craft::$app->getElements()->setPlaceholderElement($entry);
        $this->actingAsAdmin();

        $content = $this->get(rpUrl($entry))->assertOk()->content;

        expect($content)->not->toContain('cpresources');
    });

    it('allows anonymous requests to the preview action and nothing else', function() {
        $property = (new ReflectionClass(ReadabilityController::class))->getProperty('allowAnonymous');

        expect($property->getDefaultValue())->toBe(['preview']);
    });
});

describe('Marking hard sentences', function() {
    it('marks each sentence by its own reading level, paragraph by paragraph', function() {
        // Twenty words each. Five-letter words work out at grade 12, nine-letter
        // words at 31, three-letter words at 3. The last sentence has long
        // words but only ten of them, under the fourteen a mark needs.
        $hard = 'House ' . str_repeat('house ', 18) . 'house.';
        $veryHard = 'Elephants ' . str_repeat('elephants ', 18) . 'elephants.';
        $longButEasy = 'The ' . str_repeat('cat ', 18) . 'ran.';
        $shortButDense = 'Elephants ' . str_repeat('elephants ', 8) . 'elephants.';

        $blocks = AccessibilityAudit::getInstance()->getReadability()
            ->previewBlocks("Opening hours\n\n{$hard} {$veryHard} {$longButEasy} {$shortButDense}");

        expect($blocks)->toHaveCount(2)
            ->and(array_column($blocks[0], 'difficulty'))->toBe([null])
            ->and(array_column($blocks[1], 'difficulty'))->toBe(['hard', 'veryHard', null, null])
            ->and(array_column($blocks[1], 'level'))->toBe([12, 31, 3, 26]);
    });

    it('moves the marks with the reading target', function() {
        // Twenty-word sentences at grade 9, 12 and 15.
        $sentences = [
            'Four ' . str_repeat('four ', 15) . str_repeat('wooden ', 3) . 'wooden.',
            'House ' . str_repeat('house ', 18) . 'house.',
            'Garden ' . str_repeat('garden ', 13) . str_repeat('house ', 5) . 'house.',
        ];
        $readability = AccessibilityAudit::getInstance()->getReadability();
        $difficulties = static fn(string $target): array => array_column(
            $readability->previewBlocks(implode(' ', $sentences), $target)[0],
            'difficulty',
        );

        expect(array_column($readability->previewBlocks(implode(' ', $sentences))[0], 'level'))->toBe([9, 12, 15])
            ->and($difficulties('accessible'))->toBe(['hard', 'veryHard', 'veryHard'])
            ->and($difficulties('default'))->toBe([null, 'hard', 'veryHard'])
            ->and($difficulties('technical'))->toBe([null, null, 'hard'])
            ->and($difficulties('no-such-target'))->toBe($difficulties('default'));
    });

    it('only accepts a reading target it knows', function() {
        $settings = clone AccessibilityAudit::getInstance()->getSettings();

        $settings->readabilityTarget = 'technical';
        expect($settings->validate(['readabilityTarget']))->toBeTrue();

        $settings->readabilityTarget = 'easy';
        expect($settings->validate(['readabilityTarget']))->toBeFalse();
    });

    it('renders the target picker, every target for the browser, and an eye on each card', function() {
        // Grade 12: hard under the default target, very hard under accessible.
        $readability = AccessibilityAudit::getInstance()->getReadability();
        $text = 'House ' . str_repeat('house ', 18) . 'house.';

        $html = Craft::$app->getView()->renderTemplate('accessibility-audit/_readability/preview', [
            'element' => scannableEntry(),
            'preview' => [
                'result' => $readability->analyseText($text),
                'blocks' => $readability->previewBlocks($text, 'accessible'),
                'counts' => ['sentences' => 1, 'hard' => 0, 'veryHard' => 1, 'adverb' => 0, 'passive' => 0, 'complex' => 0],
            ],
            'suggestions' => null,
            'suggestedAt' => null,
            'canSuggest' => false,
            'minWords' => $readability::HARD_SENTENCE_MIN_WORDS,
            'target' => 'accessible',
            'targets' => $readability::TARGETS,
        ], View::TEMPLATE_MODE_CP);

        expect($html)
            ->toContain('value="accessible" checked')
            ->toContain('"technical":{"hard":13,"veryHard":17}')
            ->toContain('class="rp-s rp-verylong" data-words="20" data-level="12"')
            ->toContain('Very hard to read, grade 12:')
            ->toContain('14 words or more, at grade 10 or above')
            ->toContain('None found. Well done.')
            ->and(substr_count($html, 'data-rp-toggle='))->toBe(5);
    });
});
