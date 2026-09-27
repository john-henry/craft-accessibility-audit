<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Bounds on what a table or trend endpoint can be asked for.
//
// per_page and range come off the query string. Floored but not capped, one
// request can ask for a page holding the whole table, or a trend spanning any
// number of days. These endpoints need only the Read permission, so the caller
// need not be an admin, and the listings carry issue context and page titles
// rather than a handful of columns.
//
// The page size is read back from the pagination block the table endpoints
// return, which reports the size actually used rather than the size asked for.
// ---------------------------------------------------------------------------

describe('what a listing endpoint will answer with', function() {
    beforeEach(function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
        $this->actingAs(UserFactory::factory()->admin(true)->create());
        $this->boundsSiteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    });

    it('caps the page size however large a one is asked for', function(string $endpoint) {
        $data = $this->http('get', "actions/accessibility-audit/dashboard/{$endpoint}?" . http_build_query([
            'siteId' => $this->boundsSiteId,
            'ruleId' => 'img-alt',
            'per_page' => 1000000,
            'page' => 1,
        ]))->addHeader('Accept', 'application/json')->send()->getJsonContent();

        expect((int) $data['pagination']['per_page'])->toBeLessThanOrEqual(200);
    })->with([
        'scanned pages' => ['scanned-pages-table'],
        'potential pages' => ['potential-pages-table'],
        'issue pages' => ['issue-pages-table'],
        'dismissed' => ['dismissed-table'],
    ]);

    it('honours an ordinary page size unchanged', function() {
        $data = $this->http('get', 'actions/accessibility-audit/dashboard/scanned-pages-table?' . http_build_query([
            'siteId' => $this->boundsSiteId,
            'per_page' => 50,
            'page' => 1,
        ]))->addHeader('Accept', 'application/json')->send()->getJsonContent();

        expect((int) $data['pagination']['per_page'])->toBe(50);
    });

    it('bounds the trend range at both ends', function(string $endpoint) {
        $data = $this->http('get', "actions/accessibility-audit/dashboard/{$endpoint}?" . http_build_query([
            'siteId' => $this->boundsSiteId,
            'ruleId' => 'img-alt',
            'range' => 100000,
        ]))->addHeader('Accept', 'application/json')->send()->getJsonContent();

        expect($data['success'])->toBeTrue();
        // A point a day at most, so the clamp shows in the length.
        expect(count($data['trend']))->toBeLessThanOrEqual(366);
    })->with(['resolved-trend', 'rule-trend']);
});
