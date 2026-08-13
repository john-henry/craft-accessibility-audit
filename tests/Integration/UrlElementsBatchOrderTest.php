<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// getUrlElementsQuery batch safety
//
// ScanElements wraps this query in a QueryBatcher, which pages with
// LIMIT/OFFSET. Without a total order the database may return rows in a
// different order per page, silently skipping some elements and scanning
// others twice. These tests emulate that paging and assert the slices
// reassemble the full set exactly.
// ---------------------------------------------------------------------------

describe('AuditService::getUrlElementsQuery batch ordering', function() {
    it('carries a deterministic total order', function() {
        $query = AccessibilityAudit::getInstance()->audit->getUrlElementsQuery(1);

        expect($query->orderBy)->not->toBeEmpty();
    });

    it('reassembles the full element set from LIMIT/OFFSET slices with no skips or repeats', function() {
        // Enough rows to force several pages at the slice size below.
        foreach (range(1, 12) as $i) {
            scannableEntry("Batch order fixture {$i}");
        }

        $audit = AccessibilityAudit::getInstance()->audit;
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        $allIds = array_map(
            static fn(array $row): int => (int) $row['elementId'],
            $audit->getUrlElementsQuery($siteId)->all(),
        );

        $slicedIds = [];
        $sliceSize = 5;

        for ($offset = 0; $offset < count($allIds); $offset += $sliceSize) {
            $slice = $audit->getUrlElementsQuery($siteId)
                ->limit($sliceSize)
                ->offset($offset)
                ->all();

            foreach ($slice as $row) {
                $slicedIds[] = (int) $row['elementId'];
            }
        }

        expect(count($slicedIds))->toBe(count($allIds))
            ->and(count(array_unique($slicedIds)))->toBe(count($slicedIds))
            ->and($slicedIds)->toBe($allIds);
    });
});
