# Accessibility statement for Accessibility Audit

Accessibility Audit is a plugin for Craft CMS 5. This statement sets out how accessible it is, what I know is wrong with it, and how to tell me about anything else.

## What this covers

This statement covers Accessibility Audit 2.0.0:

- the control panel screens: the dashboard, issues, issue details, potential issues, page reports, readability, assets and the alt text panel, PDF details, reports and their print views, colour tools, the accessibility statement builder and the VPAT/ACR builder
- the settings pages and the Accessibility Score dashboard widget
- the accessibility panel in the entry sidebar, and the alt text suggestions on an asset's own edit screen
- the scan overlay shown on the front end of your site to people running audits
- the public accessibility statement template and the VPAT/ACR export

It doesn't cover:

- Craft's own control panel, which Craft reports on at https://craftcms.com/accessibility
- your site's own templates and content
- axe-core, the third-party scanning engine the plugin bundles

## The standard I'm working to

WCAG 2.2 level AA. Accessibility Audit adds to the Craft control panel, which is an authoring tool, so I also follow ATAG 2.0 Part A where it applies to the parts Accessibility Audit adds. Craft itself works to the same standards and publishes its own reports at https://craftcms.com/accessibility, but those reports don't cover plugins.

## How far it meets it

I believe the parts listed above meet WCAG 2.2 AA. The review for 2.0.0 found three problems in the new alt text tools, and 2.0.0 fixes all of them:

- the character count under the alt text field was read out by screen readers after every keystroke
- keyboard focus was lost when Generate with AI failed
- keyboard focus was lost when Discard draft failed

It hasn't been tested with assistive technology yet, though, so I'm not claiming full conformance until it has.

## How it was checked

Last checked in November 2026, for 2.0.0:

- An automated pass with the same axe-core the plugin scans sites with, against the WCAG 2.0, 2.1 and 2.2 A and AA rules, over 28 control panel screens and states. That includes the Reports screens and their print view, the page report with everything opened, and the entry sidebar panel. It found nothing.
- A read through the templates and scripts of the alt text panel and its suggestions, the PDF detail page and the Reports screens, for what an automated check can't see: where keyboard focus goes, what gets announced, and heading and table structure.
- The rest of what changed in 2.0.0, such as the rebuilt Assets list and the edition badge, was covered by the automated pass only.
- The alt text panel and the PDF detail page were reviewed by reading their templates and scripts but not run through the automated pass, because the test site has no images or PDFs to open them with.
- The screens that didn't change in 2.0.0 still rest on the full review done for 1.5.0 in September 2026, which went through every template, script and stylesheet against WCAG 2.2 AA, including the WAI-ARIA Authoring Practices for custom widgets and contrast ratios worked out from the actual colours in the CSS.
- Not done yet: testing with screen readers (NVDA, JAWS and VoiceOver), zoom and reflow at 400%, or speech input. This statement will be updated once that's done.

## Known issues

None known right now. If you come across one, please tell me (see below).

## For site developers

The public accessibility statement template works out its own heading levels from where you place it, so it fits the heading structure of your page. The scan overlay only appears for people running an audit, not for your visitors.

## Tell me about a problem

If something in Accessibility Audit is hard or impossible for you to use, open an issue at https://github.com/john-henry/craft-accessibility-audit/issues and put "Accessibility" in the title. Say which screen you were on, what you were trying to do, and which browser and assistive technology you use, if any. I aim to reply within five working days.

## Last reviewed

27 November 2026, for version 2.0.0.
