<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// libxml's error mode is process-wide, not per-document. The scanners switch
// it on so malformed page markup does not spray warnings, and have to switch
// it back: a queue worker runs many jobs in one process, so leaving it on
// silences parse errors for every later job, and for anything else in the
// request that parses XML or HTML.
//
// Nothing about a scan's own results changes either way, which is why this
// needs pinning on its own.
// ---------------------------------------------------------------------------

describe('libxml error mode around a scan', function() {
    it('is left as it was found', function(bool $before) {
        $previous = libxml_use_internal_errors($before);

        try {
            AccessibilityAudit::getInstance()->getContent()->scan(
                '<html lang="en"><body><p>Grand<div>unclosed</body></html>',
            );

            expect(libxml_use_internal_errors())->toBe($before);
        } finally {
            libxml_use_internal_errors($previous);
        }
    })->with([
        'reporting on' => [false],
        'reporting already suppressed' => [true],
    ]);
});
