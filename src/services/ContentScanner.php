<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use johnhenry\accessibilityaudit\helpers\ExcludedElements;
use johnhenry\accessibilityaudit\models\IssueModel;
use yii\base\Component;

/**
 * Scans HTML using DOMDocument and returns IssueModel instances.
 * Covers WCAG 2.2 A and AA success criteria checkable server-side.
 */
class ContentScanner extends Component
{
    private const GENERIC_LINK_TEXTS = [
        'click here', 'click', 'here', 'read more', 'more', 'learn more',
        'this', 'link', 'details', 'info', 'information', 'go', 'continue',
        'next', 'previous', 'page', 'article', 'post', 'open', 'view',
    ];

    private const WCAG_HELP_BASE = 'https://www.w3.org/WAI/WCAG22/Understanding/';

    /** @return IssueModel[] */
    public function scan(string $html, array $ignoreRules = []): array
    {
        $issues = [];

        $dom = new DOMDocument('1.0', 'utf-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Excluded page furniture (consent banners, chat widgets) comes out
        // of the DOM before any check runs, mirroring the browser engines'
        // axe exclude context, so no engine reports what another skips.
        ExcludedElements::removeFrom($xpath);

        $checks = [
            'img-alt' => fn() => $this->checkImgAlt($dom, $xpath),
            'img-alt-filename' => fn() => $this->checkImgAltFilename($dom, $xpath),
            'heading-order' => fn() => $this->checkHeadingOrder($dom, $xpath),
            'empty-heading' => fn() => $this->checkEmptyHeadings($dom, $xpath),
            'multiple-h1' => fn() => $this->checkMultipleH1($dom, $xpath),
            'link-name' => fn() => $this->checkLinkName($dom, $xpath),
            'link-generic' => fn() => $this->checkLinkGeneric($dom, $xpath),
            'link-new-window' => fn() => $this->checkLinkNewWindow($dom, $xpath),
            'button-name' => fn() => $this->checkButtonName($dom, $xpath),
            'form-label' => fn() => $this->checkFormLabels($dom, $xpath),
            'table-header' => fn() => $this->checkTableHeaders($dom, $xpath),
            'html-lang' => fn() => $this->checkHtmlLang($dom, $xpath),
            'page-title' => fn() => $this->checkPageTitle($dom, $xpath),
            'skip-link' => fn() => $this->checkSkipLink($dom, $xpath),
            'landmark-main' => fn() => $this->checkLandmarkMain($dom, $xpath),
            'iframe-title' => fn() => $this->checkIframeTitle($dom, $xpath),
            'video-captions' => fn() => $this->checkVideoCaptions($dom, $xpath),
            'autoplay' => fn() => $this->checkAutoplay($dom, $xpath),
            'duplicate-id' => fn() => $this->checkDuplicateIds($dom, $xpath),
            'meta-description' => fn() => $this->checkMetaDescription($dom, $xpath),
            'aria-hidden-focus' => fn() => $this->checkAriaHiddenFocus($dom, $xpath),
            'landmark-regions' => fn() => $this->checkLandmarkRegions($dom, $xpath),
            'list-structure' => fn() => $this->checkListStructure($dom, $xpath),
            'input-type' => fn() => $this->checkInputTypes($dom, $xpath),
            'select-label' => fn() => $this->checkSelectLabels($dom, $xpath),
        ];

        foreach ($checks as $ruleId => $check) {
            if (!in_array($ruleId, $ignoreRules, true)) {
                $found = $check();
                if ($found) {
                    $issues = array_merge($issues, $found);
                }
            }
        }

        return $issues;
    }

    // ─── Images ──────────────────────────────────────────────────────────────

    /** WCAG 1.1.1 (A): images must have alt text */
    private function checkImgAlt(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        /** @var DOMElement $img */
        foreach ($xpath->query('//img') as $img) {
            if (!$img->hasAttribute('alt')) {
                $issues[] = IssueModel::make(
                    'img-alt', 'error',
                    'Image is missing an alt attribute.',
                    '1.1.1', 'A',
                    $this->outerHtml($img),
                    self::WCAG_HELP_BASE . 'non-text-content'
                );
            }
        }
        return $issues;
    }

    /** WCAG 1.1.1 (A): alt text should not be a filename */
    private function checkImgAltFilename(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//img[@alt]') as $img) {
            /** @var DOMElement $img */
            $alt = trim($img->getAttribute('alt'));
            if ($alt !== '' && preg_match('/\.(jpe?g|png|gif|webp|svg|bmp|tiff?)$/i', $alt)) {
                $issues[] = IssueModel::make(
                    'img-alt-filename', 'warning',
                    'Image alt text appears to be a filename: "' . htmlspecialchars($alt) . '".',
                    '1.1.1', 'A',
                    $this->outerHtml($img),
                    self::WCAG_HELP_BASE . 'non-text-content'
                );
            }
        }
        return $issues;
    }

