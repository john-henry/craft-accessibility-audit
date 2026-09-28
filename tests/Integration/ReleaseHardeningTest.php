<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use johnhenry\accessibilityaudit\helpers\InLanguage;
use johnhenry\accessibilityaudit\helpers\UrlSafety;
use johnhenry\accessibilityaudit\jobs\AnalyseReadability;
use johnhenry\accessibilityaudit\migrations\m260920_000000_homepage_uri_pattern as HomepageMigration;
use johnhenry\accessibilityaudit\migrations\m260926_000000_kebab_case_permissions as PermissionsMigration;
use johnhenry\accessibilityaudit\migrations\m260926_000001_settings_site_uids as SiteUidMigration;
use johnhenry\accessibilityaudit\migrations\m260926_000002_keep_target_score_off as TargetScoreMigration;
use johnhenry\accessibilityaudit\models\IssueModel;
use johnhenry\accessibilityaudit\models\SettingsModel;
use johnhenry\accessibilityaudit\models\StatementExclusionModel;
use johnhenry\accessibilityaudit\services\AuditService;

// ---------------------------------------------------------------------------
// What 1.5.0 closes off before release
//
// Client-posted values that end up in the control panel, upgrades that have to
// carry settings across unchanged, and reports that have to agree with the
// content they describe. Each test pins one of them.
//
// Helper names carry an `rh` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** The primary site's id. */
function rhSiteId(): int
{
    return (int)Craft::$app->getSites()->getPrimarySite()->id;
}

/** Writes a plugin setting straight into project config, packed the way Craft stores it. */
function rhStoreSetting(string $key, mixed $value): void
{
    $projectConfig = Craft::$app->getProjectConfig();
    $projectConfig->writeYamlAutomatically = false;
    $projectConfig->readOnly = false;
    Craft::$app->getConfig()->getGeneral()->allowAdminChanges = true;

    $projectConfig->set(
        "plugins.accessibility-audit.settings.$key",
        is_array($value) ? ProjectConfigHelper::packAssociativeArrays($value) : $value,
    );
}

/** A plugin setting as stored, unpacked. */
function rhStoredSetting(string $key): mixed
{
    $value = Craft::$app->getProjectConfig()->get("plugins.accessibility-audit.settings.$key");

    return is_array($value) ? ProjectConfigHelper::unpackAssociativeArrays($value) : $value;
}

/** A scan row for an element, and its id. */
function rhScan(int $elementId): int
{
    $now = Db::prepareDateForDb(new DateTime());
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId, 'elementType' => craft\elements\Entry::class, 'siteId' => rhSiteId(),
        'score' => 50, 'scoreA' => 50, 'scoreAA' => 50, 'scoreAAA' => 50,
        'errorCount' => 1, 'warningCount' => 0, 'noticeCount' => 0,
        'dateScanned' => $now, 'dateCreated' => $now, 'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int)$db->getLastInsertID('{{%accessibilityaudit_scans}}');
}

describe('values a browser posts', function() {
    it('keeps only http and https help links', function() {
        expect(IssueModel::safeHelpUrl('javascript:alert(document.domain)'))->toBeNull()
            ->and(IssueModel::safeHelpUrl(' JavaScript:alert(1)'))->toBeNull()
            ->and(IssueModel::safeHelpUrl('data:text/html,<script>alert(1)</script>'))->toBeNull()
            ->and(IssueModel::safeHelpUrl('https://dequeuniversity.com/rules/axe/4.13/image-alt'))
            ->toBe('https://dequeuniversity.com/rules/axe/4.13/image-alt');
    });

    it('drops an unsafe help link on the way into the database', function() {
        $issue = IssueModel::make(ruleId: 'axe:image-alt', severity: 'error', message: 'x', helpUrl: 'javascript:alert(1)');

        expect($issue->helpUrl)->toBeNull();
    });

    it('lets only colour values into the contrast swatches', function() {
        expect(AuditService::cssColour('#1a2b3c'))->toBe('#1a2b3c')
            ->and(AuditService::cssColour('rgb(0, 0, 0)'))->toBe('rgb(0, 0, 0)')
            ->and(AuditService::cssColour('rgba(0,0,0,0.5)'))->toBe('rgba(0,0,0,0.5)')
            ->and(AuditService::cssColour('red;background-image:url(https://evil.example/x)'))->toBeNull()
            ->and(AuditService::cssColour('url(x)'))->toBeNull()
            ->and(AuditService::cssColour(null))->toBeNull();
    });
});

