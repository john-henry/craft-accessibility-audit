# Accessibility statement for Accessibility Audit

Accessibility Audit is a plugin for Craft CMS 5. This statement sets out how accessible it is, what I know is wrong with it, and how to tell me about anything else.

## What this covers

This statement covers Accessibility Audit 1.5.0:

- the control panel screens: the dashboard, issues, issue details, page reports, assets and alt text, readability, the accessibility statement builder and the VPAT/ACR builder
- the settings pages and the Accessibility Score dashboard widget
- the accessibility panel in the entry sidebar
- the scan overlay shown on the front end of your site to people running audits
- the public accessibility statement template and the VPAT/ACR export

It doesn't cover:

- Craft's own control panel, which Craft reports on at https://craftcms.com/accessibility
- your site's own templates and content
- axe-core, the third-party scanning engine the plugin bundles

## The standard I'm working to

WCAG 2.2 level AA. Accessibility Audit adds to the Craft control panel, which is an authoring tool, so I also follow ATAG 2.0 Part A where it applies to the parts Accessibility Audit adds. Craft itself works to the same standards and publishes its own reports at https://craftcms.com/accessibility, but those reports don't cover plugins.

## How far it meets it

I believe it meets WCAG 2.2 AA. The review found three issues, and this version fixes all of them. It hasn't been tested with assistive technology yet, though, so I'm not claiming full conformance until it has.

## How it was checked

Last checked in September 2026:

- A review of every control panel template, script and stylesheet against WCAG 2.2 AA, including the WAI-ARIA Authoring Practices for any custom widgets, and contrast ratios worked out from the actual colours in the CSS.
- The main control panel scripts were read in full. `cp.js`, `utilities.js`, `vpat.js`, `statement.js` and some settings screens were only sampled.
- Not done yet: testing with screen readers (NVDA, JAWS and VoiceOver), zoom and reflow at 400%, or speech input. This statement will be updated once that's done.

## Known issues

None known right now. If you come across one, please tell me (see below).

## For site developers

The public accessibility statement template works out its own heading levels from where you place it, so it fits the heading structure of your page. The scan overlay only appears for people running an audit, not for your visitors.

## Tell me about a problem

If something in Accessibility Audit is hard or impossible for you to use, open an issue at https://github.com/john-henry/craft-accessibility-audit/issues and put "Accessibility" in the title. Say which screen you were on, what you were trying to do, and which browser and assistive technology you use, if any. I aim to reply within five working days.

## Last reviewed

27 September 2026, for version 1.5.0.