    // ─── Headings ────────────────────────────────────────────────────────────

    /** WCAG 1.3.1, 2.4.6: heading levels must not skip */
    private function checkHeadingOrder(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        $headings = $xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]');
        $prev = 0;

        foreach ($headings as $heading) {
            /** @var DOMElement $heading */
            $level = (int) substr($heading->nodeName, 1);
            if ($prev > 0 && ($level - $prev) > 1) {
                $issues[] = IssueModel::make(
                    'heading-order', 'warning',
                    "Heading level skipped: h{$prev} followed by h{$level}.",
                    '1.3.1', 'A',
                    $this->outerHtml($heading),
                    self::WCAG_HELP_BASE . 'info-and-relationships'
                );
            }
            $prev = $level;
        }
        return $issues;
    }

    /** WCAG 1.3.1: headings must not be empty */
    private function checkEmptyHeadings(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $h) {
            /** @var DOMElement $h */
            if (trim($h->textContent) === '') {
                $issues[] = IssueModel::make(
                    'empty-heading', 'error',
                    "Empty <{$h->nodeName}> heading found.",
                    '1.3.1', 'A',
                    $this->outerHtml($h),
                    self::WCAG_HELP_BASE . 'info-and-relationships'
                );
            }
        }
        return $issues;
    }

    /** Best practice: only one h1 per page */
    private function checkMultipleH1(DOMDocument $dom, DOMXPath $xpath): array
    {
        $h1s = $xpath->query('//h1');
        if ($h1s->length > 1) {
            return [IssueModel::make(
                'multiple-h1', 'notice',
                "Page has {$h1s->length} <h1> elements. Best practice is one per page.",
                '2.4.6', 'AA',
                null,
                self::WCAG_HELP_BASE . 'headings-and-labels'
            )];
        }
        return [];
    }

    // ─── Links ───────────────────────────────────────────────────────────────

    /** WCAG 4.1.2, 2.4.4 (A): links must have a discernible name */
    private function checkLinkName(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            /** @var DOMElement $a */
            $text = trim($a->textContent);
            $ariaLabel = trim($a->getAttribute('aria-label'));
            $title = trim($a->getAttribute('title'));
            $ariaLabelledBy = $a->getAttribute('aria-labelledby');

            if ($text === '' && $ariaLabel === '' && $title === '' && $ariaLabelledBy === '') {
                // Check for image with alt
                $imgAlt = '';
                foreach ($xpath->query('.//img[@alt]', $a) as $img) {
                    /** @var DOMElement $img */
                    $imgAlt = trim($img->getAttribute('alt'));
                }
                if ($imgAlt === '') {
                    $issues[] = IssueModel::make(
                        'link-name', 'error',
                        'Link has no discernible name (no text, aria-label, title, or image alt).',
                        '4.1.2', 'A',
                        $this->outerHtml($a),
                        self::WCAG_HELP_BASE . 'name-role-value'
                    );
                }
            }
        }
        return $issues;
    }

    /** WCAG 2.4.4 (A): link text should describe destination */
    private function checkLinkGeneric(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            /** @var DOMElement $a */
            $text = strtolower(trim($a->textContent));
            if (in_array($text, self::GENERIC_LINK_TEXTS, true)) {
                $issues[] = IssueModel::make(
                    'link-generic', 'warning',
                    'Link text "' . htmlspecialchars($text) . '" does not describe its destination.',
                    '2.4.4', 'A',
                    $this->outerHtml($a),
                    self::WCAG_HELP_BASE . 'link-purpose-in-context'
                );
            }
        }
        return $issues;
    }

    /** Best practice: links opening in new tab should warn users */
    private function checkLinkNewWindow(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//a[@target="_blank"]') as $a) {
            /** @var DOMElement $a */
            $label = strtolower($a->getAttribute('aria-label') . ' ' . $a->getAttribute('title'));
            $hasWarning = str_contains($label, 'new') || str_contains($label, 'opens') || str_contains($label, 'window') || str_contains($label, 'tab');
            if (!$hasWarning) {
                $issues[] = IssueModel::make(
                    'link-new-window', 'warning',
                    'Link opens in a new tab but does not warn users via aria-label or title.',
                    '3.2.2', 'A',
                    $this->outerHtml($a),
                    self::WCAG_HELP_BASE . 'on-input'
                );
            }
        }
        return $issues;
    }

    // ─── Buttons ─────────────────────────────────────────────────────────────

    /** WCAG 4.1.2 (A): buttons must have a name */
    private function checkButtonName(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//button') as $btn) {
            /** @var DOMElement $btn */
            $text = trim($btn->textContent);
            $ariaLabel = trim($btn->getAttribute('aria-label'));
            $ariaLabelledBy = $btn->getAttribute('aria-labelledby');
            $title = trim($btn->getAttribute('title'));

            if ($text === '' && $ariaLabel === '' && $title === '' && $ariaLabelledBy === '') {
                $imgAlt = '';
                foreach ($xpath->query('.//img[@alt]', $btn) as $img) {
                    /** @var DOMElement $img */
                    $imgAlt = trim($img->getAttribute('alt'));
                }
                if ($imgAlt === '') {
                    $issues[] = IssueModel::make(
                        'button-name', 'error',
                        'Button has no discernible name.',
                        '4.1.2', 'A',
                        $this->outerHtml($btn),
                        self::WCAG_HELP_BASE . 'name-role-value'
                    );
                }
            }
        }
        return $issues;
    }

    // ─── Forms ───────────────────────────────────────────────────────────────

    /** WCAG 1.3.1, 3.3.2 (A): form controls must have labels */
    private function checkFormLabels(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        $skipTypes = ['hidden', 'submit', 'button', 'reset', 'image'];

        foreach ($xpath->query('//input[not(@type) or (@type!="hidden" and @type!="submit" and @type!="button" and @type!="reset" and @type!="image")]') as $input) {
            /** @var DOMElement $input */
            $type = strtolower($input->getAttribute('type') ?: 'text');
            if (in_array($type, $skipTypes, true)) {
                continue;
            }

            $id = $input->getAttribute('id');
            $ariaLabel = trim($input->getAttribute('aria-label'));
            $ariaLabelledBy = $input->getAttribute('aria-labelledby');
            $title = trim($input->getAttribute('title'));
            $placeholder = trim($input->getAttribute('placeholder'));

            $hasLabel = $ariaLabel !== '' || $ariaLabelledBy !== '' || $title !== '';

            if (!$hasLabel && $id !== '') {
                $labels = $xpath->query('//label[@for="' . addslashes($id) . '"]');
                if ($labels->length > 0) {
                    $hasLabel = true;
                }
            }

            if (!$hasLabel && $xpath->query('ancestor::label', $input)->length > 0) {
                $hasLabel = true;
            }

            if (!$hasLabel) {
                $msg = $placeholder !== ''
                    ? 'Form input uses placeholder as label, placeholder is not a substitute for a <label>.'
                    : 'Form input is missing an associated <label>.';
                $issues[] = IssueModel::make(
                    'form-label', $placeholder !== '' ? 'warning' : 'error',
                    $msg,
                    '1.3.1', 'A',
                    $this->outerHtml($input),
                    self::WCAG_HELP_BASE . 'info-and-relationships'
                );
            }
        }
        return $issues;
    }

    /** WCAG 1.3.1: select elements must have labels */
    private function checkSelectLabels(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//select') as $select) {
            /** @var DOMElement $select */
            $id = $select->getAttribute('id');
            $ariaLabel = trim($select->getAttribute('aria-label'));
            $ariaLabelledBy = $select->getAttribute('aria-labelledby');

            $hasLabel = $ariaLabel !== '' || $ariaLabelledBy !== '';

            if (!$hasLabel && $id !== '') {
                if ($xpath->query('//label[@for="' . addslashes($id) . '"]')->length > 0) {
                    $hasLabel = true;
                }
            }

            if (!$hasLabel) {
                $issues[] = IssueModel::make(
                    'select-label', 'error',
                    'Select element is missing an associated label.',
                    '1.3.1', 'A',
                    $this->outerHtml($select),
                    self::WCAG_HELP_BASE . 'info-and-relationships'
                );
            }
        }
        return $issues;
    }

    // ─── Tables ──────────────────────────────────────────────────────────────

    /** WCAG 1.3.1 (A): data tables need header cells */
    private function checkTableHeaders(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//table') as $table) {
            /** @var DOMElement $table */
            // Skip layout tables (role="presentation" or role="none")
            $role = strtolower($table->getAttribute('role'));
            if ($role === 'presentation' || $role === 'none') {
                continue;
            }
            $ths = $xpath->query('.//th', $table);
            if ($ths->length === 0) {
                $issues[] = IssueModel::make(
                    'table-header', 'error',
                    'Table has no header cells (<th>). Use <th> for header cells and add scope attribute.',
                    '1.3.1', 'A',
                    '<table>…</table>',
                    self::WCAG_HELP_BASE . 'info-and-relationships'
                );
            }
        }
        return $issues;
    }

    // ─── Document structure ───────────────────────────────────────────────────

    /** WCAG 3.1.1 (A): html element must have lang attribute */
    private function checkHtmlLang(DOMDocument $dom, DOMXPath $xpath): array
    {
        $html = $xpath->query('//html')->item(0);
        if ($html instanceof DOMElement) {
            $lang = trim($html->getAttribute('lang'));
            if ($lang === '') {
                return [IssueModel::make(
                    'html-lang', 'error',
                    'The <html> element is missing a lang attribute.',
                    '3.1.1', 'A',
                    '<html>',
                    self::WCAG_HELP_BASE . 'language-of-page'
                )];
            }
        }
        return [];
    }

    /** WCAG 2.4.2 (A): page must have a title */
    private function checkPageTitle(DOMDocument $dom, DOMXPath $xpath): array
    {
        $titles = $xpath->query('//title');
        if ($titles->length === 0 || trim($titles->item(0)->textContent) === '') {
            return [IssueModel::make(
                'page-title', 'error',
                'Page is missing a <title> element.',
                '2.4.2', 'A',
                null,
                self::WCAG_HELP_BASE . 'page-titled'
            )];
        }
        return [];
    }

    /** WCAG 2.4.1 (A): skip navigation link */
    private function checkSkipLink(DOMDocument $dom, DOMXPath $xpath): array
    {
        // Look for a skip link in the first 3 links on the page
        $links = $xpath->query('//a[@href]');
        $count = min($links->length, 5);
        for ($i = 0; $i < $count; $i++) {
            /** @var DOMElement $a */
            $a = $links->item($i);
            $href = $a->getAttribute('href');
            if (str_starts_with($href, '#')) {
                return [];
            }
        }
        // Also check for aria-label="skip" type links
        if ($xpath->query('//a[contains(translate(@href," ",""), "#main") or contains(translate(@aria-label,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), "skip")]')->length > 0) {
            return [];
        }
        return [IssueModel::make(
            'skip-link', 'warning',
            'No skip navigation link found. Add a "Skip to main content" link as the first focusable element.',
            '2.4.1', 'A',
            null,
            self::WCAG_HELP_BASE . 'bypass-blocks'
        )];
    }

    /** Best practice: page should have a <main> landmark */
    private function checkLandmarkMain(DOMDocument $dom, DOMXPath $xpath): array
    {
        $main = $xpath->query('//main | //*[@role="main"]');
        if ($main->length === 0) {
            return [IssueModel::make(
                'landmark-main', 'warning',
                'Page has no <main> landmark element. Add <main> to wrap primary content.',
                '1.3.6', 'AAA',
                null,
                self::WCAG_HELP_BASE . 'identify-purpose'
            )];
        }
        return [];
    }

    /** WCAG 1.3.1: page should use landmark regions */
    private function checkLandmarkRegions(DOMDocument $dom, DOMXPath $xpath): array
    {
        $landmarks = $xpath->query(
            '//header | //nav | //main | //footer | //aside | //section[@aria-label or @aria-labelledby] ' .
            '| //*[@role="banner"] | //*[@role="navigation"] | //*[@role="main"] | //*[@role="contentinfo"]'
        );
        if ($landmarks->length === 0) {
            return [IssueModel::make(
                'landmark-regions', 'notice',
                'Page does not use any ARIA landmark regions (header, nav, main, footer).',
                '1.3.1', 'A',
                null,
                self::WCAG_HELP_BASE . 'info-and-relationships'
            )];
        }
        return [];
    }

    // ─── Iframes & Media ─────────────────────────────────────────────────────

    /** WCAG 4.1.2 (A): iframes must have title */
    private function checkIframeTitle(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//iframe') as $iframe) {
            /** @var DOMElement $iframe */
            $title = trim($iframe->getAttribute('title'));
            if ($title === '') {
                $issues[] = IssueModel::make(
                    'iframe-title', 'error',
                    'iframe is missing a title attribute.',
                    '4.1.2', 'A',
                    $this->outerHtml($iframe),
                    self::WCAG_HELP_BASE . 'name-role-value'
                );
            }
        }
        return $issues;
    }

    /** WCAG 1.2.2 (A): video must have captions */
    private function checkVideoCaptions(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//video') as $video) {
            /** @var DOMElement $video */
            $tracks = $xpath->query('.//track[@kind="captions" or @kind="subtitles"]', $video);
            if ($tracks->length === 0) {
                $issues[] = IssueModel::make(
                    'video-captions', 'error',
                    'Video element has no captions track (<track kind="captions">).',
                    '1.2.2', 'A',
                    '<video>…</video>',
                    self::WCAG_HELP_BASE . 'captions-prerecorded'
                );
            }
        }
        return $issues;
    }

    /** WCAG 1.4.2 (A): auto-playing media must have controls */
    private function checkAutoplay(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($xpath->query('//video[@autoplay] | //audio[@autoplay]') as $el) {
            /** @var DOMElement $el */
            $muted = $el->hasAttribute('muted');
            if (!$muted) {
                $issues[] = IssueModel::make(
                    'autoplay', 'error',
                    ucfirst($el->nodeName) . ' has autoplay without muted attribute, users cannot control audio.',
                    '1.4.2', 'A',
                    '<' . $el->nodeName . '>',
                    self::WCAG_HELP_BASE . 'audio-control'
                );
            }
        }
        return $issues;
    }

    // ─── ARIA & IDs ──────────────────────────────────────────────────────────

    /** WCAG 4.1.1 (A): duplicate IDs */
    private function checkDuplicateIds(DOMDocument $dom, DOMXPath $xpath): array
    {
        $ids = [];
        $duplicates = [];
        foreach ($xpath->query('//*[@id]') as $el) {
            /** @var DOMElement $el */
            $id = $el->getAttribute('id');
            // An empty id="" can't be referenced by anything (labels,
            // aria-labelledby, fragment links all need a value), so two of
            // them collide with nothing and are no duplicate. Sloppy markup,
            // but not an accessibility failure.
            if (trim($id) === '') {
                continue;
            }
            if (isset($ids[$id])) {
                $duplicates[$id] = true;
            } else {
                $ids[$id] = true;
            }
        }

        $issues = [];
        foreach (array_keys($duplicates) as $id) {
            $issues[] = IssueModel::make(
                'duplicate-id', 'error',
                "Duplicate id=\"{$id}\" found. IDs must be unique on the page.",
                '4.1.1', 'A',
                "id=\"{$id}\"",
                self::WCAG_HELP_BASE . 'parsing'
            );
        }
        return $issues;
    }

    /** WCAG 4.1.2 (A): focusable elements inside aria-hidden */
    private function checkAriaHiddenFocus(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        $focusable = 'a[@href] | button | input | select | textarea | *[@tabindex]';
        foreach ($xpath->query('//*[@aria-hidden="true"]') as $hidden) {
            /** @var DOMElement $hidden */
            $focusableChildren = $xpath->query('.//' . $focusable, $hidden);
            if ($focusableChildren->length > 0) {
                $issues[] = IssueModel::make(
                    'aria-hidden-focus', 'error',
                    'Focusable element found inside aria-hidden="true". Hidden content must not receive focus.',
                    '4.1.2', 'A',
                    $this->outerHtml($hidden),
                    self::WCAG_HELP_BASE . 'name-role-value'
                );
            }
        }
        return $issues;
    }

    // ─── Meta ────────────────────────────────────────────────────────────────

    /** Best practice: meta description */
    private function checkMetaDescription(DOMDocument $dom, DOMXPath $xpath): array
    {
        $meta = $xpath->query('//meta[@name="description"]');
        $metaNode = $meta->length > 0 ? $meta->item(0) : null;
        if (!$metaNode instanceof DOMElement || trim($metaNode->getAttribute('content')) === '') {
            return [IssueModel::make(
                'meta-description', 'notice',
                'Page is missing a meta description.',
                null, null,
                null,
                'https://developers.google.com/search/docs/appearance/snippet'
            )];
        }
        return [];
    }

    // ─── Lists ───────────────────────────────────────────────────────────────

    /** WCAG 1.3.1: list items must be in a list */
    private function checkListStructure(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        // li that is not inside ul, ol, or menu
        foreach ($xpath->query('//li[not(parent::ul) and not(parent::ol) and not(parent::menu)]') as $li) {
            $issues[] = IssueModel::make(
                'list-structure', 'error',
                '<li> element is not inside a <ul> or <ol>.',
                '1.3.1', 'A',
                $li instanceof DOMElement ? $this->outerHtml($li) : null,
                self::WCAG_HELP_BASE . 'info-and-relationships'
            );
        }
        return $issues;
    }

    // ─── Input types ─────────────────────────────────────────────────────────

    /** WCAG 1.3.5 (AA): input purpose should be identified */
    private function checkInputTypes(DOMDocument $dom, DOMXPath $xpath): array
    {
        $issues = [];
        $purposeMap = [
            'name' => ['name', 'full-name', 'fullname'],
            'email' => ['email', 'e-mail'],
            'tel' => ['phone', 'telephone', 'tel', 'mobile'],
        ];

        foreach ($xpath->query('//input[@type="text" or not(@type)]') as $input) {
            /** @var DOMElement $input */
            $autocomplete = strtolower(trim($input->getAttribute('autocomplete')));
            $name = strtolower(trim($input->getAttribute('name')));
            $id = strtolower(trim($input->getAttribute('id')));

            if ($autocomplete !== '' && $autocomplete !== 'off') {
                continue;
            }

            // Check if this looks like a personal data field but has no autocomplete
            foreach ($purposeMap as $type => $hints) {
                foreach ($hints as $hint) {
                    if (str_contains($name, $hint) || str_contains($id, $hint)) {
                        $issues[] = IssueModel::make(
                            'input-type', 'warning',
                            "Input field \"{$input->getAttribute('name')}\" looks like a {$type} field but has no autocomplete attribute.",
                            '1.3.5', 'AA',
                            $this->outerHtml($input),
                            self::WCAG_HELP_BASE . 'identify-input-purpose'
                        );
                        break 2;
                    }
                }
            }
        }
        return $issues;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function outerHtml(DOMElement $el): string
    {
        $tag = '<' . $el->nodeName;
        for ($i = 0; $i < $el->attributes->length; $i++) {
            $attr = $el->attributes->item($i);
            if (!$attr instanceof \DOMAttr) {
                continue;
            }
            $tag .= ' ' . $attr->name . '="' . htmlspecialchars($attr->value) . '"';
        }
        $tag .= '>';

        // A short text preview rides along on every context: it names the
        // element for a human (dozens of orphaned `<li>`s once rendered as
        // identical bare tags) and gives the preview's context matcher a
        // text signal. The matcher treats a trailing ellipsis as a prefix.
        $text = trim((string)preg_replace('/\s+/u', ' ', $el->textContent));
        if ($text !== '') {
            if (mb_strlen($text) > 60) {
                $text = mb_substr($text, 0, 60) . '…';
            }
            $tag .= htmlspecialchars($text) . '</' . $el->nodeName . '>';
        }

        return mb_substr($tag, 0, 200);
    }
}
