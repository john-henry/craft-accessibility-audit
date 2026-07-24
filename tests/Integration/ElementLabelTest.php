<?php

use craft\elements\Entry;
use johnhenry\accessibilityaudit\helpers\ElementLabel;

// ---------------------------------------------------------------------------
// Naming a scanned page. An untitled homepage has now caused two visible
// defects: a breadcrumb that rendered as nothing, and a queue job announcing
// itself as "Entry 1343". Both call sites read the label their own way, so
// fixing one never fixed the other.
// ---------------------------------------------------------------------------

it('names the homepage rather than showing its slug or id', function() {
    // Craft marks the homepage with the __home__ URI, and Singles routinely have
    // no title, so this is the common case rather than an edge one.
    $entry = new Entry();
    $entry->uri = '__home__';
    $entry->title = null;

    expect(ElementLabel::for($entry))->toBe('Home page');
});

it('keeps a real title on the homepage when one is set', function() {
    // A saved entry, not a bare one: getUiLabel() needs a section behind it, and
    // the untitled case above never reaches that call.
    $entry = scannableEntry('Welcome');
    $entry->uri = '__home__';

    expect(ElementLabel::for($entry))->toBe('Welcome');
});

it('uses the element title for an ordinary page', function() {
    $entry = scannableEntry('Colors');

    expect(ElementLabel::for($entry))->toBe('Colors');
});

it('never returns the raw element cast, which reads as "Entry 1343"', function() {
    $entry = scannableEntry();

    // (string) $element is what the queue job used to use.
    expect(ElementLabel::for($entry))->not->toStartWith('Entry ');
});

it('falls back to the given fallback before inventing an id label', function() {
    // The queue job passes the page URL: more use to somebody reading a queue
    // than "Element #1343".
    expect(ElementLabel::for(null, 1343, 'https://example.com/about'))
        ->toBe('https://example.com/about');
});

it('falls back to the id when there is nothing else', function() {
    expect(ElementLabel::for(null, 1343))->toContain('1343');
});
