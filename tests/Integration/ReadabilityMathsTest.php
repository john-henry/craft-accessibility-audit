<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * A page of short monosyllabic sentences, which makes the Flesch-Kincaid
 * arithmetic fully deterministic: every word is 3 letters or fewer (the
 * syllable counter scores those as exactly 1), and every sentence is exactly
 * 7 words. words/sentence = 7, syllables/word = 1, so:
 *   ease  = 206.835 - 1.015*7 - 84.6  = 115.13 → clamped to 100.0
 *   grade = 0.39*7 + 11.8 - 15.59     = -1.06  → clamped to 0.0
 */
function readabilitySimplePage(int $sentences = 15): string
{
    return '<html><body><main><p>'
        . str_repeat('The cat ran off to the den. ', $sentences)
        . '</p></main></body></html>';
}

// ---------------------------------------------------------------------------
// Flesch-Kincaid maths (deterministic fixture)
// ---------------------------------------------------------------------------

describe('ReadabilityService::analyseHtml maths', function() {
    it('computes exact scores for a fully deterministic text', function() {
        $result = AccessibilityAudit::getInstance()->readability->analyseHtml(readabilitySimplePage());

        expect($result)->not->toHaveKey('error')
            ->and($result['readingEase'])->toBe(100.0)
            ->and($result['readingEaseLabel'])->toBe('Very easy')
            ->and($result['gradeLevel'])->toBe(0.0)
            ->and($result['readingAge'])->toBe(5)
            ->and($result['wordCount'])->toBe(105)
            ->and($result['sentenceCount'])->toBe(15)
            ->and($result['syllableCount'])->toBe(105)
            ->and($result['avgWordsPerSentence'])->toBe(7.0)
            ->and($result['wcag315Pass'])->toBeTrue();
    });

    it('grades dense academic prose past the WCAG 3.1.5 threshold', function() {
        $sentence = 'Notwithstanding the organisational implementation of comprehensive administrative '
            . 'accessibility remediation methodologies, considerable infrastructural complications '
            . 'necessitate additional interdepartmental collaboration and systematic evaluation procedures. ';
        $result = AccessibilityAudit::getInstance()->readability->analyseHtml(
            '<html><body><main><p>' . str_repeat($sentence, 5) . '</p></main></body></html>'
        );

        expect($result)->not->toHaveKey('error')
            ->and($result['gradeLevel'])->toBeGreaterThan(9.0)
            ->and($result['wcag315Pass'])->toBeFalse()
            ->and($result['readingEase'])->toBeLessThan(50.0);
    });

    it('refuses to analyse fewer than 100 characters of text', function() {
        $result = AccessibilityAudit::getInstance()->readability->analyseHtml(
            '<html><body><main><p>Too short to score.</p></main></body></html>'
        );

        expect($result)->toHaveKey('error')
            ->and($result['error'])->toContain('Not enough text');
    });

    it('ignores nav, script, and footer chrome when scoring', function() {
        // The polysyllabic junk outside <main> would wreck the perfect score
        // if it leaked into the analysis.
        $html = '<html><body>'
            . '<nav>Institutional organisational miscellaneous configurational administration</nav>'
            . '<script>var incomprehensibility = "multidimensional";</script>'
            . '<main><p>' . str_repeat('The cat ran off to the den. ', 15) . '</p></main>'
            . '<footer>Supplementary infrastructural documentation</footer>'
            . '</body></html>';

        $result = AccessibilityAudit::getInstance()->readability->analyseHtml($html);

        expect($result['wordCount'])->toBe(105)
            ->and($result['readingEase'])->toBe(100.0);
    });

    it('surfaces the longest sentences as complex', function() {
        $long = 'This one very long and winding sentence keeps going on and on with many more words than any of the short ones do here. ';
        $result = AccessibilityAudit::getInstance()->readability->analyseHtml(
            '<html><body><main><p>' . str_repeat('The cat ran off to the den. ', 10) . $long . '</p></main></body></html>'
        );

        expect($result['complexSentences'][0])->toContain('long and winding');
    });
});

// ---------------------------------------------------------------------------
// Stored results round-trip
// ---------------------------------------------------------------------------

describe('ReadabilityService persistence', function() {
    it('stores a result and reads it back with proper numeric types', function() {
        $service = AccessibilityAudit::getInstance()->readability;
        $result = $service->analyseHtml(readabilitySimplePage());

        $service->storeResult($result, null, null, 'https://example.com/test-page', 'Test page');
        $rows = $service->getResults(url: 'https://example.com/test-page');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['readingEase'])->toBe(100.0)
            ->and($rows[0]['gradeLevel'])->toBe(0.0)
            ->and($rows[0]['wordCount'])->toBe(105)
            ->and($rows[0]['wcag315Pass'])->toBeTrue();
    });

    it('upserts on the same URL instead of duplicating', function() {
        $service = AccessibilityAudit::getInstance()->readability;
        $result = $service->analyseHtml(readabilitySimplePage());

        $service->storeResult($result, null, null, 'https://example.com/upsert-page');
        $service->storeResult($result, null, null, 'https://example.com/upsert-page');

        expect($service->getResults(url: 'https://example.com/upsert-page'))->toHaveCount(1);
    });

    it('extracts a page title from HTML', function() {
        $service = AccessibilityAudit::getInstance()->readability;

        expect($service->extractPageTitle('<html><head><title>About &amp; Contact</title></head></html>'))
            ->toBe('About & Contact')
            ->and($service->extractPageTitle('<html><head></head></html>'))->toBe('');
    });
});

// ---------------------------------------------------------------------------
// The analyse endpoints' throttle: retryAfter response shape
// ---------------------------------------------------------------------------

describe('ReadabilityController throttle', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $this->user = UserFactory::factory()->admin(true)->create();
        $this->actingAs($this->user);
    });

    afterEach(function() {
        // The throttle flag lives in Craft's cache, which the DB transaction
        // rollback doesn't touch; clear it so no state leaks between tests.
        Craft::$app->getCache()->delete(
            'accessibility-audit:readability-throttle:' . $this->user->id
        );
    });

    it('returns a machine-readable retryAfter on the second analyse call', function() {
        // A private-range host passes URL validation (starting the throttle
        // window) and is then refused by the SSRF guard, so no network
        // request is ever made.
        $first = $this->postJson('actions/accessibility-audit/readability/analyse', [
            'url' => 'https://192.168.0.1/page',
        ])->getJsonContent();

        expect($first['success'])->toBeFalse()
            ->and($first)->not->toHaveKey('retryAfter');

        $second = $this->postJson('actions/accessibility-audit/readability/analyse', [
            'url' => 'https://192.168.0.1/page',
        ])->getJsonContent();

        expect($second['success'])->toBeFalse()
            ->and($second['retryAfter'])->toBeInt()
            ->and($second['retryAfter'])->toBeGreaterThanOrEqual(1)
            ->and($second['error'])->toContain('wait');
    });

    it('shares the throttle window across both analyse endpoints', function() {
        $this->postJson('actions/accessibility-audit/readability/analyse', [
            'url' => 'https://192.168.0.1/page',
        ]);

        // Same user straight into analyse-entry: the shared per-user window
        // must refuse it with the same retryAfter shape.
        $json = $this->postJson('actions/accessibility-audit/readability/analyse-entry', [
            'elementId' => $this->user->id,
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['retryAfter'])->toBeGreaterThanOrEqual(1);
    });
});
