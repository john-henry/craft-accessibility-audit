<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\models;

use craft\base\Model;

/**
 * Represents a single accessibility issue found during a scan.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class IssueModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The rule identifier the issue was raised against.
     */
    public string $ruleId = '';

    /**
     * @var string|null The WCAG success criterion number, if known.
     */
    public ?string $wcagCriterion = null;

    /**
     * @var string|null The WCAG conformance level (A, AA, AAA), if known.
     */
    public ?string $wcagLevel = null;

    /**
     * @var string The severity of the issue (error, warning, notice).
     */
    public string $severity = 'warning';

    /**
     * @var string The human-readable issue message.
     */
    public string $message = '';

    /**
     * @var string|null The HTML or text context the issue was found in.
     */
    public ?string $context = null;

    /**
     * @var string|null A URL to documentation about the issue, if any.
     */
    public ?string $helpUrl = null;

    /**
     * @var string The scanner that produced the issue (php, axe, contrast).
     */
    public string $source = 'php';

    /**
     * @var string|null The viewport bucket the issue belongs to (desktop,
     * mobile). Null for viewport-independent findings: the PHP scanner reads
     * static HTML, so its results hold at every width. Browser-sourced
     * findings (axe, contrast) are always tagged, because landmarks, heading
     * visibility, contrast, and target sizes all change across breakpoints.
     */
    public ?string $viewport = null;

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['ruleId', 'severity', 'message', 'source'], 'required'],
            [['severity'], 'in', 'range' => ['error', 'warning', 'notice']],
            [['source'], 'in', 'range' => ['php', 'axe', 'contrast']],
            [['viewport'], 'in', 'range' => ['desktop', 'mobile']],
        ]);
    }

    // Public Methods
    // =========================================================================

    /**
     * Creates an issue model from the given values.
     *
     * @param string $ruleId The rule identifier.
     * @param string $severity The severity of the issue.
     * @param string $message The human-readable issue message.
     * @param string|null $wcagCriterion The WCAG success criterion number.
     * @param string|null $wcagLevel The WCAG conformance level.
     * @param string|null $context The HTML or text context.
     * @param string|null $helpUrl A URL to documentation about the issue.
     * @param string $source The scanner that produced the issue.
     * @param string|null $viewport The viewport bucket (desktop, mobile), or null when viewport-independent.
     * @return self
     */
    public static function make(
        string $ruleId,
        string $severity,
        string $message,
        ?string $wcagCriterion = null,
        ?string $wcagLevel = null,
        ?string $context = null,
        ?string $helpUrl = null,
        string $source = 'php',
        ?string $viewport = null,
    ): self {
        $model = new self();
        $model->ruleId = $ruleId;
        $model->severity = $severity;
        $model->message = $message;
        $model->wcagCriterion = $wcagCriterion;
        $model->wcagLevel = $wcagLevel;
        $model->context = $context;
        $model->helpUrl = $helpUrl;
        $model->source = $source;
        $model->viewport = $viewport;
        return $model;
    }
}
