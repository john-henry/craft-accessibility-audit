<?php

use johnhenry\accessibilityaudit\helpers\Csv;

// ---------------------------------------------------------------------------
// Csv::guard — spreadsheet formula-injection neutralisation
// ---------------------------------------------------------------------------

describe('Csv::guard', function() {
    it('quotes every dangerous leading character', function(string $value) {
        expect(Csv::guard($value))->toBe("'" . $value);
    })->with([
        'equals'    => '=1+1',
        'plus'      => '+1',
        'minus'     => '-1',
        'at'        => '@SUM(A1)',
        'tab'       => "\tfoo",
        'return'    => "\rfoo",
        'formula'   => '=HYPERLINK("http://evil","click")',
    ]);

    it('leaves ordinary and numeric text untouched', function(string $value) {
        expect(Csv::guard($value))->toBe($value);
    })->with([
        'plain'     => 'Homepage banner',
        'number'    => '42',
        'decimal'   => '3.14',
        'url'       => 'https://example.com/page',
        'inner'     => 'Total = 5', // dangerous char, but not leading
        'empty'     => '',
    ]);

    it('only quotes the leading character, not the rest of the value', function() {
        expect(Csv::guard('=a=b=c'))->toBe("'=a=b=c");
    });
});

// ---------------------------------------------------------------------------
// Csv::guardRow — whole-row guard with type coercion
// ---------------------------------------------------------------------------

describe('Csv::guardRow', function() {
    it('guards every cell and casts non-strings to string', function() {
        $row = Csv::guardRow(['=danger', 'safe', 42, 3.5, null]);

        expect($row)->toBe(["'=danger", 'safe', '42', '3.5', '']);
    });

    it('returns an empty row unchanged', function() {
        expect(Csv::guardRow([]))->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// Csv::download — the shared download response
// ---------------------------------------------------------------------------

describe('Csv::download', function() {
    it('sets every header a CSV download needs', function() {
        $response = Csv::download("a,b\n1,2\n", 'report.csv');
        $headers = $response->headers;

        expect($response->content)->toBe("a,b\n1,2\n")
            ->and($headers->get('Content-Type'))->toBe('text/csv; charset=utf-8')
            ->and($headers->get('Content-Disposition'))->toBe('attachment; filename="report.csv"')
            // The first row of these files is somebody's page titles, so the
            // browser is told not to pick a type for it.
            ->and($headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('is the only place an export builds a CSV response', function() {
        // The header set drifted once already by being written out at each
        // export in turn, so the rule is pinned rather than the symptom: an
        // export that hand-rolls its own response is the thing that goes
        // missing a header, whichever header it happens to be.
        $offenders = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/controllers/*.php') ?: [] as $path) {
            if (str_contains((string)file_get_contents($path), 'text/csv')) {
                $offenders[] = basename($path);
            }
        }

        expect($offenders)->toBe([]);
    });
});
