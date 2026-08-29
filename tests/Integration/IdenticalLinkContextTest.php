<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\services\PotentialScanner;

// ---------------------------------------------------------------------------
// Same-named links, judged by where they sit.
//
// 2.4.4 is satisfied when context separates two links, so the same pair can be
// a failure or perfectly conformant depending on the page around it. Reporting
// both at one weight is what teaches people to clear the queue unread, which is
// how the real ones get missed.
// ---------------------------------------------------------------------------

/** The docs-page shape from the specification: a site header and a sidebar. */
function docsPage(string $sidebarNavAttrs = '', string $sidebarLinkInner = 'Services'): string
{
    return <<<HTML
        <!DOCTYPE html><html lang="en"><head><title>T</title>
        <meta name="description" content="d"></head><body>
        <a href="#main">Skip to main content</a>
        <header><nav aria-label="Main"><a href="/services">Services</a></nav></header>
        <main id="main">
            <h1>API Reference</h1>
            <nav {$sidebarNavAttrs}><a href="/plugins/x/docs/api/services">{$sidebarLinkInner}</a></nav>
        </main>
        </body></html>
        HTML;
}

/** The rule's findings for a page. */
function identicalLinkFindings(string $html): array
{
    return array_values(array_filter(
        (new PotentialScanner())->scan($html),
        static fn($issue): bool => $issue->ruleId === 'potential:identical-links',
    ));
}

describe('identical links judged by context', function() {
    it('fails when a sidebar landmark has no name', function() {
        $found = identicalLinkFindings(docsPage());

        expect($found)->toHaveCount(1)
            ->and($found[0]->wcagCriterion)->toBe('2.4.4')
            ->and($found[0]->wcagLevel)->toBe('A')
            ->and($found[0]->severity)->toBe('warning')
            ->and($found[0]->message)->toContain('nav[unnamed')
            ->and($found[0]->message)->toContain('has no name');
    });

    it('advises rather than fails when both landmarks are named', function() {
        $found = identicalLinkFindings(docsPage('aria-label="Documentation"'));

        expect($found)->toHaveCount(1)
            ->and($found[0]->wcagCriterion)->toBe('2.4.9')
            ->and($found[0]->wcagLevel)->toBe('AAA')
            ->and($found[0]->severity)->toBe('notice')
            ->and($found[0]->message)->toContain('nav[Documentation]')
            ->and($found[0]->message)->toContain('nav[Main]')
            ->and($found[0]->message)->toContain('links list');
    });

    it('says nothing once a hidden suffix makes the names differ', function() {
        $found = identicalLinkFindings(docsPage(
            'aria-label="Documentation"',
            'Services<span class="sr-only">, API Reference</span>',
        ));

        expect($found)->toBeEmpty();
    });

    it('fails when both sit in the same landmark with nothing between them', function() {
        $html = '<!DOCTYPE html><html lang="en"><head><title>T</title>'
            . '<meta name="description" content="d"></head><body>'
            . '<a href="#main">Skip to main content</a><main id="main"><h1>H</h1>'
            . '<nav aria-label="Main"><a href="/a">Services</a><a href="/b">Services</a></nav>'
            . '</main></body></html>';

        $found = identicalLinkFindings($html);

        expect($found)->toHaveCount(1)
            ->and($found[0]->wcagCriterion)->toBe('2.4.4')
            ->and($found[0]->severity)->toBe('warning')
            ->and($found[0]->message)->toContain('same part of the page');
    });

    it('reports each destination with the place it sits in', function() {
        $found = identicalLinkFindings(docsPage('aria-label="Documentation"'));

        expect($found[0]->message)->toContain('nav[Main] → /services')
            ->and($found[0]->message)->toContain('nav[Documentation] → /plugins/x/docs/api/services');
    });

    it('keeps the context string byte for byte, so dismissals survive', function() {
        // The context is hashed to key the author's ruling. Rewording it would
        // discard every dismissal already made against this rule.
        $found = identicalLinkFindings(docsPage('aria-label="Documentation"'));

        expect($found[0]->context)
            ->toBe('"Services" → /services, /plugins/x/docs/api/services');
    });

    it('offers the fixes in order and warns off aria-label on the link', function() {
        $message = identicalLinkFindings(docsPage())[0]->message;

        expect($message)->toContain('Change the visible text')
            ->and($message)->toContain('sr-only')
            ->and($message)->toContain('aria-label on the surrounding landmark')
            ->and($message)->toContain('2.5.3 Label in Name')
            ->and($message)->toContain('Do not reach for aria-label on the link itself');
    });

    it('ignores a pair that cannot both be reached', function() {
        // Responsive markup ships both a rail and a drawer. One is not there
        // for the reader, so the two are not a duplicate anybody experiences.
        $html = '<!DOCTYPE html><html lang="en"><head><title>T</title>'
            . '<meta name="description" content="d"></head><body>'
            . '<a href="#main">Skip to main content</a><main id="main"><h1>H</h1>'
            . '<nav aria-label="Main"><a href="/a">Services</a></nav>'
            . '<nav aria-label="Mobile" hidden><a href="/b">Services</a></nav>'
            . '</main></body></html>';

        expect(identicalLinkFindings($html))->toBeEmpty();
    });

    it('leaves links alone when they share text and destination', function() {
        $html = '<!DOCTYPE html><html lang="en"><head><title>T</title>'
            . '<meta name="description" content="d"></head><body>'
            . '<a href="#main">Skip to main content</a><main id="main"><h1>H</h1>'
            . '<a href="/services">Services</a><p>and</p><a href="/services">Services</a>'
            . '</main></body></html>';

        expect(identicalLinkFindings($html))->toBeEmpty();
    });

    it('compares the announced name, not the visible text', function() {
        // Two icon links named only by aria-label are exactly this rule's case,
        // and reading the text node would miss them entirely.
        $html = '<!DOCTYPE html><html lang="en"><head><title>T</title>'
            . '<meta name="description" content="d"></head><body>'
            . '<a href="#main">Skip to main content</a><main id="main"><h1>H</h1>'
            . '<a href="/a" aria-label="Search"><svg></svg></a>'
            . '<a href="/b" aria-label="search"><svg></svg></a>'
            . '</main></body></html>';

        $found = identicalLinkFindings($html);

        expect($found)->toHaveCount(1)
            ->and($found[0]->wcagCriterion)->toBe('2.4.4');
    });
});

