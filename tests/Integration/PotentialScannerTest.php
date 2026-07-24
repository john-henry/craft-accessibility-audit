<?php

use johnhenry\accessibilityaudit\services\PotentialScanner;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Scans a body snippet and returns the rule IDs that fired. The potential
 * checks are all element-local (no page-level rules), so a bare body wrapper
 * is enough of a document.
 *
 * @return string[]
 */
function a11yPotentialRuleIds(string $body): array
{
    $issues = (new PotentialScanner())->scan('<html><body>' . $body . '</body></html>');

    return array_values(array_unique(array_map(
        static fn($issue): string => $issue->ruleId,
        $issues,
    )));
}

// ---------------------------------------------------------------------------
// Table-driven: snippet in → rule fires / stays quiet
// ---------------------------------------------------------------------------

$potentialCases = [
    // potential:short-alt (1-3 chars, non-decorative)
    'short-alt fires on two-character alt' => ['potential:short-alt', '<img src="/p.jpg" alt="ab">', true],
    'short-alt fires on three-character alt' => ['potential:short-alt', '<img src="/p.jpg" alt="abc">', true],
    'short-alt allows a longer alt' => ['potential:short-alt', '<img src="/p.jpg" alt="A dog">', false],
    'short-alt skips role=presentation images' => ['potential:short-alt', '<img src="/p.jpg" alt="ab" role="presentation">', false],
    'short-alt skips aria-hidden images' => ['potential:short-alt', '<img src="/p.jpg" alt="ab" aria-hidden="true">', false],

    // potential:long-alt (> 150 chars)
    'long-alt fires past 150 characters' => ['potential:long-alt', '<img src="/p.jpg" alt="' . str_repeat('a', 151) . '">', true],
    'long-alt allows exactly 150 characters' => ['potential:long-alt', '<img src="/p.jpg" alt="' . str_repeat('a', 150) . '">', false],

    // potential:identical-links (same text, different destinations)
    'identical-links fires on same text with different hrefs' => [
        'potential:identical-links',
        '<a href="/annual-2024">Annual report</a><a href="/annual-2025">Annual report</a>',
        true,
    ],
    'identical-links allows same text pointing at the same place' => [
        'potential:identical-links',
        '<a href="/annual">Annual report</a><a href="/annual">Annual report</a>',
        false,
    ],
    'identical-links ignores in-page anchors' => [
        'potential:identical-links',
        '<a href="#one">Jump</a><a href="#two">Jump</a>',
        false,
    ],

    // potential:url-as-link-text
    'url-as-link-text fires on an https URL as text' => ['potential:url-as-link-text', '<a href="/x">https://example.com/report</a>', true],
    'url-as-link-text fires on a www address as text' => ['potential:url-as-link-text', '<a href="/x">www.example.com</a>', true],
    'url-as-link-text allows descriptive text' => ['potential:url-as-link-text', '<a href="https://example.com">The annual report</a>', false],

    // potential:decorative-image (empty alt, not marked decorative)
    'decorative-image asks about an empty alt with no role' => ['potential:decorative-image', '<img src="/p.jpg" alt="">', true],
    'decorative-image allows role=presentation' => ['potential:decorative-image', '<img src="/p.jpg" alt="" role="presentation">', false],
    'decorative-image allows aria-hidden' => ['potential:decorative-image', '<img src="/p.jpg" alt="" aria-hidden="true">', false],

    // potential:possible-heading (short bold-only paragraph)
    'possible-heading fires on a short bold-only paragraph' => ['potential:possible-heading', '<p><strong>Our Services</strong></p>', true],
    'possible-heading fires on b tags too' => ['potential:possible-heading', '<p><b>Opening Hours</b></p>', true],
    'possible-heading allows bold with surrounding text' => ['potential:possible-heading', '<p><strong>Note:</strong> the office shuts at five.</p>', false],
    'possible-heading allows long bold paragraphs' => [
        'potential:possible-heading',
        '<p><strong>This bold paragraph runs to eleven words so it reads as emphasis</strong></p>',
        false,
    ],
    'possible-heading ignores plain paragraphs' => ['potential:possible-heading', '<p>Our Services</p>', false],

    // potential:table-layout (no th, no caption, no presentation role)
    'table-layout asks about a bare table' => ['potential:table-layout', '<table><tr><td>1</td></tr></table>', true],
    'table-layout allows tables with th' => ['potential:table-layout', '<table><tr><th>N</th></tr></table>', false],
    'table-layout allows tables with a caption' => ['potential:table-layout', '<table><caption>Prices</caption><tr><td>1</td></tr></table>', false],
    'table-layout allows presentation tables' => ['potential:table-layout', '<table role="presentation"><tr><td>1</td></tr></table>', false],

    // potential:video-audio-desc
    'video-audio-desc asks about a video with no description track' => ['potential:video-audio-desc', '<video src="/clip.mp4"></video>', true],
    'video-audio-desc allows a descriptions track' => ['potential:video-audio-desc', '<video src="/clip.mp4"><track kind="descriptions" src="/d.vtt"></video>', false],
];

describe('PotentialScanner checks', function() use ($potentialCases) {
    it('resolves each snippet to the expected verdict', function(string $ruleId, string $snippet, bool $fires) {
        $fired = a11yPotentialRuleIds($snippet);

        if ($fires) {
            expect($fired)->toContain($ruleId);
        } else {
            expect($fired)->not->toContain($ruleId);
        }
    })->with($potentialCases);
});

// ---------------------------------------------------------------------------
// Contract behaviours
// ---------------------------------------------------------------------------

describe('PotentialScanner contract', function() {
    it('returns nothing for empty input', function() {
        expect((new PotentialScanner())->scan(''))->toBeEmpty()
            ->and((new PotentialScanner())->scan('   '))->toBeEmpty();
    });

    it('prefixes every rule id with potential: and grades everything a notice', function() {
        $issues = (new PotentialScanner())->scan(
            '<html><body>' .
            '<img src="/p.jpg" alt=""><img src="/q.jpg" alt="ab">' .
            '<a href="/a">Read</a><a href="/b">Read</a>' .
            '<p><strong>Our Services</strong></p>' .
            '<table><tr><td>1</td></tr></table>' .
            '<video src="/v.mp4"></video>' .
            '</body></html>'
        );

        expect($issues)->not->toBeEmpty();

        foreach ($issues as $issue) {
            expect($issue->ruleId)->toStartWith('potential:')
                ->and($issue->severity)->toBe('notice');
        }
    });

    it('reports identical link text once per text, not once per link', function() {
        $issues = (new PotentialScanner())->scan(
            '<html><body>' .
            '<a href="/a">Read more</a><a href="/b">Read more</a><a href="/c">Read more</a>' .
            '</body></html>'
        );

        $identical = array_filter($issues, fn($i) => $i->ruleId === 'potential:identical-links');

        expect($identical)->toHaveCount(1);
    });
});
