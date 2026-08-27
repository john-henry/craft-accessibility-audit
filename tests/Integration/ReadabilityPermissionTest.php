<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\accessibilityaudit\controllers\ReadabilityController;

// ---------------------------------------------------------------------------
// Analysing is a scanning act, not a reading one.
//
// Both analyse endpoints fetch a page from the server and can reach the
// Anthropic API, so they spend the site's outbound requests and the account's
// budget. That is the runScans tier's authority. Reading stored results stays
// on viewReports, which is all the page itself needs.
//
// Rate limiting already capped how fast this could be driven; it never settled
// who was allowed to drive it, and viewReports is the permission you give
// someone so they can look at the reports.
//
// Checked against the source rather than by calling the endpoints: granting a
// single permission to a test user does not work in this harness (the plugin's
// permissions are not registered in that context, so Craft drops them), and a
// test that could only be written with an admin user would pass whatever the
// required permission said.
// ---------------------------------------------------------------------------

/**
 * The body of one controller action, up to the start of the next.
 *
 * @param string $method The action method name.
 * @return string
 */
function readabilityActionBody(string $method): string
{
    $source = (string)file_get_contents((new ReflectionClass(ReadabilityController::class))->getFileName());

    $start = strpos($source, "public function {$method}(");
    expect($start)->not->toBeFalse("{$method}() is gone: update this test with its replacement.");

    $next = strpos($source, "\n    public function ", (int)$start + 20);

    return substr($source, (int)$start, $next !== false ? $next - (int)$start : null);
}

describe('readability permission tiers', function() {
    it('requires the scanning permission to analyse a URL', function() {
        $body = readabilityActionBody('actionAnalyse');

        expect($body)->toContain("requirePermission('accessibility-audit:runScans')")
            ->and($body)->not->toContain("requirePermission('accessibility-audit:viewReports')");
    });

    it('requires the scanning permission to analyse an element', function() {
        $body = readabilityActionBody('actionAnalyseEntry');

        expect($body)->toContain("requirePermission('accessibility-audit:runScans')")
            ->and($body)->not->toContain("requirePermission('accessibility-audit:viewReports')");
    });

    it('keeps both analyse endpoints POST-only', function() {
        // Both spend money and fetch. A GET route to either would also be
        // reachable without Craft's CSRF check.
        expect(readabilityActionBody('actionAnalyse'))->toContain('requirePostRequest()')
            ->and(readabilityActionBody('actionAnalyseEntry'))->toContain('requirePostRequest()');
    });

    it('leaves reading the results on the viewing permission', function() {
        // The tightening is on the act, not on the reporting: whoever can see
        // the audit can still see what it found.
        expect(readabilityActionBody('actionIndex'))
            ->toContain("requirePermission('accessibility-audit:viewReports')");
    });

    it('hides the analyse controls from someone who cannot use them', function() {
        // A button that 403s is worse than no button, and the sidebar link
        // would otherwise send a reader to a page with nothing on it.
        $template = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/readability.twig');
        $panel = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/_sidebar/accessibility-panel.twig');

        expect($template)->toContain('{% if canRunScans %}')
            ->and($template)->toContain('!v || !canRunScans')
            ->and($panel)->toContain("currentUser.can('accessibility-audit:runScans')");
    });
});