describe('the SSRF guard', function() {
    it('only exempts the site on its own scheme, host and port', function() {
        $parts = parse_url((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl());
        $host = (string)$parts['host'];

        // The dev host resolves to a private address, so the exemption is the
        // only thing letting it through.
        expect(fn() => UrlSafety::assertHostIsPublic($host, (string)$parts['scheme'], $parts['port'] ?? null))
            ->not->toThrow(UnsafeUrlException::class)
            ->and(fn() => UrlSafety::assertHostIsPublic($host, (string)$parts['scheme'], 6379))
            ->toThrow(UnsafeUrlException::class);
    })->skip(fn() => !str_ends_with((string)parse_url((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST), '.ddev.site'), 'Needs a site host that resolves privately.');

    it('pins a one-off request and turns redirects off', function() {
        $options = UrlSafety::pinnedRequestOptions('https://93.184.215.14/hook');

        expect($options['allow_redirects'])->toBeFalse()
            ->and($options['curl'][CURLOPT_RESOLVE] ?? [])->toContain('93.184.215.14:443:93.184.215.14');
    });

    it('refuses a one-off request to a private address', function() {
        expect(fn() => UrlSafety::pinnedRequestOptions('http://169.254.169.254/latest/meta-data/'))
            ->toThrow(UnsafeUrlException::class);
    });
});

describe('upgrading from 1.4', function() {
    it('rewrites a packed blank pattern as the homepage', function() {
        rhStoreSetting('excludedUriPatterns', [
            ['enabled' => true, 'siteId' => '', 'uriPattern' => ''],
            ['enabled' => true, 'siteId' => '', 'uriPattern' => '^checkout'],
        ]);

        HomepageMigration::apply();

        expect(rhStoredSetting('excludedUriPatterns'))->toBe([
            ['enabled' => true, 'siteId' => '', 'uriPattern' => '^$'],
            ['enabled' => true, 'siteId' => '', 'uriPattern' => '^checkout'],
        ]);
    });

    it('stores row sites by UID', function() {
        $site = Craft::$app->getSites()->getPrimarySite();

        rhStoreSetting('customUrls', [
            ['enabled' => true, 'siteId' => (int)$site->id, 'url' => '/search'],
            ['enabled' => true, 'siteId' => '', 'url' => '/elsewhere'],
        ]);

        SiteUidMigration::apply();

        expect(rhStoredSetting('customUrls'))->toBe([
            ['enabled' => true, 'url' => '/search', 'siteUid' => $site->uid],
            ['enabled' => true, 'url' => '/elsewhere', 'siteUid' => ''],
        ]);
    });

    it('still reads a row saved with a site id', function() {
        expect(SettingsModel::rowSiteId(['siteId' => '2']))->toBe(2)
            ->and(SettingsModel::rowSiteId(['siteId' => '']))->toBeNull()
            ->and(SettingsModel::rowSiteId(['siteUid' => 'no-such-site']))->toBeNull()
            ->and(SettingsModel::rowSiteId(['siteUid' => Craft::$app->getSites()->getPrimarySite()->uid]))->toBe(rhSiteId());
    });

    it('keeps the target score off where none was ever saved', function() {
        Craft::$app->getProjectConfig()->writeYamlAutomatically = false;
        Craft::$app->getProjectConfig()->remove('plugins.accessibility-audit.settings.targetScore');

        TargetScoreMigration::apply();

        expect(rhStoredSetting('targetScore'))->toBe(0);
    });

    it('leaves a saved target score alone', function() {
        rhStoreSetting('targetScore', 75);

        TargetScoreMigration::apply();

        expect(rhStoredSetting('targetScore'))->toBe(75);
    });

    it('carries existing grants over to the kebab-case permissions', function() {
        $user = markhuot\craftpest\factories\User::factory()->create();
        $db = Craft::$app->getDb();

        $db->createCommand()->insert(Table::USERPERMISSIONS, ['name' => 'accessibility-audit:viewreports'])->execute();
        $permissionId = (int)$db->getLastInsertID(Table::USERPERMISSIONS);
        $db->createCommand()->insert(Table::USERPERMISSIONS_USERS, [
            'permissionId' => $permissionId,
            'userId' => $user->id,
        ])->execute();

        (new PermissionsMigration())->safeUp();

        $names = (new Query())
            ->select(['p.name'])
            ->from(['p' => Table::USERPERMISSIONS])
            ->innerJoin(['pu' => Table::USERPERMISSIONS_USERS], '[[pu.permissionId]] = [[p.id]]')
            ->where(['pu.userId' => $user->id])
            ->column();

        expect($names)->toBe(['accessibility-audit:view-reports']);
    });
});

describe('reports that agree with the content', function() {
    it('stops counting a page once its entry is trashed', function() {
        $entry = scannableEntry();
        rhScan((int)$entry->id);
        $audit = AccessibilityAudit::getInstance()->getAudit();
        $before = $audit->getCoverage(rhSiteId())['scanned'];

        Craft::$app->getElements()->deleteElement($entry);

        expect($audit->getCoverage(rhSiteId())['scanned'])->toBe($before - 1);
    });

    it('does not scan a draft as a page of its own', function() {
        $entry = scannableEntry();
        $draft = Craft::$app->getDrafts()->createDraft($entry);

        $result = AccessibilityAudit::getInstance()->getAudit()->scanElement($draft, false);

        expect($result['scanId'])->toBe(0);
    });

    it('drops an "already running" flag when no run is left in the queue', function() {
        $cache = Craft::$app->getCache();
        $cache->set(AuditService::sweepKey(rhSiteId()), true, 60);
        $cache->set(AnalyseReadability::runningKey(rhSiteId()), true, 60);
        Db::delete(Table::QUEUE);

        expect(AccessibilityAudit::getInstance()->getAudit()->isSweepRunning(rhSiteId()))->toBeFalse()
            ->and(AnalyseReadability::isRunning(rhSiteId()))->toBeFalse()
            ->and($cache->exists(AuditService::sweepKey(rhSiteId())))->toBeFalse();
    });
});

describe('readability', function() {
    it('declines to score a page that says it is not in English', function() {
        $result = AccessibilityAudit::getInstance()->getReadability()->analyseHtml(
            '<html lang="de"><body><p>' . str_repeat('Das ist ein langer deutscher Satz. ', 20) . '</p></body></html>',
        );

        expect($result['error'] ?? null)->toContain('English');
    });

    it('scores a page with no site of its own', function() {
        expect(AccessibilityAudit::getInstance()->getReadability()->supportsSite(null))->toBeTrue();
    });
});

describe('the statement', function() {
    it('needs a reason for a disproportionate burden', function() {
        $entry = new StatementExclusionModel([
            'category' => StatementExclusionModel::CATEGORY_BURDEN,
            'content' => 'Scanned PDF archive from before 2020',
            'reason' => '',
        ]);

        expect($entry->validate())->toBeFalse()
            ->and($entry->getErrors('reason'))->not->toBeEmpty();
    });

    it('puts the language and date format back after rendering in another', function() {
        $formatter = Craft::$app->getFormatter();
        $language = Craft::$app->language;
        $locale = $formatter->locale;

        $inside = InLanguage::run('de', fn(): array => [Craft::$app->language, $formatter->locale]);

        expect($inside)->toBe(['de', 'de'])
            ->and(Craft::$app->language)->toBe($language)
            ->and($formatter->locale)->toBe($locale);
    });
});
