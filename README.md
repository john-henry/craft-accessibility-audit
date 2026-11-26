[![Stable Version](https://img.shields.io/packagist/v/johnhenry/craft-accessibility-audit?label=stable&style=for-the-badge)](https://packagist.org/packages/johnhenry/craft-accessibility-audit)
[![Static Badge](https://img.shields.io/badge/BUY-plugin?style=for-the-badge&logo=craftcms&logoColor=white&logoSize=auto&label=Craft%20Plugin%20Store&labelColor=%23E5422B)](https://plugins.craftcms.com/accessibility-audit?craft5)

![Accessibility Audit](https://johnhenry.ie/images/plugins/promos/accessibility-audit/1.png)

# Accessibility Audit for Craft CMS

When a client, a procurement team or a regulator asks whether the site meets WCAG, this is how you find out from inside Craft. It scans your pages for WCAG 2.2 failures, tells you which fixes lift the score most, asks you the questions no scanner can answer, and keeps a dated record, statement and VPAT to show for it.

## Editions

There are three editions, and the full split is on the [Editions](https://johnhenry.ie/plugins/accessibility-audit/docs/getting-started/editions) page.

- **Lite** is free: up to 25 pages, the primary site, and 30 days of scan history.
- **Standard** takes the page cap off and keeps 90 days of history.
- **Pro** adds multi-site and keeps history for as long as you want it.

## In every edition

- **28 checks in PHP** on every scan: images, links, forms, headings, tables, structure and more, with nothing to install.
- **axe-core in the browser**, on the live site for admins and in the page report. It catches the contrast, focus visibility and target size problems that only show once a page renders, at desktop and phone width.
- **Hover, focus and selection contrast.** A page is always at rest while it's scanned, so these colours never turn up in an ordinary check. The plugin reads them out of your stylesheet instead and measures them against the background the element sits on.
- **Twelve questions for a person**, for what no scanner can settle, like whether alt text describes its image or whether text over a photo can be read. Answer once and the answer stays through re-scans.
- **Scores against WCAG 2.2 A, AA or AAA**, with EN 301 549 alongside, and a history so a drop shows up the week it happens.
- **A page for every rule**, with a plain title, what to change, and every page it turned up on, so you can tell a template fix from a content fix.
- **The page report**, with every occurrence highlighted in a live preview and in the source, plus colour-blindness and low-vision filters.
- **A panel on every element**, with its score, its issues and a re-scan button, where the editing happens.
- **Any element type**: entries, categories, Craft Commerce products and any custom element with a public URL, plus pages with no element behind them.
- **Scan on save and in the background**, through the queue, with console commands for scheduled sweeps.
- **Colour tools**: a contrast analyser, a colour-blindness simulator and a grid for a whole palette.
- **The accessibility statement** the EU and UK require, built to your jurisdiction's model, with the compliance claim held to what your evidence supports.

## Standard adds

- **Unlimited pages and 90 days of history.**
- **Server-side browser scanning.** Point it at Chrome on the server, or at a remote Chrome on hosts like Craft Cloud where nothing can be installed, and every queued scan runs the browser checks at both widths without anybody visiting the page.
- **AI alt text**, from Claude Haiku on your own Anthropic API key. Each image gets three versions and a note on anything worth checking. Draft one image or the whole missing-alt list, then approve them one at a time or all together. New uploads can be drafted as they arrive.
- **PDF checks** on every PDF in the asset library, for what a machine can settle: tags, title, language, figure alt text and form fields, plus headings, tables, lists and links with a browser set up. It isn't a PDF/UA validator, and it says so.
- **Readability**, with reading ease and grade level against WCAG 3.1.5 and plainer wording suggested for the hard sentences.
- **Fix guides**, a Markdown to-do list for the site, one rule or one page, split between developers and content editors, with a prompt for each developer rule to paste into an AI coding assistant.
- **AI assistant tools.** On Craft 5.12 or later, an assistant signed in to Craft's MCP server can read the audit and re-scan a page.
- **Email and Slack alerts** when a new error lands or a score drops.

## Pro adds

- **Multi-site**, with every site in the install scanned and reported on.
- **VPAT / ACR**, the conformance report procurement teams ask for, filled in from your scans and exported print-ready, through your own template, or as OpenACR.
- **A CI/CD endpoint**, so a build pipeline can hold a deploy until the score reaches your target.
- **The decoupled frontend overlay**, for headless front ends like Next, Nuxt or Astro, from one script tag.
- **Unlimited history and a permanent record of resolved issues**, for reporting and compliance.

## Documentation

Full documentation is at [johnhenry.ie/plugins/accessibility-audit/docs](https://johnhenry.ie/plugins/accessibility-audit/docs/getting-started/overview).

## Requirements

- Craft CMS 5.0 or later (5.12 or later for the AI assistant tools)
- PHP 8.2 or later

## Accessibility

How accessible the plugin is, what's been checked, and how to report a problem are all in the [accessibility statement](https://github.com/john-henry/craft-accessibility-audit/blob/craft-5/ACCESSIBILITY.md).

## Support

Need a hand? Open an issue on the [GitHub Issues page](https://github.com/john-henry/craft-accessibility-audit/issues).

## License

Proprietary. Copyright (c) 2026 John Henry Donovan.

The PDF checks use [tc-lib-pdf-parser](https://github.com/tecnickcom/tc-lib-pdf-parser) and [tc-lib-pdf-filter](https://github.com/tecnickcom/tc-lib-pdf-filter) (both LGPL-3.0-or-later), installed by Composer as dependencies, and bundle [pdf.js](https://github.com/mozilla/pdf.js) (Apache-2.0). See [NOTICE.md](https://github.com/john-henry/craft-accessibility-audit/blob/craft-5/NOTICE.md) for the third-party notices.

---

<a href="https://johnhenry.ie/plugins/" target="_blank">
    <img height="46" src="https://johnhenry.ie/images/plugins/logo.svg" alt="John Henry - Craft CMS Plugins">
</a>
