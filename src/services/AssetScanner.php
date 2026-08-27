<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\models\IssueModel;
use Throwable;
use yii\base\Component;
use yii\db\Exception;

/**
 * Scans Craft Asset elements for accessibility issues.
 *
 * @property-read null|array $storedAssetStats
 * @property-read int[] $siteAssetStats
 */
class AssetScanner extends Component
{
    /**
     * @var int Libraries larger than this skip the live whole-library count
     * when a custom alt field is in use and no stored sweep exists: counting
     * would mean loading every image element in one request. The queued
     * sweep is the answer at that scale.
     */
    public const LIVE_COUNT_LIMIT = 2000;

    /** @return IssueModel[] */
    public function scanAsset(Asset $asset): array
    {
        $issues = [];

        if (!in_array($asset->kind, [Asset::KIND_IMAGE, Asset::KIND_PDF], true)) {
            return [];
        }

        // An excluded volume's images are left out of the audit entirely: raise
        // nothing for them, so a save in an excluded volume stores no findings.
        if ($this->_isExcludedVolume($asset)) {
            return [];
        }

        if ($asset->kind === Asset::KIND_IMAGE) {
            // Single asset, so a single indexed lookup: no loop to batch here.
            $issues = array_merge($issues, $this->checkImageAlt($asset, $this->isDecorative((int)$asset->id)));
        }

        if ($asset->kind === Asset::KIND_PDF) {
            $issues[] = IssueModel::make(
                'pdf-accessibility', 'notice',
                'PDF files are often inaccessible. Ensure this PDF has been tagged for accessibility or provide an accessible HTML alternative.',
                '1.1.1', 'A',
                $asset->filename,
                'https://www.w3.org/WAI/WCAG22/Understanding/non-text-content'
            );
        }

        return $issues;
    }

    /** @return array{asset: Asset, issues: IssueModel[]}[] */
    public function scanAllImages(): array
    {
        $results = [];

        $query = Asset::find()->kind(Asset::KIND_IMAGE);
        // Resolved once for the whole scan, never per asset in the loop below.
        $excludedIds = $this->excludedVolumeIds();
        if (!empty($excludedIds)) {
            $query->andWhere(['not', ['volumeId' => $excludedIds]]);
        }

        $assets = $query->all();

        // Batch-load the decorative set once for the whole page rather than
        // querying per asset inside the loop.
        $decorative = array_flip($this->decorativeAssetIds(array_map(
            static fn(Asset $asset): int => (int)$asset->id,
            $assets,
        )));

        foreach ($assets as $asset) {
            $issues = $this->checkImageAlt($asset, isset($decorative[(int)$asset->id]));
            if (!empty($issues)) {
                $results[] = ['asset' => $asset, 'issues' => $issues];
            }
        }

        return $results;
    }

