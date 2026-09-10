<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\VpatService;

// ---------------------------------------------------------------------------
// The EN 301 549 clause beside each WCAG criterion.
//
// A European buyer checks a report against the standard their obligation names,
// not against WCAG directly. The mapping is mechanical, and it stops where the
// harmonised version stops.
// ---------------------------------------------------------------------------

describe('VpatService::enClause', function() {
    it('prefixes the WCAG number with clause 9', function() {
        $vpat = AccessibilityAudit::getInstance()->vpat;

        expect($vpat->enClause('1.1.1'))->toBe('9.1.1.1')
            ->and($vpat->enClause('1.4.3'))->toBe('9.1.4.3')
            ->and($vpat->enClause('4.1.2'))->toBe('9.4.1.2');
    });

    it('gives no clause for the criteria WCAG 2.2 added', function() {
        $vpat = AccessibilityAudit::getInstance()->vpat;

        foreach (['2.4.11', '2.5.7', '2.5.8', '3.2.6', '3.3.7', '3.3.8'] as $number) {
            expect($vpat->enClause($number))->toBeNull();
        }
    });

    it('gives no clause for a criterion it does not carry', function() {
        expect(AccessibilityAudit::getInstance()->vpat->enClause('9.9.9'))->toBeNull();
    });

    it('names the harmonised version it maps to', function() {
        expect(VpatService::EN_301_549_VERSION)->toBe('V3.2.1');
    });
});

describe('the full report', function() {
    it('carries the clause on every row it covers', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $report = AccessibilityAudit::getInstance()->vpat->getFullReport($siteId);

        $rows = $report['levelA'] + $report['levelAA'];

        expect($rows)->not->toBeEmpty()
            ->and($rows['1.1.1']['enClause'])->toBe('9.1.1.1')
            ->and($rows['2.5.8']['enClause'])->toBeNull()
            ->and($report['en301549Version'])->toBe('V3.2.1');
    });

    it('maps every covered criterion or says why not', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $report = AccessibilityAudit::getInstance()->vpat->getFullReport($siteId);

        // The guard against a criterion being added later and silently
        // arriving with no clause and no reason for having none.
        foreach ($report['levelA'] + $report['levelAA'] as $number => $row) {
            if ($row['enClause'] === null) {
                expect($number)->toBeIn(['2.4.11', '2.5.7', '2.5.8', '3.2.6', '3.3.7', '3.3.8']);
                continue;
            }

            expect($row['enClause'])->toBe('9.' . $number);
        }
    });
});
