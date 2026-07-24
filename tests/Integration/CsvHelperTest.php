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