// ---------------------------------------------------------------------------
// How different are the destinations, really?
// ---------------------------------------------------------------------------

/** A page with two same-named links to the given destinations. */
function twoLinksTo(string $first, string $second): string
{
    return '<!DOCTYPE html><html lang="en"><head><title>T</title>'
        . '<meta name="description" content="d"></head><body>'
        . '<a href="#main">Skip to main content</a><main id="main"><h1>H</h1>'
        . '<nav aria-label="Main"><a href="' . $first . '">Services</a></nav>'
        . '<nav aria-label="Docs"><a href="' . $second . '">Services</a></nav>'
        . '</main></body></html>';
}

describe('identical links graded by how far apart the destinations are', function() {
    it('says nothing when both go to the same place', function() {
        // A body link and a sidebar link to one page is correct and common.
        expect(identicalLinkFindings(twoLinksTo('/services', '/services')))->toBeEmpty();
    });

    it('says nothing when the two differ only by a trailing slash', function() {
        expect(identicalLinkFindings(twoLinksTo('/services', '/services/')))->toBeEmpty();
    });

    it('treats a fragment-only difference as a tidiness point, not a failure', function() {
        // Most of what this rule finds in documentation. Nobody is sent
        // anywhere they did not expect.
        $found = identicalLinkFindings(twoLinksTo('/docs/api#overview', '/docs/api#events'));

        expect($found)->toHaveCount(1)
            ->and($found[0]->severity)->toBe('notice')
            ->and($found[0]->wcagCriterion)->toBe('2.4.9')
            ->and($found[0]->wcagLevel)->toBe('AAA')
            ->and($found[0]->message)->toContain('same page, different sections')
            ->and($found[0]->message)->not->toContain('2.4.4 Link Purpose (In Context) describes');
    });

    it('offers naming the section or dropping the fragment', function() {
        $message = identicalLinkFindings(twoLinksTo('/docs/api#overview', '/docs/api#events'))[0]->message;

        expect($message)->toContain('Name the section in the link text')
            ->and($message)->toContain('drop the fragment')
            ->and($message)->toContain('different sections of the same page');
    });

    it('still grades a genuinely different path by its landmarks', function() {
        $found = identicalLinkFindings(twoLinksTo('/services', '/docs/api/services'));

        expect($found)->toHaveCount(1)
            ->and($found[0]->message)->toContain('different named parts of the page')
            ->and($found[0]->message)->not->toContain('different sections of the same page');
    });

    it('treats a differing query string as a different destination', function() {
        // ?page=2 is another document, not another part of one.
        $found = identicalLinkFindings(twoLinksTo('/archive?page=1#top', '/archive?page=2#top'));

        expect($found)->toHaveCount(1)
            ->and($found[0]->message)->not->toContain('different sections of the same page');
    });

    it('reports a fragment-only pair in the same landmark as a tidiness point too', function() {
        // Where they sit cannot make a same-document pair into a 2.4.4 breach.
        $html = '<!DOCTYPE html><html lang="en"><head><title>T</title>'
            . '<meta name="description" content="d"></head><body>'
            . '<a href="#main">Skip to main content</a><main id="main"><h1>H</h1>'
            . '<nav aria-label="Main"><a href="/docs/api#a">Services</a>'
            . '<a href="/docs/api#b">Services</a></nav></main></body></html>';

        $found = identicalLinkFindings($html);

        expect($found)->toHaveCount(1)
            ->and($found[0]->severity)->toBe('notice')
            ->and($found[0]->wcagCriterion)->toBe('2.4.9');
    });
});

