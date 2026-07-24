<?php

use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\StatementExclusionModel;
use johnhenry\accessibilityaudit\models\StatementMetaModel;
use johnhenry\accessibilityaudit\services\StatementProfiles;
use johnhenry\accessibilityaudit\variables\AccessibilityVariable;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function publishFixture(int $siteId): void
{
    // Pin the compliance derivation: no VPAT sign-off and no scan-derived
    // failures, so the published status is a deterministic "partially
    // compliant" regardless of what the dev database happens to hold.
    resetVpat($siteId);
    resetScanData($siteId);

    saveVpatMetaFlat($siteId, [
        'productName' => 'Acme Council',
        'contactName' => 'The web team',
        'contactEmail' => 'access@example.com',
        'contactPhone' => '+353 1 234 5678',
        'evalMethods' => ['Keyboard-only testing', 'Screen reader testing with NVDA'],
    ]);

    $meta = new StatementMetaModel();
    $meta->profile = StatementProfiles::PROFILE_EU;
    $meta->enforcementBody = 'The Ombudsman';
    $meta->enforcementUrl = 'https://example.com/ombudsman';
    $meta->statementDate = '2026-07-01';
    $meta->reviewDate = '2026-07-15';
    $meta->feedbackResponseTime = 'within 5 working days';
    AccessibilityAudit::getInstance()->statement->saveMeta($siteId, $meta);

    AccessibilityAudit::getInstance()->statement->saveExclusions($siteId, [
        StatementExclusionModel::fromArray([
            'category' => StatementExclusionModel::CATEGORY_NON_COMPLIANCE,
            'content' => 'Some older PDF menus',
            'criterion' => '1.1.1',
            'reason' => 'They were published without text alternatives.',
            'plannedDate' => '2027-01-01',
        ]),
        StatementExclusionModel::fromArray([
            'category' => StatementExclusionModel::CATEGORY_BURDEN,
            'content' => 'The legacy video archive',
            'reason' => 'Recaptioning 4,000 hours is disproportionate.',
        ]),
        StatementExclusionModel::fromArray([
            'category' => StatementExclusionModel::CATEGORY_OUT_OF_SCOPE,
            'content' => 'Archived committee minutes from before 2018',
        ]),
    ]);
}

function renderPublishedStatement(int $siteId): string
{
    return (string) (new AccessibilityVariable())->accessibilityStatementHtml($siteId);
}

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    AccessibilityAudit::getInstance()->getSettings()->statementTemplate = '';
});

// ---------------------------------------------------------------------------
// The Twig variable
// ---------------------------------------------------------------------------

describe('craft.a11y.accessibilityStatement', function() {
    it('returns the statement data', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);

        $statement = (new AccessibilityVariable())->accessibilityStatement($siteId);

        expect($statement['profile'])->toBe(StatementProfiles::PROFILE_EU)
            ->and($statement['meta']['productName'])->toBe('Acme Council')
            ->and($statement['exclusions'])->toHaveKey('disproportionateBurden');
    });

    it('publishes on the Standard edition too', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        $variable = new AccessibilityVariable();

        expect($variable->accessibilityStatement($siteId))->not->toBeNull()
            ->and((string) $variable->accessibilityStatementHtml($siteId))->toContain('Compliance status');
    });
});

// ---------------------------------------------------------------------------
// The published document
// ---------------------------------------------------------------------------

