<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\web\View;
use DateTime;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\OrganisationMetaModel;
use johnhenry\accessibilityaudit\models\StatementExclusionModel;
use johnhenry\accessibilityaudit\models\StatementMetaModel;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Component;
use yii\db\Exception;

/**
 * Builds and stores a site's accessibility statement.
 *
 * A statement is a public legal declaration, not a report, and that governs
 * every derivation here. Scan data is evidence for a claim, never the claim
 * itself: automated testing reaches perhaps a third of WCAG, so this service
 * will narrow a claim on the evidence but will never widen one. In particular
 * {@see deriveComplianceStatus()} cannot return "fully compliant" from scan
 * results alone.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class StatementService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var float The share of applicable criteria that must be failing before a
     * site is described as not compliant rather than partially compliant.
     *
     * The EU model statement distinguishes the two as conforming to "most" of
     * the requirements or not, and half is the plainest reading of most. A site
     * failing more than half its criteria should not be telling the public it
     * partially conforms.
     */
    private const NON_COMPLIANT_THRESHOLD = 0.5;

    /**
     * @var string A conformance level from the VPAT that counts as a pass.
     */
    private const LEVEL_SUPPORTS = 'Supports';

    // Private Properties
    // =========================================================================

    /**
     * @var bool Whether a render is already in progress.
     *
     * Guards against a self-referential statementTemplate. Pointing the setting
     * at the page that displays the statement is an easy mistake to make, and
     * without this it recurses until the PHP worker dies: no error, no log, just
     * a request that never returns and a worker gone from the pool. A handful of
     * those takes the whole site down, which is a wretched thing to debug.
     */
    private bool $_rendering = false;

    // Public Methods
    // =========================================================================

    /**
     * Returns the stored statement for a site, creating the record on first
     * read so the editor always has something to write against.
     *
     * The returned `meta` merges the shared organisation metadata underneath
     * the statement's own, exactly as the VPAT does, so a template sees one
     * flat array and never needs to know storage is split three ways.
     *
     * @param int $siteId The site to read.
     * @return array{id: int, profile: string, meta: array<string, mixed>, exclusions: array<int, array<string, string>>, sourceScanDate: string|null}
     * @throws Exception When the record cannot be created.
     * @throws \Exception
     */
    public function getRecord(int $siteId): array
    {
        // Blank defaults for every key both halves own, so a template reading
        // a missing key doesn't throw under strict_variables.
        $defaults = array_fill_keys(
            array_merge(OrganisationMetaModel::storageKeys(), StatementMetaModel::storageKeys()),
            '',
        );

        $shared = array_merge($defaults, AccessibilityAudit::getInstance()->organisation->getMeta($siteId));

        $row = (new Query())
            ->select(['id', 'profile', 'meta', 'exclusions', 'sourceScanDate'])
            ->from('{{%accessibilityaudit_statement}}')
            ->where(['siteId' => $siteId])
            ->one();

        if (!$row) {
            $this->_createRecord($siteId);

            return [
                'id' => (int) Craft::$app->getDb()->getLastInsertID(),
                'profile' => StatementProfiles::PROFILE_GENERIC,
                'meta' => $shared,
                'exclusions' => [],
                'sourceScanDate' => null,
            ];
        }

        $own = $row['meta'] ? Json::decode($row['meta']) : [];
        $exclusions = $row['exclusions'] ? Json::decode($row['exclusions']) : [];

        return [
            'id' => (int) $row['id'],
            'profile' => (string) $row['profile'],
            'meta' => array_merge($shared, is_array($own) ? $own : []),
            'exclusions' => is_array($exclusions) ? $exclusions : [],
            'sourceScanDate' => $row['sourceScanDate'],
        ];
    }

    /**
     * Saves the validated statement metadata for a site.
     *
     * The profile is written to its own column; everything else goes to the
     * JSON blob. The shared organisation metadata is saved separately.
     *
     * @param int $siteId The site the statement belongs to.
     * @param StatementMetaModel $meta The validated metadata model.
     * @return void
     * @throws Exception When the update fails.
     * @throws \Exception
     * @see OrganisationService::saveMeta()
     */
    public function saveMeta(int $siteId, StatementMetaModel $meta): void
    {
        $this->_ensureRecord($siteId);

        Craft::$app->getDb()->createCommand()
            ->update(
                '{{%accessibilityaudit_statement}}',
                [
                    'profile' => $meta->profile,
                    'meta' => Json::encode($meta->toStorageArray()),
                    'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                ],
                ['siteId' => $siteId],
            )
            ->execute();
    }

    /**
     * Replaces the non-accessible content entries for a site.
     *
     * @param int $siteId The site the statement belongs to.
     * @param StatementExclusionModel[] $entries The validated entries, in order.
     * @return void
     * @throws Exception When the update fails.
     * @throws \Exception
     */
    public function saveExclusions(int $siteId, array $entries): void
    {
        $this->_ensureRecord($siteId);

        $stored = array_map(
            static fn(StatementExclusionModel $entry): array => $entry->toStorageArray(),
            $entries,
        );

        Craft::$app->getDb()->createCommand()
            ->update(
                '{{%accessibilityaudit_statement}}',
                [
                    'exclusions' => Json::encode(array_values($stored)),
                    // Stamp the scan the entries were reconciled against, so the
                    // editor can be told when the site has moved on since.
                    'sourceScanDate' => Db::prepareDateForDb(new DateTime()),
                    'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                ],
                ['siteId' => $siteId],
            )
            ->execute();
    }

    /**
     * Works out what the site can honestly claim.
     *
     * Three outcomes, matching the EU model statement's own vocabulary. The
     * important asymmetry: failures found by the scanner can only ever push the
     * claim down. "Fully compliant" additionally requires a human to have signed
     * off every criterion no scanner can test, via the VPAT, because a scanner
     * that finds nothing has not established that there is nothing to find.
     *
     * @param int $siteId The site to assess.
     * @return array{
     *     status: string,
     *     canClaimFull: bool,
     *     hasScanData: bool,
     *     failingCriteria: string[],
     *     unconfirmedCriteria: string[],
     *     applicableCount: int,
     * }
     * @throws Exception
     * @throws \Exception
     */
    public function deriveComplianceStatus(int $siteId): array
    {
        $report = AccessibilityAudit::getInstance()->vpat->getFullReport($siteId);
        $rows = array_merge($report['levelA'], $report['levelAA']);

        // A blanket attestation stands in for per-criterion VPAT sign-off. The
        // VPAT is better evidence and is a Pro feature; the statement is not, so
        // without this a Standard site could never state full compliance no
        // matter how thoroughly it had been tested by hand.
        $attested = (bool) ($this->getRecord($siteId)['meta']['manualReviewConfirmed'] ?? false);

        $failing = [];
        $unconfirmed = [];

        foreach ($rows as $number => $row) {
            $effective = (string) ($row['effectiveLevel'] ?? '');

            if ($effective !== '' && $effective !== self::LEVEL_SUPPORTS) {
                $failing[] = (string) $number;
                continue;
            }

            // A criterion no scanner can test counts as unconfirmed until a
            // person has said otherwise on the VPAT. An empty level is equally
            // unconfirmed: nothing has been established either way.
            $needsHuman = ($row['auto'] ?? '') === 'manual';

            if (($effective === '' || ($needsHuman && ($row['overrideLevel'] ?? null) === null)) && !$attested) {
                $unconfirmed[] = (string) $number;
            }
        }

        $applicable = count($rows);
        $hasScanData = (bool) $report['hasScanData'];

        $status = StatementMetaModel::STATUS_PARTIAL;

        if ($applicable > 0 && (count($failing) / $applicable) > self::NON_COMPLIANT_THRESHOLD) {
            $status = StatementMetaModel::STATUS_NONE;
        }

        // The only route to a full claim: nothing failing, nothing unexamined.
        $canClaimFull = $hasScanData && empty($failing) && empty($unconfirmed);

        if ($canClaimFull) {
            $status = StatementMetaModel::STATUS_FULL;
        }

        return [
            'status' => $status,
            'canClaimFull' => $canClaimFull,
            'hasScanData' => $hasScanData,
            'failingCriteria' => $failing,
            'unconfirmedCriteria' => $unconfirmed,
            'applicableCount' => $applicable,
        ];
    }

    /**
     * The compliance status the statement will actually publish.
     *
     * An editor may override the derived value, with one exception: they cannot
     * override *up* to "fully compliant" while criteria remain failing or
     * unexamined. Every other direction is theirs to take, since a person may
     * well know something the scanner does not.
     *
     * @param int $siteId The site to assess.
     * @return array{status: string, derived: string, isOverridden: bool, refusedOverride: bool}
     * @throws Exception
     * @throws \Exception
     */
    public function resolveComplianceStatus(int $siteId): array
    {
        $derivation = $this->deriveComplianceStatus($siteId);
        $record = $this->getRecord($siteId);
        $override = (string) ($record['meta']['statusOverride'] ?? '');

        if ($override === '') {
            return [
                'status' => $derivation['status'],
                'derived' => $derivation['status'],
                'isOverridden' => false,
                'refusedOverride' => false,
            ];
        }

        if ($override === StatementMetaModel::STATUS_FULL && !$derivation['canClaimFull']) {
            return [
                'status' => $derivation['status'],
                'derived' => $derivation['status'],
                'isOverridden' => false,
                'refusedOverride' => true,
            ];
        }

        return [
            'status' => $override,
            'derived' => $derivation['status'],
            'isOverridden' => true,
            'refusedOverride' => false,
        ];
    }

    /**
     * Suggested non-accessible content entries, drawn from what is currently
     * failing and not already written into the statement.
     *
     * These are suggestions, never published content. The editor promotes one
     * into a real entry and edits the wording, because a scanner's phrasing is
     * aimed at a developer and a statement is read by the public.
     *
     * Grouped by WCAG criterion rather than by rule ID for the same reason: a
     * statement speaks in terms of the standard, not the plugin's rule names.
     *
     * @param int $siteId The site to assess.
     * @return array<int, array{criterion: string, name: string, reason: string, occurrences: int}>
     * @throws Exception
     * @throws \Exception
     */
    public function deriveSuggestions(int $siteId): array
    {
        $plugin = AccessibilityAudit::getInstance();
        $criteria = $plugin->vpat->getCriteria();
        $covered = $this->_coveredCriteria($siteId);

        $grouped = [];

        foreach ($plugin->audit->getIssuesByImpact($siteId, 500) as $issue) {
            $criterion = (string) ($issue['wcagCriterion'] ?? '');

            if ($criterion === '' || isset($covered[$criterion]) || !isset($criteria[$criterion])) {
                continue;
            }

            $grouped[$criterion] = ($grouped[$criterion] ?? 0) + (int) $issue['occurrences'];
        }

        $suggestions = [];

        foreach ($grouped as $criterion => $occurrences) {
            $suggestions[] = [
                'criterion' => $criterion,
                'name' => (string) $criteria[$criterion]['name'],
                'reason' => (string) $criteria[$criterion]['desc'],
                'occurrences' => $occurrences,
            ];
        }

        usort($suggestions, static fn(array $a, array $b): int => $b['occurrences'] <=> $a['occurrences']);

        return $suggestions;
    }

    /**
     * Whether the statement has fallen behind the site it describes.
     *
     * Stale means there is something currently failing that the statement does
     * not account for. A published statement that omits a known failure is the
     * specific way these documents go wrong: they are written once and left
     * while the site moves on.
     *
     * @param int $siteId The site to assess.
     * @return bool
     * @throws Exception
     * @throws \Exception
     */
    public function isStale(int $siteId): bool
    {
        return !empty($this->deriveSuggestions($siteId));
    }

    /**
     * Assembles everything a statement template needs.
     *
     * @param int $siteId The site to render.
     * @return array<string, mixed>
     * @throws Exception
     * @throws \Exception
     */
    public function getFullStatement(int $siteId): array
    {
        $record = $this->getRecord($siteId);
        $profile = StatementProfiles::get($record['profile']);
        $status = $this->resolveComplianceStatus($siteId);
        $settings = AccessibilityAudit::getInstance()->getSettings();
        $targetLevel = (string) ($settings->wcagLevel ?? 'AA');

        return [
            'profile' => $record['profile'],
            'profileLabel' => $profile['label'],
            'legislation' => $profile['legislation'],
            'standard' => $profile['standard'],
            'meta' => $record['meta'],
            // Two shapes on purpose: grouped for the published document, which
            // renders each legal category under its own heading, and flat for
            // the editor, which edits one ordered list.
            'exclusions' => $this->_groupExclusions($record['exclusions']),
            'entries' => $record['exclusions'],
            'status' => $status['status'],
            'derivedStatus' => $status['derived'],
            'isOverridden' => $status['isOverridden'],
            'sourceScanDate' => $record['sourceScanDate'],
            'isStale' => $this->isStale($siteId),
            // A target below the profile's minimum cannot support the claim the
            // statement is about to make, and the editor needs telling plainly.
            'targetLevelSatisfies' => StatementProfiles::targetLevelSatisfies($record['profile'], $targetLevel),
            'targetLevel' => $targetLevel,
        ];
    }

    /**
     * Renders the statement to markup.
     *
     * The single render path: the Twig variable, the CP preview and anything
     * else all come through here, so a preview can never show something other
     * than what gets published.
     *
     * @param int $siteId The site to render.
     * @param array<string, mixed> $options Presentation options. `headingLevel`
     *        (1 to 6, default 1) sets the level the statement's own title
     *        renders at, with its subheadings stepping down from there, so the
     *        statement can be dropped into a page that already has a heading
     *        above it. `title` replaces the title text.
     * @return string The statement markup.
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws \yii\base\Exception
     * @throws \Exception
     */
    public function render(int $siteId, array $options = []): string
    {
        $statement = $this->getFullStatement($siteId);
        $view = Craft::$app->getView();
        $template = trim(AccessibilityAudit::getInstance()->getSettings()->statementTemplate);

        // Re-entry means the custom template rendered the statement again, so it
        // is the page displaying it rather than a replacement for the markup.
        // Fall through to the built-in markup: that ends the loop and still puts
        // a correct statement on the page.
        if ($this->_rendering) {
            Craft::warning(
                "Statement template \"{$template}\" renders the statement itself, which would recurse. " .
                'Using the built-in markup instead: a statement template replaces the markup and must not ' .
                'call craft.a11y.accessibilityStatementHtml().',
                'accessibility-audit',
            );
        }

        $headingLevel = (int)($options['headingLevel'] ?? 1);
        $vars = [
            'statement' => $statement,
            'headingLevel' => max(1, min(6, $headingLevel)),
            'title' => (string)($options['title'] ?? Craft::t('accessibility-audit', 'Accessibility statement')),
        ];

        if (!$this->_rendering && $template !== '' && $view->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
            $this->_rendering = true;

            try {
                return $view->renderTemplate($template, $vars, View::TEMPLATE_MODE_SITE);
            } finally {
                $this->_rendering = false;
            }
        }

        if ($template !== '' && !$this->_rendering && !$view->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
            Craft::warning(
                "Statement template \"{$template}\" not found; falling back to the built-in markup.",
                'accessibility-audit',
            );
        }

        // Site mode, not CP: this markup goes into a page of the site, and a CP
        // template rendered from a site request hangs the request rather than
        // failing. The template lives under src/templates/public, registered as
        // a site template root.
        return $view->renderTemplate(
            'accessibility-audit/statement-render',
            $vars,
            View::TEMPLATE_MODE_SITE,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * The WCAG criteria already written into the statement's entries.
     *
     * @param int $siteId The site to read.
     * @return array<string, true> Criterion numbers as keys.
     * @throws Exception
     * @throws \Exception
     */
    private function _coveredCriteria(int $siteId): array
    {
        $covered = [];

        foreach ($this->getRecord($siteId)['exclusions'] as $entry) {
            $criterion = (string) ($entry['criterion'] ?? '');

            if ($criterion !== '') {
                $covered[$criterion] = true;
            }
        }

        return $covered;
    }

    /**
     * Splits stored entries into the three categories a statement renders under
     * their own headings.
     *
     * @param array<int, array<string, string>> $exclusions The stored entries.
     * @return array<string, array<int, array<string, string>>>
     */
    private function _groupExclusions(array $exclusions): array
    {
        $grouped = array_fill_keys(StatementExclusionModel::categories(), []);

        foreach ($exclusions as $entry) {
            $category = (string) ($entry['category'] ?? StatementExclusionModel::CATEGORY_NON_COMPLIANCE);

            if (!isset($grouped[$category])) {
                $category = StatementExclusionModel::CATEGORY_NON_COMPLIANCE;
            }

            $grouped[$category][] = $entry;
        }

        return $grouped;
    }

    /**
     * Creates the statement record for a site if it does not exist yet.
     *
     * @param int $siteId The site to ensure.
     * @return void
     * @throws Exception When the insert fails.
     * @throws \Exception
     */
    private function _ensureRecord(int $siteId): void
    {
        $exists = (new Query())
            ->from('{{%accessibilityaudit_statement}}')
            ->where(['siteId' => $siteId])
            ->exists();

        if (!$exists) {
            $this->_createRecord($siteId);
        }
    }

    /**
     * Inserts an empty statement record for a site.
     *
     * @param int $siteId The site to create for.
     * @return void
     * @throws Exception When the insert fails.
     * @throws \Exception
     */
    private function _createRecord(int $siteId): void
    {
        $now = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()
            ->insert('{{%accessibilityaudit_statement}}', [
                'siteId' => $siteId,
                'profile' => StatementProfiles::PROFILE_GENERIC,
                'meta' => null,
                'exclusions' => null,
                'sourceScanDate' => null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])
            ->execute();
    }
}
