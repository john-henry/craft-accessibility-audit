<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\console\controllers;

use Craft;
use craft\console\Controller;
use craft\errors\SiteNotFoundException;
use craft\helpers\DateTimeHelper;
use DateTime;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use yii\console\ExitCode;
use yii\db\Exception;
use yii\helpers\BaseConsole;

/**
 * VPAT console commands.
 *
 * The revision history is a record of documents that went out, and the control
 * panel deliberately only lets you take back the most recent one. These are
 * for the times that is not enough: a report tested to destruction before it
 * was ever issued, or one revision in the middle that was a mistake.
 *
 * Usage:
 *   php craft accessibility-audit/vpat/revisions
 *   php craft accessibility-audit/vpat/delete-revision --revision-id=12
 *   php craft accessibility-audit/vpat/clear-revisions
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.3.0
 */
class VpatController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The default action.
     */
    public $defaultAction = 'revisions';

    /**
     * @var string|null Site handle whose report to act on.
     */
    public ?string $site = null;

    /**
     * @var int|null The revision to remove. Not `$id`: Yii's own controller
     * property of that name is untyped, and redeclaring it with a type is
     * fatal.
     */
    public ?int $revisionId = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'revisions' => ['site'],
            'delete-revision' => ['revisionId', 'site'],
            'clear-revisions' => ['site'],
            default => [],
        });
    }

    /**
     * List the recorded revisions of the VPAT, newest first.
     *
     * @return int
     * @throws SiteNotFoundException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionRevisions(): int
    {
        $siteId = $this->resolveSiteId();
        $revisions = AccessibilityAudit::getInstance()->vpat->getRevisions($siteId);

        if (empty($revisions)) {
            $this->stdout("No revisions have been recorded for this site.\n", BaseConsole::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-8s %-22s %s\n", 'ID', 'RECORDED', 'ANSWERS'), BaseConsole::BOLD);

        foreach ($revisions as $revision) {
            $this->stdout(sprintf(
                "%-8d %-22s %d\n",
                $revision['id'],
                $this->recordedAt($revision['dateCreated']),
                $revision['answers'],
            ));
        }

        $this->stdout(sprintf(
            "\n%d revision(s). Remove one with --revision-id, or all of them with clear-revisions.\n",
            count($revisions),
        ));

        return ExitCode::OK;
    }

    /**
     * Remove one recorded revision by ID.
     *
     * @return int
     * @throws SiteNotFoundException
     * @throws Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function actionDeleteRevision(): int
    {
        if ($this->revisionId === null) {
            $this->stderr("Pass the revision to remove with --revision-id. Run `revisions` to list them.\n", BaseConsole::FG_RED);

            return ExitCode::USAGE;
        }

        $siteId = $this->resolveSiteId();
        $vpat = AccessibilityAudit::getInstance()->vpat;

        $revision = null;

        foreach ($vpat->getRevisions($siteId) as $candidate) {
            if ($candidate['id'] === $this->revisionId) {
                $revision = $candidate;
                break;
            }
        }

        if ($revision === null) {
            $this->stderr("No revision {$this->revisionId} on this site's report.\n", BaseConsole::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout(sprintf(
            "Revision %d, recorded %s.\n",
            $revision['id'],
            $this->recordedAt($revision['dateCreated']),
        ), BaseConsole::FG_YELLOW);

        // Removing one from the middle changes what the revisions on either
        // side are compared against, so the history around it is rewritten too.
        $this->stdout("The revisions on either side of it will be compared against each other instead.\n");

        if (!$this->confirmRemoval('Remove it?')) {
            $this->stdout("Cancelled. Nothing removed.\n");

            return ExitCode::OK;
        }

        $vpat->deleteRevision($this->revisionId, $siteId);
        $this->stdout("Removed.\n", BaseConsole::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Remove every recorded revision, leaving the report with no history.
     *
     * @return int
     * @throws SiteNotFoundException
     * @throws Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function actionClearRevisions(): int
    {
        $siteId = $this->resolveSiteId();
        $vpat = AccessibilityAudit::getInstance()->vpat;
        $count = $vpat->countRevisions($siteId);

        if ($count === 0) {
            $this->stdout("No revisions have been recorded for this site.\n", BaseConsole::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout("{$count} revision(s) will be removed, and the exported report will carry no history.\n", BaseConsole::FG_YELLOW);
        $this->stdout("The report itself, every answer and remark on it, is untouched.\n");

        if (!$this->confirmRemoval('Remove them all?')) {
            $this->stdout("Cancelled. Nothing removed.\n");

            return ExitCode::OK;
        }

        $removed = $vpat->deleteAllRevisions($siteId);
        $this->stdout("Removed {$removed} revision(s).\n", BaseConsole::FG_GREEN);

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * A stored date as it reads on screen, falling back to the raw value.
     *
     * DateTimeHelper::toDateTime() answers false rather than null on anything
     * it cannot read, so the check is for a DateTime rather than for null.
     *
     * @param string $date The stored date.
     * @return string
     */
    private function recordedAt(string $date): string
    {
        $value = DateTimeHelper::toDateTime($date);

        return $value instanceof DateTime ? $value->format('Y-m-d H:i:s') : $date;
    }

    /**
     * Confirmation prompt, honouring non-interactive runs (--interactive=0),
     * where it returns true.
     *
     * @param string $question What to ask.
     * @return bool
     */
    private function confirmRemoval(string $question): bool
    {
        if (!$this->interactive) {
            return true;
        }

        return $this->confirm($question, false);
    }

    /**
     * The site to act on, from --site, falling back to the primary site.
     *
     * @return int
     * @throws SiteNotFoundException
     * @throws \yii\base\InvalidConfigException
     */
    private function resolveSiteId(): int
    {
        // Multi-site is a Pro feature: --site is only honoured on Pro, so
        // Standard always operates on the primary site.
        if ($this->site) {
            if (!AccessibilityAudit::getInstance()->isPro()) {
                $this->stderr(
                    "Multi-site is a Pro feature; --site is ignored on the Standard edition.\n",
                    BaseConsole::FG_YELLOW
                );
            } else {
                $site = Craft::$app->getSites()->getSiteByHandle($this->site);

                if ($site) {
                    return $site->id;
                }
            }
        }

        return Craft::$app->getSites()->getPrimarySite()->id;
    }
}
