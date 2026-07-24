[![Stable Version](https://img.shields.io/packagist/v/johnhenry/craft-accessibility-audit?label=stable&style=for-the-badge)](https://packagist.org/packages/johnhenry/craft-accessibility-audit)
[![Static Badge](https://img.shields.io/badge/BUY-plugin?style=for-the-badge&logo=craftcms&logoColor=white&logoSize=auto&label=Craft%20Plugin%20Store&labelColor=%23E5422B)](https://plugins.craftcms.com/accessibility-audit?craft5)

![Accessibility Audit](https://johnhenry.ie/images/plugins/promos/accessibility-audit/1.png)



# Accessibility Audit for Craft CMS

A full WCAG 2.2 accessibility audit, built right into the Craft control panel. It scans every page on your site for accessibility failures, keeps track of issues over time, and flags up the ones that need a human eye, all without leaving Craft.

## Features

- **25+ WCAG 2.2 rules** – the PHP scanner checks images, links, forms, headings, colour usage, tables, and more
- **axe-core overlay** – runs on the live site for admins, catching contrast, focus visibility, and target size issues that only show up once the page is actually rendered
- **Server-side browser scanning** – point it at a Chrome binary and every queued scan runs the full axe checks at desktop and mobile widths, so nobody has to visit a page for it to be tested properly
- **Potential issues** – 8 checks that need a human review, phrased as questions (e.g. "Is this image alt text descriptive enough?"). Answer once and the answer sticks through re-scans
- **Any element type** – entries, categories, Craft Commerce products, and any custom element with a public URL
- **Queue-based background scanning** and **scan on save**, so reports keep themselves up to date
- **Score and history** – a site-wide score tracked over time, issues grouped by rule with difficulty and responsibility metadata, and a list of every affected page
- **Page report** – every occurrence highlighted in a live preview of the page and in its source, with colour-blindness and low-vision filters to see what a user actually gets
- **Sidebar panel on every element** – latest score, issues grouped by rule, and a re-scan button, right where the editing happens
- **AI alt text** – Claude Haiku writes alt text for your images, with a full-library audit of what is missing, filename-as-alt, or too short
- **Readability** – Flesch-Kincaid reading ease and grade level against WCAG 3.1.5, with plain-language rewrite suggestions
- **Colour tools** – contrast analyser, colour blindness simulator, and a contrast grid for checking a whole palette at once
- **Accessibility statement** – the public statement the EU and UK legally require, built to your jurisdiction's model, with the compliance claim held to what your evidence actually supports
- **VPAT / ACR** – the conformance report procurement teams ask for, pre-filled from your scan data, exported as a print-ready document or through your own template
- **EN 301 549** – report against the European standard alongside WCAG
- **Email and Slack alerts** when a new error lands or a score drops
- **CI/CD endpoint** so a build pipeline can gate a deploy on your accessibility score

Some of the above is Pro only. See [Editions]([https://johnhenry.ie/plugins/accessibility-audit/docs/guide/editions](https://johnhenry.ie/plugins/accessibility-audit/docs/getting-started/editions)) for the split.


## Documentation

Full documentation at [https://johnhenry.ie/plugins/accessibility-audit/docs/](https://johnhenry.ie/plugins/accessibility-audit/docs/)

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later

---

<a href="https://johnhenry.ie/plugins/" target="_blank">
    <img height="46" src="https://johnhenry.ie/images/plugins/logo.svg" alt="John Henry - Craft CMS Plugins">
</a>
