<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// The save-time handlers run inside the element save's own transaction, so
// anything they throw is the editor's entry refused for the sake of a scan.
//
// getUrl() is the part worth guarding: it fires two events, so a third-party
// handler on one entry throws here as readily as the queueing does. The asset
// sync already said this in its own comment ("an audit-row sync must never
// break an asset save"); the entry path did not follow it.
//
// Asserted from the source rather than by making a save fail. A handler that
// throws from EVENT_BEFORE_DEFINE_URL takes the save down from whichever call
// site reaches getUrl() first, which is not always this one, so a passing save
// would not tell you whose catch caught it. What can be pinned is the shape:
// getUrl() inside the try, and every save-time handler carrying one.
// ---------------------------------------------------------------------------

it('keeps the whole scan-on-save step inside its catch', function() {
    // Asserted from the source: the ordering that matters is that getUrl() sits
    // inside the try, and there is no way to observe that from a passing save.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/src/base/PluginTrait.php',
    );

    preg_match('/private function _registerScanOnSave\(\).*?\n    \}/s', $source, $m);
    $body = $m[0] ?? '';

    expect($body)->not->toBeEmpty();

    $tryAt = strpos($body, 'try {');
    $urlAt = strpos($body, '$element->getUrl()');

    expect($tryAt)->not->toBeFalse()
        ->and($urlAt)->not->toBeFalse()
        ->and($tryAt)->toBeLessThan($urlAt)
        ->and($body)->toContain('catch (Throwable $err)');
});

it('guards every save-time handler, not only the asset one', function() {
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/src/base/PluginTrait.php',
    );

    $unguarded = [];

    foreach (['_registerAssetAuditSync', '_registerScanOnSave', '_registerAutoGenerateAlt'] as $method) {
        preg_match('/private function ' . $method . '\(\).*?\n    \}/s', $source, $m);

        if (!str_contains($m[0] ?? '', 'catch (Throwable')) {
            $unguarded[] = $method;
        }
    }

    expect($unguarded)->toBe([]);
});
