<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use johnhenry\accessibilityaudit\models\PdfCheckResult;
use johnhenry\accessibilityaudit\pdf\HybridParser;
use johnhenry\accessibilityaudit\pdf\PdfDocument;
use johnhenry\accessibilityaudit\pdf\StandardSecurity;
use Throwable;
use yii\base\Component;

/**
 * Reads a PDF for the accessibility failures a machine can settle.
 *
 * Two layers. This one reads the catalogue, the page tree and the structure
 * tree in PHP, with stream decoding off, so it runs on every host from the
 * queue and its memory follows the object count rather than what the pages
 * hold. The browser layer, run by HeadlessScanner where Chrome is reachable,
 * reads the structure and text through pdf.js and is folded in by
 * {@see self::applyBrowserLayer()}.
 *
 * Neither is a PDF/UA validator. A pass means something is present, not that
 * it is any good: alt text that exists can still say nothing.
 *
 * Nothing here touches the database.
 *
 * @phpstan-type Rule array{severity: string, wcag: string|null, level: string|null, structure: bool, pageLevel: bool, message: string, helpUrl: string|null}
 * @phpstan-type Finding array<string, mixed>
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class PdfChecker extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The file was read and its findings stand.
     */
    public const STATUS_CHECKED = 'checked';

    /**
     * @var string The file could not be read; the reason says why.
     */
    public const STATUS_NOT_CHECKED = 'not-checked';

    /**
     * @var string Larger than the configured cap.
     */
    public const REASON_TOO_LARGE = 'too-large';

    /**
     * @var string Declares more objects than MAX_OBJECTS.
     */
    public const REASON_TOO_MANY_OBJECTS = 'too-many-objects';

    /**
     * @var string Opens only with a user password.
     */
    public const REASON_PASSWORD = 'password-required';

    /**
     * @var string The parser could not make sense of it.
     */
    public const REASON_DAMAGED = 'damaged';

    /**
     * @var string The file could not be copied off its volume.
     */
    public const REASON_FETCH_FAILED = 'fetch-failed';

    /**
     * @var string The browser pass ran out of time on a file PHP could not read.
     */
    public const REASON_TIMED_OUT = 'timed-out';

    /**
     * @var string No %PDF- header: a file named .pdf that is something else.
     */
    public const REASON_NOT_PDF = 'not-pdf';

    /**
     * @var string An XFA form, which only some viewers can show at all.
     */
    public const REASON_XFA = 'xfa';

    /**
     * @var string[] Every reason, in the order the screen lists them.
     */
    public const REASONS = [
        self::REASON_PASSWORD,
        self::REASON_XFA,
        self::REASON_DAMAGED,
        self::REASON_NOT_PDF,
        self::REASON_TOO_LARGE,
        self::REASON_TOO_MANY_OBJECTS,
        self::REASON_TIMED_OUT,
        self::REASON_FETCH_FAILED,
    ];

    /**
     * @var int Bumped whenever a rule is added or changes what it fails, so a
     * stored result checked under older rules reads as a rule change and is
     * checked again, rather than passing for a regression.
     */
    public const RULE_SET_VERSION = 1;

    /**
     * @var int The default size cap, 20 MB: 98.9% of real-web PDFs, with a
     * worst-case parse near 190 MB of memory.
     */
    public const DEFAULT_MAX_BYTES = 20971520;

    /**
     * @var int The smallest cap the setting accepts, 1 MB.
     */
    public const MIN_MAX_BYTES = 1048576;

    /**
     * @var int The largest cap the setting accepts, 50 MB. Past it the parse
     * can reach 400 MB and the browser handover takes 15 seconds.
     */
    public const MAX_MAX_BYTES = 52428800;

    /**
     * @var int The most objects a file may declare. Parse memory follows the
     * object count (about 4.7 KB each), so this bounds it near 230 MB.
     */
    public const MAX_OBJECTS = 50000;

    /**
     * @var int Pages read for fonts here and walked for text and structure in
     * the browser.
     */
    public const PAGE_SAMPLE = 20;

    /**
     * @var int A document longer than this many pages should have bookmarks.
     */
    public const BOOKMARK_PAGES = 20;

    /**
     * @var int A tagged document this long with no headings is reported.
     */
    public const NO_HEADINGS_PAGES = 5;

    /**
     * @var int Page numbers kept per finding.
     */
    public const MAX_LISTED_PAGES = 20;

    /**
     * @var int Pages walked in the page tree.
     */
    private const MAX_PAGES = 20000;

    /**
     * @var int Structure elements and form fields visited.
     */
    private const MAX_NODES = 200000;

    /**
     * @var int Page tree and RoleMap depth followed.
     */
    private const MAX_DEPTH = 64;

    /**
     * @var string Where WCAG's Understanding documents live.
     */
    private const UNDERSTANDING = 'https://www.w3.org/WAI/WCAG22/Understanding/';

    /**
     * @var array<string, Rule> Every rule, in the order the screen lists them.
     * `severity` is error, warning, notice or review; `structure` rules only
     * mean something on a tagged file and are dropped from an untagged one;
     * `pageLevel` rules are read from sampled pages, so a browser pass that
     * stopped short cannot clear what the PHP pass found further in.
     */
    public const RULES = [
        'pdf-field-no-tooltip' => [
            'severity' => 'error', 'wcag' => '4.1.2', 'level' => 'A', 'structure' => false, 'pageLevel' => false,
            'message' => 'Form fields have no tooltip, so a screen reader cannot say what they are for.',
            'helpUrl' => self::UNDERSTANDING . 'name-role-value',
        ],
        'pdf-untagged' => [
            'severity' => 'error', 'wcag' => '1.3.1', 'level' => 'A', 'structure' => false, 'pageLevel' => false,
            'message' => 'The PDF is not tagged, so assistive technology cannot read its structure.',
            'helpUrl' => self::UNDERSTANDING . 'info-and-relationships',
        ],
        'pdf-no-text' => [
            'severity' => 'review', 'wcag' => '1.1.1', 'level' => 'A', 'structure' => false, 'pageLevel' => true,
            'message' => 'Pages carry no readable text: likely a scan, or text turned into outlines.',
            'helpUrl' => self::UNDERSTANDING . 'non-text-content',
        ],
        'pdf-at-blocked' => [
            'severity' => 'error', 'wcag' => '4.1.2', 'level' => 'A', 'structure' => false, 'pageLevel' => false,
            'message' => 'The security settings stop assistive technology reading the content.',
            'helpUrl' => self::UNDERSTANDING . 'name-role-value',
        ],
        'pdf-no-title' => [
            'severity' => 'error', 'wcag' => '2.4.2', 'level' => 'A', 'structure' => false, 'pageLevel' => false,
            'message' => 'The document has no title.',
            'helpUrl' => self::UNDERSTANDING . 'page-titled',
        ],
        'pdf-weak-title' => [
            'severity' => 'warning', 'wcag' => '2.4.2', 'level' => 'A', 'structure' => false, 'pageLevel' => false,
            'message' => 'The title is a filename, a program name or a placeholder.',
            'helpUrl' => self::UNDERSTANDING . 'page-titled',
        ],
        'pdf-title-not-shown' => [
            'severity' => 'warning', 'wcag' => '2.4.2', 'level' => 'A', 'structure' => false, 'pageLevel' => false,
            'message' => 'Viewers are not told to show the title, so they show the filename.',
            'helpUrl' => self::UNDERSTANDING . 'page-titled',
        ],
        'pdf-no-lang' => [
            'severity' => 'error', 'wcag' => '3.1.1', 'level' => 'A', 'structure' => false, 'pageLevel' => false,
            'message' => 'The document language is not set.',
            'helpUrl' => self::UNDERSTANDING . 'language-of-page',
        ],
        'pdf-figure-no-alt' => [
            'severity' => 'error', 'wcag' => '1.1.1', 'level' => 'A', 'structure' => true, 'pageLevel' => true,
            'message' => 'Tagged figures have no alt text.',
            'helpUrl' => self::UNDERSTANDING . 'non-text-content',
        ],
        'pdf-figure-junk-alt' => [
            'severity' => 'error', 'wcag' => '1.1.1', 'level' => 'A', 'structure' => true, 'pageLevel' => true,
            'message' => 'Figure alt text is a filename or a generic word.',
            'helpUrl' => self::UNDERSTANDING . 'non-text-content',
        ],
        'pdf-suspects' => [
            'severity' => 'warning', 'wcag' => '1.3.1', 'level' => 'A', 'structure' => false, 'pageLevel' => false,
            'message' => 'The tags were added automatically and are marked as not reviewed.',
            'helpUrl' => self::UNDERSTANDING . 'info-and-relationships',
        ],
        'pdf-heading-order' => [
            'severity' => 'warning', 'wcag' => '1.3.1', 'level' => 'A', 'structure' => true, 'pageLevel' => true,
            'message' => 'The first heading is not level 1, or heading levels are skipped.',
            'helpUrl' => self::UNDERSTANDING . 'info-and-relationships',
        ],
        'pdf-no-headings' => [
            'severity' => 'warning', 'wcag' => '1.3.1', 'level' => 'A', 'structure' => true, 'pageLevel' => true,
            'message' => 'A long tagged document has no headings.',
            'helpUrl' => self::UNDERSTANDING . 'info-and-relationships',
        ],
        'pdf-table-no-th' => [
            'severity' => 'error', 'wcag' => '1.3.1', 'level' => 'A', 'structure' => true, 'pageLevel' => true,
            'message' => 'Tables have no header cells.',
            'helpUrl' => self::UNDERSTANDING . 'info-and-relationships',
        ],
        'pdf-th-no-scope' => [
            'severity' => 'error', 'wcag' => '1.3.1', 'level' => 'A', 'structure' => true, 'pageLevel' => true,
            'message' => 'Table header cells have neither Scope nor Headers.',
            'helpUrl' => self::UNDERSTANDING . 'info-and-relationships',
        ],
        'pdf-list-structure' => [
            'severity' => 'warning', 'wcag' => '1.3.1', 'level' => 'A', 'structure' => true, 'pageLevel' => true,
            'message' => 'List items sit outside a list, or have no list body.',
            'helpUrl' => self::UNDERSTANDING . 'info-and-relationships',
        ],
        'pdf-link-untagged' => [
            'severity' => 'warning', 'wcag' => '2.4.4', 'level' => 'A', 'structure' => true, 'pageLevel' => true,
            'message' => 'Link annotations are not inside a Link element.',
            'helpUrl' => self::UNDERSTANDING . 'link-purpose-in-context',
        ],
        'pdf-tab-order' => [
            'severity' => 'warning', 'wcag' => '2.4.3', 'level' => 'A', 'structure' => true, 'pageLevel' => false,
            'message' => 'Pages with annotations do not set the tab order to follow the structure.',
            'helpUrl' => self::UNDERSTANDING . 'focus-order',
        ],
        'pdf-no-bookmarks' => [
            'severity' => 'notice', 'wcag' => '2.4.5', 'level' => 'AA', 'structure' => false, 'pageLevel' => false,
            'message' => 'A long document has no bookmarks.',
            'helpUrl' => self::UNDERSTANDING . 'multiple-ways',
        ],
    ];

    /**
     * @var string[] Rules the browser pass can stand in for only when the PHP
     * pass could not read the file at all. Otherwise PHP's answer stands: it
     * reads the whole file, and pdf.js exposes these less directly.
     */
    private const BROWSER_FALLBACK_RULES = ['pdf-untagged', 'pdf-suspects', 'pdf-no-bookmarks'];

    /**
     * @var string[] Words that, alone and with an optional number, describe
     * nothing about a figure.
     */
    private const GENERIC_ALT = [
        'image', 'picture', 'photo', 'photograph', 'graphic', 'figure', 'img', 'logo', 'icon', 'chart',
        'graph', 'diagram', 'untitled', 'alt', 'alt text', 'placeholder', 'grafik', 'bild', 'imagen',
    ];

    /**
     * @var string[] Titles that are placeholders rather than titles.
     */
    private const PLACEHOLDER_TITLES = [
        'untitled', 'untitled document', 'document', 'title', 'presentation', 'powerpoint presentation',
        'slide 1', 'new document', 'pdf', 'word document', 'blank', 'no title', 'unknown',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Checks the PDF at a path.
     *
     * @param string $path The file to read.
     * @param string $filename The asset's filename, for the weak-title rule.
     * @param int|null $maxBytes The size cap, clamped; the default where null.
     * @return PdfCheckResult
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function check(string $path, string $filename = '', ?int $maxBytes = null): PdfCheckResult
    {
        if (!is_file($path) || !is_readable($path)) {
            return PdfCheckResult::notChecked(self::REASON_FETCH_FAILED);
        }

        $size = (int)filesize($path);

        if ($size > self::clampMaxBytes($maxBytes ?? self::DEFAULT_MAX_BYTES)) {
            return PdfCheckResult::notChecked(self::REASON_TOO_LARGE, ['bytes' => $size]);
        }

        $data = file_get_contents($path);

        if ($data === false) {
            return PdfCheckResult::notChecked(self::REASON_FETCH_FAILED);
        }

        return $this->checkData($data, $filename);
    }

    /**
     * Checks PDF bytes already in memory.
     *
     * @param string $data The file's bytes.
     * @param string $filename The asset's filename, for the weak-title rule.
     * @return PdfCheckResult
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function checkData(string $data, string $filename = ''): PdfCheckResult
    {
        if (!str_contains(substr($data, 0, 1024), '%PDF-')) {
            return PdfCheckResult::notChecked(self::REASON_NOT_PDF);
        }

        $declared = self::declaredObjectCount($data);

        if ($declared > self::MAX_OBJECTS) {
            return PdfCheckResult::notChecked(self::REASON_TOO_MANY_OBJECTS, ['objects' => $declared]);
        }

        try {
            [$xref, $objects] = (new HybridParser([
                'ignore_filter_errors' => true,
                'decode_streams' => false,
            ]))->parse($data);
        } catch (Throwable $e) {
            Craft::info("PdfChecker: could not parse {$filename}: " . $e->getMessage(), 'accessibility-audit');

            return PdfCheckResult::notChecked(self::REASON_DAMAGED);
        }

        unset($data);

        if (count($objects) > self::MAX_OBJECTS) {
            return PdfCheckResult::notChecked(self::REASON_TOO_MANY_OBJECTS, ['objects' => count($objects)]);
        }

        return $this->_inspect(new PdfDocument($xref['trailer'], $objects), $filename);
    }

    /**
     * Folds the browser pass into the PHP result.
     *
     * Where both layers answer a rule, the browser's answer wins: pdf.js reads
     * text and figures more accurately. A page-level rule the browser cleared
     * after sampling only part of the document keeps what PHP found, since
     * PHP read all of it. A file PHP could not parse but pdf.js opened (pdf.js
     * repairs broken cross-reference tables) is checked on the browser's
     * findings alone.
     *
     * @param PdfCheckResult $result The PHP pass.
     * @param array<string, mixed>|null $browser What the pdf.js pass returned,
     *        or null where it did not run.
     * @param string $filename The asset's filename, for the weak-title rule.
     * @return PdfCheckResult
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function applyBrowserLayer(PdfCheckResult $result, ?array $browser, string $filename = ''): PdfCheckResult
    {
        if ($browser === null) {
            return $result;
        }

        $phpRead = $result->isChecked();

        if (!$phpRead && $result->reason !== self::REASON_DAMAGED) {
            return $result;
        }

        $meta = $result->meta;

        if (($browser['ok'] ?? false) !== true) {
            $error = (string)($browser['error'] ?? 'failed');
            $meta['browser'] = $error;

            if ($error === 'password') {
                return PdfCheckResult::notChecked(self::REASON_PASSWORD, $meta);
            }

            if (!$phpRead && $error === 'timeout') {
                return PdfCheckResult::notChecked(self::REASON_TIMED_OUT, $meta);
            }

            $result->meta = $meta;

            return $result;
        }

        if (!$phpRead && ($browser['xfa'] ?? false) === true) {
            return PdfCheckResult::notChecked(self::REASON_XFA, $meta);
        }

        $tagged = $phpRead
            ? (bool)($meta['tagged'] ?? false)
            : (($browser['marked'] ?? false) === true && (int)($browser['structPages'] ?? 0) > 0);

        [$evaluated, $found] = $this->_browserFindings($browser, $filename, $tagged, !$phpRead);
        $complete = ($browser['complete'] ?? false) === true;
        $findings = $phpRead ? $result->findings : [];

        foreach ($evaluated as $ruleId) {
            if (isset($found[$ruleId])) {
                $findings[$ruleId] = $found[$ruleId];
                continue;
            }

            if (!$complete && self::RULES[$ruleId]['pageLevel']) {
                continue;
            }

            unset($findings[$ruleId]);
        }

        foreach (['title', 'lang', 'producer', 'creator'] as $key) {
            $value = trim((string)($browser[$key] ?? ''));

            if (($meta[$key] ?? null) === null && $value !== '') {
                $meta[$key] = mb_substr($value, 0, 255);
            }
        }

        $meta['title'] ??= self::_nonEmpty(mb_substr(trim((string)($browser['xmpTitle'] ?? '')), 0, 255));
        $meta['pages'] = (int)($meta['pages'] ?? 0) ?: (int)($browser['pages'] ?? 0);
        $meta['pdfua'] ??= is_bool($browser['pdfua'] ?? null) ? $browser['pdfua'] : null;
        $meta['encrypted'] = (bool)($meta['encrypted'] ?? false) || ($browser['encrypted'] ?? false) === true;
        $meta['tagged'] = $tagged;
        $meta['layers'] = array_values(array_unique([...(array)($meta['layers'] ?? []), 'pdfjs']));
        unset($meta['browser']);

        return new PdfCheckResult([
            'status' => self::STATUS_CHECKED,
            'findings' => self::_withoutStructureWhenUntagged(self::_ordered($findings)),
            'meta' => $meta,
            'hasForm' => $result->hasForm || ($browser['hasForm'] ?? false) === true,
        ]);
    }

    /**
     * The object count a file declares in its newest trailer, read from the
     * raw bytes before anything is parsed.
     *
     * Read from two places, because the newest trailer is either a classic one
     * near the end of the file or the dictionary of the cross-reference stream
     * that `startxref` points at, which can sit well before the end.
     *
     * @param string $data The file's bytes.
     * @return int The largest /Size found, 0 where there is none.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function declaredObjectCount(string $data): int
    {
        $regions = [substr($data, -8192)];

        if (preg_match_all('/startxref\s+(\d+)/', $regions[0], $m) > 0) {
            $offset = (int)end($m[1]);

            if ($offset > 0 && $offset < strlen($data)) {
                $regions[] = substr($data, $offset, 2048);
            }
        }

        $size = 0;

        foreach ($regions as $region) {
            if (preg_match_all('#/Size\s+(\d+)#', $region, $m) > 0) {
                $size = max($size, ...array_map('intval', $m[1]));
            }
        }

        return $size;
    }

    /**
     * The size cap, held between MIN_MAX_BYTES and MAX_MAX_BYTES.
     *
     * Applied when the check runs as well as when the setting is saved,
     * because a config file override never passes through validation.
     *
     * @param int $bytes The configured cap.
     * @return int
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function clampMaxBytes(int $bytes): int
    {
        return min(max($bytes, self::MIN_MAX_BYTES), self::MAX_MAX_BYTES);
    }

    /**
     * Whether a title is a stand-in rather than a title: the filename, the
     * name of the program that made it, or a placeholder.
     *
     * @param string $title The title.
     * @param string $filename The file's name.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function isWeakTitle(string $title, string $filename = ''): bool
    {
        $title = mb_strtolower(trim($title));

        if ($title === '') {
            return false;
        }

        if ($filename !== '') {
            $normalise = static fn(string $s): string => trim((string)preg_replace('/[\s_\-.]+/u', ' ', mb_strtolower($s)));
            $base = pathinfo($filename, PATHINFO_FILENAME);

            if (in_array($normalise($title), [$normalise($filename), $normalise($base)], true)) {
                return true;
            }
        }

        if (preg_match('/^microsoft (word|powerpoint|excel|publisher)\b/u', $title) === 1) {
            return true;
        }

        if (preg_match('/\.(docx?|pptx?|xlsx?|pdf|rtf|odt|odp|ods|pages|key|indd|pub|txt)$/u', $title) === 1) {
            return true;
        }

        return in_array((string)preg_replace('/\s*\d+$/u', '', $title), self::PLACEHOLDER_TITLES, true);
    }

    /**
     * Whether alt text says nothing: a filename, an identifier with no
     * spaces, or a generic word such as "image".
     *
     * @param string $alt The alt text.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function isJunkAlt(string $alt): bool
    {
        $alt = mb_strtolower(trim($alt));

        if ($alt === '') {
            return false;
        }

        if (preg_match('/\.(jpe?g|png|gif|tiff?|bmp|webp|svg|emf|wmf|eps|heic)$/u', $alt) === 1) {
            return true;
        }

        // Camera and export identifiers: TU_Logo_SW_57, IMG_2041, DSC00123.
        if (preg_match('/^[\p{L}\p{N}]+(?:_[\p{L}\p{N}]+)+$|^(img|dsc|dscn|pict?|p)\d{3,}$/u', $alt) === 1) {
            return true;
        }

        return in_array((string)preg_replace('/[\s_\-#]*\d+$/u', '', $alt), self::GENERIC_ALT, true);
    }

    /**
     * The severity an issue model can carry. Needs-review findings go out as
     * notices: they ask for a look rather than state a failure.
     *
     * @param string $severity A rule's severity.
     * @return string error, warning or notice.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function issueSeverity(string $severity): string
    {
        return in_array($severity, ['error', 'warning'], true) ? $severity : 'notice';
    }

    /**
     * The words the Assets screen shows for each rule: its label, what it
     * means for someone reading the file, and the fix.
     *
     * @return array<string, array{label: string, desc: string, advice: string}>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function ruleTexts(): array
    {
        return [
            'pdf-field-no-tooltip' => [
                'label' => Craft::t('accessibility-audit', 'Form fields without a name'),
                'desc' => Craft::t('accessibility-audit', 'A screen reader announces these fields without saying what to put in them.'),
                'advice' => Craft::t('accessibility-audit', 'Give every field a tooltip: in Acrobat, open the field’s properties and fill in Tooltip on the General tab.'),
            ],
            'pdf-untagged' => [
                'label' => Craft::t('accessibility-audit', 'Not tagged'),
                'desc' => Craft::t('accessibility-audit', 'With no tags, a screen reader cannot tell headings, lists, tables or reading order apart.'),
                'advice' => Craft::t('accessibility-audit', 'Export again with tags turned on (in Word: Save as PDF, Options, Document structure tags for accessibility), or add tags in Acrobat Pro.'),
            ],
            'pdf-no-text' => [
                'label' => Craft::t('accessibility-audit', 'No readable text'),
                'desc' => Craft::t('accessibility-audit', 'Pages with no text a screen reader can read, which usually means a scan. Worth a look: posters and outlined text land here too.'),
                'advice' => Craft::t('accessibility-audit', 'Export from the original document rather than scanning a printout, or run text recognition and check what it produces.'),
            ],
            'pdf-at-blocked' => [
                'label' => Craft::t('accessibility-audit', 'Blocks assistive technology'),
                'desc' => Craft::t('accessibility-audit', 'The security settings forbid screen readers from reading the content.'),
                'advice' => Craft::t('accessibility-audit', 'Remove the security, or allow content copying for accessibility in the security settings.'),
            ],
            'pdf-no-title' => [
                'label' => Craft::t('accessibility-audit', 'No title'),
                'desc' => Craft::t('accessibility-audit', 'Viewers and browser tabs show the filename because there is no title.'),
                'advice' => Craft::t('accessibility-audit', 'Set the title in the document properties (in Word: File, Info, Title) and export again.'),
            ],
            'pdf-weak-title' => [
                'label' => Craft::t('accessibility-audit', 'Unhelpful title'),
                'desc' => Craft::t('accessibility-audit', 'The title is the filename, the name of a program, or a placeholder such as “Untitled”.'),
                'advice' => Craft::t('accessibility-audit', 'Give it a title that says what the document is, in the document properties, and export again.'),
            ],
            'pdf-title-not-shown' => [
                'label' => Craft::t('accessibility-audit', 'Title not shown'),
                'desc' => Craft::t('accessibility-audit', 'Viewers are not told to show the title, so they show the filename instead.'),
                'advice' => Craft::t('accessibility-audit', 'In Acrobat: File, Properties, Initial View, then Show: Document Title.'),
            ],
            'pdf-no-lang' => [
                'label' => Craft::t('accessibility-audit', 'No language'),
                'desc' => Craft::t('accessibility-audit', 'Without a language a screen reader may read the text in the wrong voice.'),
                'advice' => Craft::t('accessibility-audit', 'Set the language in the program that made it, or in Acrobat under File, Properties, Advanced.'),
            ],
            'pdf-figure-no-alt' => [
                'label' => Craft::t('accessibility-audit', 'Images without alt text'),
                'desc' => Craft::t('accessibility-audit', 'Tagged images with no description, so a screen reader passes over them in silence.'),
                'advice' => Craft::t('accessibility-audit', 'Add alt text to each image in the source document before exporting, and mark purely decorative ones as artifacts.'),
            ],
            'pdf-figure-junk-alt' => [
                'label' => Craft::t('accessibility-audit', 'Alt text that says nothing'),
                'desc' => Craft::t('accessibility-audit', 'Alt text that is a filename or a word like “image” tells the reader nothing.'),
                'advice' => Craft::t('accessibility-audit', 'Replace it with a short description of what the image shows.'),
            ],
            'pdf-suspects' => [
                'label' => Craft::t('accessibility-audit', 'Tags not reviewed'),
                'desc' => Craft::t('accessibility-audit', 'The tags were added automatically and the file says nobody has checked them.'),
                'advice' => Craft::t('accessibility-audit', 'Review the tags with Acrobat’s accessibility checker, then clear the flag.'),
            ],
            'pdf-heading-order' => [
                'label' => Craft::t('accessibility-audit', 'Headings out of order'),
                'desc' => Craft::t('accessibility-audit', 'The first heading is not a level 1 heading, or a heading level is skipped.'),
                'advice' => Craft::t('accessibility-audit', 'Use the built-in heading styles, in order, in the source document.'),
            ],
            'pdf-no-headings' => [
                'label' => Craft::t('accessibility-audit', 'No headings'),
                'desc' => Craft::t('accessibility-audit', 'A long document with no headings is hard to find your way around with a screen reader.'),
                'advice' => Craft::t('accessibility-audit', 'Use heading styles for the section titles in the source document.'),
            ],
            'pdf-table-no-th' => [
                'label' => Craft::t('accessibility-audit', 'Tables without header cells'),
                'desc' => Craft::t('accessibility-audit', 'A screen reader cannot say which column or row a value belongs to.'),
                'advice' => Craft::t('accessibility-audit', 'Mark the header row in the source document (in Word: Repeat as header row).'),
            ],
            'pdf-th-no-scope' => [
                'label' => Craft::t('accessibility-audit', 'Header cells without a scope'),
                'desc' => Craft::t('accessibility-audit', 'Header cells do not say whether they head a row or a column.'),
                'advice' => Craft::t('accessibility-audit', 'Set the scope of each header cell in Acrobat’s table editor, or keep tables to one header row.'),
            ],
            'pdf-list-structure' => [
                'label' => Craft::t('accessibility-audit', 'Broken lists'),
                'desc' => Craft::t('accessibility-audit', 'List items sit outside a list or have no body, so the list is not announced as one.'),
                'advice' => Craft::t('accessibility-audit', 'Use the built-in list styles in the source document.'),
            ],
            'pdf-link-untagged' => [
                'label' => Craft::t('accessibility-audit', 'Links not tagged'),
                'desc' => Craft::t('accessibility-audit', 'Links are missing from the tags, so a screen reader may never reach them.'),
                'advice' => Craft::t('accessibility-audit', 'Export again with tags turned on, or tag the links in Acrobat.'),
            ],
            'pdf-tab-order' => [
                'label' => Craft::t('accessibility-audit', 'Tab order not set'),
                'desc' => Craft::t('accessibility-audit', 'On pages with links or fields, the Tab key does not follow the document’s structure.'),
                'advice' => Craft::t('accessibility-audit', 'In Acrobat, set each page’s tab order to Use Document Structure.'),
            ],
            'pdf-no-bookmarks' => [
                'label' => Craft::t('accessibility-audit', 'No bookmarks'),
                'desc' => Craft::t('accessibility-audit', 'A document this long with no bookmarks is hard to move around in.'),
                'advice' => Craft::t('accessibility-audit', 'Create bookmarks from headings when exporting (in Word: Save as PDF, Options, Create bookmarks using Headings).'),
            ],
        ];
    }

    /**
     * The words the Assets screen shows for each reason a file was not checked.
     *
     * @return array<string, array{label: string, desc: string}>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function reasonTexts(): array
    {
        return [
            self::REASON_PASSWORD => [
                'label' => Craft::t('accessibility-audit', 'Needs a password to open'),
                'desc' => Craft::t('accessibility-audit', 'Nobody can read it without the password, which is a barrier in itself.'),
            ],
            self::REASON_XFA => [
                'label' => Craft::t('accessibility-audit', 'XFA form'),
                'desc' => Craft::t('accessibility-audit', 'XFA forms only open in some viewers and cannot be checked.'),
            ],
            self::REASON_DAMAGED => [
                'label' => Craft::t('accessibility-audit', 'Damaged'),
                'desc' => Craft::t('accessibility-audit', 'The file could not be read. It may not open for everyone either.'),
            ],
            self::REASON_NOT_PDF => [
                'label' => Craft::t('accessibility-audit', 'Not actually a PDF'),
                'desc' => Craft::t('accessibility-audit', 'The file is named as a PDF but holds something else.'),
            ],
            self::REASON_TOO_LARGE => [
                'label' => Craft::t('accessibility-audit', 'Too large to check'),
                'desc' => Craft::t('accessibility-audit', 'Larger than the size limit under Settings.'),
            ],
            self::REASON_TOO_MANY_OBJECTS => [
                'label' => Craft::t('accessibility-audit', 'Too complex to check'),
                'desc' => Craft::t('accessibility-audit', 'Holds more objects than can be read safely in the background.'),
            ],
            self::REASON_TIMED_OUT => [
                'label' => Craft::t('accessibility-audit', 'Timed out'),
                'desc' => Craft::t('accessibility-audit', 'Reading it took too long.'),
            ],
            self::REASON_FETCH_FAILED => [
                'label' => Craft::t('accessibility-audit', 'Could not be fetched'),
                'desc' => Craft::t('accessibility-audit', 'The file could not be copied from its volume.'),
            ],
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Runs every PHP-layer rule against a parsed document.
     *
     * Encrypted files are read for what is visible without decrypting: key
     * presence, booleans and /P. Their strings are ciphertext, so the weak
     * title rule is left to the browser pass, which decrypts.
     *
     * @param PdfDocument $doc The parsed document.
     * @param string $filename The asset's filename.
     * @return PdfCheckResult
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _inspect(PdfDocument $doc, string $filename): PdfCheckResult
    {
        $root = $doc->dict($doc->trailerRef('root'));

        if ($root === []) {
            return PdfCheckResult::notChecked(self::REASON_DAMAGED);
        }

        $info = $doc->dict($doc->trailerRef('info'));
        $encrypted = $doc->trailerRef('encrypt') !== null;
        $encrypt = $doc->dict($doc->trailerRef('encrypt'));

        if ($encrypted && !$this->_opensWithoutPassword($doc, $encrypt)) {
            return PdfCheckResult::notChecked(self::REASON_PASSWORD, ['encrypted' => true]);
        }

        $acroForm = $doc->dict($root['AcroForm'] ?? null);

        if (isset($acroForm['XFA'])) {
            return PdfCheckResult::notChecked(self::REASON_XFA, ['encrypted' => $encrypted]);
        }

        $pages = $this->_pages($doc, $root);
        $xmp = $encrypted ? null : $doc->streamData($root['Metadata'] ?? null);
        $text = static fn(?array $token): string => $encrypted ? '' : trim((string)$doc->text($token));

        $markInfo = $doc->dict($root['MarkInfo'] ?? null);
        $tagged = $doc->bool($markInfo['Marked'] ?? null) === true && isset($root['StructTreeRoot']);
        $infoTitle = $text($info['Title'] ?? null);
        $xmpTitle = $xmp !== null ? self::_xmpTitle($xmp) : '';
        $title = $infoTitle !== '' ? $infoTitle : $xmpTitle;
        $lang = $text($root['Lang'] ?? null);
        $fields = $this->_formFields($doc, $acroForm);

        $findings = [];

        if (!$tagged) {
            $findings['pdf-untagged'] = [];
        } elseif ($doc->bool($markInfo['Suspects'] ?? null) === true) {
            $findings['pdf-suspects'] = [];
        }

        if (!isset($root['Lang']) || (!$encrypted && $lang === '')) {
            $findings['pdf-no-lang'] = [];
        }

        $titleMissing = $encrypted
            ? !isset($info['Title']) && !isset($root['Metadata'])
            : $title === '';

        if ($titleMissing) {
            $findings['pdf-no-title'] = [];
        } elseif (!$encrypted && self::isWeakTitle($title, $filename)) {
            $findings['pdf-weak-title'] = [];
        }

        $viewerPreferences = $doc->dict($root['ViewerPreferences'] ?? null);

        if ($doc->bool($viewerPreferences['DisplayDocTitle'] ?? null) !== true) {
            $findings['pdf-title-not-shown'] = [];
        }

        if ($encrypted && ((int)$doc->num($encrypt['P'] ?? null) & 512) === 0) {
            $findings['pdf-at-blocked'] = [];
        }

        $sample = array_slice($pages, 0, self::PAGE_SAMPLE);

        if ($sample !== [] && !array_filter($sample, fn(array $page): bool => $this->_hasFonts($doc, $page['resources']))) {
            $findings['pdf-no-text'] = [
                'count' => count($sample),
                'total' => count($sample),
                'pages' => range(1, min(count($sample), self::MAX_LISTED_PAGES)),
            ];
        }

        if ($fields['missing'] > 0) {
            $findings['pdf-field-no-tooltip'] = ['count' => $fields['missing'], 'total' => $fields['total']];
        }

        if ($tagged) {
            $findings += $this->_tabOrder($doc, $pages);
            $findings += $this->_figures($doc, $root, $pages, $encrypted);
        }

        if (count($pages) > self::BOOKMARK_PAGES && !$this->_hasOutline($doc, $root)) {
            $findings['pdf-no-bookmarks'] = ['total' => count($pages)];
        }

        return new PdfCheckResult([
            'status' => self::STATUS_CHECKED,
            'findings' => self::_ordered($findings),
            'hasForm' => $fields['total'] > 0,
            'meta' => [
                'title' => self::_nonEmpty(mb_substr($title, 0, 255)),
                'lang' => self::_nonEmpty(mb_substr($lang, 0, 35)),
                'pages' => count($pages),
                'producer' => self::_nonEmpty(mb_substr($text($info['Producer'] ?? null), 0, 255)),
                'creator' => self::_nonEmpty(mb_substr($text($info['Creator'] ?? null), 0, 255)),
                'encrypted' => $encrypted,
                'tagged' => $tagged,
                'pdfua' => $xmp !== null ? preg_match('/pdfuaid:part\s*(?:=\s*["\']\s*\d|>\s*\d)/', $xmp) === 1 : null,
                'layers' => ['php'],
            ],
        ]);
    }

    /**
     * Whether an encrypted file opens with the empty user password.
     *
     * A security handler other than the standard one (certificate security,
     * for one) needs credentials of its own, so it counts as a password.
     *
     * @param PdfDocument $doc The parsed document.
     * @param array<string, array<int, mixed>> $encrypt The encryption dictionary.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _opensWithoutPassword(PdfDocument $doc, array $encrypt): bool
    {
        if ($encrypt === []) {
            return true;
        }

        if ($doc->name($encrypt['Filter'] ?? null) !== 'Standard') {
            return false;
        }

        $fileId = (string)$doc->bytes(['<', (string)($doc->trailer['id'][0] ?? ''), 0]);

        return StandardSecurity::opensWithoutPassword(
            revision: (int)$doc->num($encrypt['R'] ?? null),
            keyBits: (int)($doc->num($encrypt['Length'] ?? null) ?? 40),
            owner: (string)$doc->bytes($encrypt['O'] ?? null),
            user: (string)$doc->bytes($encrypt['U'] ?? null),
            permissions: (int)$doc->num($encrypt['P'] ?? null),
            fileId: $fileId,
            encryptMetadata: $doc->bool($encrypt['EncryptMetadata'] ?? null) !== false,
        );
    }

    /**
     * The pages in reading order, each with the resources it inherits.
     *
     * @param PdfDocument $doc The parsed document.
     * @param array<string, array<int, mixed>> $root The catalogue.
     * @return array<int, array{ref: string|null, node: array<string, array<int, mixed>>, resources: array<int, mixed>|null}>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _pages(PdfDocument $doc, array $root): array
    {
        $pages = [];
        $seen = [];
        $stack = [[$root['Pages'] ?? null, null, 0]];

        while ($stack !== [] && count($pages) < self::MAX_PAGES) {
            [$token, $inherited, $depth] = array_pop($stack);

            if (!is_array($token) || $depth > self::MAX_DEPTH) {
                continue;
            }

            $ref = ($token[0] ?? null) === 'objref' ? (string)$token[1] : null;

            if ($ref !== null) {
                if (isset($seen[$ref])) {
                    continue;
                }

                $seen[$ref] = true;
            }

            $node = $doc->dict($token);

            if ($node === []) {
                continue;
            }

            $resources = $node['Resources'] ?? $inherited;

            if (isset($node['Kids']) || $doc->name($node['Type'] ?? null) === 'Pages') {
                foreach (array_reverse($doc->arr($node['Kids'] ?? null)) as $kid) {
                    $stack[] = [$kid, $resources, $depth + 1];
                }

                continue;
            }

            $pages[] = ['ref' => $ref, 'node' => $node, 'resources' => $resources];
        }

        return $pages;
    }

    /**
     * Whether a page's resources name a font, directly or in a form XObject.
     *
     * A page with no font can draw no text, so a document whose sampled
     * pages have none is likely a scan. Vector-outlined text has no font
     * either, which is why the rule asks for a look rather than failing.
     *
     * @param PdfDocument $doc The parsed document.
     * @param array<int, mixed>|null $resources The page's resources.
     * @param int $depth How many form XObjects deep this is.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _hasFonts(PdfDocument $doc, ?array $resources, int $depth = 0): bool
    {
        $dict = $doc->dict($resources);

        if ($doc->dict($dict['Font'] ?? null) !== []) {
            return true;
        }

        if ($depth >= 2) {
            return false;
        }

        foreach ($doc->dict($dict['XObject'] ?? null) as $xObject) {
            $form = $doc->dict($xObject);

            if ($doc->name($form['Subtype'] ?? null) === 'Form' && $this->_hasFonts($doc, $form['Resources'] ?? null, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts the form's fields and those without a tooltip.
     *
     * Only terminal fields count: a parent field groups its children and is
     * never announced itself. /TU is the accessible name (PDF 32000-1,
     * 12.7.3.1); /T is an internal name and does not stand in for it.
     *
     * @param PdfDocument $doc The parsed document.
     * @param array<string, array<int, mixed>> $acroForm The interactive form dictionary.
     * @return array{total: int, missing: int}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _formFields(PdfDocument $doc, array $acroForm): array
    {
        $total = 0;
        $missing = 0;
        $seen = [];
        $stack = $doc->arr($acroForm['Fields'] ?? null);
        $visited = 0;

        while ($stack !== [] && $visited++ < self::MAX_NODES) {
            $token = array_pop($stack);

            if (($token[0] ?? null) === 'objref') {
                if (isset($seen[$token[1]])) {
                    continue;
                }

                $seen[$token[1]] = true;
            }

            $field = $doc->dict($token);

            if ($field === []) {
                continue;
            }

            $children = array_filter(
                $doc->arr($field['Kids'] ?? null),
                static fn(array $kid): bool => isset($doc->dict($kid)['T']),
            );

            if ($children !== []) {
                array_push($stack, ...$children);
                continue;
            }

            $total++;

            if (!isset($field['TU'])) {
                $missing++;
            }
        }

        return ['total' => $total, 'missing' => $missing];
    }

    /**
     * Pages with annotations whose tab order does not follow the structure.
     *
     * @param PdfDocument $doc The parsed document.
     * @param array<int, array{ref: string|null, node: array<string, array<int, mixed>>, resources: array<int, mixed>|null}> $pages The pages.
     * @return array<string, Finding> The finding, empty where every such page sets /Tabs /S.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _tabOrder(PdfDocument $doc, array $pages): array
    {
        $withAnnotations = 0;
        $failing = [];

        foreach ($pages as $index => $page) {
            if ($doc->arr($page['node']['Annots'] ?? null) === []) {
                continue;
            }

            $withAnnotations++;

            if ($doc->name($page['node']['Tabs'] ?? null) !== 'S') {
                $failing[] = $index + 1;
            }
        }

        if ($failing === []) {
            return [];
        }

        return ['pdf-tab-order' => [
            'count' => count($failing),
            'total' => $withAnnotations,
            'pages' => array_slice($failing, 0, self::MAX_LISTED_PAGES),
        ]];
    }

    /**
     * Walks the structure tree for figures without alt text.
     *
     * Alt is tried, then ActualText, and either one empty after trimming
     * counts as missing: Word writes `/Alt ()` on every figure nobody
     * described. Roles go through the RoleMap, so a custom tag mapped to
     * Figure is a figure. On an encrypted file the strings are ciphertext, so
     * only an absent or zero-length string counts.
     *
     * @param PdfDocument $doc The parsed document.
     * @param array<string, array<int, mixed>> $root The catalogue.
     * @param array<int, array{ref: string|null, node: array<string, array<int, mixed>>, resources: array<int, mixed>|null}> $pages The pages.
     * @param bool $encrypted Whether the file's strings are encrypted.
     * @return array<string, Finding> The finding, empty where every figure has alt text.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _figures(PdfDocument $doc, array $root, array $pages, bool $encrypted): array
    {
        $pageNumbers = [];

        foreach ($pages as $index => $page) {
            if ($page['ref'] !== null) {
                $pageNumbers[$page['ref']] = $index + 1;
            }
        }

        $treeRoot = $doc->dict($root['StructTreeRoot'] ?? null);
        $roleMap = $doc->dict($treeRoot['RoleMap'] ?? null);
        $stack = [[$treeRoot['K'] ?? null, null]];
        $seen = [];
        $visited = 0;
        $total = 0;
        $missing = 0;
        $missingPages = [];

        while ($stack !== [] && $visited++ < self::MAX_NODES) {
            [$token, $pageRef] = array_pop($stack);

            if (!is_array($token)) {
                continue;
            }

            if (($token[0] ?? null) === 'objref') {
                if (isset($seen[$token[1]])) {
                    continue;
                }

                $seen[$token[1]] = true;
            }

            $resolved = $doc->resolve($token);

            if ($resolved === null) {
                continue;
            }

            if ($resolved[0] === '[') {
                foreach ($doc->arr($resolved) as $item) {
                    $stack[] = [$item, $pageRef];
                }

                continue;
            }

            $element = $doc->dict($resolved);

            if ($element === []) {
                continue;
            }

            $pg = $element['Pg'] ?? null;

            if (($pg[0] ?? null) === 'objref') {
                $pageRef = (string)$pg[1];
            }

            if ($this->_role($doc, $element, $roleMap) === 'Figure') {
                $total++;

                if (!$this->_hasAlt($doc, $element, $encrypted)) {
                    $missing++;

                    if ($pageRef !== null && isset($pageNumbers[$pageRef])) {
                        $missingPages[$pageNumbers[$pageRef]] = true;
                    }
                }
            }

            if (isset($element['K'])) {
                $stack[] = [$element['K'], $pageRef];
            }
        }

        if ($missing === 0) {
            return [];
        }

        $listed = array_keys($missingPages);
        sort($listed);

        return ['pdf-figure-no-alt' => [
            'count' => $missing,
            'total' => $total,
            'pages' => array_slice($listed, 0, self::MAX_LISTED_PAGES),
        ]];
    }

    /**
     * A structure element's standard role, through the RoleMap.
     *
     * @param PdfDocument $doc The parsed document.
     * @param array<string, array<int, mixed>> $element The structure element.
     * @param array<string, array<int, mixed>> $roleMap The RoleMap.
     * @return string|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _role(PdfDocument $doc, array $element, array $roleMap): ?string
    {
        $role = $doc->name($element['S'] ?? null);

        for ($hops = 0; $role !== null && isset($roleMap[$role]) && $hops < self::MAX_DEPTH; $hops++) {
            $mapped = $doc->name($roleMap[$role]);

            if ($mapped === null || $mapped === $role) {
                break;
            }

            $role = $mapped;
        }

        return $role;
    }

    /**
     * Whether a structure element carries alt text or actual text.
     *
     * @param PdfDocument $doc The parsed document.
     * @param array<string, array<int, mixed>> $element The structure element.
     * @param bool $encrypted Whether strings are encrypted.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _hasAlt(PdfDocument $doc, array $element, bool $encrypted): bool
    {
        foreach (['Alt', 'ActualText'] as $key) {
            if ($encrypted) {
                if ((string)$doc->bytes($element[$key] ?? null) !== '') {
                    return true;
                }

                continue;
            }

            if (trim((string)$doc->text($element[$key] ?? null)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the catalogue has an outline with at least one entry.
     *
     * @param PdfDocument $doc The parsed document.
     * @param array<string, array<int, mixed>> $root The catalogue.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _hasOutline(PdfDocument $doc, array $root): bool
    {
        $outlines = $doc->dict($root['Outlines'] ?? null);

        return isset($outlines['First']);
    }

    /**
     * Turns what the pdf.js pass read into findings.
     *
     * The catalogue rules always run. The structure rules run on a tagged
     * file; on an untagged one there is nothing to walk. The fallback rules
     * run only when PHP could not read the file.
     *
     * @param array<string, mixed> $browser What the pdf.js pass returned.
     * @param string $filename The asset's filename.
     * @param bool $tagged Whether the file is tagged.
     * @param bool $fallback Whether to answer the rules PHP normally owns.
     * @return array{0: string[], 1: array<string, Finding>} The rules
     *         evaluated, and the findings.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _browserFindings(array $browser, string $filename, bool $tagged, bool $fallback): array
    {
        $evaluated = ['pdf-no-lang', 'pdf-no-title', 'pdf-weak-title', 'pdf-title-not-shown', 'pdf-at-blocked', 'pdf-no-text'];
        $found = [];
        $pages = (int)($browser['pages'] ?? 0);
        $sampled = (int)($browser['sampled'] ?? 0);

        if (trim((string)($browser['lang'] ?? '')) === '') {
            $found['pdf-no-lang'] = [];
        }

        $title = trim((string)($browser['title'] ?? '')) ?: trim((string)($browser['xmpTitle'] ?? ''));

        if ($title === '') {
            $found['pdf-no-title'] = [];
        } elseif (self::isWeakTitle($title, $filename)) {
            $found['pdf-weak-title'] = [];
        }

        if (($browser['displayDocTitle'] ?? false) !== true) {
            $found['pdf-title-not-shown'] = [];
        }

        $permissions = $browser['permissions'] ?? null;

        if (is_array($permissions) && !in_array(512, array_map('intval', $permissions), true)) {
            $found['pdf-at-blocked'] = [];
        }

        $emptyPages = array_map('intval', (array)($browser['emptyTextPages'] ?? []));

        if ($emptyPages !== []) {
            $found['pdf-no-text'] = [
                'count' => count($emptyPages),
                'total' => $sampled,
                'pages' => array_slice($emptyPages, 0, self::MAX_LISTED_PAGES),
            ];
        }

        if ($fallback) {
            array_push($evaluated, ...self::BROWSER_FALLBACK_RULES);

            if (!$tagged) {
                $found['pdf-untagged'] = [];
            } elseif (($browser['suspects'] ?? false) === true) {
                $found['pdf-suspects'] = [];
            }

            if ($pages > self::BOOKMARK_PAGES && (int)($browser['outline'] ?? 0) === 0) {
                $found['pdf-no-bookmarks'] = ['total' => $pages];
            }
        }

        if (!$tagged || (int)($browser['structPages'] ?? 0) === 0) {
            return [$evaluated, $found];
        }

        $figures = (array)($browser['figures'] ?? []);

        // pdf.js can walk a structure tree and still return none of the figures
        // PHP finds in it, so an empty list is no answer: PHP keeps the rule.
        if ($figures !== []) {
            array_push($evaluated, 'pdf-figure-no-alt', 'pdf-figure-junk-alt');
        }

        array_push(
            $evaluated,
            'pdf-heading-order',
            'pdf-no-headings',
            'pdf-table-no-th',
            'pdf-th-no-scope',
            'pdf-list-structure',
            'pdf-link-untagged',
        );

        $found += self::_figureFindings($figures);
        $found += self::_headingFindings((array)($browser['headings'] ?? []), $pages);

        foreach ([
            'pdf-table-no-th' => ['tablesWithoutHeaders', 'tables'],
            'pdf-th-no-scope' => ['headersWithoutScope', 'headerCells'],
            'pdf-list-structure' => ['brokenListItems', 'listItems'],
            'pdf-link-untagged' => ['untaggedLinks', 'links'],
        ] as $ruleId => [$countKey, $totalKey]) {
            $count = (int)($browser[$countKey] ?? 0);

            if ($count > 0) {
                $found[$ruleId] = [
                    'count' => $count,
                    'total' => (int)($browser[$totalKey] ?? 0),
                    'pages' => array_slice(array_map('intval', (array)($browser[$countKey . 'Pages'] ?? [])), 0, self::MAX_LISTED_PAGES),
                ];
            }
        }

        return [$evaluated, $found];
    }

    /**
     * The figure findings from the browser pass's figure list.
     *
     * @param array<int, mixed> $figures Each figure's alt text (null where it
     *        has none) and page.
     * @return array<string, Finding>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _figureFindings(array $figures): array
    {
        $missing = [];
        $junk = [];

        foreach ($figures as $figure) {
            if (!is_array($figure)) {
                continue;
            }

            $alt = trim((string)($figure['alt'] ?? ''));
            $page = (int)($figure['page'] ?? 0);

            if ($alt === '') {
                $missing[] = $page;
            } elseif (self::isJunkAlt($alt)) {
                $junk[] = $page;
            }
        }

        $findings = [];

        foreach (['pdf-figure-no-alt' => $missing, 'pdf-figure-junk-alt' => $junk] as $ruleId => $onPages) {
            if ($onPages !== []) {
                $listed = array_values(array_unique(array_filter($onPages)));
                sort($listed);

                $findings[$ruleId] = [
                    'count' => count($onPages),
                    'total' => count($figures),
                    'pages' => array_slice($listed, 0, self::MAX_LISTED_PAGES),
                ];
            }
        }

        return $findings;
    }

    /**
     * The heading findings from the browser pass's heading list.
     *
     * @param array<int, mixed> $headings Each heading's level and page, in
     *        reading order.
     * @param int $pages The document's page count.
     * @return array<string, Finding>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _headingFindings(array $headings, int $pages): array
    {
        $levels = array_values(array_filter($headings, 'is_array'));

        if ($levels === []) {
            return $pages >= self::NO_HEADINGS_PAGES ? ['pdf-no-headings' => ['total' => $pages]] : [];
        }

        $wrong = [];
        $previous = null;

        foreach ($levels as $heading) {
            $level = (int)($heading['level'] ?? 0);

            if ($level <= 0) {
                continue;
            }

            if (($previous === null && $level > 1) || ($previous !== null && $level > $previous + 1)) {
                $wrong[] = (int)($heading['page'] ?? 0);
            }

            $previous = $level;
        }

        if ($wrong === []) {
            return [];
        }

        $listed = array_values(array_unique(array_filter($wrong)));
        sort($listed);

        return ['pdf-heading-order' => [
            'count' => count($wrong),
            'total' => count($levels),
            'pages' => array_slice($listed, 0, self::MAX_LISTED_PAGES),
        ]];
    }

    /**
     * The document title from XMP's dc:title.
     *
     * @param string $xmp The metadata stream.
     * @return string The title, empty where there is none.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _xmpTitle(string $xmp): string
    {
        if (preg_match('#<dc:title\b[^>]*>(.*?)</dc:title>#s', $xmp, $m) !== 1) {
            return '';
        }

        $inner = preg_match('#<rdf:li\b[^>]*>(.*?)</rdf:li>#s', $m[1], $li) === 1 ? $li[1] : $m[1];

        return trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /**
     * Findings in the order RULES lists them.
     *
     * @param array<string, Finding> $findings The findings.
     * @return array<string, Finding>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _ordered(array $findings): array
    {
        return array_intersect_key(array_replace(array_fill_keys(array_keys(self::RULES), null), $findings), $findings);
    }

    /**
     * Drops the structure findings from an untagged file. With no tags there
     * is no structure to fault, and "not tagged" already says everything.
     *
     * @param array<string, Finding> $findings The findings.
     * @return array<string, Finding>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _withoutStructureWhenUntagged(array $findings): array
    {
        if (!isset($findings['pdf-untagged'])) {
            return $findings;
        }

        return array_filter(
            $findings,
            static fn(string $ruleId): bool => !(self::RULES[$ruleId]['structure'] ?? false),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Null for an empty string.
     *
     * @param string $value The value.
     * @return string|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _nonEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
