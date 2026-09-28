<?php

use craft\db\Query;
use craft\elements\User;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Marking issues resolved.
//
// A finding that stops appearing is the whole point of scanning again, and it
// is what the Resolved screen and the "issues fixed" trend are built from. The
// transition had no test: every existing one seeds `isResolved` rows rather
// than watching a scan flip one, so a rescan that quietly stopped resolving
// anything would have gone unnoticed until somebody wondered why the Resolved
// list never grew.
// ---------------------------------------------------------------------------

/** A page that passes every rule, with a slot for markup that does not. */
function resolvedFixture(string $body = ''): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Resolved fixture</title>
    <meta name="description" content="A fixture page for resolution tests.">
</head>
<body>
    <a href="#main">Skip to main content</a>
    <header><nav><a href="/about">About us</a></nav></header>
    <main id="main">
        <h1>Page heading</h1>
        {$body}
    </main>
    <footer><p>Footer text</p></footer>
</body>
</html>
HTML;
}

/** The issue rows for one element, newest scan first. */
function resolvedRowsFor(int $elementId, int $siteId): array
{
    return (new Query())
        ->select(['i.ruleId', 'i.isResolved', 'i.dateResolved', 'i.scanId'])
        ->from(['i' => '{{%accessibilityaudit_issues}}'])
        ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], 's.id = i.scanId')
        ->where(['s.elementId' => $elementId, 's.siteId' => $siteId])
        ->orderBy(['i.scanId' => SORT_DESC])
        ->all();
}

describe('marking issues resolved', function() {
    beforeEach(function() {
        $this->resolvedElementId = (int) UserFactory::factory()->create()->id;
        $this->resolvedSiteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    });

    it('flips a finding to resolved once the markup that caused it is fixed', function() {
        $audit = AccessibilityAudit::getInstance()->getAudit();

        // First pass: an image with no alt attribute, which is img-alt.
        $audit->scanHtml(
            resolvedFixture('<img src="/uploads/photo.jpg">'),
            $this->resolvedElementId,
            User::class,
            $this->resolvedSiteId,
        );

        $before = resolvedRowsFor($this->resolvedElementId, $this->resolvedSiteId);
        $imgAlt = array_values(array_filter($before, static fn(array $r): bool => $r['ruleId'] === 'img-alt'));

        expect($imgAlt)->toHaveCount(1)
            ->and((bool) $imgAlt[0]['isResolved'])->toBeFalse();

        // Second pass: same page with the alt written in.
        $audit->scanHtml(
            resolvedFixture('<img src="/uploads/photo.jpg" alt="A photograph of the harbour">'),
            $this->resolvedElementId,
            User::class,
            $this->resolvedSiteId,
        );

        $after = resolvedRowsFor($this->resolvedElementId, $this->resolvedSiteId);
        $oldImgAlt = array_values(array_filter(
            $after,
            static fn(array $r): bool => $r['ruleId'] === 'img-alt' && $r['scanId'] === $imgAlt[0]['scanId'],
        ));

        expect($oldImgAlt)->toHaveCount(1)
            ->and((bool) $oldImgAlt[0]['isResolved'])->toBeTrue()
            ->and($oldImgAlt[0]['dateResolved'])->not->toBeNull();
    });

    it('leaves a finding that is still there alone', function() {
        $audit = AccessibilityAudit::getInstance()->getAudit();
        $broken = '<img src="/uploads/photo.jpg">';

        $audit->scanHtml(resolvedFixture($broken), $this->resolvedElementId, User::class, $this->resolvedSiteId);
        $first = resolvedRowsFor($this->resolvedElementId, $this->resolvedSiteId);
        $firstScanId = $first[0]['scanId'];

        // Nothing fixed between the two passes.
        $audit->scanHtml(resolvedFixture($broken), $this->resolvedElementId, User::class, $this->resolvedSiteId);

        $stillOpen = array_values(array_filter(
            resolvedRowsFor($this->resolvedElementId, $this->resolvedSiteId),
            static fn(array $r): bool => $r['ruleId'] === 'img-alt' && $r['scanId'] === $firstScanId,
        ));

        expect($stillOpen)->toHaveCount(1)
            ->and((bool) $stillOpen[0]['isResolved'])->toBeFalse();
    });

    it('does not resolve a rule that was only added to the ignore list', function() {
        // Muting a rule takes it out of the scan results, which looks exactly
        // like a fix from the resolver's point of view. Counting it as one
        // would put work nobody did on the Resolved screen.
        $audit = AccessibilityAudit::getInstance()->getAudit();
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $originalIgnore = $settings->ignoreRules;

        $audit->scanHtml(
            resolvedFixture('<img src="/uploads/photo.jpg">'),
            $this->resolvedElementId,
            User::class,
            $this->resolvedSiteId,
        );

        $firstScanId = resolvedRowsFor($this->resolvedElementId, $this->resolvedSiteId)[0]['scanId'];

        $settings->ignoreRules = array_merge($originalIgnore, ['img-alt']);

        try {
            $audit->scanHtml(
                resolvedFixture('<img src="/uploads/photo.jpg">'),
                $this->resolvedElementId,
                User::class,
                $this->resolvedSiteId,
            );
        } finally {
            $settings->ignoreRules = $originalIgnore;
        }

        $muted = array_values(array_filter(
            resolvedRowsFor($this->resolvedElementId, $this->resolvedSiteId),
            static fn(array $r): bool => $r['ruleId'] === 'img-alt' && $r['scanId'] === $firstScanId,
        ));

        expect($muted)->toHaveCount(1)
            ->and((bool) $muted[0]['isResolved'])->toBeFalse();
    });
});
