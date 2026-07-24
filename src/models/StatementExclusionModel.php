<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\models;

use craft\base\Model;

/**
 * One entry in a statement's "non-accessible content" section.
 *
 * The EU model statement requires shortfalls to be split three ways, because
 * they carry different obligations: a non-compliance has to be fixed, a
 * disproportionate burden has to be justified and reviewed, and out-of-scope
 * content is simply outside the legislation. Lumping them together reads as an
 * admission that everything is a failure, which is both wrong and worse for the
 * organisation than the truth.
 *
 * Entries are stored as a JSON list on the statement record rather than their
 * own table: they are only ever read as a complete set for one site, never
 * queried or joined across sites.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class StatementExclusionModel extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * @var string Content that fails the standard and is due to be fixed.
     */
    public const CATEGORY_NON_COMPLIANCE = 'nonCompliance';

    /**
     * @var string Content exempted as a disproportionate burden (Article 5 of
     * the EU Directive, and its UK equivalent).
     */
    public const CATEGORY_BURDEN = 'disproportionateBurden';

    /**
     * @var string Content the legislation does not cover, such as archived
     * documents or third-party content outside the body's control.
     */
    public const CATEGORY_OUT_OF_SCOPE = 'outOfScope';

    // Public Properties
    // =========================================================================

    /**
     * @var string Which of the three categories this entry falls under.
     */
    public string $category = self::CATEGORY_NON_COMPLIANCE;

    /**
     * @var string What the content is, in terms a member of the public would
     * recognise (e.g. "PDF menus published before 2023").
     */
    public string $content = '';

    /**
     * @var string Why it falls short, or why the exemption applies.
     */
    public string $reason = '';

    /**
     * @var string The WCAG success criterion at issue, where there is one.
     */
    public string $criterion = '';

    /**
     * @var string When it is expected to be fixed (Y-m-d). Only meaningful for
     * non-compliances: the other two categories are not scheduled work.
     */
    public string $plannedDate = '';

    // Public Methods
    // =========================================================================

    /**
     * All valid category handles.
     *
     * @return string[]
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_NON_COMPLIANCE,
            self::CATEGORY_BURDEN,
            self::CATEGORY_OUT_OF_SCOPE,
        ];
    }

    /**
     * Builds a validated model from a stored or posted row.
     *
     * @param array<string, mixed> $row The raw values.
     * @return self
     */
    public static function fromArray(array $row): self
    {
        $model = new self();
        $model->category = (string) ($row['category'] ?? self::CATEGORY_NON_COMPLIANCE);
        $model->content = trim((string) ($row['content'] ?? ''));
        $model->reason = trim((string) ($row['reason'] ?? ''));
        $model->criterion = trim((string) ($row['criterion'] ?? ''));
        $model->plannedDate = trim((string) ($row['plannedDate'] ?? ''));

        return $model;
    }

    /**
     * Returns the validated entry as a flat associative array ready for JSON
     * storage.
     *
     * @return array<string, string>
     */
    public function toStorageArray(): array
    {
        return [
            'category' => $this->category,
            'content' => $this->content,
            'reason' => $this->reason,
            'criterion' => $this->criterion,
            'plannedDate' => $this->plannedDate,
        ];
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['content'], 'required'],
            [['category'], 'in', 'range' => self::categories()],
            [['content'], 'string', 'max' => 500],
            [['reason'], 'string', 'max' => 2000],
            // Criterion numbers only: "1.4.3", not a sentence about one.
            [['criterion'], 'match', 'pattern' => '/^(\d+\.\d+\.\d+)?$/', 'message' => '{attribute} must be a WCAG criterion number, e.g. 1.4.3.'],
            [['plannedDate'], 'date', 'format' => StatementMetaModel::DATE_FORMAT],
        ]);
    }
}