// ---------------------------------------------------------------------------
// Telling the two rows apart on the listing.
//
// The rule judges each occurrence on its own and can land on either criterion,
// and the Potential issues listing groups on criterion. One question per rule
// therefore puts the same sentence on two rows, with different counts and
// different conformance badges, and nothing saying which is which.
// ---------------------------------------------------------------------------

/** The listing label for a rule at a given criterion. */
function potentialQuestion(string $ruleId, ?string $criterion = null): string
{
    $method = new ReflectionMethod(
        \johnhenry\accessibilityaudit\controllers\DashboardController::class,
        '_potentialQuestion',
    );
    $method->setAccessible(true);

    return $method->invoke(
        new \johnhenry\accessibilityaudit\controllers\DashboardController(
            'dashboard',
            \johnhenry\accessibilityaudit\AccessibilityAudit::getInstance(),
        ),
        $ruleId,
        $criterion,
    );
}

it('asks a different question of each criterion', function() {
    $a = potentialQuestion('potential:identical-links', '2.4.4');
    $aaa = potentialQuestion('potential:identical-links', '2.4.9');

    expect($a)->not->toBe($aaa)
        ->and($a)->toContain('going to different places')
        ->and($aaa)->toContain('out of context');
});

it('keeps the plain question when no criterion is given', function() {
    // The page report groups by rule rather than by criterion, so it wants the
    // question that covers both.
    expect(potentialQuestion('potential:identical-links'))
        ->toBe(potentialQuestion('potential:identical-links', '2.4.4'));
});

it('leaves rules that only ever land on one criterion alone', function() {
    foreach (['potential:short-alt', 'potential:long-alt', 'potential:table-layout'] as $ruleId) {
        expect(potentialQuestion($ruleId, '1.1.1'))->toBe(potentialQuestion($ruleId));
    }
});

it('passes the criterion through from the listing', function() {
    $source = (string) file_get_contents(
        (new ReflectionClass(\johnhenry\accessibilityaudit\controllers\DashboardController::class))->getFileName(),
    );

    expect($source)->toContain("\$this->_potentialQuestion(\$ruleId, \$row['wcagCriterion'] ?? null)");
});
