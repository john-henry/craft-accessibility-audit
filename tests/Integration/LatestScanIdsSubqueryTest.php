<?php

use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;

// ---------------------------------------------------------------------------
// A site has one scan row per page, and roughly every listing, export and
// summary narrows on "the latest scan of each page". Read into PHP that is a
// list of ids the length of the site, rebuilt and sent back with each query.
// As a subquery it never leaves the database.
//
// The difference does not show up in results, only in how large the statement
// gets, so nothing else in the suite would notice it being reverted.
// ---------------------------------------------------------------------------

/** The subquery the service narrows its site-wide reads with. */
function latestScanIdsQuery(int $siteId): Query
{
    $method = new ReflectionMethod(AuditService::class, '_latestScanIdsQuery');
    $method->setAccessible(true);

    return $method->invoke(AccessibilityAudit::getInstance()->getAudit(), $siteId);
}

describe('narrowing to the latest scan of each page', function() {
    beforeEach(function() {
        $this->subSiteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    });

    it('hands back a query rather than the ids themselves', function() {
        expect(latestScanIdsQuery($this->subSiteId))->toBeInstanceOf(Query::class);
    });

    it('renders into the outer query as a subselect, so no ids cross the wire', function() {
        $sql = (new Query())
            ->from('{{%accessibilityaudit_issues}}')
            ->where(['scanId' => latestScanIdsQuery($this->subSiteId)])
            ->createCommand()
            ->getRawSql();

        expect($sql)->toContain('IN (SELECT')
            ->and($sql)->toContain('MAX(');
    });

    it('counts the pages without listing them', function() {
        // getCoverage reports how many pages have a scan. Counting a grouped
        // query has to count the groups, not the rows in the first one.
        $sql = latestScanIdsQuery($this->subSiteId)->createCommand()->getRawSql();

        expect($sql)->toContain('GROUP BY');
        expect((int) latestScanIdsQuery($this->subSiteId)->count())
            ->toBe(count(latestScanIdsQuery($this->subSiteId)->column()));
    });
});
