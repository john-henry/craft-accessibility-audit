<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\models;

use craft\base\Model;

/**
 * Validates and represents the organisation metadata shared by every
 * compliance document the plugin produces for a site.
 *
 * These are the fields that describe *who* is reporting and *how* they
 * evaluated, as opposed to anything specific to one document: the site or
 * product being reported on, who to contact about accessibility problems, and
 * the method behind the assessment. A VPAT needs them, and so does an
 * accessibility statement, which is why they live here rather than on either.
 *
 * Stored JSON-encoded in one row per site. Document-specific metadata (report
 * periods, disclaimers, statement dates) stays with its own document model.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class OrganisationMetaModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The product / site name the reporting covers.
     */
    public string $productName = '';

    /**
     * @var string A short description of the product.
     */
    public string $productDescription = '';

    /**
     * @var string The name of the accessibility contact.
     */
    public string $contactName = '';

    /**
     * @var string The email address of the accessibility contact.
     */
    public string $contactEmail = '';

    /**
     * @var string The phone number of the accessibility contact.
     */
    public string $contactPhone = '';

    /**
     * @var string A description of the evaluation methodology used.
     */
    public string $evalMethodology = '';

    /**
     * @var string[] The evaluation methods used, as human-readable labels
     * (e.g. "Keyboard-only testing"). Rendered as a list ahead of the free-form
     * methodology notes, so a report only ever claims testing somebody
     * actually ticked.
     */
    public array $evalMethods = [];

    /**
     * @var string[] The pages/screens the evaluation covered, one label per
     * entry. Rendered as a "Scope of Evaluation" section.
     */
    public array $scopePages = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns the validated metadata as a flat associative array ready for
     * JSON storage.
     *
     * @return array<string, string|string[]>
     */
    public function toStorageArray(): array
    {
        return [
            'productName' => $this->productName,
            'productDescription' => $this->productDescription,
            'contactName' => $this->contactName,
            'contactEmail' => $this->contactEmail,
            'contactPhone' => $this->contactPhone,
            'evalMethodology' => $this->evalMethodology,
            'evalMethods' => $this->evalMethods,
            'scopePages' => $this->scopePages,
        ];
    }

    /**
     * The storage keys this model owns.
     *
     * Lets callers split a merged metadata payload back into its shared and
     * document-specific halves without restating the field list, which is how
     * the two drift apart.
     *
     * @return string[]
     */
    public static function storageKeys(): array
    {
        return [
            'productName',
            'productDescription',
            'contactName',
            'contactEmail',
            'contactPhone',
            'evalMethodology',
            'evalMethods',
            'scopePages',
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
            // The one field every document meaningfully needs.
            [['productName'], 'required'],
            [['productName', 'contactName'], 'string', 'max' => 255],
            [['productDescription', 'evalMethodology'], 'string', 'max' => 2000],
            [['evalMethods', 'scopePages'], 'each', 'rule' => ['string', 'max' => 255]],
            [['contactEmail'], 'string', 'max' => 255],
            [['contactEmail'], 'email'],
            // Phone numbers vary widely: cap length and restrict to a sane
            // character set rather than enforcing a single national format.
            [['contactPhone'], 'string', 'max' => 40],
            [['contactPhone'], 'match', 'pattern' => '/^[0-9+()\-.\s]*$/', 'message' => '{attribute} contains invalid characters.'],
        ]);
    }
}
