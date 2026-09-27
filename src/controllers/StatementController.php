<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use craft\web\View;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\StatementExclusionModel;
use johnhenry\accessibilityaudit\models\StatementMetaModel;
use johnhenry\accessibilityaudit\services\StatementProfiles;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * Manages the accessibility statement: jurisdiction profile, statement
 * metadata, and the non-accessible content entries.
 *
 * Gated on its own permission rather than the VPAT's. The two documents answer
 * to different audiences, and a public legal declaration is not necessarily
 * something everybody who edits a procurement report should be signing.
 *
 * Available on every edition: in the EU and UK a statement is legally required,
 * and putting a legal obligation behind an upgrade would be a poor way to treat
 * the people who most need it.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class StatementController extends Controller
{
    // Traits
    // =========================================================================

    use OrganisationMetaTrait;

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    // Public Methods
    // =========================================================================

    /**
     * Saves the statement metadata for a site.
     *
     * The posted form is flat; storage is split three ways (shared organisation
     * metadata, the statement's own fields, and the profile column), so both
     * models are validated before either is written.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws SiteNotFoundException
     * @throws \yii\db\Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionSaveMeta(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessibility-audit:manage-statement');

        $siteId = (int) $this->request->getRequiredBodyParam('siteId');

        if (($refusal = $this->_requireAllowedSite($siteId)) !== null) {
            return $refusal;
        }

        $shared = $this->organisationMetaFromRequest();

        $meta = new StatementMetaModel();
        $meta->profile = trim((string) $this->request->getBodyParam('profile', StatementProfiles::PROFILE_GENERIC));
        $meta->statusOverride = trim((string) $this->request->getBodyParam('statusOverride', ''));
        $meta->statementDate = $this->dateParamToYmd('statementDate');
        $meta->reviewDate = $this->dateParamToYmd('reviewDate');
        $meta->nextReviewDate = $this->dateParamToYmd('nextReviewDate');
        $meta->preparationMethod = trim((string) $this->request->getBodyParam('preparationMethod', StatementMetaModel::METHOD_SELF));
        $meta->preparedBy = trim((string) $this->request->getBodyParam('preparedBy', ''));
        $meta->enforcementBody = trim((string) $this->request->getBodyParam('enforcementBody', ''));
        $meta->enforcementUrl = trim((string) $this->request->getBodyParam('enforcementUrl', ''));
        $meta->enforcementNotes = trim((string) $this->request->getBodyParam('enforcementNotes', ''));
        $meta->feedbackResponseTime = trim((string) $this->request->getBodyParam('feedbackResponseTime', ''));
        $meta->commitmentOverride = trim((string) $this->request->getBodyParam('commitmentOverride', ''));
        $meta->manualReviewConfirmed = (bool) $this->request->getBodyParam('manualReviewConfirmed', false);

        // Entries post with the same form. Row adds/removes are server round-trips
        // since Craft's date field needs its own picker and locale formatting.
        // Read before anything is written, so a bad row stops the whole save
        // rather than leaving the meta stored against rejected entries.
        $entries = $this->_postedEntries();

        $sharedValid = $shared->validate();
        $metaValid = $meta->validate();
        $entryErrors = $this->_entryErrors($entries ?? []);

        if (!$sharedValid || !$metaValid || $entryErrors !== []) {
            // Flash the actual validation reason, not a generic "couldn't save",
            // so the editor isn't left hunting a long form for the bad field.
            $errors = array_merge($shared->getErrors(), $meta->getErrors());

            $this->setFailFlash(implode(' ', array_merge(
                ...array_values($errors),
                ...[$entryErrors],
            )));

            // Redirect back with the flash, not an empty response.
            return $this->redirectToPostedUrl();
        }

        $plugin = AccessibilityAudit::getInstance();
        $plugin->getOrganisation()->saveMeta($siteId, $shared);
        $plugin->getStatement()->saveMeta($siteId, $meta);

        if ($entries !== null) {
            $plugin->getStatement()->saveExclusions($siteId, $entries);
        }

        // Refused override isn't a validation error, the save is legitimate but
        // the claim is capped. Say so, or the page looks like it ignored the pick.
        $resolved = $plugin->getStatement()->resolveComplianceStatus($siteId);

        if ($resolved['refusedOverride']) {
            $this->setFailFlash(Craft::t(
                'accessibility-audit',
                'Saved, but the full compliance claim was not applied: criteria remain failing or unconfirmed.',
            ));
        } elseif ($this->request->getBodyParam('removeEntry') !== null) {
            // Removal rode in on a full form save; confirm the row, not the statement.
            $this->setSuccessFlash(Craft::t('accessibility-audit', 'Entry removed.'));
        } else {
            $this->setSuccessFlash(Craft::t('accessibility-audit', 'Statement saved.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Returns the suggested entries drawn from the current scan results.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws SiteNotFoundException
     * @throws \yii\db\Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionSuggestions(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessibility-audit:manage-statement');


        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();

        return $this->asJson([
            'success' => true,
            'suggestions' => $plugin->getStatement()->deriveSuggestions($siteId),
        ]);
    }

    /**
     * Renders the published statement on its own, so it can be checked before
     * anybody links to it.
     *
     * Goes through StatementService::render(), the same path the Twig variable
     * uses, so what is previewed is what is published. Gated on view-reports
     * rather than manage-statement: reading the document is not editing it.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws Exception When the statement cannot be rendered.
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionPreview(): Response
    {
        $this->requirePermission('accessibility-audit:view-reports');

        $plugin = AccessibilityAudit::getInstance();
        $siteId = $plugin->requestedSiteId();

        return $this->renderTemplate(
            'accessibility-audit/statement-preview',
            ['html' => $plugin->getStatement()->render($siteId)],
            View::TEMPLATE_MODE_CP,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Refuses a save that targets a site the current user may not edit. The
     * `manage-statement` permission is install-wide, so the per-site fence has to
     * be enforced here, or a Pro multi-site user could write a site outside
     * their permissions. The form posts a full page, so a refusal flashes and
     * redirects back rather than returning JSON. Returns the redirect refusal,
     * or null when the site is allowed.
     *
     * @param int $siteId The posted site ID.
     * @return Response|null
     * @throws SiteNotFoundException|BadRequestHttpException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.1
     */
    private function _requireAllowedSite(int $siteId): ?Response
    {
        if (AccessibilityAudit::getInstance()->isSiteAllowed($siteId)) {
            return null;
        }

        $this->setFailFlash(Craft::t('accessibility-audit', 'You do not have permission to edit that site.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * The non-accessible content entries posted with the form.
     *
     * Applies the Add and Remove buttons before validating, so a row can be
     * added or dropped without the editor losing what they had typed into the
     * others.
     *
     * @return StatementExclusionModel[]|null Null when the form posted no entries key.
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _postedEntries(): ?array
    {
        $rows = $this->request->getBodyParam('entries');

        if (!is_array($rows)) {
            // No entries key at all: leave the stored list alone, don't clear
            // it. Unless an add button was pressed, in which case fall through
            // with an empty list so the append logic below runs; the first
            // entry is added from exactly this state.
            if (
                $this->request->getBodyParam('addEntry') === null
                && $this->request->getBodyParam('addSuggestion') === null
            ) {
                return null;
            }

            $rows = [];
        }

        $rows = array_values($rows);

        $removed = $this->request->getBodyParam('removeEntry');

        if ($removed !== null) {
            unset($rows[(int) $removed]);
            $rows = array_values($rows);
        }

        $entries = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Don't parse the localised date string by hand: a mismatched locale
            // would silently read 07/08 as the wrong month.
            $date = DateTimeHelper::toDateTime($row['plannedDate'] ?? '');
            $row['plannedDate'] = $date !== false ? $date->format('Y-m-d') : '';

            $entries[] = StatementExclusionModel::fromArray($row);
        }

        if ($this->request->getBodyParam('addEntry') !== null) {
            $entries[] = new StatementExclusionModel();
        }

        $suggested = $this->request->getBodyParam('addSuggestion');

        if ($suggested !== null) {
            $entries[] = $this->_entryFromSuggestion((string) $suggested);
        }

        return $entries;
    }

    /**
     * The validation messages for the posted entries, one flat list.
     *
     * A row nobody has typed into yet is skipped. The form adds rows on the
     * server, so a blank one rides along with every save from the moment Add is
     * pressed, and holding it to the rules would turn Add into an error.
     *
     * Entries are checked here and not left to the model, because they are
     * stored as a JSON list rather than rows of their own and nothing else
     * stands between the request and the published statement. The category in
     * particular decides which of the three legal headings an entry appears
     * under, and an unrecognised one is published as a non-compliance: an
     * admission of failure where the editor claimed an exemption.
     *
     * @param StatementExclusionModel[] $entries The posted entries.
     * @return string[] The messages, empty when every entry is acceptable.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _entryErrors(array $entries): array
    {
        $messages = [];

        foreach ($entries as $position => $entry) {
            if ($entry->isBlank() || $entry->validate()) {
                continue;
            }

            foreach ($entry->getErrorSummary(true) as $message) {
                $messages[] = Craft::t('accessibility-audit', 'Entry {number}: {message}', [
                    'number' => $position + 1,
                    'message' => $message,
                ]);
            }
        }

        return $messages;
    }

    /**
     * Builds a pre-filled entry from a scan suggestion.
     *
     * Pre-filled, not published: the wording is the editor's to rewrite, since
     * a criterion name means nothing to the public who read this document.
     *
     * @param string $criterion The WCAG criterion number.
     * @return StatementExclusionModel
     * @throws SiteNotFoundException
     * @throws \yii\db\Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _entryFromSuggestion(string $criterion): StatementExclusionModel
    {
        $plugin = AccessibilityAudit::getInstance();

        foreach ($plugin->getStatement()->deriveSuggestions($plugin->requestedSiteId()) as $suggestion) {
            if ($suggestion['criterion'] !== $criterion) {
                continue;
            }

            return StatementExclusionModel::fromArray([
                'category' => StatementExclusionModel::CATEGORY_NON_COMPLIANCE,
                // Left for the author. The field asks what a member of the
                // public would recognise, and a criterion name ("On Input") is
                // the opposite of that: it names the rule, not the thing on the
                // page somebody cannot use.
                'content' => '',
                // What the scan actually established, and nothing beyond it.
                // The criterion's own wording states the condition for passing,
                // so putting it here would publish a description of the site
                // behaving correctly underneath a heading that says it does
                // not. This document carries legal weight; it says what is
                // known and leaves the rest to a person.
                'reason' => Craft::t(
                    'accessibility-audit',
                    'Automated testing found {count} issues affecting this criterion.',
                    ['count' => (int)$suggestion['occurrences']],
                ),
                'criterion' => $criterion,
            ]);
        }

        return StatementExclusionModel::fromArray(['criterion' => $criterion, 'content' => '']);
    }
}
