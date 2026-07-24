<?php

// ---------------------------------------------------------------------------
// CSV formula-injection guard coverage (drift guard)
//
// Csv::guard() only protects a cell if a call site actually routes editor
// content through it. A new export that writes a page title or URL straight
// into fputcsv() would reintroduce the injection vector this plugin already
// fixed, and no behavioural test would catch it. This scans the source the
// way JsTranslationsTest scans for Craft.t() keys: every fputcsv() row that
// carries editor-controlled content must pass it through Csv::guard() or
// Csv::guardRow().
// ---------------------------------------------------------------------------

/** The plugin's src/ root, resolved from this test's location. */
function csvSrcRoot(): string
{
    return dirname(__DIR__, 2) . '/src';
}

/** Every .php file under src/. */
function csvSourceFiles(): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(csvSrcRoot(), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

/**
 * Extracts the full text of every fputcsv(...) call in a source string,
 * paren-matched so nested Csv::guard(...) / guardRow([...]) cells stay intact.
 *
 * @return string[] One entry per call, the substring from `fputcsv` to its
 *                  matching close paren.
 */
function csvFputcsvCalls(string $src): array
{
    $calls = [];
    $offset = 0;
    $len = strlen($src);

    while (($pos = strpos($src, 'fputcsv', $offset)) !== false) {
        $open = strpos($src, '(', $pos);
        if ($open === false) {
            break;
        }

        $depth = 0;
        $end = $open;
        for ($i = $open; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        $calls[] = substr($src, $pos, $end - $pos + 1);
        $offset = $end + 1;
    }

    return $calls;
}

it('routes every editor-controlled CSV cell through Csv::guard', function() {
    // Tokens that mark a cell as carrying content an editor typed (page
    // titles, URLs, issue messages) rather than a system integer or date.
    $editorTokens = ['title', 'url', 'uri', 'message', 'context', 'help', 'label'];

    $checked = 0;
    $unguarded = [];

    foreach (csvSourceFiles() as $file) {
        $src = file_get_contents($file);
        if ($src === false || !str_contains($src, 'fputcsv')) {
            continue;
        }

        foreach (csvFputcsvCalls($src) as $call) {
            // Drop the stream handle (the first argument, always a variable) so
            // its `$` doesn't make a pure-literal header row look dynamic. What
            // remains is the row expression: the cells actually written.
            $comma = strpos($call, ',');
            $row = $comma === false ? $call : substr($call, $comma + 1);

            // Header rows are pure string literals with no interpolation, so
            // they carry nothing an editor controls: skip them.
            if (!str_contains($row, '$')) {
                continue;
            }

            $lower = strtolower($row);
            $carriesEditorContent = false;
            foreach ($editorTokens as $token) {
                if (str_contains($lower, $token)) {
                    $carriesEditorContent = true;
                    break;
                }
            }

            if (!$carriesEditorContent) {
                continue;
            }

            $checked++;
            if (!str_contains($call, 'Csv::guard')) {
                $unguarded[] = basename($file) . ': ' . preg_replace('/\s+/', ' ', substr($call, 0, 80)) . '…';
            }
        }
    }

    // The scan must actually find the exports it is meant to guard; zero would
    // mean a refactor moved CSV writing off fputcsv() and silently past this.
    expect($checked)->toBeGreaterThan(0)
        ->and($unguarded)->toBe([]);
});
