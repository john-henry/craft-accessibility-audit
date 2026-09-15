<?php

use craft\elements\Entry;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// A page on Excluded Pages is skipped by every scan path, so the surfaces that
// would scan it or show its score stay off it as well: the Re-scan response,
// the entry sidebar panel, and the overlay on both of its delivery paths.
//
// Helpers are uniquely named (ep*): Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/** An Excluded Pages row matching exactly this entry's URI. */
function epRowFor(Entry $entry): array
{
    return ['uriPattern' => '^' . preg_quote((string)$entry->uri) . '$'];
}

function epExclude(array $rows): void
{
    AccessibilityAudit::getInstance()->getSettings()->excludedUriPatterns = $rows;
}

function epDraftOf(Entry $entry): Entry
{
    $draft = Craft::$app->getDrafts()->createDraft($entry, $entry->authorId ?: 1, 'excluded preview');

    return Entry::find()
        ->draftId($draft->draftId)
        ->siteId($entry->siteId)
        ->status(null)
        ->one();
}

/** The source of a private method on the plugin class, for wiring assertions. */
function epPluginMethod(string $signature): string
{
    $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/AccessibilityAudit.php');
    $start = strpos($source, $signature);

    if ($start === false) {
        return '';
    }

    $end = strpos($source, "\n    private function ", $start + strlen($signature));

    return substr($source, $start, $end === false ? null : $end - $start);
}

beforeEach(function() {
    $plugin = AccessibilityAudit::getInstance();
    $settings = $plugin->getSettings();

    $this->epOriginal = [
        'edition' => $plugin->edition,
        'excludedUriPatterns' => $settings->excludedUriPatterns,
        'decoupledOverlay' => $settings->decoupledOverlay,
        'overlayApiToken' => $settings->overlayApiToken,
    ];
});

afterEach(function() {
    $plugin = AccessibilityAudit::getInstance();
    $settings = $plugin->getSettings();

    $plugin->edition = $this->epOriginal['edition'];
    $settings->excludedUriPatterns = $this->epOriginal['excludedUriPatterns'];
    $settings->decoupledOverlay = $this->epOriginal['decoupledOverlay'];
    $settings->overlayApiToken = $this->epOriginal['overlayApiToken'];
});

describe('Re-scan', function() {
    it('says the page is excluded instead of reporting a scan', function() {
        // A success here makes the panel reload an unchanged page, which reads
        // as a scan that failed without saying so.
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $entry = scannableEntry();
        epExclude([epRowFor($entry)]);

        $json = $this->post('actions/accessibility-audit/audit/scan-entry', [
            'entryId' => $entry->id,
            'siteId' => $entry->siteId,
        ])->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['excluded'] ?? false)->toBeTrue()
            ->and($json['error'])->toContain('Excluded Pages')
            ->and($json)->not->toHaveKey('score');
    });
});

describe('Sidebar panel', function() {
    it('treats a page matched by a URI pattern as excluded, not just an excluded type', function() {
        $entry = scannableEntry();
        $other = scannableEntry();
        epExclude([epRowFor($entry)]);

        $audit = AccessibilityAudit::getInstance()->getAudit();

        expect($audit->isElementExcluded($entry))->toBeTrue()
            ->and($audit->isElementExcluded($other))->toBeFalse();
    });
});

describe('Overlay on pages Craft renders', function() {
    it('judges a matched element by its URI', function() {
        $entry = scannableEntry();
        $other = scannableEntry();
        epExclude([epRowFor($entry)]);

        $overlay = AccessibilityAudit::getInstance()->getOverlay();

        expect($overlay->isPageExcluded($entry, 'unrelated/path', (int)$entry->siteId))->toBeTrue()
            ->and($overlay->isPageExcluded($other, (string)$entry->uri, (int)$other->siteId))->toBeFalse();
    });

    it('judges a preview of an excluded page by its canonical URI', function() {
        // A fresh draft may carry no URI of its own; the page it previews does.
        $entry = scannableEntry();
        epExclude([epRowFor($entry)]);

        expect(AccessibilityAudit::getInstance()->getOverlay()
            ->isPageExcluded(epDraftOf($entry), '', (int)$entry->siteId))->toBeTrue();
    });

    it('judges a page with no element behind it by its path', function() {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        epExclude([['uriPattern' => '^search']]);

        $overlay = AccessibilityAudit::getInstance()->getOverlay();

        expect($overlay->isPageExcluded(null, '/search/results', $siteId))->toBeTrue()
            ->and($overlay->isPageExcluded(null, 'about', $siteId))->toBeFalse();
    });

    it('checks exclusion before injecting the overlay', function() {
        // The injection runs on EVENT_END_BODY of a front-end render, which a
        // test cannot drive, so the ordering is asserted from source.
        $body = epPluginMethod('maybeInjectFrontendAxe(): void');
        $gate = strpos($body, 'isPageExcluded(');
        $inject = strpos($body, 'registerAssetBundle(FrontendAxeAsset::class)');

        expect($gate)->not->toBeFalse()
            ->and($inject)->not->toBeFalse()
            ->and($gate)->toBeLessThan($inject);
    });
});

describe('Overlay on decoupled front ends', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $settings->decoupledOverlay = true;
        $settings->overlayApiToken = hash('sha256', 'excluded-secret');
    });

    it('flags an excluded URL when resolving it', function() {
        $entry = scannableEntry();
        $other = scannableEntry();
        epExclude([epRowFor($entry)]);

        $overlay = AccessibilityAudit::getInstance()->getOverlay();
        $excluded = $overlay->resolveElementFromUrl((string)$entry->getUrl());
        $included = $overlay->resolveElementFromUrl((string)$other->getUrl());

        expect($excluded['excluded'])->toBeTrue()
            ->and($excluded['element'])->toBeNull()
            ->and($included['excluded'])->toBeFalse();
    });

    it('confirms the token but hands the loader no overlay config', function() {
        // Still a success, so an activation link opened on an excluded page
        // saves the token for the rest of the site.
        $entry = scannableEntry();
        epExclude([epRowFor($entry)]);

        $json = $this->http('post', 'accessibility-audit/overlay/resolve')
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer excluded-secret')
            ->setBody(['url' => (string)$entry->getUrl()])
            ->send()
            ->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and($json['excluded'] ?? false)->toBeTrue()
            ->and($json)->not->toHaveKey('config');
    });

    it('has the loader mount nothing on an excluded page', function() {
        $loader = (string)file_get_contents(
            dirname(__DIR__, 2) . '/src/resources/js/overlay-loader.js',
        );

        expect($loader)->toContain('if (injectedOwnsPage || data.excluded) return;');
    });
});
