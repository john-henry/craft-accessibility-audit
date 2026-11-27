# Third-Party Notices: Accessibility Audit for Craft CMS

This plugin incorporates or references the following third-party software and trademarks.

---

## axe-core

Copyright © Deque Systems, Inc.

Licensed under the **Mozilla Public License 2.0 (MPL-2.0)**.
Full license text: https://www.mozilla.org/en-US/MPL/2.0/

axe-core is bundled unmodified (resources/axe/axe.min.js) and served from the
plugin's own assets for every scanning surface: the frontend overlay, the
server-side headless scanner, and the control-panel Inspect preview. No
modifications have been made to axe-core source files.

Source: https://github.com/dequelabs/axe-core

axe® and axe-core are trademarks of Deque Systems, Inc. MPL-2.0 grants no
trademark rights (section 2.3), so the name is used here and in the documentation
only to identify the bundled library. This plugin is not affiliated with,
endorsed by, or produced by Deque Systems.

---

## chrome-php/chrome

Copyright © CHROME PHP contributors.

Licensed under the **MIT License**.
Source: https://github.com/chrome-php/chrome

Installed as a Composer dependency and used unmodified to drive headless
Chrome for server-side browser scanning.

---

## tc-lib-pdf-parser

Copyright © Nicola Asuni, Tecnick.com LTD.

Licensed under the **GNU Lesser General Public License v3.0 or later (LGPL-3.0-or-later)**.
Full license text: https://www.gnu.org/licenses/lgpl-3.0.html

Installed as a Composer dependency, along with tecnickcom/tc-lib-pdf-filter
(same licence, also required by the plugin directly), and used unmodified to
read PDF assets for the PDF checks. None of its source is copied into this
plugin; the plugin extends its parser class from its own code, so the library
can be replaced with a modified version of your own through Composer.

Source: https://github.com/tecnickcom/tc-lib-pdf-parser

---

## dompdf

Copyright © The Dompdf Team.

Licensed under the **GNU Lesser General Public License v2.1 (LGPL-2.1)**.
Full license text: https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html

Installed as a Composer dependency, along with dompdf/php-font-lib
(LGPL-2.1-or-later) and dompdf/php-svg-lib (LGPL-3.0-or-later), and used unmodified to turn the scheduled
directors summary into the PDF attached to its email. None of its source is
copied into this plugin, so the library can be replaced with a modified
version of your own through Composer.

Source: https://github.com/dompdf/dompdf

---

## pdf.js

Copyright © Mozilla Foundation and contributors.

Licensed under the **Apache License 2.0**.
Full license text: https://www.apache.org/licenses/LICENSE-2.0

pdf.js 6.5.438 is bundled unmodified (resources/pdfjs/pdf.min.mjs and
resources/pdfjs/pdf.worker.min.mjs, with its LICENSE file alongside) and run
in headless Chrome for the structure part of the PDF checks. The standard
fonts, CMaps, ICC profiles and WebAssembly decoders that ship with pdf.js are
not included.

Source: https://github.com/mozilla/pdf.js

---

## VPAT®

VPAT® is a registered trademark of the **Information Technology Industry Council (ITI)**.
https://www.itic.org/policy/accessibility/vpat

This plugin generates reports in VPAT format based on the publicly available
VPAT® 2.5Rev template. It is not affiliated with, endorsed by, or produced by ITI.

---

## WCAG (Web Content Accessibility Guidelines)

Copyright © World Wide Web Consortium (W3C).
https://www.w3.org/TR/WCAG22/

WCAG is a W3C Recommendation. Rule descriptions and success criterion numbers
are referenced for informational purposes in accordance with W3C document use policies.

---

## Flesch-Kincaid Readability Formulas

Developed by Rudolf Flesch and J. Peter Kincaid for the United States Navy.
Published as a US Government work and in the public domain.

---

## Anthropic Claude API

This plugin optionally calls the Anthropic Claude API for AI-generated alt text
and readability suggestions. Use of this feature is subject to the
[Anthropic Usage Policy](https://www.anthropic.com/legal/usage-policy) and requires
your own Anthropic API key. No content is processed by Anthropic unless you
configure an API key and explicitly trigger an AI feature.
