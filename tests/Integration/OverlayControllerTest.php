<?php

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// The decoupled overlay endpoints: token auth, the Pro/enabled gates, the
// loader script's two states, and that the token-authenticated store/read
// twins actually write and read scan data. All requests here are anonymous —
// that is the point of the surface: a decoupled front end has no CP session.
//
// Helpers are uniquely named (ov*): Pest loads every test file into one process.
// ---------------------------------------------------------------------------

function ovSetSettings(array $overrides): void
{
    $plugin = AccessibilityAudit::getInstance();
    $settings = $plugin->getSettings();

    foreach ($overrides as $attr => $value) {
        $settings->$attr = $value;
    }

    Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
}

/** Inserts a scan row on the given site and returns its id. */
function ovScanId(int $elementId, int $siteId): int
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%accessibilityaudit_scans}}', [
        'elementId' => $elementId,
        'elementType' => \craft\elements\User::class,
        'siteId' => $siteId,
        'score' => 100,
        'scoreA' => 100,
        'scoreAA' => 100,
        'scoreAAA' => 100,
        'errorCount' => 0,
        'warningCount' => 0,
        'noticeCount' => 0,
        'dateScanned' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int) Craft::$app->getDb()->getLastInsertID('{{%accessibilityaudit_scans}}');
}

/** A minimal axe violation payload. */
function ovViolation(): array
{
    return [
        'id' => 'image-alt',
        'impact' => 'critical',
        'tags' => ['wcag2a', 'wcag111'],
        'description' => 'Images must have alternate text',
        'help' => 'Images must have alternate text',
        'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.9/image-alt',
        'nodes' => [
            ['html' => '<img src="/x.jpg">', 'target' => ['img']],
        ],
    ];
}

/** Count of stored issue rows for a scan. */
function ovIssueCount(int $scanId): int
{
    return (int) (new Query())
        ->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $scanId])
        ->count();
}

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    ovSetSettings([
        'decoupledOverlay' => true,
        'overlayApiToken' => hash('sha256', 'overlay-secret'),
    ]);
});

// ---------------------------------------------------------------------------
// Token authentication + gates
// ---------------------------------------------------------------------------

describe('OverlayController token auth and gates', function() {
    it('refuses resolve on the Standard edition', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        $json = $this->http('post', 'accessibility-audit/overlay/resolve')
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer overlay-secret')
            ->setBody(['url' => 'https://example.com/about'])
            ->send()
            ->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['proRequired'] ?? false)->toBeTrue();
    });

    it('refuses resolve when the feature is disabled', function() {
        ovSetSettings(['decoupledOverlay' => false]);

        $json = $this->http('post', 'accessibility-audit/overlay/resolve')
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer overlay-secret')
            ->setBody(['url' => 'https://example.com/about'])
            ->send()
            ->getJsonContent();

        expect($json['success'])->toBeFalse();
    });

    it('returns 401 when no token is provided', function() {
        $this->http('post', 'accessibility-audit/overlay/resolve')
            ->addHeader('Accept', 'application/json')
            ->setBody(['url' => 'https://example.com/about'])
            ->send()
            ->assertStatus(401);
    });

    it('returns 401 when the token is wrong', function() {
        $this->http('post', 'accessibility-audit/overlay/resolve')
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer wrong')
            ->setBody(['url' => 'https://example.com/about'])
            ->send()
            ->assertStatus(401);
    });

    it('returns 401 when no token has been generated', function() {
        ovSetSettings(['overlayApiToken' => null]);

        $this->http('post', 'accessibility-audit/overlay/resolve')
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer overlay-secret')
            ->setBody(['url' => 'https://example.com/about'])
            ->send()
            ->assertStatus(401);
    });
});

// ---------------------------------------------------------------------------
// Resolve
// ---------------------------------------------------------------------------

