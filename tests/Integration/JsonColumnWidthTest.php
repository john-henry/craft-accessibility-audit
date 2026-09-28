<?php

// ---------------------------------------------------------------------------
// The columns holding written prose as JSON.
//
// A VPAT's overrides carry a conformance level and a remark for every
// criterion, and a remark is a paragraph aimed at a procurement team. Fifty of
// those run past what a `text` column holds. Overflowing fails the save where
// the database runs strict and truncates the JSON where it does not, and
// truncated JSON cannot be read back at all: the answers are gone and the
// report stops loading.
//
// Install.php and the migrations both define these, and the two have drifted
// before (see m260907_000000_repair_fresh_install_schema). This asserts what
// the database actually has, whichever route built it.
//
// The sweep below matters more than the list. This test once named the columns
// it knew about, and organisation.meta was added to none of them: it stayed a
// `text` column holding a JSON document for two releases, and the list could
// not report a column nobody had thought to add to it. So the check is put the
// other way round now. Every narrow column has to be justified, and a new one
// holding JSON fails until somebody says otherwise.
// ---------------------------------------------------------------------------

describe('the JSON columns', function() {
    it('is wide enough for the prose written into it', function(string $table, string $column) {
        $schema = Craft::$app->getDb()->getTableSchema($table);

        expect($schema)->not->toBeNull();
        expect($schema->getColumn($column)?->dbType)->toBe('mediumtext');
    })->with([
        'vpat overrides' => ['{{%accessibilityaudit_vpat}}', 'overrides'],
        'vpat meta' => ['{{%accessibilityaudit_vpat}}', 'meta'],
        'vpat revision snapshot' => ['{{%accessibilityaudit_vpat_revisions}}', 'snapshot'],
        'statement exclusions' => ['{{%accessibilityaudit_statement}}', 'exclusions'],
        'statement meta' => ['{{%accessibilityaudit_statement}}', 'meta'],
        'organisation meta' => ['{{%accessibilityaudit_organisation}}', 'meta'],
    ]);

    it('has no narrow column beyond the few that hold one short string', function() {
        // One value each, written by the scanner or typed into a single field,
        // and none of them a document. Anything else the plugin adds is a
        // document until somebody decides otherwise here.
        $shortStrings = [
            'accessibilityaudit_issues.message',
            'accessibilityaudit_issues.context',
            'accessibilityaudit_verdicts.note',
        ];

        $db = Craft::$app->getDb();
        $prefix = $db->tablePrefix;
        $narrow = [];

        foreach ($db->getSchema()->getTableNames() as $table) {
            if (!str_starts_with($table, $prefix . 'accessibilityaudit_')) {
                continue;
            }

            foreach ($db->getTableSchema($table)->columns as $column) {
                if ($column->dbType !== 'text') {
                    continue;
                }

                $name = substr($table, strlen($prefix)) . '.' . $column->name;

                if (!in_array($name, $shortStrings, true)) {
                    $narrow[] = $name;
                }
            }
        }

        expect($narrow)->toBe([]);
    });
});
