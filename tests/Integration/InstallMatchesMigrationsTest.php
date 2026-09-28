<?php

// ---------------------------------------------------------------------------
// A fresh install runs Install.php and records every dated migration as
// already applied, without running them. So anything a migration adds has to
// be in Install.php too, or a new install is missing it while an upgraded one
// has it, and the difference only shows when something reads the column.
//
// This has happened here before: m260907_000000_repair_fresh_install_schema
// exists to put back what a fresh install had been missing. The check is
// mechanical, so it may as well be a test rather than something to remember.
// ---------------------------------------------------------------------------

/** The source of every dated migration, keyed by file name. */
function migrationSources(): array
{
    $dir = dirname(__DIR__, 2) . '/src/migrations';
    $out = [];

    foreach ((array) glob($dir . '/m*.php') as $path) {
        $out[basename((string) $path)] = (string) file_get_contents((string) $path);
    }

    return $out;
}

it('creates every column the migrations add', function() {
    $install = (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');

    $missing = [];

    foreach (migrationSources() as $name => $source) {
        // Resolve the `$table = '{{%name}}'` locals the migrations use.
        preg_match_all('/\$(\w+)\s*=\s*\'(\{\{%[a-z_]+\}\})\'/', $source, $vars, PREG_SET_ORDER);
        $tables = [];

        foreach ($vars as $var) {
            $tables[$var[1]] = $var[2];
        }

        preg_match_all('/->addColumn\(\s*(\$\w+|\'[^\']+\')\s*,\s*\'(\w+)\'/', $source, $adds, PREG_SET_ORDER);

        foreach ($adds as $add) {
            $target = $add[1];
            $table = str_starts_with($target, '$')
                ? ($tables[substr($target, 1)] ?? $target)
                : trim($target, "'");
            $column = $add[2];

            // Install.php's createTable block for that table.
            if (preg_match('/' . preg_quote($table, '/') . '\'\s*,\s*\[(.*?)\n            \]\);/s', $install, $block) !== 1) {
                $missing[] = "{$name}: {$table} is not created by Install.php";
                continue;
            }

            if (!str_contains($block[1], "'{$column}' =>")) {
                $missing[] = "{$name}: {$table}.{$column} is added by the migration but absent from Install.php";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('creates every column at the type the migrations alter it to', function() {
    // addColumn is only half of it. A migration that widens a column leaves
    // Install.php declaring the old type, so an upgraded install gets the wide
    // one and a fresh install quietly keeps the narrow one. That is how
    // organisation.meta stayed a `text` column holding a JSON document.
    $install = (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');
    $mismatched = [];

    foreach (migrationSources() as $name => $source) {
        preg_match_all(
            '/->alterColumn\(\s*\'(\{\{%[a-z_]+\}\})\'\s*,\s*\'(\w+)\'\s*,\s*\$this->(\w+)\(/',
            $source,
            $alters,
            PREG_SET_ORDER,
        );

        foreach ($alters as [, $table, $column, $type]) {
            if (preg_match('/' . preg_quote($table, '/') . '\'\s*,\s*\[(.*?)\n            \]\);/s', $install, $block) !== 1) {
                $mismatched[] = "{$name}: {$table} is not created by Install.php";
                continue;
            }

            if (!str_contains($block[1], "'{$column}' => \$this->{$type}(")) {
                $mismatched[] = "{$name}: {$table}.{$column} is altered to {$type}() but Install.php creates it otherwise";
            }
        }
    }

    expect($mismatched)->toBe([]);
});

it('drops every table it creates when the plugin is uninstalled', function() {
    // The other half: a table left behind after uninstall is somebody else's
    // database carrying this plugin's rows forever.
    $install = (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');

    preg_match_all('/->createTable\(\'(\{\{%[a-z_]+\}\})\'/', $install, $created);
    preg_match_all('/->dropTableIfExists\(\'(\{\{%[a-z_]+\}\})\'/', $install, $dropped);

    expect(array_values(array_diff($created[1], $dropped[1])))->toBe([]);
});
