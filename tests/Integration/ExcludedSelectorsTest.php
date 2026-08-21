<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\SettingsModel;
use johnhenry\accessibilityaudit\services\ContentScanner;

// ---------------------------------------------------------------------------
// Excluded elements: page furniture the site doesn't control (consent
// banners, chat widgets) must be invisible to every scan surface. These lock
// the PHP side: the selector resolver, the CSS→XPath translation, and the
// pre-scan DOM removal. The browser engines consume the same resolved list
// via getAxeExclude(), covered by its shape test below.
//
// Helpers are uniquely named (exsel*): Pest loads every file into one process.
// ---------------------------------------------------------------------------

function exselScanRuleIds(string $body): array
{
    $issues = (new ContentScanner())->scan(a11yCleanPage($body));

    return array_values(array_unique(array_map(
        static fn($issue): string => $issue->ruleId,
        $issues,
    )));
}

function exselWithSetting(string $excludedSelectors, callable $fn): void
{
    $settings = AccessibilityAudit::getInstance()->getSettings();
    $original = $settings->excludedSelectors;
    $settings->excludedSelectors = $excludedSelectors;
    try {
        $fn();
    } finally {
        $settings->excludedSelectors = $original;
    }
}

describe('excluded selectors: PHP scanner', function() {
    it('ignores findings inside a default consent-platform container', function() {
        // An orphaned li inside OneTrust's mount: without the exclusion this
        // is a guaranteed list-structure error (see ContentScannerTest).
        $ids = exselScanRuleIds('<div id="onetrust-consent-sdk"><div><li>Cookie category</li></div></div>');

        expect($ids)->not->toContain('list-structure');
    });

    it('still reports the same finding outside an excluded container', function() {
        $ids = exselScanRuleIds('<div><li>Stray item</li></div>');

        expect($ids)->toContain('list-structure');
    });

    it('keeps the potential scanner out of excluded containers too', function() {
        // A meaningful-looking image inside OneTrust's mount: without the
        // shared exclusion this asks "is this image purely decorative?".
        $issues = (new \johnhenry\accessibilityaudit\services\PotentialScanner())->scan(
            a11yCleanPage('<div id="onetrust-consent-sdk"><img src="/banner.jpg" alt=""></div>')
        );

        $ids = array_map(static fn($issue): string => $issue->ruleId, $issues);
        expect($ids)->not->toContain('potential:decorative-image');
    });

    it('still asks the potential question outside an excluded container', function() {
        $issues = (new \johnhenry\accessibilityaudit\services\PotentialScanner())->scan(
            a11yCleanPage('<img src="/banner.jpg" alt="">')
        );

        $ids = array_map(static fn($issue): string => $issue->ruleId, $issues);
        expect($ids)->toContain('potential:decorative-image');
    });

    it('honours a custom class selector from settings', function() {
        exselWithSetting('.live-chat-widget', function() {
            $ids = exselScanRuleIds('<div class="live-chat-widget"><div><li>Chat item</li></div></div>');

            expect($ids)->not->toContain('list-structure');
        });
    });

    it('skips an unsupported selector without breaking the scan', function() {
        exselWithSetting("div > span\n.fine-selector", function() {
            $ids = exselScanRuleIds('<div><li>Stray item</li></div>');

            // The combinator line is ignored on the PHP surface; the scan
            // itself still runs and reports normally.
            expect($ids)->toContain('list-structure');
        });
    });
});

describe('context snippets', function() {
    it('carries a text preview on attribute-less elements', function() {
        $issues = (new ContentScanner())->scan(a11yCleanPage('<div><li>Stray item</li></div>'));
        $issue = collect($issues)->firstWhere('ruleId', 'list-structure');

        expect($issue)->not->toBeNull()
            ->and($issue->context)->toBe('<li>Stray item</li>');
    });

    it('caps a long text preview with an ellipsis for the prefix matcher', function() {
        $long = str_repeat('word ', 30); // 150 chars of text
        $issues = (new ContentScanner())->scan(a11yCleanPage('<div><li>' . $long . '</li></div>'));
        $issue = collect($issues)->firstWhere('ruleId', 'list-structure');

        expect($issue->context)->toStartWith('<li>word word')
            ->and($issue->context)->toContain('…');
    });
});

describe('excluded selectors: resolution', function() {
    it('merges custom lines with the defaults and dedupes', function() {
        exselWithSetting("#my-widget\n#onetrust-consent-sdk\n\n  .spaced  ", function() {
            $resolved = AccessibilityAudit::getInstance()->getSettings()->resolvedExcludedSelectors();

            expect($resolved)->toContain('#my-widget')
                ->and($resolved)->toContain('.spaced')
                ->and($resolved)->toContain('#lanyard_root')
                // The duplicate of a default appears once.
                ->and(array_count_values($resolved)['#onetrust-consent-sdk'])->toBe(1);
        });
    });

    it('exposes the axe context shape: one selector per wrapped entry', function() {
        $exclude = AccessibilityAudit::getInstance()->audit->getAxeExclude();

        expect($exclude)->not->toBeEmpty();
        foreach ($exclude as $entry) {
            expect($entry)->toBeArray()->toHaveCount(1);
        }
        expect(array_map(static fn(array $entry): string => $entry[0], $exclude))
            ->toContain('#lanyard_root');
    });

    it('always includes every consent-platform default', function() {
        foreach (SettingsModel::DEFAULT_EXCLUDED_SELECTORS as $selector) {
            expect(AccessibilityAudit::getInstance()->getSettings()->resolvedExcludedSelectors())
                ->toContain($selector);
        }
    });
});
