<?php

// ---------------------------------------------------------------------------
// The plugin's own control panel, held to the rule it reports on other people.
//
// form-label and select-label are checks this scanner runs against customer
// sites. Its own screens should pass them, and the colour-vision simulator's
// hex field did not: a bold span beside a field is not a label, and a screen
// reader reached an unnamed text box.
//
// A control counts as labelled by a `for` pointing at its id, by an aria-label
// or aria-labelledby, or by sitting inside a <label>, which is how the radios
// and checkboxes here do it.
// ---------------------------------------------------------------------------

/** Every Twig template that renders control-panel markup. */
function cpTemplates(): array
{
    $root = dirname(__DIR__, 2) . '/src/templates';
    $found = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'twig') {
            $found[str_replace($root . '/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }
    }

    return $found;
}

it('names every form control on its own screens', function() {
    $unlabelled = [];

    foreach (cpTemplates() as $name => $source) {
        preg_match_all('/<(input|select|textarea)\b([^>]*)>/i', $source, $controls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($controls as $control) {
            [$whole, $offset] = $control[0];
            $attrs = $control[2][0];

            if (str_contains($attrs, 'type="hidden"')) {
                continue;
            }

            if (str_contains($attrs, 'aria-label')) {
                continue;
            }

            // A <label> opened before this control and not yet closed wraps it.
            $before = substr($source, 0, $offset);
            if (substr_count($before, '<label') > substr_count($before, '</label>')) {
                continue;
            }

            if (preg_match('/id="([^"]+)"/', $attrs, $id) === 1
                && str_contains($source, 'for="' . $id[1] . '"')) {
                continue;
            }

            $line = substr_count($before, "\n") + 1;
            $unlabelled[] = "{$name}:{$line} " . substr(trim($whole), 0, 60);
        }
    }

    expect($unlabelled)->toBe([]);
});