    /**
     * Scans a single page of image assets for alt-text issues.
     *
     * Loading every image on every render of the Assets tab OOMs / times out on
     * large media libraries, so the CP paginates through this method instead.
     *
     * @param int $page The 1-based page number.
     * @param int $perPage The number of image assets to scan per page.
     * @param string|null $volume A volume handle to restrict the scan to, or
     *                            null/empty for every volume.
     * @param string|null $search A partial filename to filter by, or null/empty
     *                            for no filename filter.
     * @param string|null $ruleId A single asset rule id to restrict the rows
     *                            (and each row's issues) to, or null/empty for
     *                            every asset rule.
     * @return array{results: array{asset: Asset, issues: IssueModel[], currentAlt: string, decorative: bool}[], total: int, page: int, perPage: int, totalPages: int}
     */
    public function scanImagesPaged(int $page = 1, int $perPage = 100, ?string $volume = null, ?string $search = null, ?string $ruleId = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $query = Asset::find()->kind(Asset::KIND_IMAGE);
        // Excluded volumes drop out of the live listing and its total, resolved
        // once here rather than per asset.
        $excludedIds = $this->excludedVolumeIds();
        if (!empty($excludedIds)) {
            $query->andWhere(['not', ['volumeId' => $excludedIds]]);
        }
        if ($volume !== null && $volume !== '') {
            $query->volume($volume);
        }
        // Filename filter: a partial match on the stored filename, so a large
        // library can be narrowed to one image without paging through it all.
        $search = $search !== null ? trim($search) : '';
        if ($search !== '') {
            $query->filename('*' . $search . '*');
        }

        // Restrict to assets with a stored alt-text issue, optionally of one
        // rule, so a filter chip's count matches the rows it shows across the
        // whole library. `?: [0]` forces an empty match when nothing qualifies.
        $issueQuery = (new Query())
            ->select('assetId')
            ->distinct()
            ->from('{{%accessibilityaudit_asset_issues}}');
        $ruleId = $ruleId !== null ? trim($ruleId) : '';
        if ($ruleId !== '') {
            $issueQuery->where(['ruleId' => $ruleId]);
        }
        $query->id($issueQuery->column() ?: [0]);

        $total = (int) $query->count();

        $assets = (clone $query)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        // Batch-load the decorative set for this page in one query, so a
        // library paged through 100 at a time never queries per asset.
        $decorative = array_flip($this->decorativeAssetIds(array_map(
            static fn(Asset $asset): int => (int)$asset->id,
            $assets,
        )));

        $results = [];
        foreach ($assets as $asset) {
            $issues = $this->checkImageAlt($asset, isset($decorative[(int)$asset->id]));
            // Under a rule filter, show only that rule's issue on each row, so
            // the view matches the chip the user clicked.
            if ($ruleId !== '') {
                $issues = array_values(array_filter(
                    $issues,
                    static fn(IssueModel $issue): bool => $issue->ruleId === $ruleId,
                ));
            }
            if (!empty($issues)) {
                // Resolve the alt from the configured field (not always the
                // native `alt`), so the dashboard editor pre-fills and rewrites
                // the same field the scanner read and the save writes.
                $results[] = [
                    'asset' => $asset,
                    'issues' => $issues,
                    'currentAlt' => $this->getAltText($asset) ?? '',
                    'decorative' => isset($decorative[(int)$asset->id]),
                ];
            }
        }

        return [
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Lists a single page of image assets currently marked decorative, in the
     * same shape as scanImagesPaged() so the Assets page renders them as
     * ordinary image rows. A marked image drops off the issues list once its
     * missing-alt warning clears, so this is how it can be reviewed and, if it
     * was a mistake, switched back off.
     *
     * Honours the volume filter, the filename search, and pagination, and
     * leaves out images in an excluded volume, exactly like the issues listing.
     * Excluded volumes are resolved once for the page, never per asset.
     *
     * @param int $page The 1-based page number.
     * @param int $perPage The number of image assets per page.
     * @param string|null $volume A volume handle to restrict to, or null/empty
     *                            for every volume.
     * @param string|null $search A partial filename to filter by, or null/empty
     *                            for no filename filter.
     * @return array{results: array{asset: Asset, currentAlt: string}[], total: int, page: int, perPage: int, totalPages: int}
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.1
     */
    public function listDecorativePaged(int $page = 1, int $perPage = 100, ?string $volume = null, ?string $search = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $query = $this->_decorativeQuery($volume, $search);

        $total = (int) $query->count();

        $assets = (clone $query)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        $results = [];
        foreach ($assets as $asset) {
            // Resolve the alt from the configured field so the row shows what
            // the scanner reads, the same as the issues listing does.
            $results[] = [
                'asset' => $asset,
                'currentAlt' => $this->getAltText($asset) ?? '',
            ];
        }

        return [
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Counts image assets currently marked decorative, for the Decorative
     * filter chip's badge. Honours the volume filter and filename search and
     * leaves out images in an excluded volume, so the badge matches the rows
     * the filter shows.
     *
     * @param string|null $volume A volume handle to restrict to, or null/empty
     *                            for every volume.
     * @param string|null $search A partial filename to filter by, or null/empty
     *                            for no filename filter.
     * @return int
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.1
     */
    public function getDecorativeCount(?string $volume = null, ?string $search = null): int
    {
        return (int) $this->_decorativeQuery($volume, $search)->count();
    }

    private function getAltText(Asset $asset): ?string
    {
        $field = AccessibilityAudit::getInstance()->getSettings()->altTextField ?: 'alt';

        // Built-in Craft alt field
        if ($field === 'alt') {
            return $asset->alt ?? null;
        }

        // Custom field: getFieldValue throws InvalidFieldException if not found
        try {
            $value = $asset->getFieldValue($field);
            return $value !== null ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param Asset $asset The image asset to check.
     * @param bool $isDecorative Whether the asset has been marked decorative,
     *                           in which case an empty alt is correct and no
     *                           missing-alt issue is raised. Callers that scan
     *                           a set of assets batch-load this via
     *                           decorativeAssetIds() rather than querying per
     *                           asset in the loop.
     * @return IssueModel[]
     */
    private function checkImageAlt(Asset $asset, bool $isDecorative = false): array
    {
        $issues = [];
        $label = $asset->title ?: $asset->filename;
        $altText = $this->getAltText($asset);

        if ($altText === null || trim($altText) === '') {
            // A decorative image is correctly marked with an empty alt, so its
            // missing alt is not a fault: raise nothing for it.
            if ($isDecorative) {
                return [];
            }

            // A warning, not an error: an empty alt is the right value for a
            // decorative image, and whether an image is decorative is a human
            // judgement the scanner cannot make. Authors mark the decorative
            // ones; the rest are surfaced here to prompt a description.
            $issues[] = IssueModel::make(
                'asset-alt-missing', 'warning',
                "Image \"{$label}\" has no alt text set in the asset library.",
                '1.1.1', 'A',
                $asset->filename,
                'https://www.w3.org/WAI/WCAG22/Understanding/non-text-content'
            );
            return $issues;
        }

        $alt = trim($altText);

        // Alt looks like a filename
        if (preg_match('/\.(jpe?g|png|gif|webp|svg|bmp|tiff?)$/i', $alt)) {
            $issues[] = IssueModel::make(
                'asset-alt-filename', 'warning',
                "Image \"{$label}\" has a filename as its alt text: \"{$alt}\".",
                '1.1.1', 'A',
                $asset->filename,
                'https://www.w3.org/WAI/WCAG22/Understanding/non-text-content'
            );
        }

        // Alt is very short and likely uninformative
        if (strlen($alt) < 3 && strtolower($alt) !== '') {
            $issues[] = IssueModel::make(
                'asset-alt-short', 'notice',
                "Image \"{$label}\" has very short alt text: \"{$alt}\".",
                '1.1.1', 'A',
                $asset->filename,
                'https://www.w3.org/WAI/WCAG22/Understanding/non-text-content'
            );
        }

        return $issues;
    }

    /**
     * @throws \yii\base\Exception
     */
    public function getSiteAssetStats(): array
    {
        // The total is the audited library's image count, so excluded volumes
        // leave the denominator too, matching the issue count below and
        // getStoredAssetStats(). NULL-safe against the assets alias.
        $excludedVolumes = $this->_excludedVolumeCondition('a.volumeId');
        $totalQuery = (new Query())
            ->from(['a' => '{{%assets}}'])
            ->where(['a.kind' => Asset::KIND_IMAGE]);
        if ($excludedVolumes !== null) {
            $totalQuery->andWhere($excludedVolumes);
        }
        $total = (int) $totalQuery->count();

        return [
            'total' => $total,
            'withIssues' => $this->_countImagesWithIssues($total),
        ];
    }

    // ─── Decorative flags ───────────────────────────────────────────────────

    /**
     * Whether one asset has been marked decorative. A decorative image
     * intentionally carries an empty alt, so its missing alt is not flagged.
     *
     * @param int $assetId The asset's id.
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.1
     */
    public function isDecorative(int $assetId): bool
    {
        if ($assetId === 0) {
            return false;
        }

        return (bool) (new Query())
            ->select(['isDecorative'])
            ->from('{{%accessibilityaudit_asset_flags}}')
            ->where(['assetId' => $assetId, 'isDecorative' => true])
            ->exists();
    }

    /**
     * Marks one asset decorative, or unmarks it. Upserts the flag row so the
     * unique assetId key holds, keeping one row per asset.
     *
     * @param int $assetId The asset's id.
     * @param bool $decorative Whether the asset is decorative.
     * @throws Exception
     * @throws \Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.1
     */
    public function setDecorative(int $assetId, bool $decorative): void
    {
        Craft::$app->getDb()->createCommand()->upsert('{{%accessibilityaudit_asset_flags}}', [
            'assetId' => $assetId,
            'isDecorative' => $decorative,
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ], [
            'isDecorative' => $decorative,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ])->execute();
    }

    /**
     * Batch-loads which of the given asset ids are marked decorative, in a
     * single query. The listing pages and the sweep hold a set of assets at
     * once and must never query per asset in a loop, so they intersect their
     * page of ids against this instead.
     *
     * @param int[] $assetIds The candidate asset ids.
     * @return int[] The subset that is marked decorative.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.1
     */
    public function decorativeAssetIds(array $assetIds): array
    {
        $assetIds = array_values(array_filter(array_map('intval', $assetIds)));
        if (empty($assetIds)) {
            return [];
        }

        return array_map('intval', (new Query())
            ->select(['assetId'])
            ->from('{{%accessibilityaudit_asset_flags}}')
            ->where(['assetId' => $assetIds, 'isDecorative' => true])
            ->column());
    }

    // ─── Stored asset audit (queued sweep) ──────────────────────────────────

    /**
     * Records the audit outcome for one asset in the stored audit table:
     * inserts a row per issue found, removes rows for issues that no longer
     * apply, and clears the asset entirely when its alt text is now fine.
     * Called per item by the batched AuditAssets queue job.
     *
     * @param Asset $asset The image asset to audit and record.
     * @throws Exception
     * @throws \Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function syncAssetAudit(Asset $asset): void
    {
        // An excluded volume's images store nothing, so a save in one leaves the
        // stored audit untouched. The sweep already filters these out at the
        // query level; this guards the per-save re-audit path.
        if ($this->_isExcludedVolume($asset)) {
            return;
        }

        $ruleIds = array_map(
            static fn(IssueModel $issue): string => $issue->ruleId,
            // Single asset, so a single indexed lookup: no loop to batch here.
            $this->checkImageAlt($asset, $this->isDecorative((int)$asset->id)),
        );

        $db = Craft::$app->getDb();

        // Remove findings that no longer apply (all of them, when clean).
        $condition = ['assetId' => $asset->id];
        if (!empty($ruleIds)) {
            $condition = ['and', $condition, ['not', ['ruleId' => $ruleIds]]];
        }
        $db->createCommand()->delete('{{%accessibilityaudit_asset_issues}}', $condition)->execute();

        foreach ($ruleIds as $ruleId) {
            $db->createCommand()->upsert('{{%accessibilityaudit_asset_issues}}', [
                'assetId' => $asset->id,
                'ruleId' => $ruleId,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ], [
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ])->execute();
        }
    }

    /**
     * Removes every stored audit row for one asset. Called when an image is
     * hard-deleted, so its findings don't linger as orphans.
     *
     * @param int $assetId The deleted asset's id.
     * @throws Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function clearStoredIssues(int $assetId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%accessibilityaudit_asset_issues}}', ['assetId' => $assetId])
            ->execute();
    }

    /**
     * Clears the whole stored asset audit: every image issue and the cached
     * summary counts. Used when the Alt Text Field setting changes, since the
     * stored findings were computed against the previous field and no longer
     * describe the current one. The next asset sweep repopulates both.
     *
     * @throws Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function clearAssetAudit(): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->delete('{{%accessibilityaudit_asset_issues}}')->execute();
        $db->createCommand()->delete('{{%accessibilityaudit_asset_stats}}')->execute();
    }

    /**
     * Clears the stored asset findings for the images in the given volumes.
     * Called when volumes are newly excluded on save, so the Assets page and
     * the dashboard's Asset alt text panel shed those images from their counts
     * right away rather than waiting for the next sweep.
     *
     * The stored rows key on assetId, so the affected image ids are resolved
     * through a subquery on the assets table (never a loaded id list), keeping
     * this cheap on a large library.
     *
     * @param int[] $volumeIds The ids of the volumes whose findings to clear.
     * @throws Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function clearAssetAuditForVolumes(array $volumeIds): void
    {
        $volumeIds = array_values(array_filter(array_map('intval', $volumeIds)));
        if (empty($volumeIds)) {
            return;
        }

        $assetIds = (new Query())
            ->select(['id'])
            ->from('{{%assets}}')
            ->where(['volumeId' => $volumeIds]);

        Craft::$app->getDb()->createCommand()
            ->delete('{{%accessibilityaudit_asset_issues}}', ['assetId' => $assetIds])
            ->execute();
    }

    /**
     * Deletes stored audit rows whose asset element no longer exists at all
     * (hard-deleted, or bulk-removed in a way that skipped the delete event).
     * A subquery, not a loaded id list, so it scales to a large library.
     *
     * Trashed (soft-deleted) assets keep their rows so a restore brings the
     * audit history back; the stats queries already exclude them from counts.
     * The stats queries also join past orphans out, so this is table hygiene
     * rather than a correctness fix: run at the start of a sweep to keep the
     * table from growing without bound over a site's lifetime.
     *
     * @return int The number of orphaned rows removed.
     * @throws Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function pruneOrphanedAssetIssues(): int
    {
        // Anything whose id is not an image in the assets table: a hard-deleted
        // asset (the row goes with it) or a row pointing at a non-asset element
        // entirely. A trashed asset keeps its assets row, so its history is
        // preserved here and simply left out of the counts.
        $imageAssets = (new Query())
            ->select('a.id')
            ->from(['a' => '{{%assets}}'])
            ->where(['a.kind' => Asset::KIND_IMAGE]);

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%accessibilityaudit_asset_issues}}', ['not', ['assetId' => $imageAssets]])
            ->execute();
    }

    /**
     * Records that a sweep pass has run, with the library size it covered.
     * Called after each batch of the AuditAssets job, so the final batch
     * leaves the completion timestamp.
     *
     * @param int $totalImages The image count at sweep time.
     * @throws Exception
     * @throws \Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function updateStoredStats(int $totalImages): void
    {
        $db = Craft::$app->getDb();
        $exists = (new Query())->from('{{%accessibilityaudit_asset_stats}}')->exists();

        if ($exists) {
            $db->createCommand()->update('{{%accessibilityaudit_asset_stats}}', [
                'totalImages' => $totalImages,
                'dateScanned' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ])->execute();
            return;
        }

        $db->createCommand()->insert('{{%accessibilityaudit_asset_stats}}', [
            'totalImages' => $totalImages,
            'dateScanned' => Db::prepareDateForDb(new DateTime()),
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    /**
     * The stored sweep results: per-rule counts (indexed COUNT queries, so
     * instant regardless of library size), the CURRENT library total (live,
     * since the on-save sync keeps issue rows current between sweeps), and
     * when the last full sweep completed. Returns null when no sweep has
     * ever run.
     *
     * @return array{total: int, withIssues: int, byRule: array<string, int>, dateScanned: string}|null
     * @throws \yii\base\Exception
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    public function getStoredAssetStats(): ?array
    {
        $stats = (new Query())
            ->select(['dateScanned'])
            ->from('{{%accessibilityaudit_asset_stats}}')
            ->one();

        if (!$stats) {
            return null;
        }

        // Images in an excluded volume drop out of the counts, so a config-set
        // exclusion takes effect immediately without waiting for the stored rows
        // to be cleared. NULL-safe against the assets join.
        $excludedVolumes = $this->_excludedVolumeCondition('a.volumeId');

        // The denominator is the library the audit actually covers, so an
        // excluded volume leaves the total as well as the issue counts.
        $totalQuery = (new Query())
            ->from(['a' => '{{%assets}}'])
            ->where(['a.kind' => Asset::KIND_IMAGE]);
        if ($excludedVolumes !== null) {
            $totalQuery->andWhere($excludedVolumes);
        }
        $liveTotal = (int) $totalQuery->count();

        // Both joins matter: assets/kind keeps the id pointing at a real image,
        // and elements.dateDeleted drops trashed assets, which keep their
        // assets row. Decorative images' stored missing-alt rows are excluded
        // at query time, so toggling decorative reflects immediately.
        $decorativeIds = (new Query())
            ->select(['assetId'])
            ->from('{{%accessibilityaudit_asset_flags}}')
            ->where(['isDecorative' => true]);

        $liveIssues = function() use ($decorativeIds, $excludedVolumes): Query {
            $query = (new Query())
                ->from(['ai' => '{{%accessibilityaudit_asset_issues}}'])
                ->innerJoin(['a' => '{{%assets}}'], '[[a.id]] = [[ai.assetId]]')
                ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[ai.assetId]]')
                ->where(['a.kind' => Asset::KIND_IMAGE, 'e.dateDeleted' => null])
                ->andWhere(['not', [
                    'and',
                    ['ai.ruleId' => 'asset-alt-missing'],
                    ['ai.assetId' => $decorativeIds],
                ]]);
            if ($excludedVolumes !== null) {
                $query->andWhere($excludedVolumes);
            }

            return $query;
        };

        $byRule = $liveIssues()
            ->select(['ai.ruleId', 'COUNT(*) as n'])
            ->groupBy(['ai.ruleId'])
            ->pairs();

        $withIssues = (int) $liveIssues()->count('DISTINCT [[ai.assetId]]');

        return [
            'total' => $liveTotal,
            'withIssues' => $withIssues,
            'byRule' => array_map('intval', $byRule),
            'dateScanned' => $stats['dateScanned'],
        ];
    }

    /**
     * Counts image assets across the whole library that have an alt-text issue,
     * for the Assets page footer summary.
     *
     * Prefers the stored sweep results (indexed count, any library size).
     * Without a sweep: the built-in `alt` column allows a cheap direct count;
     * a custom alt field would mean loading every image element, so past
     * LIVE_COUNT_LIMIT images it returns -1 ("unknown, run a sweep") rather
     * than OOM a 65,000-image library on page render.
     *
     * @param int $totalImages The current library image count.
     * @return int The count, or -1 when it can only be known via a sweep.
     * @throws \yii\base\Exception
     * @since 1.0.0
     * @author JohnHenry <info@johnhenry.ie>
     */
    private function _countImagesWithIssues(int $totalImages): int
    {
        $stored = $this->getStoredAssetStats();
        if ($stored !== null) {
            return $stored['withIssues'];
        }

        $field = AccessibilityAudit::getInstance()->getSettings()->altTextField ?: 'alt';

        // Custom fields aren't columns on the assets table, so counting means
        // loading and checking every image element: only sane on small libraries.
        if ($field !== 'alt') {
            if ($totalImages > self::LIVE_COUNT_LIMIT) {
                return -1;
            }

            return count($this->scanAllImages());
        }

        // Decorative images correctly carry an empty alt, so exclude them from
        // the live missing-alt count.
        $decorativeIds = (new Query())
            ->select(['assetId'])
            ->from('{{%accessibilityaudit_asset_flags}}')
            ->where(['isDecorative' => true]);

        // Joined to elements for the soft-delete flag: Craft trashes assets
        // rather than removing the row, and the listing is an element query
        // that leaves trashed images out, so this must too.
        $query = (new Query())
            ->from(['a' => '{{%assets}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->where(['a.kind' => Asset::KIND_IMAGE, 'e.dateDeleted' => null])
            ->andWhere(['or', ['a.alt' => null], ['a.alt' => '']])
            ->andWhere(['not', ['a.id' => $decorativeIds]]);
        // Images in an excluded volume are left out of the issue count.
        $excludedVolumes = $this->_excludedVolumeCondition('a.volumeId');
        if ($excludedVolumes !== null) {
            $query->andWhere($excludedVolumes);
        }

        return (int) $query->count();
    }

    /**
     * Resolves the configured excluded-volume UIDs to their volume ids,
     * dropping any UID that no longer resolves to a live volume. Volumes are
     * held in an in-memory registry, so this is a handful of hash lookups
     * rather than a query; callers still resolve it once per operation and
     * reuse the result instead of calling it inside a per-asset loop.
     *
     * @return int[] The excluded volumes' ids, empty when none are configured.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function excludedVolumeIds(): array
    {
        $uids = AccessibilityAudit::getInstance()->getSettings()->excludedVolumes;
        if (empty($uids)) {
            return [];
        }

        $volumes = Craft::$app->getVolumes();
        $ids = [];
        foreach ($uids as $uid) {
            $volume = $volumes->getVolumeByUid((string)$uid);
            if ($volume !== null) {
                $ids[] = (int)$volume->id;
            }
        }

        return $ids;
    }

    /**
     * Builds the image-asset query behind the decorative listing and its count:
     * every image currently marked decorative, narrowed by the volume filter and
     * filename search, with excluded volumes dropped. Excluded volumes and the
     * decorative id set are each resolved once here, never per asset.
     *
     * `?: [0]` forces an empty match when nothing is marked, so the listing and
     * its count both come back empty rather than unfiltered.
     *
     * @param string|null $volume A volume handle to restrict to, or null/empty.
     * @param string|null $search A partial filename to filter by, or null/empty.
     * @return AssetQuery
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.1
     */
    private function _decorativeQuery(?string $volume, ?string $search): AssetQuery
    {
        $query = Asset::find()->kind(Asset::KIND_IMAGE);

        // Excluded volumes drop out of the listing and its count, resolved once
        // here rather than per asset.
        $excludedIds = $this->excludedVolumeIds();
        if (!empty($excludedIds)) {
            $query->andWhere(['not', ['volumeId' => $excludedIds]]);
        }
        if ($volume !== null && $volume !== '') {
            $query->volume($volume);
        }
        $search = $search !== null ? trim($search) : '';
        if ($search !== '') {
            $query->filename('*' . $search . '*');
        }

        $decorativeIds = (new Query())
            ->select('assetId')
            ->distinct()
            ->from('{{%accessibilityaudit_asset_flags}}')
            ->where(['isDecorative' => true])
            ->column();

        $query->id($decorativeIds ?: [0]);

        return $query;
    }

    /**
     * Whether one asset sits in an excluded volume. Used by the per-save
     * re-audit paths to skip storing findings for images the audit ignores.
     *
     * @param Asset $asset The asset to test.
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _isExcludedVolume(Asset $asset): bool
    {
        if ($asset->volumeId === null) {
            return false;
        }

        return in_array((int)$asset->volumeId, $this->excludedVolumeIds(), true);
    }

    /**
     * A NULL-safe "not in an excluded volume" condition for the raw stored-audit
     * queries, or null when no volumes are excluded (so callers add nothing).
     * Real assets always carry a volumeId, but the IS NULL branch keeps a row
     * that a query synthesises without one from being dropped by a NOT IN test
     * against NULL.
     *
     * @param string $column The volumeId column, qualified where a join needs it.
     * @return array<mixed>|null
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _excludedVolumeCondition(string $column = 'volumeId'): ?array
    {
        $excludedIds = $this->excludedVolumeIds();
        if (empty($excludedIds)) {
            return null;
        }

        return ['or', [$column => null], ['not', [$column => $excludedIds]]];
    }
}