describe('the published statement', function() {
    it('renders every section the EU model requires', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);

        $html = renderPublishedStatement($siteId);

        // The six sections of Commission Implementing Decision (EU) 2018/1523.
        // A statement missing one of these is not the document the law asks for.
        expect($html)->toContain('Accessibility statement')
            ->toContain('Compliance status')
            ->toContain('Non-accessible content')
            ->toContain('Feedback and contact information')
            ->toContain('Enforcement procedure')
            ->toContain('Preparation of this statement');
    });

    it('keeps the three shortfall categories under separate headings', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);

        $html = renderPublishedStatement($siteId);

        // They carry different obligations, so merging them would misrepresent
        // an exemption as a failure the organisation still owes work on.
        expect($html)->toContain('Non-compliance with the accessibility regulations')
            ->toContain('Disproportionate burden')
            ->toContain('Content not within the scope')
            ->toContain('Some older PDF menus')
            ->toContain('The legacy video archive')
            ->toContain('Archived committee minutes');
    });

    it('states the compliance status in words, not a code', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);

        $html = renderPublishedStatement($siteId);

        expect($html)->toContain('partially compliant')
            ->and($html)->not->toContain('partiallyCompliant');
    });

    it('carries the contact details and the enforcement route', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);

        $html = renderPublishedStatement($siteId);

        expect($html)->toContain('access@example.com')
            ->toContain('within 5 working days')
            ->toContain('The Ombudsman')
            ->toContain('https://example.com/ombudsman');
    });

    it('names the standard the claim is made against', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);

        expect(renderPublishedStatement($siteId))->toContain('EN 301 549');
    });

    it('omits sections it has no content for', function() {
        // A statement with no enforcement body must not render an empty
        // "Enforcement procedure" heading: a bare heading reads as an omission.
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $meta = new StatementMetaModel();
        $meta->profile = StatementProfiles::PROFILE_GENERIC;
        AccessibilityAudit::getInstance()->statement->saveMeta($siteId, $meta);
        AccessibilityAudit::getInstance()->statement->saveExclusions($siteId, []);

        $html = renderPublishedStatement($siteId);

        expect($html)->not->toContain('Enforcement procedure')
            ->and($html)->not->toContain('Non-accessible content');
    });

    it('renders without a Twig error when nothing has been filled in', function() {
        // The first thing anybody does is load the page before typing anything.
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        expect(renderPublishedStatement($siteId))->toContain('Accessibility statement');
    });
});

// ---------------------------------------------------------------------------
// Self-referential template guard
// ---------------------------------------------------------------------------

describe('a statementTemplate that renders the statement', function() {
    it('breaks the loop instead of hanging the request', function() {
        // Pointing the setting at the page that displays the statement is an
        // easy mistake. Unguarded it recurses until the PHP worker dies: no
        // error, no log, just a request that never returns. A few of those and
        // the whole site is down.
        $dir = Craft::getAlias('@templates') . '/_a11y-recursion-test';
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/render.twig', 'LOOP {{ craft.a11y.accessibilityStatementHtml() }}');

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);
        AccessibilityAudit::getInstance()->getSettings()->statementTemplate = '_a11y-recursion-test/render';

        $html = AccessibilityAudit::getInstance()->statement->render($siteId);

        // The outer call still honours the template; the inner one falls back to
        // the built-in markup rather than going round again.
        expect($html)->toContain('LOOP')
            ->and($html)->toContain('Compliance status')
            ->and(substr_count($html, 'Compliance status'))->toBe(1);

        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    });
});

// ---------------------------------------------------------------------------
// Preview
// ---------------------------------------------------------------------------

describe('the CP preview', function() {
    it('renders the same markup the site publishes', function() {
        // If these ever diverge the preview is worse than useless: it would be
        // telling an editor their statement says something it does not.
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);

        $published = renderPublishedStatement($siteId);
        $rendered = AccessibilityAudit::getInstance()->statement->render($siteId);

        expect($rendered)->toBe($published);
    });

    it('follows the custom template too', function() {
        $dir = Craft::getAlias('@templates') . '/_a11y-preview-test';
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/render.twig', 'CUSTOM-PREVIEW');

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);
        AccessibilityAudit::getInstance()->getSettings()->statementTemplate = '_a11y-preview-test/render';

        // A site overriding the markup must see its own in the preview, not the
        // built-in one.
        expect(AccessibilityAudit::getInstance()->statement->render($siteId))->toContain('CUSTOM-PREVIEW');

        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    });
});

// ---------------------------------------------------------------------------
// Template override
// ---------------------------------------------------------------------------

describe('the statementTemplate setting', function() {
    afterEach(function() {
        $path = Craft::getAlias('@templates') . '/_a11y-statement-test';

        if (is_dir($path)) {
            array_map('unlink', glob($path . '/*') ?: []);
            rmdir($path);
        }
    });

    it('hands the statement to a custom template', function() {
        $dir = Craft::getAlias('@templates') . '/_a11y-statement-test';
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/render.twig', 'CUSTOM-STATEMENT {{ statement.meta.productName }}');

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);
        AccessibilityAudit::getInstance()->getSettings()->statementTemplate = '_a11y-statement-test/render';

        expect(renderPublishedStatement($siteId))
            ->toContain('CUSTOM-STATEMENT')
            ->toContain('Acme Council');
    });

    it('falls back to the built-in markup when the template is missing', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        publishFixture($siteId);
        AccessibilityAudit::getInstance()->getSettings()->statementTemplate = '_nope/does-not-exist';

        // A typo in a setting must not take down a published page.
        expect(renderPublishedStatement($siteId))->toContain('Compliance status');
    });
});
