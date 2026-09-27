<?php

use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\console\controllers\AuditController;
use johnhenry\accessibilityaudit\console\controllers\VpatController;
use markhuot\craftpest\factories\User as UserFactory;
use yii\console\ExitCode;

// ---------------------------------------------------------------------------
// These commands run unattended, on a schedule, with nobody reading the output.
// The failures worth pinning are the quiet ones: a command that acts on a site
// nobody named, one that deletes everything when asked to keep everything, and
// one that reports a page it never opened as clean.
// ---------------------------------------------------------------------------

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
});

/** An audit console controller, wired to the plugin as its module. */
function auditCommand(): AuditController
{
    $controller = new AuditController('audit', AccessibilityAudit::getInstance());
    $controller->interactive = false;

    return $controller;
}

/** A VPAT console controller, wired to the plugin as its module. */
function vpatCommand(): VpatController
{
    $controller = new VpatController('vpat', AccessibilityAudit::getInstance());
    $controller->interactive = false;

    return $controller;
}

describe('an unresolvable --site', function() {
    it('refuses to report on a site handle that names nothing', function() {
        $controller = auditCommand();
        $controller->site = 'noSuchSiteHandle';

        // Falling back to the primary site here means a scheduled command
        // quietly reports the wrong site's numbers as the requested site's.
        expect($controller->actionReport())->toBe(ExitCode::USAGE);
    });

    it('refuses to clear VPAT revisions on a site handle that names nothing', function() {
        $controller = vpatCommand();
        $controller->site = 'noSuchSiteHandle';

        // The same fallback on a destructive command deletes another site's
        // revisions and prints success.
        expect($controller->actionClearRevisions())->toBe(ExitCode::USAGE);
    });

    it('still works without --site', function() {
        expect(auditCommand()->actionReport())->toBe(ExitCode::OK);
    });
});

describe('prune --days=0', function() {
    it('keeps history rather than deleting all of it', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        seedComplianceScan($siteId);

        $before = (new Query())->from('{{%accessibilityaudit_scans}}')->count();
        expect($before)->toBeGreaterThan(0);

        $controller = auditCommand();
        $controller->days = 0;

        // Zero means "keep for good" in the setting and in the garbage
        // collector. Read as a cutoff it is "delete everything recorded before
        // this instant", which is every scan there has ever been.
        expect($controller->actionPrune())->toBe(ExitCode::OK)
            ->and((new Query())->from('{{%accessibilityaudit_scans}}')->count())->toBe($before);
    });
});

describe('scan-element on something with no page', function() {
    it('says so rather than reporting a perfect score', function() {
        $controller = auditCommand();
        // A user is an element with no URL, so there is nothing to fetch.
        $controller->elementId = UserFactory::factory()->create()->id;

        // scanElement() answers 100 with no scan behind it, leaving the caller
        // to read why it stopped. Printing that number alone reports a page
        // nobody opened as a clean one.
        expect($controller->actionScanElement())->toBe(ExitCode::DATAERR);
    });

    it('reports a missing element as bad data, not as a pass', function() {
        $controller = auditCommand();
        $controller->elementId = 99999999;

        expect($controller->actionScanElement())->toBe(ExitCode::DATAERR);
    });
});

describe('a confirmation that destroys something', function() {
    // Every file that can put a console confirmation. The trait is in here
    // because the check used to read the controllers alone: when the prompt
    // moved out of them, the search found nothing and the test went green
    // while checking nothing at all.
    $files = static fn(): array => array_merge(
        glob(dirname(__DIR__, 2) . '/src/console/controllers/*.php') ?: [],
        glob(dirname(__DIR__, 2) . '/src/base/ConsoleSiteTrait.php') ?: [],
    );

    it('defaults to no wherever it is asked', function() use ($files) {
        // confirm() reads stdin, so the default is asserted from the source.
        // The two controllers disagreed once: prune-excluded defaulted to yes,
        // and what it clears belongs to pages on the exclusion list, which are
        // never scanned again, so nothing rebuilds it.
        $defaultsToYes = [];
        $found = 0;

        foreach ($files() as $path) {
            $source = (string) file_get_contents((string) $path);

            preg_match_all('/\$this->confirm\(([^;]*?)\);/s', $source, $calls);

            foreach ($calls[1] as $args) {
                $found++;

                if (!str_contains($args, 'false')) {
                    $defaultsToYes[] = basename((string) $path) . ': confirm(' . trim($args) . ')';
                }
            }
        }

        // Without this the test passes on a codebase that asks nothing.
        expect($found)->toBeGreaterThan(0)
            ->and($defaultsToYes)->toBe([]);
    });

    it('still goes ahead unprompted when the run is not interactive', function() {
        // --interactive=0 is how these run from a deploy script, and a prompt
        // nobody can answer would hang it. Asserted by calling it rather than
        // by reading the source, so it follows the code wherever it lives.
        foreach ([AuditController::class, VpatController::class] as $class) {
            $controller = new $class('accessibility-audit', AccessibilityAudit::getInstance());
            $controller->interactive = false;

            $confirm = new ReflectionMethod($controller, '_confirmRemoval');

            expect($confirm->invoke($controller, 'Remove them?'))->toBeTrue();
        }
    });

    it('asks both controllers through the one implementation', function() {
        // Two copies of a refusal is one copy that gets fixed.
        expect(class_uses(AuditController::class))
            ->toHaveKey(johnhenry\accessibilityaudit\base\ConsoleSiteTrait::class)
            ->and(class_uses(VpatController::class))
            ->toHaveKey(johnhenry\accessibilityaudit\base\ConsoleSiteTrait::class);
    });
});
