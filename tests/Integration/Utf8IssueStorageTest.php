<?php

use craft\db\Query;
use craft\elements\Entry;
use johnhenry\accessibilityaudit\AccessibilityAudit;

// ---------------------------------------------------------------------------
// Multibyte content must survive truncation and storage. A byte-based cut can
// split a multibyte character (a curly quote is three bytes), and strict-mode
// MySQL rejects the resulting row, aborting the whole scan transaction. This
// reproduces the exact failure: alt text where the 100-character context cut
// lands on a multibyte character boundary, pushed through the full
// scan-and-store path.
// ---------------------------------------------------------------------------

function utf8FixturePage(string $alt): string
{
    $altEsc = htmlspecialchars($alt, ENT_QUOTES);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <title>UTF-8 fixture</title>
    <meta name="description" content="A UTF-8 truncation fixture page.">
</head>
<body>
    <main>
        <h1>Heading</h1>
        <p><img src="/card.jpg" alt="{$altEsc}"></p>
    </main>
</body>
</html>
HTML;
}

it('stores a long multibyte alt text without failing the scan', function() {
    $entry = scannableEntry('UTF-8 truncation fixture');

    // 99 ASCII chars then a curly quote: the byte-based cut at 100 used to
    // slice the quote in half. Padding past 150 characters triggers the
    // long-alt check.
    $alt = str_repeat('a', 99) . '”' . str_repeat('b', 60);

    $result = AccessibilityAudit::getInstance()->audit->scanHtml(
        utf8FixturePage($alt),
        (int) $entry->id,
        Entry::class,
        (int) $entry->siteId,
    );

    expect($result['scanId'])->toBeGreaterThan(0);

    $context = (new Query())
        ->select(['context'])
        ->from('{{%accessibilityaudit_issues}}')
        ->where(['scanId' => $result['scanId'], 'ruleId' => 'potential:long-alt'])
        ->scalar();

    expect($context)->toBe(str_repeat('a', 99) . '”…')
        ->and(mb_check_encoding((string) $context, 'UTF-8'))->toBeTrue();
});
