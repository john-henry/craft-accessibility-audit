<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\models;

use craft\base\Model;
use johnhenry\accessibilityaudit\services\StatementProfiles;

/**
 * Validates and represents the accessibility statement metadata for a site.
 *
 * Only fields belonging to the statement live here. The name of the site, the
 * accessibility contact and the evaluation method are shared with the VPAT and
 * live on {@see OrganisationMetaModel}.
 *
 * Which of these are compulsory depends on the jurisdiction profile, so the
 * conditional rules read the profile rather than hard-requiring fields that are
 * meaningless outside the EU and UK regimes.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class StatementMetaModel extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The expected format for all date fields (flat ISO date).
     */
    public const DATE_FORMAT = 'php:Y-m-d';

    /**
     * @var string The statement was prepared by the organisation itself.
     */
    public const METHOD_SELF = 'self';

    /**
     * @var string The statement was prepared by an external evaluator.
     */
    public const METHOD_THIRD_PARTY = 'thirdParty';

    /**
     * @var string Compliance claims, worst to best. Only `full` is withheld from
     * automatic derivation: see StatementService.
     */
    public const STATUS_NONE = 'nonCompliant';
    public const STATUS_PARTIAL = 'partiallyCompliant';
    public const STATUS_FULL = 'fullyCompliant';

    // Public Properties
    // =========================================================================

    /**
     * @var string The jurisdiction profile this statement is written to.
     */
    public string $profile = StatementProfiles::PROFILE_GENERIC;

    /**
     * @var string An override for the derived compliance status. Empty means
     * the derived value stands.
     */
    public string $statusOverride = '';

    /**
     * @var string The date the statement was prepared (Y-m-d).
     */
    public string $statementDate = '';

    /**
     * @var string The date the statement was last reviewed (Y-m-d).
     */
    public string $reviewDate = '';

    /**
     * @var string How the assessment was carried out.
     */
    public string $preparationMethod = self::METHOD_SELF;

    /**
     * @var string The external evaluator's name, when the assessment was not a
     * self-assessment.
     */
    public string $preparedBy = '';

    /**
     * @var string The body a complainant escalates to when the organisation's
     * own response is unsatisfactory. Required by the EU and UK profiles.
     */
    public string $enforcementBody = '';

    /**
     * @var string The enforcement body's URL.
     */
    public string $enforcementUrl = '';

    /**
     * @var string Anything else the enforcement procedure needs to say, such as
     * a postal address or a complaints reference.
     */
    public string $enforcementNotes = '';

    /**
     * @var string How quickly feedback is answered, e.g. "within 5 working
     * days". Rendered beside the contact details.
     */
    public string $feedbackResponseTime = '';

    /**
     * @var bool Whether a person has confirmed they reviewed the criteria no
     * scanner can test.
     *
     * The VPAT records this per criterion with remarks, which is better
     * evidence, but the VPAT is a Pro feature and the statement is not. Without
     * a blanket attestation here, a Standard site could never state full
     * compliance no matter how much manual testing it had actually done. The
     * principle was never "you must own Pro": it is that a machine must not
     * make this claim on a person's behalf.
     */
    public bool $manualReviewConfirmed = false;

    /**
     * @var string Replaces the generated opening commitment paragraph.
     */
    public string $commitmentOverride = '';

    // Public Methods
    // =========================================================================

    /**
     * Returns the validated metadata as a flat associative array ready for
     * JSON storage.
     *
     * @return array<string, string|bool>
     */
    public function toStorageArray(): array
    {
        return [
            'statusOverride' => $this->statusOverride,
            'statementDate' => $this->statementDate,
            'reviewDate' => $this->reviewDate,
            'preparationMethod' => $this->preparationMethod,
            'preparedBy' => $this->preparedBy,
            'enforcementBody' => $this->enforcementBody,
            'enforcementUrl' => $this->enforcementUrl,
            'enforcementNotes' => $this->enforcementNotes,
            'feedbackResponseTime' => $this->feedbackResponseTime,
            'commitmentOverride' => $this->commitmentOverride,
            'manualReviewConfirmed' => $this->manualReviewConfirmed,
        ];
    }

    /**
     * The storage keys this model owns.
     *
     * `profile` is absent by design: it lives in its own column so a statement
     * can be queried by jurisdiction without unpacking JSON.
     *
     * @return string[]
     */
    public static function storageKeys(): array
    {
        return [
            'statusOverride',
            'statementDate',
            'reviewDate',
            'preparationMethod',
            'preparedBy',
            'enforcementBody',
            'enforcementUrl',
            'enforcementNotes',
            'feedbackResponseTime',
            'commitmentOverride',
            'manualReviewConfirmed',
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
            [['profile'], 'in', 'range' => StatementProfiles::handles()],
            [['preparationMethod'], 'in', 'range' => [self::METHOD_SELF, self::METHOD_THIRD_PARTY]],
            [['statusOverride'], 'in', 'range' => ['', self::STATUS_NONE, self::STATUS_PARTIAL, self::STATUS_FULL]],
            [['preparedBy', 'enforcementBody', 'feedbackResponseTime'], 'string', 'max' => 255],
            [['enforcementUrl'], 'string', 'max' => 500],
            [['enforcementUrl'], 'url', 'defaultScheme' => 'https', 'skipOnEmpty' => true],
            [['enforcementNotes'], 'string', 'max' => 2000],
            [['commitmentOverride'], 'string', 'max' => 5000],
            [['manualReviewConfirmed'], 'boolean'],
            [['statementDate', 'reviewDate'], 'date', 'format' => self::DATE_FORMAT],

            // Conditional on the profile: an enforcement body is compulsory
            // under the EU and UK regimes and meaningless without one, so it is
            // required there and optional everywhere else.
            [
                ['enforcementBody'],
                'required',
                'when' => fn(self $model): bool => (bool) StatementProfiles::get($model->profile)['requiresEnforcement'],
                'message' => 'An enforcement body is required for this jurisdiction.',
            ],

            // A third-party claim has to name the third party, otherwise the
            // statement asserts an independent evaluation nobody can check.
            [
                ['preparedBy'],
                'required',
                'when' => fn(self $model): bool => $model->preparationMethod === self::METHOD_THIRD_PARTY,
                'message' => 'Name the organisation that carried out the evaluation.',
            ],
        ]);
    }
}
