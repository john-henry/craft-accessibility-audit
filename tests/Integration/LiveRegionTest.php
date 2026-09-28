<?php

// ---------------------------------------------------------------------------
// Progress on a scan is announced from one status region, not from the button
// that started it.
//
// The scan buttons used to carry aria-live themselves and rely on their own
// label changing. That does announce, but a live region on the control the
// reader is sitting on is read twice by some screen readers, and the button is
// disabled while the scan runs, so the element being announced from is one the
// reader can no longer reach. Every other status on these screens is a separate
// role="status" element, and this is now too.
// ---------------------------------------------------------------------------

/** Every Twig template the plugin ships. */
function pluginTemplates(): array
{
    $root = dirname(__DIR__, 2) . '/src/templates';

    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
        '/\.twig$/',
        RegexIterator::MATCH,
    );

    return array_map(static fn($f): string => (string)$f, iterator_to_array($files));
}

it('never puts a live region on an interactive element', function() {
    $offenders = [];

    foreach (pluginTemplates() as $path) {
        $markup = (string)file_get_contents($path);

        // Matched across the whole file, not line by line: an opening tag is
        // routinely wrapped over several lines, and the one case that reached
        // production was written exactly that way.
        preg_match_all(
            '/<(button|a|input|select|textarea)\b[^>]*?aria-live/s',
            $markup,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[0] as $match) {
            $line = substr_count(substr($markup, 0, (int)$match[1]), "\n") + 1;
            $offenders[] = basename($path) . ':' . $line;
        }
    }

    expect($offenders)->toBe([]);
});

it('pairs every live region with the status role', function() {
    $unpaired = [];

    foreach (pluginTemplates() as $path) {
        foreach (explode("\n", (string)file_get_contents($path)) as $n => $line) {
            if (str_contains($line, 'aria-live') && !str_contains($line, 'role="status"')) {
                $unpaired[] = basename($path) . ':' . ($n + 1);
            }
        }
    }

    expect($unpaired)->toBe([]);
});

it('builds the status region the scan buttons announce through', function() {
    $js = (string)file_get_contents(dirname(__DIR__, 2) . '/src/resources/js/cp.js');

    // Created at init rather than on first use: a live region inserted and
    // filled in the same tick is not reliably announced.
    expect($js)->toContain("role', 'status'")
        ->and($js)->toContain('accessibility-audit-live')
        ->and($js)->toMatch('/init\(\)\s*\{\s*(\/\/[^\n]*\n\s*)*liveRegion\(\);/');
});

it('announces every state the scan buttons show', function() {
    $js = (string)file_get_contents(dirname(__DIR__, 2) . '/src/resources/js/cp.js');

    // Both setText helpers route through announce(), so a state that reaches the
    // button's label reaches the status region as well.
    expect(substr_count($js, 'announce(msg)'))->toBe(2);
});
