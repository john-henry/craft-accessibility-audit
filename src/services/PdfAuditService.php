<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\errors\SiteNotFoundException;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\Queue as QueueHelper;
use craft\helpers\StringHelper;
use DateTime;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\jobs\CheckPdf;
use johnhenry\accessibilityaudit\models\PdfCheckResult;
use Throwable;
use yii\base\Component;
use yii\db\Exception;
use yii\db\Expression;

/**
 * Keeps the stored PDF audit: which PDFs were checked, what they fail, and
 * which could not be checked and why.
 *
 * The findings share the asset issues table with the image alt-text audit,
 * under the PDF rule ids; the check row per file holds the rest. PDF figures
 * never reach the site score or the image counts: they are reported on the
 * Assets screen alone.
 *
 * Standard and Pro only. On Lite nothing is queued, checked or listed.
 *
 * @phpstan-type CheckRow array{status: string, reason: string|null, hasForm: bool, meta: array<string, mixed>, findings: array<string, array<string, mixed>>, dateChecked: string|null}
 * @phpstan-type FailingCheck array{ruleId: string, label: string, desc: string, advice: string, severity: string, wcag: string|null, level: string|null, helpUrl: string|null, finding: array<string, mixed>}
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class PdfAuditService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The check rows.
     */
    public const TABLE = '{{%accessibilityaudit_pdf_checks}}';

    /**
     * @var string The Assets screen filter that opens the PDFs tab.
     */
    public const FILTER_ALL = 'pdf';

    /**
     * @var string An older Assets screen filter for the PDFs that could not be
     * checked, still honoured so saved links land on that list.
     */
    public const FILTER_NOT_CHECKED = 'pdf-not-checked';

    /**
     * @var string The PDF listing for checked files that fail something.
     */
    public const LIST_NEEDS_FIXING = 'needs-fixing';

    /**
     * @var string The PDF listing for files that could not be checked.
     */
    public const LIST_NOT_CHECKED = 'not-checked';

    /**
     * @var string The PDF listing for checked files that fail nothing.
     */
    public const LIST_PASSED = 'passed';

    /**
     * @var string The PDF listing's default: every file with a problem, those that
     *      fail a check and those that could not be checked. The whole library is
     *      Craft's own asset index.
     */
    public const LIST_ISSUES = 'issues';

    /**
     * @var string What the Issues listing was called before it left out the PDFs
     *      that pass, still honoured so saved links land on it.
     */
    public const LIST_ALL_LEGACY = 'all';

    /**
     * @var string[] Every PDF listing, in the order the sources offer them.
     */
    public const LISTS = [self::LIST_ISSUES, self::LIST_NEEDS_FIXING, self::LIST_NOT_CHECKED, self::LIST_PASSED];

    /**
     * @var string The findings, shared with the image audit.
     */
    private const ISSUES = '{{%accessibilityaudit_asset_issues}}';

    /**
     * @var array<string, int> The order failing checks are shown in, by severity.
     */
    private const SEVERITY_RANK = ['error' => 0, 'warning' => 1, 'review' => 2, 'notice' => 3];

    // Public Methods
    // =========================================================================

    /**
     * Whether PDF checks run on this install: Standard or higher, with the
     * setting on.
     *
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function isEnabled(): bool
    {
        $plugin = AccessibilityAudit::getInstance();

        return $plugin->isStandard() && $plugin->getSettings()->pdfChecks;
    }

    /**
     * Every PDF the audit covers, as an unexecuted query, with the same
     * exclusions as the image library: excluded volumes, and Craft's
     * temporary uploads, which belong to no volume.
     *
     * @param string|null $volume A volume handle to restrict to.
     * @param string|null $search A partial filename to filter by.
     * @return AssetQuery<int, Asset>
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function pdfQuery(?string $volume = null, ?string $search = null): AssetQuery
    {
        $query = AssetScanner::anySiteQuery()->kind(Asset::KIND_PDF);
        $query->andWhere(['not', ['volumeId' => null]]);

        $excludedIds = AccessibilityAudit::getInstance()->getAssets()->excludedVolumeIds();

        if ($excludedIds !== []) {
            $query->andWhere(['not', ['volumeId' => $excludedIds]]);
        }

        if ($volume !== null && $volume !== '') {
            $query->volume($volume);
        }

        $search = trim((string)$search);

        if ($search !== '') {
            $query->filename('*' . $search . '*');
        }

        return $query;
    }

    /**
     * Whether an asset is a PDF the audit covers.
     *
     * @param Asset $asset The asset.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function covers(Asset $asset): bool
    {
        if ($asset->kind !== Asset::KIND_PDF || $asset->id === null || $asset->volumeId === null) {
            return false;
        }

        return !in_array((int)$asset->volumeId, AccessibilityAudit::getInstance()->getAssets()->excludedVolumeIds(), true);
    }

    /**
     * Whether a PDF has to be checked: never checked, checked under an older
     * rule set, or a different file from the one checked (Replace file keeps
     * the asset and changes its size or date).
     *
     * @param Asset $asset The asset.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function needsCheck(Asset $asset): bool
    {
        $row = (new Query())
            ->select(['fileSize', 'fileModified', 'ruleSetVersion'])
            ->from(self::TABLE)
            ->where(['assetId' => $asset->id])
            ->one();

        if (!$row) {
            return true;
        }

        if ((int)$row['ruleSetVersion'] < PdfChecker::RULE_SET_VERSION) {
            return true;
        }

        if ((int)$row['fileSize'] !== (int)$asset->size) {
            return true;
        }

        return $row['fileModified'] !== ($asset->dateModified !== null ? Db::prepareDateForDb($asset->dateModified) : null);
    }

    /**
     * Queues a check of one PDF where one is due.
     *
     * @param Asset $asset The asset.
     * @param bool $force Whether to check it even when nothing has changed.
     * @return bool Whether a check was queued.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function queueCheck(Asset $asset, bool $force = false): bool
    {
        if (!$this->isEnabled() || !$this->covers($asset)) {
            return false;
        }

        if (!$force && !$this->needsCheck($asset)) {
            return false;
        }

        QueueHelper::push(new CheckPdf(['assetId' => (int)$asset->id, 'force' => $force]), ttr: CheckPdf::ttr());

        return true;
    }

    /**
     * Checks one PDF and stores what was found.
     *
     * The size cap is applied to the asset's recorded size before anything is
     * downloaded. The file is copied to a local temp file, which matters on
     * S3 and other remote volumes, and the copy is deleted whatever happens.
     * The browser layer runs where Chrome is reachable and the PHP layer did
     * not refuse the file outright.
     *
     * @param Asset $asset The asset.
     * @return PdfCheckResult
     * @throws Exception
     * @throws Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function checkAsset(Asset $asset): PdfCheckResult
    {
        $plugin = AccessibilityAudit::getInstance();
        $maxBytes = PdfChecker::clampMaxBytes($plugin->getSettings()->pdfMaxBytes);

        if ((int)$asset->size > $maxBytes) {
            $result = PdfCheckResult::notChecked(PdfChecker::REASON_TOO_LARGE, ['bytes' => (int)$asset->size]);
            $this->store($asset, $result);

            return $result;
        }

        $result = PdfCheckResult::notChecked(PdfChecker::REASON_FETCH_FAILED);
        $path = null;

        try {
            $path = $asset->getCopyOfFile();
            $result = $plugin->getPdf()->check($path, (string)$asset->filename, $maxBytes);
            $headless = $plugin->getHeadless();

            if (($result->isChecked() || $result->reason === PdfChecker::REASON_DAMAGED) && $headless->isAvailable()) {
                $result = $plugin->getPdf()->applyBrowserLayer($result, $headless->checkPdf($path), (string)$asset->filename);
            }
        } catch (Throwable $e) {
            Craft::warning("PDF check: could not read asset {$asset->id}: " . $e->getMessage(), 'accessibility-audit');
        } finally {
            if ($path !== null && is_file($path)) {
                FileHelper::unlink($path);
            }
        }

        $this->store($asset, $result);

        return $result;
    }

    /**
     * Writes one check's outcome: the findings that apply, the removal of the
     * ones that no longer do, and the check row, in one transaction. Apart, a
     * failure between them leaves a file listed with fewer failures than it
     * has.
     *
     * @param Asset $asset The asset.
     * @param PdfCheckResult $result What the check found.
     * @throws Exception
     * @throws Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function store(Asset $asset, PdfCheckResult $result): void
    {
        $db = Craft::$app->getDb();
        $ruleIds = array_keys($result->findings);
        $assetId = (int)$asset->id;

        $db->transaction(function() use ($db, $asset, $assetId, $result, $ruleIds): void {
            $now = Db::prepareDateForDb(new DateTime());
            $condition = ['assetId' => $assetId];

            if ($ruleIds !== []) {
                $condition = ['and', $condition, ['not', ['ruleId' => $ruleIds]]];
            }

            $db->createCommand()->delete(self::ISSUES, $condition)->execute();

            foreach ($ruleIds as $ruleId) {
                $db->createCommand()->upsert(self::ISSUES, [
                    'assetId' => $assetId,
                    'ruleId' => $ruleId,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ], [
                    'dateUpdated' => $now,
                ])->execute();
            }

            $row = [
                'fileSize' => (int)$asset->size,
                'fileModified' => $asset->dateModified !== null ? Db::prepareDateForDb($asset->dateModified) : null,
                'status' => $result->status,
                'reason' => $result->reason,
                'ruleSetVersion' => PdfChecker::RULE_SET_VERSION,
                'hasForm' => $result->hasForm,
                'meta' => Json::encode($result->meta + ['findings' => $result->findings]),
                'dateChecked' => $now,
                'dateUpdated' => $now,
            ];

            $db->createCommand()->upsert(self::TABLE, $row + [
                'assetId' => $assetId,
                'dateCreated' => $now,
                'uid' => StringHelper::UUID(),
            ], $row)->execute();
        });
    }

    /**
     * One PDF's stored check, or null where it has never been checked.
     *
     * @param int $assetId The asset's id.
     * @return CheckRow|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function getCheck(int $assetId): ?array
    {
        return $this->_checkRows([$assetId])[$assetId] ?? null;
    }

    /**
     * Reads the Assets screen's query params as the PDF listing they ask for,
     * or null where they ask for the images instead.
     *
     * Older links still land where they used to: a rule id given as the
     * filter narrows to that problem, and FILTER_NOT_CHECKED opens the files
     * that could not be checked. A status or problem given explicitly wins
     * over what the old filter implied; one that isn't known is dropped.
     *
     * @param string $filter The `filter` param.
     * @param string $status The `status` param, one of LISTS.
     * @param string $problem The `problem` param, a PDF rule id.
     * @return array{status: string, problem: string|null}|null
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function resolveListing(string $filter, string $status = '', string $problem = ''): ?array
    {
        $ruleFilter = isset(PdfChecker::RULES[$filter]);

        if (!$ruleFilter && $filter !== self::FILTER_ALL && $filter !== self::FILTER_NOT_CHECKED) {
            return null;
        }

        if ($status === self::LIST_ALL_LEGACY) {
            $status = self::LIST_ISSUES;
        }

        if (!in_array($status, self::LISTS, true)) {
            $status = match (true) {
                $filter === self::FILTER_NOT_CHECKED => self::LIST_NOT_CHECKED,
                $ruleFilter => self::LIST_NEEDS_FIXING,
                default => self::LIST_ISSUES,
            };
        }

        if (!isset(PdfChecker::RULES[$problem])) {
            $problem = $ruleFilter ? $filter : '';
        }

        return ['status' => $status, 'problem' => $problem !== '' ? $problem : null];
    }

    /**
     * One page of the PDF listing, a row per document.
     *
     * Sorted in the database, so a page costs the same however large the
     * library: forms first, since they are what people most often have to
     * fill in, then the most errors, then the most warnings, then by
     * filename. The counts come from the stored findings, by the severity
     * PdfChecker::RULES gives each rule.
     *
     * @param int $page The 1-based page.
     * @param int $perPage PDFs per page.
     * @param string|null $volume A volume handle to restrict to.
     * @param string|null $search A partial filename to filter by.
     * @param string $status One of LISTS.
     * @param string|null $problem A PDF rule id the files must fail.
     * @return array{results: array<int, array{asset: Asset, check: CheckRow|null, failing: array<int, FailingCheck>}>, total: int, page: int, perPage: int, totalPages: int}
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function listPaged(int $page = 1, int $perPage = 50, ?string $volume = null, ?string $search = null, string $status = self::LIST_ISSUES, ?string $problem = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $query = $this->_listingQuery($volume, $search, $status, $problem);
        $total = (int)(clone $query)->count();

        /** @var Asset[] $assets */
        $assets = $query->offset(($page - 1) * $perPage)->limit($perPage)->all();
        $rows = $this->_checkRows(array_map(static fn(Asset $asset): int => (int)$asset->id, $assets));
        $results = [];

        foreach ($assets as $asset) {
            $check = $rows[(int)$asset->id] ?? null;
            $results[] = [
                'asset' => $asset,
                'check' => $check,
                'failing' => $check !== null ? $this->failingChecks($check) : [],
            ];
        }

        return [
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * The checks a stored PDF fails, with the words the screen shows for
     * each: errors first, then warnings, then those that need a look, each
     * group in PdfChecker::RULES order.
     *
     * @param CheckRow $check A stored check.
     * @return array<int, FailingCheck>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function failingChecks(array $check): array
    {
        $texts = PdfChecker::ruleTexts();
        $failing = [];

        foreach (PdfChecker::RULES as $ruleId => $rule) {
            if (!array_key_exists($ruleId, $check['findings'])) {
                continue;
            }

            $failing[] = [
                'ruleId' => $ruleId,
                'label' => $texts[$ruleId]['label'],
                'desc' => $texts[$ruleId]['desc'],
                'advice' => $texts[$ruleId]['advice'],
                'severity' => $rule['severity'],
                'wcag' => $rule['wcag'],
                'level' => $rule['level'],
                'helpUrl' => $rule['helpUrl'],
                'finding' => (array)$check['findings'][$ruleId],
            ];
        }

        // usort() is stable, so each severity keeps the rule order.
        usort($failing, static fn(array $a, array $b): int
            => self::SEVERITY_RANK[$a['severity']] <=> self::SEVERITY_RANK[$b['severity']]);

        return $failing;
    }

    /**
     * How many checks a stored PDF passed: every rule that applies to it,
     * less the ones it fails. The structure rules only apply to a tagged
     * file, so an untagged one is not credited with passing them.
     *
     * @param CheckRow $check A stored check.
     * @return int Zero for a file that could not be checked.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function passedCount(array $check): int
    {
        if ($check['status'] !== PdfChecker::STATUS_CHECKED) {
            return 0;
        }

        $tagged = ($check['meta']['tagged'] ?? null) !== false && !array_key_exists('pdf-untagged', $check['findings']);
        $applies = array_filter(PdfChecker::RULES, static fn(array $rule): bool => $tagged || !$rule['structure']);

        return max(0, count($applies) - count(array_intersect_key($check['findings'], $applies)));
    }

    /**
     * The options for the Problem filter: each rule at least one PDF in the
     * library fails, in PdfChecker::RULES order, with how many fail it. The
     * selected rule stays in the list at a count of zero, so the filter
     * still shows what the listing is narrowed to.
     *
     * @param array<string, int> $byRule Failing PDFs per rule, from getStats().
     * @param string|null $selected The rule the listing is narrowed to.
     * @return array<string, array{label: string, count: int}> Keyed by rule id.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function problemOptions(array $byRule, ?string $selected = null): array
    {
        $texts = PdfChecker::ruleTexts();
        $options = [];

        foreach (array_keys(PdfChecker::RULES) as $ruleId) {
            $count = (int)($byRule[$ruleId] ?? 0);

            if ($count > 0 || $ruleId === $selected) {
                $options[$ruleId] = ['label' => $texts[$ruleId]['label'], 'count' => $count];
            }
        }

        return $options;
    }

    /**
     * What to do about each reason a file could not be checked, for the PDF
     * detail page.
     *
     * @return array<string, string> Keyed by reason.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function reasonAdvice(): array
    {
        return [
            PdfChecker::REASON_PASSWORD => Craft::t('accessibility-audit', 'Remove the password to open it, or put the content on a web page instead.'),
            PdfChecker::REASON_XFA => Craft::t('accessibility-audit', 'Rebuild it as an ordinary PDF form, or better again as a form on the website.'),
            PdfChecker::REASON_DAMAGED => Craft::t('accessibility-audit', 'Export it again from the program that made it, replace the file, and check it again.'),
            PdfChecker::REASON_NOT_PDF => Craft::t('accessibility-audit', 'Replace it with the real PDF, or give the file a name that matches what it holds.'),
            PdfChecker::REASON_TOO_LARGE => Craft::t('accessibility-audit', 'Make the file smaller, or raise Largest PDF to Check under Settings, then check it again.'),
            PdfChecker::REASON_TOO_MANY_OBJECTS => Craft::t('accessibility-audit', 'Check it by hand with a tool such as Acrobat’s accessibility checker, or put the content on a web page.'),
            PdfChecker::REASON_TIMED_OUT => Craft::t('accessibility-audit', 'Check it again when the queue is quieter. If it keeps timing out, check it by hand with a tool such as Acrobat’s accessibility checker.'),
            PdfChecker::REASON_FETCH_FAILED => Craft::t('accessibility-audit', 'Make sure the file is still on its volume and the volume can be reached, then check it again.'),
        ];
    }

    /**
     * Everything the PDF detail page shows about one document: its stored
     * check, the checks it fails, how many it passed, and, where it could not
     * be checked, why and what to do instead.
     *
     * @param Asset $asset The PDF.
     * @return array{check: CheckRow|null, failing: array<int, FailingCheck>, passed: int, reason: array{label: string, desc: string, advice: string}|null}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function detail(Asset $asset): array
    {
        $check = $this->getCheck((int)$asset->id);

        if ($check === null) {
            return ['check' => null, 'failing' => [], 'passed' => 0, 'reason' => null];
        }

        $reason = null;

        if ($check['status'] === PdfChecker::STATUS_NOT_CHECKED) {
            $texts = PdfChecker::reasonTexts();
            $key = isset($texts[(string)$check['reason']]) ? (string)$check['reason'] : PdfChecker::REASON_FETCH_FAILED;
            $reason = $texts[$key] + ['advice' => self::reasonAdvice()[$key]];
        }

        return [
            'check' => $check,
            'failing' => $this->failingChecks($check),
            'passed' => $this->passedCount($check),
            'reason' => $reason,
        ];
    }

    /**
     * Counts across the audited PDFs: how many there are, how many were
     * checked, how many fail something, how many pass everything, how many
     * could not be checked and why, per rule, and when the last check ran.
     *
     * Trashed assets and excluded volumes are left out, the same as the image
     * counts.
     *
     * @return array{total: int, checked: int, withIssues: int, passed: int, notChecked: int, byRule: array<string, int>, byReason: array<string, int>, lastChecked: string|null}
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function getStats(): array
    {
        $live = function(string $table, string $alias): Query {
            $query = (new Query())
                ->from([$alias => $table])
                ->innerJoin(['a' => '{{%assets}}'], "[[a.id]] = [[{$alias}.assetId]]")
                ->innerJoin(['e' => '{{%elements}}'], "[[e.id]] = [[{$alias}.assetId]]")
                ->where(['a.kind' => Asset::KIND_PDF, 'e.dateDeleted' => null])
                ->andWhere(['not', ['a.volumeId' => null]]);
            $excluded = AccessibilityAudit::getInstance()->getAssets()->excludedVolumeIds();

            if ($excluded !== []) {
                $query->andWhere(['not', ['a.volumeId' => $excluded]]);
            }

            return $query;
        };

        $byStatus = $live(self::TABLE, 'pc')
            ->select(['pc.status', 'COUNT(*) as n'])
            ->groupBy(['pc.status'])
            ->pairs();

        $byReason = $live(self::TABLE, 'pc')
            ->select(['pc.reason', 'COUNT(*) as n'])
            ->andWhere(['pc.status' => PdfChecker::STATUS_NOT_CHECKED])
            ->groupBy(['pc.reason'])
            ->pairs();

        $issues = $live(self::ISSUES, 'ai')->andWhere(['ai.ruleId' => array_keys(PdfChecker::RULES)]);
        $byRule = (clone $issues)->select(['ai.ruleId', 'COUNT(*) as n'])->groupBy(['ai.ruleId'])->pairs();
        $lastChecked = $live(self::TABLE, 'pc')->max('[[pc.dateChecked]]');
        $passed = $live(self::TABLE, 'pc')
            ->andWhere(['pc.status' => PdfChecker::STATUS_CHECKED])
            ->andWhere(['not', ['pc.assetId' => (new Query())
                ->select(['assetId'])
                ->from(self::ISSUES)
                ->where(['ruleId' => array_keys(PdfChecker::RULES)]),
            ]])
            ->count();

        return [
            'total' => (int)$this->pdfQuery()->count(),
            'checked' => (int)($byStatus[PdfChecker::STATUS_CHECKED] ?? 0),
            'withIssues' => (int)(clone $issues)->count('DISTINCT [[ai.assetId]]'),
            'passed' => (int)$passed,
            'notChecked' => (int)($byStatus[PdfChecker::STATUS_NOT_CHECKED] ?? 0),
            'byRule' => array_map('intval', $byRule),
            'byReason' => array_map('intval', $byReason),
            'lastChecked' => is_string($lastChecked) ? $lastChecked : null,
        ];
    }

    /**
     * Removes the stored checks for the PDFs in the given volumes, for when a
     * volume is newly excluded. Findings in asset issues are cleared by the
     * image audit's own volume clear, which covers every asset kind.
     *
     * @param int[] $volumeIds The volumes' ids.
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function clearForVolumes(array $volumeIds): void
    {
        $volumeIds = array_values(array_filter(array_map('intval', $volumeIds)));

        if ($volumeIds === []) {
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['assetId' => (new Query())->select(['id'])->from('{{%assets}}')->where(['volumeId' => $volumeIds])])
            ->execute();
    }

    // Private Methods
    // =========================================================================

    /**
     * The PDF listing as an unexecuted query, filtered and sorted.
     *
     * The check rows and the per-file severity counts are joined in, so the
     * status filter and the sort run in the database. A file never checked
     * has no check row, so no listing includes it until its check has run.
     *
     * @param string|null $volume A volume handle to restrict to.
     * @param string|null $search A partial filename to filter by.
     * @param string $status One of LISTS; anything else reads as LIST_NEEDS_FIXING.
     * @param string|null $problem A PDF rule id the files must fail.
     * @return AssetQuery<int, Asset>
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _listingQuery(?string $volume, ?string $search, string $status, ?string $problem): AssetQuery
    {
        $query = $this->pdfQuery($volume, $search)
            ->leftJoin(['pc' => self::TABLE], '[[pc.assetId]] = [[elements.id]]')
            ->leftJoin(['pf' => $this->_severityCounts()], '[[pf.assetId]] = [[elements.id]]')
            ->orderBy([
                'CASE WHEN [[pc.hasForm]] THEN 1 ELSE 0 END' => SORT_DESC,
                'COALESCE([[pf.errors]], 0)' => SORT_DESC,
                'COALESCE([[pf.warnings]], 0)' => SORT_DESC,
                'assets.filename' => SORT_ASC,
                'elements.id' => SORT_ASC,
            ]);

        $condition = match ($status) {
            self::LIST_ISSUES => ['or',
                ['pc.status' => PdfChecker::STATUS_NOT_CHECKED],
                ['and', ['pc.status' => PdfChecker::STATUS_CHECKED], ['not', ['pf.assetId' => null]]],
            ],
            self::LIST_NOT_CHECKED => ['pc.status' => PdfChecker::STATUS_NOT_CHECKED],
            self::LIST_PASSED => ['pc.status' => PdfChecker::STATUS_CHECKED, 'pf.assetId' => null],
            default => ['and', ['pc.status' => PdfChecker::STATUS_CHECKED], ['not', ['pf.assetId' => null]]],
        };

        $query->andWhere($condition);

        if ($problem !== null && isset(PdfChecker::RULES[$problem])) {
            $query->andWhere(['elements.id' => (new Query())
                ->select(['assetId'])
                ->from(self::ISSUES)
                ->where(['ruleId' => $problem]),
            ]);
        }

        return $query;
    }

    /**
     * Each PDF's stored failures counted by severity, one row per file that
     * fails anything, for the listing to join and sort on.
     *
     * The rule ids are PdfChecker constants, not input, so they are quoted
     * straight into the sums.
     *
     * @return Query<int, array<string, mixed>> The unexecuted query.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _severityCounts(): Query
    {
        $db = Craft::$app->getDb();
        $sum = static function(string $severity) use ($db): Expression {
            $ruleIds = array_keys(array_filter(
                PdfChecker::RULES,
                static fn(array $rule): bool => $rule['severity'] === $severity,
            ));

            if ($ruleIds === []) {
                return new Expression('0');
            }

            $list = implode(', ', array_map(static fn(string $ruleId): string => $db->quoteValue($ruleId), $ruleIds));

            return new Expression("SUM(CASE WHEN [[ruleId]] IN ($list) THEN 1 ELSE 0 END)");
        };

        return (new Query())
            ->select([
                'assetId',
                'errors' => $sum('error'),
                'warnings' => $sum('warning'),
            ])
            ->from(self::ISSUES)
            ->where(['ruleId' => array_keys(PdfChecker::RULES)])
            ->groupBy(['assetId']);
    }

    /**
     * The stored check rows for a set of assets, decoded.
     *
     * @param int[] $assetIds The assets' ids.
     * @return array<int, CheckRow> Keyed by asset id.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _checkRows(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $rows = [];

        foreach ((new Query())->from(self::TABLE)->where(['assetId' => $assetIds])->all() as $row) {
            $meta = Json::decodeIfJson((string)($row['meta'] ?? ''));
            $meta = is_array($meta) ? $meta : [];
            $findings = is_array($meta['findings'] ?? null) ? $meta['findings'] : [];
            unset($meta['findings']);

            $rows[(int)$row['assetId']] = [
                'status' => (string)$row['status'],
                'reason' => $row['reason'] !== null ? (string)$row['reason'] : null,
                'hasForm' => (bool)$row['hasForm'],
                'meta' => $meta,
                'findings' => $findings,
                'dateChecked' => $row['dateChecked'] !== null ? (string)$row['dateChecked'] : null,
            ];
        }

        return $rows;
    }
}
