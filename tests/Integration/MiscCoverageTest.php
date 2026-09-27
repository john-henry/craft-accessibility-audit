<?php

use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\SettingsModel;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Small surfaces: getAxeTags, getScannerUserAgent, CSV export shape
// ---------------------------------------------------------------------------

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;

    $settings = AccessibilityAudit::getInstance()->getSettings();
    $settings->wcagLevel = 'AA';
    $settings->en301549 = false;
    $settings->scannerUserAgent = '';
    $settings->ignoreRules = [];
    $settings->notifyEmailEnabled = false;
    $settings->notifySlackEnabled = false;
    $settings->notifyOnNewError = false;
    $settings->notifyOnScoreDrop = false;
});

describe('AuditService::getAxeTags', function() {
    it('returns the A/AA tag set by default', function() {
        $tags = AccessibilityAudit::getInstance()->getAudit()->getAxeTags();

        expect($tags)->toContain('wcag2a')
            ->toContain('wcag2aa')
            ->toContain('wcag22aa')
            ->toContain('best-practice')
            ->not->toContain('wcag2aaa')
            ->not->toContain('EN-301-549');
    });

    it('adds the AAA tag at level AAA', function() {
        AccessibilityAudit::getInstance()->getSettings()->wcagLevel = 'AAA';

        expect(AccessibilityAudit::getInstance()->getAudit()->getAxeTags())->toContain('wcag2aaa');
    });

    it('adds the EN 301 549 tag when the setting is on', function() {
        AccessibilityAudit::getInstance()->getSettings()->en301549 = true;

        expect(AccessibilityAudit::getInstance()->getAudit()->getAxeTags())->toContain('EN-301-549');
    });
});

describe('SettingsModel::getScannerUserAgent', function() {
    it('returns an empty string when unset', function() {
        expect(AccessibilityAudit::getInstance()->getSettings()->getScannerUserAgent())->toBe('');
    });

    it('trims a literal value', function() {
        AccessibilityAudit::getInstance()->getSettings()->scannerUserAgent = '  MyScanner/1.0  ';

        expect(AccessibilityAudit::getInstance()->getSettings()->getScannerUserAgent())->toBe('MyScanner/1.0');
    });

    it('resolves an environment variable reference', function() {
        putenv('A11Y_TEST_SCANNER_UA=CraftA11y (+https://example.ie)');
        AccessibilityAudit::getInstance()->getSettings()->scannerUserAgent = '$A11Y_TEST_SCANNER_UA';

        try {
            expect(AccessibilityAudit::getInstance()->getSettings()->getScannerUserAgent())
                ->toBe('CraftA11y (+https://example.ie)');
        } finally {
            putenv('A11Y_TEST_SCANNER_UA');
        }
    });
});

describe('SettingsModel::getFetchUserAgent', function() {
    it('defaults to a branded, token-bearing UA when unset', function() {
        $ua = AccessibilityAudit::getInstance()->getSettings()->getFetchUserAgent();

        expect($ua)->toContain(SettingsModel::USER_AGENT_TOKEN);
        expect($ua)->toContain('plugins.craftcms.com/accessibility-audit');
    });

    it('returns the configured override when set', function() {
        AccessibilityAudit::getInstance()->getSettings()->scannerUserAgent = 'MyScanner/1.0';

        expect(AccessibilityAudit::getInstance()->getSettings()->getFetchUserAgent())->toBe('MyScanner/1.0');
    });
});

describe('SettingsModel::getBrowserUserAgent', function() {
    it('defaults to a realistic Chrome UA carrying the token when unset', function() {
        $ua = AccessibilityAudit::getInstance()->getSettings()->getBrowserUserAgent();

        expect($ua)->toContain('Chrome/');
        expect($ua)->toContain(SettingsModel::USER_AGENT_TOKEN);
    });

    it('returns the configured override when set', function() {
        AccessibilityAudit::getInstance()->getSettings()->scannerUserAgent = 'MyScanner/1.0';

        expect(AccessibilityAudit::getInstance()->getSettings()->getBrowserUserAgent())->toBe('MyScanner/1.0');
    });
});

describe('ReportService::exportCsv', function() {
    it('returns an empty string for a site with no scans', function() {
        // Clear the site's scans inside the test transaction; issue rows
        // cascade with them.
        Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_scans}}')->execute();

        $csv = AccessibilityAudit::getInstance()->getReport()->exportCsv(
            Craft::$app->getSites()->getPrimarySite()->id
        );

        expect($csv)->toBe('');
    });

    it('produces a header row and one line per issue on the latest scans', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $elementId = UserFactory::factory()->create()->id;

        AccessibilityAudit::getInstance()->getAudit()->scanHtml(
            '<!DOCTYPE html><html><head></head><body><img src="/p.jpg"></body></html>',
            $elementId,
            User::class,
            $siteId,
        );

        $csv = AccessibilityAudit::getInstance()->getReport()->exportCsv($siteId);
        $lines = array_filter(explode("\n", trim($csv)));

        expect($lines[0])->toContain('Page,URL,Score,Severity,Rule,WCAG,Level,Message')
            ->and($csv)->toContain('img-alt')
            ->and($csv)->toContain('html-lang')
            ->and(count($lines))->toBeGreaterThan(2);
    });

    it('keeps every URL-scanned page rather than folding them into one', function() {
        // A URL scan has no element, so every one of them carries a null
        // elementId. Picking the latest scan per element alone collapses the
        // lot into a single row and all but one address drops out of the file.
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $now = Db::prepareDateForDb(new DateTime());
        $db = Craft::$app->getDb();

        foreach (['/search?q=one', '/search?q=two', '/search?q=three'] as $url) {
            $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
                'elementId' => null, 'elementType' => null, 'siteId' => $siteId, 'url' => $url,
                'title' => $url, 'score' => 90, 'scoreA' => 90, 'scoreAA' => 90, 'scoreAAA' => 90,
                'errorCount' => 1, 'warningCount' => 0, 'noticeCount' => 0,
                'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $db->createCommand()->insert('{{%accessibilityaudit_issues}}', [
                'scanId' => (int) $db->getLastInsertID('{{%accessibilityaudit_scans}}'),
                'elementId' => null, 'elementType' => null, 'siteId' => $siteId,
                'ruleId' => 'html-lang', 'severity' => 'error', 'message' => 'q',
                'source' => 'php', 'isResolved' => false, 'firstDetected' => $now,
                'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
            ])->execute();
        }

        $csv = AccessibilityAudit::getInstance()->getReport()->exportCsv($siteId);

        expect($csv)->toContain('/search?q=one')
            ->and($csv)->toContain('/search?q=two')
            ->and($csv)->toContain('/search?q=three');
    });
});
