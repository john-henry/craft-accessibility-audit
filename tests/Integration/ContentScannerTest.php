<?php

use johnhenry\accessibilityaudit\services\ContentScanner;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Wraps a body snippet in a page that passes every page-level rule (lang,
 * title, meta description, skip link, landmarks, single h1), so element-level
 * cases only ever see the rule they're exercising.
 */
function a11yCleanPage(string $body = ''): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Test page</title>
    <meta name="description" content="A test page.">
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

/**
 * Scans a snippet inside the clean page and returns the rule IDs that fired.
 *
 * @return string[]
 */
function a11yScanSnippet(string $body): array
{
    $issues = (new ContentScanner())->scan(a11yCleanPage($body));

    return array_values(array_unique(array_map(
        static fn($issue): string => $issue->ruleId,
        $issues,
    )));
}

// ---------------------------------------------------------------------------
// Baseline: the clean fixture itself must not trip anything
// ---------------------------------------------------------------------------

describe('ContentScanner baseline', function() {
    it('finds nothing on the clean fixture page', function() {
        expect(a11yScanSnippet(''))->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// Element-level rules, table-driven: snippet in → rule fires / stays quiet
// ---------------------------------------------------------------------------

$elementRuleCases = [
    // img-alt (1.1.1)
    'img-alt fires on a missing alt attribute' => ['img-alt', '<img src="/photo.jpg">', true],
    'img-alt allows populated alt text' => ['img-alt', '<img src="/photo.jpg" alt="A red setter on a beach">', false],
    'img-alt allows empty alt (decorative image)' => ['img-alt', '<img src="/photo.jpg" alt="">', false],

    // img-alt-filename (1.1.1)
    'img-alt-filename fires when alt is a filename' => ['img-alt-filename', '<img src="/photo.jpg" alt="IMG_4032.jpg">', true],
    'img-alt-filename allows real alt text' => ['img-alt-filename', '<img src="/photo.jpg" alt="A red setter on a beach">', false],

    // heading-order (1.3.1) — the fixture provides the preceding h1
    'heading-order fires when a level is skipped' => ['heading-order', '<h3>Skipped from h1</h3>', true],
    'heading-order allows sequential levels' => ['heading-order', '<h2>Section</h2><h3>Subsection</h3>', false],

    // empty-heading (1.3.1)
    'empty-heading fires on an empty heading' => ['empty-heading', '<h2></h2>', true],
    'empty-heading fires on whitespace-only headings' => ['empty-heading', '<h2>   </h2>', true],
    'empty-heading allows headings with text' => ['empty-heading', '<h2>Section</h2>', false],

    // multiple-h1 — the fixture already has one h1
    'multiple-h1 fires on a second h1' => ['multiple-h1', '<h1>Another top heading</h1>', true],

    // link-name (4.1.2)
    'link-name fires on a link with no accessible name' => ['link-name', '<a href="/page"></a>', true],
    'link-name allows text links' => ['link-name', '<a href="/page">Annual report</a>', false],
    'link-name allows aria-label' => ['link-name', '<a href="/page" aria-label="Annual report"></a>', false],
    'link-name allows an image with alt inside' => ['link-name', '<a href="/page"><img src="/i.png" alt="Annual report"></a>', false],

    // link-generic (2.4.4)
    'link-generic fires on generic text' => ['link-generic', '<a href="/page">Click here</a>', true],
    'link-generic allows descriptive text' => ['link-generic', '<a href="/page">Read the annual report</a>', false],

    // link-new-window (3.2.2)
    'link-new-window fires without a warning label' => ['link-new-window', '<a href="/page" target="_blank">Annual report</a>', true],
    'link-new-window allows a title mentioning the new tab' => ['link-new-window', '<a href="/page" target="_blank" title="Opens in a new tab">Annual report</a>', false],

    // button-name (4.1.2)
    'button-name fires on an empty button' => ['button-name', '<button></button>', true],
    'button-name allows text' => ['button-name', '<button>Save</button>', false],
    'button-name allows aria-label' => ['button-name', '<button aria-label="Save"></button>', false],

    // form-label (1.3.1)
    'form-label fires on an unlabelled input' => ['form-label', '<input type="text" name="foo">', true],
    'form-label fires when only a placeholder is present' => ['form-label', '<input type="text" name="foo" placeholder="Your town">', true],
    'form-label allows label[for]' => ['form-label', '<label for="town">Town</label><input type="text" id="town" name="foo">', false],
    'form-label allows a wrapping label' => ['form-label', '<label>Town <input type="text" name="foo"></label>', false],
    'form-label allows aria-label' => ['form-label', '<input type="text" name="foo" aria-label="Town">', false],
    'form-label skips hidden and submit inputs' => ['form-label', '<input type="hidden" name="token"><input type="submit" value="Go">', false],

    // select-label (1.3.1)
    'select-label fires on an unlabelled select' => ['select-label', '<select name="county"><option>Cork</option></select>', true],
    'select-label allows label[for]' => ['select-label', '<label for="county">County</label><select id="county" name="county"><option>Cork</option></select>', false],

    // table-header (1.3.1)
    'table-header fires on a table without th' => ['table-header', '<table><tr><td>1</td></tr></table>', true],
    'table-header allows tables with th' => ['table-header', '<table><tr><th scope="col">N</th></tr><tr><td>1</td></tr></table>', false],
    'table-header skips presentation tables' => ['table-header', '<table role="presentation"><tr><td>1</td></tr></table>', false],

    // iframe-title (4.1.2)
    'iframe-title fires on an untitled iframe' => ['iframe-title', '<iframe src="https://example.com/embed"></iframe>', true],
    'iframe-title allows a titled iframe' => ['iframe-title', '<iframe src="https://example.com/embed" title="Location map"></iframe>', false],

    // video-captions (1.2.2)
    'video-captions fires without a captions track' => ['video-captions', '<video src="/clip.mp4"></video>', true],
    'video-captions allows a captions track' => ['video-captions', '<video src="/clip.mp4"><track kind="captions" src="/clip.vtt"></video>', false],
    'video-captions allows a subtitles track' => ['video-captions', '<video src="/clip.mp4"><track kind="subtitles" src="/clip.vtt"></video>', false],

    // autoplay (1.4.2)
    'autoplay fires on unmuted autoplay video' => ['autoplay', '<video src="/clip.mp4" autoplay></video>', true],
    'autoplay fires on unmuted autoplay audio' => ['autoplay', '<audio src="/clip.mp3" autoplay></audio>', true],
    'autoplay allows muted autoplay' => ['autoplay', '<video src="/clip.mp4" autoplay muted></video>', false],
    'autoplay allows video without autoplay' => ['autoplay', '<video src="/clip.mp4" controls><track kind="captions" src="/c.vtt"></video>', false],

    // duplicate-id (4.1.1)
    'duplicate-id fires on repeated ids' => ['duplicate-id', '<p id="dup">One</p><span id="dup">Two</span>', true],
    'duplicate-id allows unique ids' => ['duplicate-id', '<p id="one">One</p><span id="two">Two</span>', false],
    'duplicate-id ignores empty ids (nothing can reference them)' => ['duplicate-id', '<p id="">One</p><span id="">Two</span>', false],
    'duplicate-id ignores whitespace-only ids' => ['duplicate-id', '<p id=" ">One</p><span id=" ">Two</span>', false],

    // aria-hidden-focus (4.1.2)
    'aria-hidden-focus fires on a focusable child' => ['aria-hidden-focus', '<div aria-hidden="true"><button>Save</button></div>', true],
    'aria-hidden-focus allows non-focusable content' => ['aria-hidden-focus', '<div aria-hidden="true"><p>Decoration</p></div>', false],

    // list-structure (1.3.1)
    'list-structure fires on an orphaned li' => ['list-structure', '<li>Stray item</li>', true],
    'list-structure allows li inside ul' => ['list-structure', '<ul><li>Item</li></ul>', false],
    'list-structure allows li inside ol' => ['list-structure', '<ol><li>Item</li></ol>', false],

    // input-type (1.3.5) — labelled so form-label stays out of the picture
    'input-type fires on an email-ish field without autocomplete' => ['input-type', '<label>Email <input type="text" name="email_address"></label>', true],
    'input-type allows an autocomplete attribute' => ['input-type', '<label>Email <input type="text" name="email_address" autocomplete="email"></label>', false],
    'input-type ignores fields with no personal-data hint' => ['input-type', '<label>Reference <input type="text" name="reference"></label>', false],
];

describe('ContentScanner element rules', function() use ($elementRuleCases) {
    it('resolves each snippet to the expected verdict', function(string $ruleId, string $snippet, bool $fires) {
        $fired = a11yScanSnippet($snippet);

        if ($fires) {
            expect($fired)->toContain($ruleId);
        } else {
            expect($fired)->not->toContain($ruleId);
        }
    })->with($elementRuleCases);
});

// ---------------------------------------------------------------------------
// Page-level rules need their own documents rather than the clean fixture
// ---------------------------------------------------------------------------

describe('ContentScanner page-level rules', function() {
    it('html-lang fires when the lang attribute is missing', function() {
        $html = str_replace('<html lang="en">', '<html>', a11yCleanPage(''));

        $fired = array_map(fn($i) => $i->ruleId, (new ContentScanner())->scan($html));

        expect($fired)->toContain('html-lang');
    });

    it('page-title fires when the title element is missing', function() {
        $html = str_replace('<title>Test page</title>', '', a11yCleanPage(''));

        $fired = array_map(fn($i) => $i->ruleId, (new ContentScanner())->scan($html));

        expect($fired)->toContain('page-title');
    });

    it('page-title fires when the title is empty', function() {
        $html = str_replace('<title>Test page</title>', '<title> </title>', a11yCleanPage(''));

        $fired = array_map(fn($i) => $i->ruleId, (new ContentScanner())->scan($html));

        expect($fired)->toContain('page-title');
    });

    it('meta-description fires when the meta description is missing', function() {
        $html = str_replace('<meta name="description" content="A test page.">', '', a11yCleanPage(''));

        $fired = array_map(fn($i) => $i->ruleId, (new ContentScanner())->scan($html));

        expect($fired)->toContain('meta-description');
    });

    it('skip-link fires when no early anchor link exists', function() {
        $html = str_replace('<a href="#main">Skip to main content</a>', '', a11yCleanPage(''));

        $fired = array_map(fn($i) => $i->ruleId, (new ContentScanner())->scan($html));

        expect($fired)->toContain('skip-link');
    });

    it('landmark-main and landmark-regions fire on a page with no landmarks', function() {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><title>Bare page</title><meta name="description" content="Bare."></head>
<body>
    <a href="#top">Skip to main content</a>
    <h1>Heading</h1>
    <p>Content with no landmark elements at all.</p>
</body>
</html>
HTML;

        $fired = array_map(fn($i) => $i->ruleId, (new ContentScanner())->scan($html));

        expect($fired)->toContain('landmark-main')
            ->toContain('landmark-regions');
    });

    it('accepts role="main" in place of a main element', function() {
        $html = str_replace(['<main id="main">', '</main>'], ['<div role="main" id="main">', '</div>'], a11yCleanPage(''));

        $fired = array_map(fn($i) => $i->ruleId, (new ContentScanner())->scan($html));

        expect($fired)->not->toContain('landmark-main');
    });
});

// ---------------------------------------------------------------------------
// Severity and ignore-list behaviour
// ---------------------------------------------------------------------------

describe('ContentScanner severities and ignore list', function() {
    it('grades a placeholder-only input as a warning but a bare input as an error', function() {
        $scanner = new ContentScanner();

        $bare = collect($scanner->scan(a11yCleanPage('<input type="text" name="foo">')))
            ->firstWhere('ruleId', 'form-label');
        $placeholder = collect($scanner->scan(a11yCleanPage('<input type="text" name="foo" placeholder="Town">')))
            ->firstWhere('ruleId', 'form-label');

        expect($bare->severity)->toBe('error')
            ->and($placeholder->severity)->toBe('warning');
    });

    it('skips rules on the ignore list', function() {
        $scanner = new ContentScanner();
        $html = a11yCleanPage('<img src="/photo.jpg">');

        $fired = array_map(fn($i) => $i->ruleId, $scanner->scan($html, ['img-alt']));

        expect($fired)->not->toContain('img-alt');
    });

    it('reports duplicate ids once per id, not once per occurrence', function() {
        $issues = (new ContentScanner())->scan(a11yCleanPage(
            '<p id="dup">1</p><span id="dup">2</span><em id="dup">3</em>'
        ));

        $dupes = array_filter($issues, fn($i) => $i->ruleId === 'duplicate-id');

        expect($dupes)->toHaveCount(1);
    });
});

// ---------------------------------------------------------------------------
// link-generic and the new-tab notice
//
// Judging the accessible name stopped "View" with a descriptive aria-label
// being called vague, which was the point. It also let through the opposite
// case: "here (opens in new tab)" is the word "here" carrying a courtesy
// notice, and judged whole it looked specific enough to pass. The notice
// describes behaviour, not destination, so it comes off before the check.
// ---------------------------------------------------------------------------

function genericLinkIssues(string $body): array
{
    $html = '<!DOCTYPE html><html lang="en"><head><title>T</title>'
        . '<meta name="description" content="d"></head><body>'
        . '<a href="#main">Skip to main content</a><main id="main">' . $body . '</main></body></html>';

    return array_values(array_filter(
        (new ContentScanner())->scan($html),
        fn($i) => $i->ruleId === 'link-generic',
    ));
}

it('flags a vague link whose label only adds a new-tab notice', function(string $label) {
    $body = '<a href="/x" target="_blank" aria-label="' . $label . '">here</a>';

    expect(genericLinkIssues($body))->toHaveCount(1);
})->with([
    'parenthesised' => 'here (opens in new tab)',
    'bracketed'     => 'here [opens in a new window]',
    'trailing'      => 'here, opens in a new tab',
    'external'      => 'here (external)',
]);

it('leaves a label that names the destination alone', function() {
    // Issue #7: the whole reason the accessible name is consulted at all.
    $body = '<a href="/x" target="_blank" aria-label="Full recipe at ohmydish.com (opens in new tab)">View</a>';

    expect(genericLinkIssues($body))->toBeEmpty();
});

it('still flags a bare generic link', function() {
    expect(genericLinkIssues('<a href="/x">click here</a>'))->toHaveCount(1);
});

it('leaves genuinely descriptive link text alone', function() {
    expect(genericLinkIssues('<a href="/x">Read the full accessibility statement</a>'))->toBeEmpty();
});

it('reports every vague link on the page, not just the unlabelled one', function() {
    // The reported case: two labelled "here (opens in new tab)", one bare.
    $body = '<a href="https://a.example/x" target="_blank" aria-label="here (opens in new tab)">here</a>'
        . '<a href="https://b.example/x">here</a>'
        . '<a href="https://c.example/x" target="_blank" aria-label="here (opens in new tab)">here</a>';

    expect(genericLinkIssues($body))->toHaveCount(3);
});

// ---------------------------------------------------------------------------
// Alt text that is the filename by another name
//
// Craft derives an asset title from its filename, so a template reaching for
// the title ships "Asset7623" for asset7623.jpg. The asset can hold good alt
// text while the page shows none of it, which asset-level checks cannot see.
// ---------------------------------------------------------------------------

function filenameAltIssues(string $body): array
{
    $html = '<!DOCTYPE html><html lang="en"><head><title>T</title>'
        . '<meta name="description" content="d"></head><body>'
        . '<a href="#main">Skip to main content</a><main id="main">' . $body . '</main></body></html>';

    return array_values(array_filter(
        (new ContentScanner())->scan($html),
        fn($i) => $i->ruleId === 'img-alt-filename',
    ));
}

it('flags alt text that is the filename', function(string $src, string $alt) {
    expect(filenameAltIssues('<img src="' . $src . '" alt="' . $alt . '">'))->toHaveCount(1);
})->with([
    'with the extension'   => ['/uploads/hero-shot.jpg', 'hero-shot.jpg'],
    'title-derived'        => ['/uploads/asset7623.jpg', 'Asset7623'],
    'underscores and digits' => ['/uploads/IMG_20260817.jpg', 'IMG 20260817'],
    'through a transform url' => ['/transforms/_800x600/dsc01234.jpg', 'DSC01234'],
]);

it('leaves alt text alone that only happens to match a well-named file', function() {
    // A descriptive sentence is not a filename just because the file was
    // named after it too.
    $body = '<img src="/uploads/roasted-garlic-focaccia.jpg" alt="Roasted garlic focaccia">';

    expect(filenameAltIssues($body))->toBeEmpty();
});

it('leaves genuinely descriptive alt alone', function() {
    $body = '<img src="/uploads/asset7623.jpg" alt="A bowl of aubergine curry with fresh coriander">';

    expect(filenameAltIssues($body))->toBeEmpty();
});

it('ignores a data uri, which has no filename to match', function() {
    expect(filenameAltIssues('<img src="data:image/png;base64,AAAA" alt="Chart">'))->toBeEmpty();
});

// ---------------------------------------------------------------------------
// link-new-window and the announced name
// ---------------------------------------------------------------------------

function newWindowIssues(string $body): array
{
    $html = '<!DOCTYPE html><html lang="en"><head><title>T</title>'
        . '<meta name="description" content="d"></head><body>'
        . '<a href="#main">Skip to main content</a><main id="main">' . $body . '</main></body></html>';

    return array_values(array_filter(
        (new ContentScanner())->scan($html),
        fn($i) => $i->ruleId === 'link-new-window',
    ));
}

it('counts a new-tab warning carried in visually hidden text', function() {
    // The pattern the plugin's own templates use: the warning is in the link,
    // just not on screen, and a screen reader announces it either way.
    $body = '<a href="https://x.example/" target="_blank">Our partner site'
        . '<span class="visually-hidden"> (opens in new tab)</span></a>';

    expect(newWindowIssues($body))->toBeEmpty();
});

it('still flags a new-tab link that warns nowhere', function() {
    expect(newWindowIssues('<a href="https://x.example/" target="_blank">Our partner site</a>'))
        ->toHaveCount(1);
});

it('flags a link whose aria-label replaces the hidden warning', function() {
    // An aria-label replaces the content, so the hidden span is never
    // announced and the label has to carry the warning itself.
    $body = '<a href="https://x.example/" target="_blank" aria-label="Our partner site">Our partner site'
        . '<span class="visually-hidden"> (opens in new tab)</span></a>';

    expect(newWindowIssues($body))->toHaveCount(1);
});

it('reports a decorative image that is the only content of a link', function() {
    // Marking an image decorative is right until it is the whole of a link,
    // at which point the link has no name at all.
    $html = '<!DOCTYPE html><html lang="en"><head><title>T</title>'
        . '<meta name="description" content="d"></head><body>'
        . '<a href="#main">Skip to main content</a><main id="main">'
        . '<a href="/somewhere"><img src="/i/icon.svg" alt=""></a></main></body></html>';

    $found = array_values(array_filter(
        (new ContentScanner())->scan($html),
        fn($i) => $i->ruleId === 'link-name',
    ));

    expect($found)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Block content inside a paragraph
// ---------------------------------------------------------------------------

/**
 * Counts block-in-paragraph findings for a body snippet.
 */
function blockInParagraphIssues(string $body): int
{
    return count(array_filter(
        (new ContentScanner())->scan(a11yCleanPage($body)),
        static fn($issue): bool => $issue->ruleId === 'block-in-paragraph',
    ));
}

describe('block-in-paragraph', function() {
    it('flags a paragraph wrapping a paragraph', function() {
        expect(blockInParagraphIssues('<p class="x"><p>text</p></p>'))->toBe(1);
    });

    it('flags a div inside a paragraph', function() {
        expect(blockInParagraphIssues('<p><div>x</div></p>'))->toBe(1);
    });

    it('flags a list inside a paragraph', function() {
        expect(blockInParagraphIssues('<p><ul><li>x</li></ul></p>'))->toBe(1);
    });

    it('leaves inline content alone', function() {
        $body = '<p>text <strong>bold</strong> <a href="/somewhere">a real link</a></p>';

        expect(blockInParagraphIssues($body))->toBe(0);
    });

    it('leaves a line break alone', function() {
        expect(blockInParagraphIssues('<p>text<br>more</p>'))->toBe(0);
    });

    it('ignores markup the parser never treats as markup', function() {
        $body = '<template><p><div>x</div></p></template>'
            . '<!-- <p><div>x</div></p> -->';

        expect(blockInParagraphIssues($body))->toBe(0);
    });

    it('names the offending tag and carries the markup as context', function() {
        $found = array_values(array_filter(
            (new ContentScanner())->scan(a11yCleanPage('<p class="wrap"><div>x</div></p>')),
            static fn($issue): bool => $issue->ruleId === 'block-in-paragraph',
        ));

        expect($found)->toHaveCount(1)
            ->and($found[0]->message)->toContain('<div>')
            ->and($found[0]->context)->toContain('class="wrap"')
            ->and($found[0]->severity)->toBe('warning')
            // WCAG 4.1.1 was removed in 2.2, so this maps to no criterion.
            ->and($found[0]->wcagCriterion)->toBeNull();
    });

    it('is invisible to XPath, which is why the rule reads the raw string', function() {
        // If anyone ever "simplifies" the rule into a DOM query it will keep
        // passing while detecting nothing. The parser repairs the nesting on
        // load, so by the time there is a document the evidence is gone.
        $html = a11yCleanPage('<p class="x"><p>text</p></p><p><div>x</div></p>');

        $dom = new DOMDocument('1.0', 'utf-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        expect($xpath->query('//p//p')->length)->toBe(0)
            ->and($xpath->query('//p//div')->length)->toBe(0)
            // Same fixture, raw string: both are found.
            ->and(blockInParagraphIssues('<p class="x"><p>text</p></p><p><div>x</div></p>'))->toBe(2);
    });
});
