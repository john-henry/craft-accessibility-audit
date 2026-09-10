<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\models;

use craft\base\Model;
use DateTime;

/**
 * Validates and represents the VPAT-specific report metadata for a site.
 *
 * Only fields that belong to a VPAT alone live here. The product name, contact
 * details and evaluation method are shared with every other compliance document
 * and live on {@see OrganisationMetaModel}; the two halves are merged back into
 * a single `meta` array on read, so consumers see one flat set of fields.
 *
 * All values are stored (JSON-encoded) exactly as validated: in particular the
 * date fields remain flat `Y-m-d` strings. The editor's Craft date fields post
 * a locale payload that the controller normalises back to `Y-m-d` before it
 * reaches this model, and the export template consumes the flat value unchanged.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class VpatMetaModel extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The expected format for all date fields (flat ISO date).
     */
    public const DATE_FORMAT = 'php:Y-m-d';

    // Public Properties
    // =========================================================================

    /**
     * @var string The product version the report applies to.
     */
    public string $productVersion = '';

    /**
     * @var string The report publication date (Y-m-d).
     */
    public string $reportDate = '';

    /**
     * @var string The start of the evaluation period (Y-m-d).
     */
    public string $reportPeriodFrom = '';

    /**
     * @var string The end of the evaluation period (Y-m-d).
     */
    public string $reportPeriodTo = '';

    /**
     * @var string Free-form notes shown on the report.
     */
    public string $notes = '';

    /**
     * @var string A legal disclaimer rendered as its own section on the
     * exported report.
     */
    public string $legalDisclaimer = '';

    // Public Methods
    // =========================================================================

    /**
     * Returns the validated metadata as a flat associative array ready for
     * JSON storage. Values are returned exactly as validated (dates stay flat
     * `Y-m-d` strings).
     *
     * @return array<string, string>
     */
    public function toStorageArray(): array
    {
        return [
            'productVersion' => $this->productVersion,
            'reportDate' => $this->reportDate,
            'reportPeriodFrom' => $this->reportPeriodFrom,
            'reportPeriodTo' => $this->reportPeriodTo,
            'notes' => $this->notes,
            'legalDisclaimer' => $this->legalDisclaimer,
        ];
    }

    /**
     * The storage keys this model owns.
     *
     * @return string[]
     */
    public static function storageKeys(): array
    {
        return [
            'productVersion',
            'reportDate',
            'reportPeriodFrom',
            'reportPeriodTo',
            'notes',
            'legalDisclaimer',
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
            [['productVersion'], 'string', 'max' => 255],
            [['notes', 'legalDisclaimer'], 'string', 'max' => 5000],
            // Dates must be well-formed flat ISO strings; the date validator
            // does not mutate the stored value (defaultTimeZone/format left alone).
            //
            // All three describe work already done: the day the report was
            // published, and the period the evaluation covered. A future date
            // in any of them claims testing nobody has carried out.
            [
                ['reportDate', 'reportPeriodFrom', 'reportPeriodTo'],
                'date',
                'format' => self::DATE_FORMAT,
                'max' => (new DateTime('today'))->format('Y-m-d'),
                'tooBig' => '{attribute} cannot be in the future.',
            ],

            // An evaluation period that ends before it starts describes nothing,
            // and both halves are typed by hand into separate fields.
            [
                ['reportPeriodTo'],
                'compare',
                'compareAttribute' => 'reportPeriodFrom',
                'operator' => '>=',
                'type' => 'string',
                'when' => static fn(self $model): bool => $model->reportPeriodFrom !== '' && $model->reportPeriodTo !== '',
                'message' => 'The evaluation period cannot end before it starts.',
            ],
        ]);
    }
}
