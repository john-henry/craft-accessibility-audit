<?php

/**
 * Accessibility Audit configuration
 *
 * Copy this file to config/accessibility-audit.php in your project to pin any
 * plugin setting from code. Values set here take precedence over the control
 * panel settings screen, where the matching field is shown read-only with an
 * "Overridden by config file" note. Uncomment only the keys you want to fix;
 * anything left commented keeps its default (shown below) and stays editable
 * in the CP.
 */

return [
    // WCAG
    // ---------------------------------------------------------------------
    // Target conformance level: 'A', 'AA', or 'AAA'.
    // 'wcagLevel' => 'AA',

    // Include EN 301 549 (the European standard) rules alongside WCAG.
    // 'en301549' => false,

    // Target score (0-100) tracked on the dashboard. 0 disables the target.
    // 'targetScore' => 0,

    // Accessibility statement
    // ---------------------------------------------------------------------
    // Site template that renders the public statement in place of the built-in
    // markup, e.g. '_statement/render'. It receives the same `statement`
    // variable. Empty uses the built-in markup.
    // 'statementTemplate' => '',

    // VPAT (Pro)
    // ---------------------------------------------------------------------
    // Site template that renders the VPAT export in place of the built-in
    // document, e.g. '_vpat/export'. It receives the same `report` variable
    // the built-in export gets. Empty (or a template that doesn't exist)
    // uses the built-in print-ready document.
    // 'vpatExportTemplate' => '',

    // Scanning
    // ---------------------------------------------------------------------
    // Auto-scan entries when they are saved.
    // 'scanOnSave' => true,

    // Element type class names the content scanner covers. null = every native
    // (Craft-core and first-party, i.e. craft\ namespace) URL-bearing type. Set
    // an explicit list to opt a third-party plugin's custom element in or out.
    // 'scannedElementTypes' => null,

    // Rule IDs to skip during scanning.
    // 'ignoreRules' => [],

    // Pages to exclude from every scan. Each row is a regular expression
    // tested against the page URI; an empty 'uriPattern' matches the homepage.
    // Scope a row to one site with 'siteId', or leave it out to match all
    // sites. 'enabled' defaults to true.
    // 'excludedUriPatterns' => [
    //     ['uriPattern' => '^checkout'],
    //     ['uriPattern' => 'print$', 'siteId' => 1],
    // ],

    // Asset volumes to leave out of the alt-text audit, by volume UID. Images
    // in these volumes drop out of the Assets page, its counts, and the
    // dashboard panel: handy for avatars, generated thumbnails, or system
    // files that aren't public content needing alt text.
    // 'excludedVolumes' => [],

    // Path to a Chrome/Chromium binary for server-side axe scans (Pro).
    // When set, every queued scan also runs a full browser pass, so contrast,
    // focus, and target-size findings cover the whole site without an admin
    // visiting each page. Empty disables it. Prefer an env var reference.
    // 'chromePath' => '$CHROME_PATH',

    // Whether headless Chrome launches with --no-sandbox. Most containers and
    // CI runners need this on (the default), because Chrome's sandbox can't
    // initialise there. Turn it off on hosts with a working sandbox for
    // defence in depth.
    // 'chromeNoSandbox' => true,

    // User-Agent header sent by the scanner's HTTP fetches and browser passes,
    // replacing the branded default. Useful for allow-listing the scanner in a
    // WAF or CDN rule. Empty keeps the default.
    // 'scannerUserAgent' => '',

    // Frontend overlay
    // ---------------------------------------------------------------------
    // Inject the axe-core overlay on the live site for logged-in admins.
    // 'frontendAxe' => true,

    // Corner the overlay and its badge dock to: 'bottom-right', 'bottom-left',
    // 'top-right', or 'top-left'.
    // 'overlayPosition' => 'bottom-right',

    // Keep the overlay collapsed to a small count badge, expanding on click.
    // 'overlayCollapseWhenIdle' => false,

    // Seconds of inactivity before the overlay collapses back to its badge.
    // Only applies when overlayCollapseWhenIdle is on. Range 3-600.
    // 'overlayIdleSeconds' => 30,

    // Retention
    // ---------------------------------------------------------------------
    // Days to retain scan results before auto-pruning. 0 = never prune.
    // 'retainDays' => 90,

    // How resolved issues are retained when scans are pruned:
    // 'withScans' (default) prunes them along with old scans, 'keepDays' keeps
    // them the retention window past their resolution date, 'forever' never
    // prunes them (permanent record, grows the database over time).
    // 'resolvedRetention' => 'withScans',

    // AI alt text
    // ---------------------------------------------------------------------
    // Anthropic API key. Prefer an environment variable reference over a
    // literal key, for example '$ANTHROPIC_API_KEY'.
    // 'anthropicApiKey' => '$ANTHROPIC_API_KEY',

    // Field handle used to store alt text.
    // 'altTextField' => 'alt',

    // Language for generated alt text.
    // 'altTextLanguage' => 'English',

    // Short site or brand description sent with each generation request.
    // 'altTextContext' => '',

    // Generate alt text automatically when new image assets are uploaded.
    // 'autoGenerateAlt' => false,

    // Notifications
    // ---------------------------------------------------------------------
    // Email a notification when a scan crosses a threshold.
    // 'notifyEmailEnabled' => false,

    // Comma- or newline-separated recipient list.
    // 'notifyEmailRecipients' => '',

    // Post a Slack notification when a scan crosses a threshold.
    // 'notifySlackEnabled' => false,

    // Slack incoming webhook URL. Prefer an environment variable reference.
    // 'notifySlackWebhookUrl' => '$A11Y_SLACK_WEBHOOK',

    // Notify when a scan introduces a new error-severity issue.
    // 'notifyOnNewError' => false,

    // Notify when a scan's score drops sharply.
    // 'notifyOnScoreDrop' => false,

    // Points a score must drop before a notification fires (1-100).
    // 'notifyScoreDropThreshold' => 10,
];
