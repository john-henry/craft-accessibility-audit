<?php

// ---------------------------------------------------------------------------
// Permission-handle drift guard
//
// requirePermission('accessibility-audit:x') and checkPermission(...) fail
// closed: a handle that doesn't match a registered permission can never be
// granted, so the action becomes unreachable for everyone (or, if a typo goes
// the other way in the registration, an action ends up guarded by a permission
// no one was ever given). Neither shows up until someone hits the page. This
// asserts every permission string used in the source resolves to one the
// plugin registers, the same way JsTranslationsTest guards Craft.t() keys.
//
// The registration only runs on a CP request, and init() fires once at
// bootstrap (not a CP request under test), so Craft's live permission registry
// is empty here; both sides are read from source instead.
// ---------------------------------------------------------------------------

/** The plugin's src/ root. */
function permSrcRoot(): string
{
    return dirname(__DIR__, 2) . '/src';
}

/** Every .php file under src/. */
function permSourceFiles(): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(permSrcRoot(), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

/**
 * Every permission handle enforced in the source, with the file:line it's
 * used at (so a drift failure points straight at the offending call).
 *
 * @return array<string, string> handle => "file:line"
 */
function permUsedHandles(): array
{
    $used = [];
    foreach (permSourceFiles() as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $i => $line) {
            if (preg_match_all('/(?:requirePermission|checkPermission)\(\s*[\'"](accessibility-audit:\w+)[\'"]/', $line, $m)) {
                foreach ($m[1] as $handle) {
                    $used[$handle] ??= basename($file) . ':' . ($i + 1);
                }
            }
        }
    }
    return $used;
}

/** Every permission handle the plugin registers (the `handle =>` keys). */
function permRegisteredHandles(): array
{
    $registered = [];
    foreach (permSourceFiles() as $file) {
        $src = file_get_contents($file);
        if (preg_match_all('/[\'"](accessibility-audit:\w+)[\'"]\s*=>/', $src, $m)) {
            foreach ($m[1] as $handle) {
                $registered[$handle] = true;
            }
        }
    }
    return $registered;
}

it('registers every permission handle it enforces', function() {
    $used = permUsedHandles();
    $registered = permRegisteredHandles();

    // Both scans must find something, or a refactor could rename the API out
    // from under the regex and this would pass vacuously.
    expect($used)->not->toBeEmpty()
        ->and($registered)->not->toBeEmpty();

    $unregistered = [];
    foreach ($used as $handle => $where) {
        if (!isset($registered[$handle])) {
            $unregistered[] = "{$handle} (used at {$where}) is not registered";
        }
    }

    expect($unregistered)->toBe([]);
});
