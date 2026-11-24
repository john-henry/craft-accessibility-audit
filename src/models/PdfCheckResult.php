<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\models;

use craft\base\Model;
use johnhenry\accessibilityaudit\services\PdfChecker;

/**
 * What one PDF check found: the failures per rule, or why the file could not
 * be checked, and the document facts the Assets screen shows above them.
 *
 * @phpstan-type Finding array{count?: int, total?: int, pages?: int[]}
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class PdfCheckResult extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string `checked`, or `not-checked` with a reason.
     */
    public string $status = PdfChecker::STATUS_CHECKED;

    /**
     * @var string|null Why the file was not checked (a PdfChecker::REASON_*
     * value), or null where it was.
     */
    public ?string $reason = null;

    /**
     * @var array<string, array<string, mixed>> The failures, keyed by rule id.
     * Each holds what the screen needs to say how much failed: a count, the
     * total it is out of, and the pages it is on, where the rule has them.
     */
    public array $findings = [];

    /**
     * @var array<string, mixed> Document facts: title, language, page count,
     * producer and creator, whether it is encrypted, tagged or claims PDF/UA,
     * and which layers ran.
     */
    public array $meta = [];

    /**
     * @var bool Whether the document holds form fields. Forms are what people
     * most often have to fill in, so they are listed first.
     */
    public bool $hasForm = false;

    // Public Methods
    // =========================================================================

    /**
     * A result for a file that could not be checked.
     *
     * @param string $reason A PdfChecker::REASON_* value.
     * @param array<string, mixed> $meta Whatever was learnt before giving up.
     * @return self
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function notChecked(string $reason, array $meta = []): self
    {
        return new self([
            'status' => PdfChecker::STATUS_NOT_CHECKED,
            'reason' => $reason,
            'meta' => $meta,
        ]);
    }

    /**
     * Whether the file was checked.
     *
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function isChecked(): bool
    {
        return $this->status === PdfChecker::STATUS_CHECKED;
    }

    /**
     * The findings as issue models, for anything that reports issues in the
     * shape the rest of the audit uses.
     *
     * @return IssueModel[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function issues(): array
    {
        $issues = [];

        foreach ($this->findings as $ruleId => $finding) {
            $rule = PdfChecker::RULES[$ruleId] ?? null;

            if ($rule === null) {
                continue;
            }

            $pages = array_map('intval', (array)($finding['pages'] ?? []));

            $issues[] = IssueModel::make(
                ruleId: $ruleId,
                severity: PdfChecker::issueSeverity($rule['severity']),
                message: $rule['message'],
                wcagCriterion: $rule['wcag'],
                wcagLevel: $rule['level'],
                context: $pages !== [] ? 'Pages ' . implode(', ', $pages) : null,
                helpUrl: $rule['helpUrl'],
            );
        }

        return $issues;
    }
}