describe('OverlayController::actionResolve', function() {
    it('returns the overlay config with absolute URLs for an unmatched page', function() {
        $json = $this->http('post', 'accessibility-audit/overlay/resolve')
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer overlay-secret')
            ->setBody(['url' => 'https://nowhere.invalid/no/such/page'])
            ->send()
            ->getJsonContent();

        expect($json['success'])->toBeTrue();

        $config = $json['config'];
        expect($config['elementId'])->toBe(0)
            ->and($config['scanId'])->toBe(0)
            ->and($config['axeTags'])->toBeArray()->not->toBeEmpty()
            ->and($config['storeUrl'])->toStartWith('http')
            ->and($config['pageIssuesUrl'])->toStartWith('http')
            ->and($config['reportUrl'])->toStartWith('http')
            ->and($config['siteId'])->toBe((int) Craft::$app->getSites()->getPrimarySite()->id);
        // The credential fields are the delivery paths' own business: CSRF is
        // added by the injection path, the token client-side by the loader.
        expect($config)->not->toHaveKey('csrfValue');
    });

    it('refuses an empty URL', function() {
        $json = $this->http('post', 'accessibility-audit/overlay/resolve')
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer overlay-secret')
            ->setBody(['url' => ''])
            ->send()
            ->getJsonContent();

        expect($json['success'])->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Loader script
// ---------------------------------------------------------------------------

describe('OverlayController::actionScript', function() {
    it('serves the loader with boot config when enabled on Pro', function() {
        $response = $this->http('get', 'accessibility-audit/overlay.js')->send();

        $body = $response->content;
        expect($body)->toContain('__A11Y_OVERLAY_BOOT__')
            ->and($body)->toContain('resolveUrl')
            ->and($body)->toContain('a11y-connect');
    });

    it('serves an inert stub on the Standard edition', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        $body = $this->http('get', 'accessibility-audit/overlay.js')->send()->content;

        expect($body)->toContain('not enabled')
            ->and($body)->not->toContain('__A11Y_OVERLAY_BOOT__');
    });

    it('serves an inert stub when the feature is disabled', function() {
        ovSetSettings(['decoupledOverlay' => false]);

        $body = $this->http('get', 'accessibility-audit/overlay.js')->send()->content;

        expect($body)->not->toContain('__A11Y_OVERLAY_BOOT__');
    });
});

// ---------------------------------------------------------------------------
// Store + read twins
// ---------------------------------------------------------------------------

describe('OverlayController store and read', function() {
    it('stores axe results against an existing scan with a valid token', function() {
        $elementId = (int) \markhuot\craftpest\factories\User::factory()->create()->id;
        $scanId = ovScanId($elementId, (int) Craft::$app->getSites()->getPrimarySite()->id);

        $json = $this->http('post', 'accessibility-audit/overlay/store-axe-results')
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer overlay-secret')
            ->setBody([
                'scanId' => $scanId,
                'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                'violations' => json_encode([ovViolation()]),
            ])
            ->send()
            ->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and($json['scanId'])->toBe($scanId)
            ->and(ovIssueCount($scanId))->toBeGreaterThan(0);
    });

    it('refuses a store without a token even when everything else is valid', function() {
        $elementId = (int) \markhuot\craftpest\factories\User::factory()->create()->id;
        $scanId = ovScanId($elementId, (int) Craft::$app->getSites()->getPrimarySite()->id);

        $this->http('post', 'accessibility-audit/overlay/store-axe-results')
            ->addHeader('Accept', 'application/json')
            ->setBody([
                'scanId' => $scanId,
                'violations' => json_encode([ovViolation()]),
            ])
            ->send()
            ->assertStatus(401);

        expect(ovIssueCount($scanId))->toBe(0);
    });

    it('refuses a store against an unknown site', function() {
        $json = $this->http('post', 'accessibility-audit/overlay/store-axe-results')
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer overlay-secret')
            ->setBody([
                'siteId' => 99999,
                'violations' => json_encode([ovViolation()]),
            ])
            ->send()
            ->getJsonContent();

        expect($json['success'])->toBeFalse();
    });

    it('refuses page-issues without a token', function() {
        $this->http('get', 'accessibility-audit/overlay/page-issues?scanId=1')
            ->addHeader('Accept', 'application/json')
            ->send()
            ->assertStatus(401);
    });

    it('returns stored issues from page-issues with a valid token', function() {
        $elementId = (int) \markhuot\craftpest\factories\User::factory()->create()->id;
        $scanId = ovScanId($elementId, (int) Craft::$app->getSites()->getPrimarySite()->id);

        AccessibilityAudit::getInstance()->audit->storeAxeIssues($scanId, [ovViolation()], 'desktop');

        $json = $this->http('get', 'accessibility-audit/overlay/page-issues?scanId=' . $scanId)
            ->addHeader('Accept', 'application/json')
            ->addHeader('Authorization', 'Bearer overlay-secret')
            ->send()
            ->getJsonContent();

        expect($json['success'])->toBeTrue()
            ->and($json['issues'])->toBeArray()->not->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// Settings template
// ---------------------------------------------------------------------------

describe('Tools settings template', function() {
    it('renders the decoupled frontends section with both tag options', function() {
        $this->actingAs(\markhuot\craftpest\factories\User::factory()->admin(true)->create());

        $plugin = AccessibilityAudit::getInstance();
        $view = Craft::$app->getView();
        $html = $view->renderTemplate('accessibility-audit/_settings/tools', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'config' => [],
            'isPro' => true,
            'newCiApiToken' => null,
            'newOverlayApiToken' => null,
            'overlayScriptUrl' => 'https://example.test/accessibility-audit/overlay.js',
        ], \craft\web\View::TEMPLATE_MODE_CP);

        expect($html)->toContain('overlayScriptTag()')
            ->and($html)->toContain('a11y-connect')
            ->and($html)->toContain('https://example.test/accessibility-audit/overlay.js')
            ->and($html)->toContain('defer');
    });
});

// ---------------------------------------------------------------------------
// Twig variable
// ---------------------------------------------------------------------------

describe('AccessibilityVariable::overlayScriptTag', function() {
    it('renders a defer script tag pointing at the loader when enabled on Pro', function() {
        $tag = (string) (new \johnhenry\accessibilityaudit\variables\AccessibilityVariable())->overlayScriptTag();

        expect($tag)->toContain('<script src="')
            ->and($tag)->toContain('/accessibility-audit/overlay.js')
            ->and($tag)->toContain(' defer');
    });

    it('renders nothing on the Standard edition', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        $tag = (string) (new \johnhenry\accessibilityaudit\variables\AccessibilityVariable())->overlayScriptTag();

        expect($tag)->toBe('');
    });

    it('renders nothing when the feature is disabled', function() {
        ovSetSettings(['decoupledOverlay' => false]);

        $tag = (string) (new \johnhenry\accessibilityaudit\variables\AccessibilityVariable())->overlayScriptTag();

        expect($tag)->toBe('');
    });
});

// ---------------------------------------------------------------------------
// OverlayService origin allow-list
// ---------------------------------------------------------------------------

describe('OverlayService::isAllowedOrigin', function() {
    it('allows extra origins from settings and refuses unknown ones', function() {
        ovSetSettings(['overlayAllowedOrigins' => "http://localhost:3000\nhttps://preview.example.com/"]);

        $overlay = AccessibilityAudit::getInstance()->getOverlay();

        expect($overlay->isAllowedOrigin('http://localhost:3000'))->toBeTrue()
            ->and($overlay->isAllowedOrigin('https://preview.example.com'))->toBeTrue()   // trailing slash normalised
            ->and($overlay->isAllowedOrigin('https://evil.example.com'))->toBeFalse()
            ->and($overlay->isAllowedOrigin(''))->toBeFalse();
    });

    it('always allows the origins of the sites\' base URLs', function() {
        ovSetSettings(['overlayAllowedOrigins' => '']);

        $overlay = AccessibilityAudit::getInstance()->getOverlay();
        $baseUrl = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        if (!$baseUrl || !($parts = parse_url($baseUrl)) || empty($parts['host'])) {
            $this->markTestSkipped('Primary site has no absolute base URL in this environment.');
        }

        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host']
            . (!empty($parts['port']) ? ':' . $parts['port'] : '');

        expect($overlay->isAllowedOrigin($origin))->toBeTrue();
    });
});
