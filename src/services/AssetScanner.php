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
 * Reads Craft Asset elements for accessibility problems.
 *
 * Assets are binary files, so nothing here parses markup: every finding is
 * about the text describing a file rather than about the file itself.
 *
 * @property-read array{total: int, withIssues: int, byRule: array<string, int>, dateScanned: string}|null $storedAssetStats
 * @property-read array{total: int, withIssues: int} $siteAssetStats
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class AssetScanner extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Libraries larger than this skip the live whole-library count
     * when a custom alt field is in use and no stored sweep exists: counting
     * would mean loading every image element in one request. The queued
     * sweep is the answer at that scale.
     */
    public const LIVE_COUNT_LIMIT = 2000;

    // Private Properties
    // =========================================================================

    /**
     * @var array<int, true>|null Every decorative asset id, keyed by id and
     *                             loaded once per request.
     *                             Front-end templates ask per image, so a query
     *                             each would be one per image on the page.
     */
    private ?array $_decorativeIds = null;

    // Public Methods
    // =========================================================================

    /**
     * Reads one asset for alt-text problems.
     *
     * Assets are binary files, so nothing here parses markup: the questions are
     * about the text describing the file, not about the file itself.
     *
     * @param Asset $asset The asset to read.
     * @return IssueModel[] The findings, empty where the asset passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
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
            $issues = array_merge($issues, $this->_checkImageAlt($asset, $this->isDecorative((int)$asset->id)));
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

    /**
     * Reads every image in the audited library, excluded volumes aside.
     *
     * Loads the lot in one go, so it suits a queue job rather than a page
     * render: {@see self::scanImagesPaged()} is what the CP calls.
     *
     * @return array<int, array{asset: Asset, issues: IssueModel[]}> One entry
     *         per image with something outstanding.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function scanAllImages(): array
    {
        $results = [];

        $assets = $this->_imageQuery()->all();

        // Batch-load the decorative set once for the whole page rather than
        // querying per asset inside the loop.
        $decorative = array_flip($this->decorativeAssetIds(array_map(
            static fn(Asset $asset): int => (int)$asset->id,
            $assets,
        )));

        foreach ($assets as $asset) {
            $issues = $this->_checkImageAlt($asset, isset($decorative[(int)$asset->id]));
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function scanImagesPaged(int $page = 1, int $perPage = 100, ?string $volume = null, ?string $search = null, ?string $ruleId = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $query = $this->_imageQuery($volume, $search);

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
            $issues = $this->_checkImageAlt($asset, isset($decorative[(int)$asset->id]));
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
                    'currentAlt' => $this->_getAltText($asset) ?? '',
                    'decorative' => isset($decorative[(int)$asset->id]),
                ];
            }
        }

        return self::_page($results, $total, $page, $perPage);
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
                'currentAlt' => $this->_getAltText($asset) ?? '',
            ];
        }

        return self::_page($results, $total, $page, $perPage);
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.1
     */
    public function getDecorativeCount(?string $volume = null, ?string $search = null): int
    {
        return (int) $this->_decorativeQuery($volume, $search)->count();
    }

    /**
     * One page of asset rows, in the shape the Assets screen reads.
     *
     * Both listings answer through here so the decorative list and the issues
     * list cannot drift into returning different keys. The screen renders them
     * with the same code, and a missing `totalPages` on one of them is a pager
     * that quietly stops working on that tab alone.
     *
     * @param array<int, array<string, mixed>> $results The rows on this page.
     * @param int $total How many rows there are in total.
     * @param int $page The page these rows came from, from 1.
     * @param int $perPage Rows per page.
     * @return array{results: array<int, array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private static function _page(array $results, int $total, int $page, int $perPage): array
    {
        return [
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Every image the audit covers, as an unexecuted query.
     *
     * Two things are left out. Volumes somebody excluded under Settings, and
     * Craft's temporary uploads, which are not in a volume at all: a part-built
     * upload carries a NULL volumeId, so no volume exclusion can reach it and
     * it never appears in the settings list to be picked in the first place.
     * They are working files, cleared once the entry they were headed for is
     * saved, and nobody can write alt text on a file that may never land. Left
     * in, they fill the Images screen with "photo.jpg / Temporary Uploads" rows
     * whose thumbnails have already gone.
     *
     * Shared so the sweep, the queue job, the console command and the count
     * shown before a sweep runs all describe the same library. Each built its
     * own before, and the count excluded nothing at all.
     *
     * @return AssetQuery<int, Asset> The query, ready for further conditions.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function imageQuery(): AssetQuery
    {
        $query = Asset::find()->kind(Asset::KIND_IMAGE);

        // Temporary uploads, which belong to no volume.
        $query->andWhere(['not', ['volumeId' => null]]);

        // Resolved once here rather than per asset.
        $excludedIds = $this->excludedVolumeIds();

        if (!empty($excludedIds)) {
            $query->andWhere(['not', ['volumeId' => $excludedIds]]);
        }

        return $query;
    }

    /**
     * The image library a listing works from, with the excluded volumes and any
     * volume or filename filter already applied.
     *
     * Every listing starts here so a volume excluded in settings is excluded
     * from the rows, the counts and the totals alike. One of them missing the
     * exclusion is how a figure ends up disagreeing with the list beneath it.
     *
     * @param string|null $volume A volume handle to restrict to, or null for
     *        every volume.
     * @param string|null $search A partial filename to filter by, or null for
     *        no filename filter.
     * @return AssetQuery<int, Asset> The query, ready for further conditions.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    private function _imageQuery(?string $volume = null, ?string $search = null): AssetQuery
    {
        $query = $this->imageQuery();

        if ($volume !== null && $volume !== '') {
            $query->volume($volume);
        }

        // A partial match on the stored filename, so a large library can be
        // narrowed to one image without paging through the lot.
        $search = $search !== null ? trim($search) : '';

        if ($search !== '') {
            $query->filename('*' . $search . '*');
        }

        return $query;
    }

    /**
     * An asset's alt text, from whichever field the settings point at.
     *
     * Craft's own `alt` is read directly; anything else goes through
     * getFieldValue(), which throws where the handle names no field, so a
     * mis-set handle reads as no alt text rather than taking the scan down.
     *
     * @param Asset $asset The asset to read.
     * @return string|null The alt text, or null where there is none.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _getAltText(Asset $asset): ?string
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
     * Reads one image's alt text and raises what is outstanding.
     *
     * @param Asset $asset The image asset to check.
     * @param bool $isDecorative Whether the asset has been marked decorative,
     *                           in which case an empty alt is correct and no
     *                           missing-alt issue is raised. Callers that scan
     *                           a set of assets batch-load this via
     *                           decorativeAssetIds() rather than querying per
     *                           asset in the loop.
     * @return IssueModel[] The findings, empty where the image passes.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkImageAlt(Asset $asset, bool $isDecorative = false): array
    {
        $issues = [];
        $label = $asset->title ?: $asset->filename;
        $altText = $this->_getAltText($asset);

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

        // Alt is very short and likely uninformative. Counted in characters,
        // not bytes: two characters of a non-Latin script are six bytes, and a
        // byte count never flags them however short the alt actually is.
        if ($alt !== '' && mb_strlen($alt) < 3) {
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
     * How much of the image library still wants attention.
     *
     * The total counts the audited library only, so an excluded volume leaves
     * the denominator as well as the numerator and the two figures describe the
     * same set.
     *
     * @return array{total: int, withIssues: int} The image count and how many
     *         carry an outstanding finding.
     * @throws \yii\base\Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
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
        $totalQuery->andWhere($excludedVolumes);
        $total = (int) $totalQuery->count();

        return [
            'total' => $total,
            'withIssues' => $this->_countImagesWithIssues($total),
        ];
    }

    // Decorative Flags
    // =========================================================================

    /**
     * Whether one asset has been marked decorative. A decorative image
     * intentionally carries an empty alt, so its missing alt is not flagged.
     *
     * @param int $assetId The asset's id.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.1
     */
    public function isDecorative(int $assetId): bool
    {
        if ($assetId === 0) {
            return false;
        }

        return isset($this->allDecorativeIds()[$assetId]);
    }

    /**
     * Every decorative asset id, keyed by id, loaded once per request.
     *
     * @return array<int, true> Decorative asset ids as keys.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function allDecorativeIds(): array
    {
        if ($this->_decorativeIds === null) {
            $this->_decorativeIds = array_fill_keys(
                array_map('intval', (new Query())
                    ->select(['assetId'])
                    ->from('{{%accessibilityaudit_asset_flags}}')
                    ->where(['isDecorative' => true])
                    ->column()),
                true,
            );
        }

        return $this->_decorativeIds;
    }

    /**
     * Marks one asset decorative, or unmarks it. Upserts the flag row so the
     * unique assetId key holds, keeping one row per asset.
     *
     * @param int $assetId The asset's id.
     * @param bool $decorative Whether the asset is decorative.
     * @throws Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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

        // The memo is brought into step with the write rather than dropped.
        // Marking images in bulk sets them one at a time, and the audit sync
        // that follows each one asks whether it is decorative: dropping the set
        // there reloads every decorative id in the library once per image
        // selected, which is the cost this memo exists to avoid.
        if ($this->_decorativeIds !== null) {
            if ($decorative) {
                $this->_decorativeIds[$assetId] = true;
            } else {
                unset($this->_decorativeIds[$assetId]);
            }
        }
    }

    /**
     * Drops the memoised decorative set, so the next lookup reads the table.
     *
     * A web request builds the service fresh, so this is only needed where the
     * process outlives a single unit of work: a long-running queue worker, a
     * console command, a test suite between cases.
     *
     * @return void
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function forgetDecorative(): void
    {
        $this->_decorativeIds = null;
    }

    /**
     * Batch-loads which of the given asset ids are marked decorative, in a
     * single query. The listing pages and the sweep hold a set of assets at
     * once and must never query per asset in a loop, so they intersect their
     * page of ids against this instead.
     *
     * @param int[] $assetIds The candidate asset ids.
     * @return int[] The subset that is marked decorative.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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

    // Stored Asset Audit
    // =========================================================================

    /**
     * Records the audit outcome for one asset in the stored audit table:
     * inserts a row per issue found, removes rows for issues that no longer
     * apply, and clears the asset entirely when its alt text is now fine.
     * Called per item by the batched AuditAssets queue job.
     *
     * @param Asset $asset The image asset to audit and record.
     * @throws Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
            $this->_checkImageAlt($asset, $this->isDecorative((int)$asset->id)),
        );

        $db = Craft::$app->getDb();

        // Remove findings that no longer apply (all of them, when clean).
        $condition = ['assetId' => $asset->id];
        if (!empty($ruleIds)) {
            $condition = ['and', $condition, ['not', ['ruleId' => $ruleIds]]];
        }
        // Clearing what no longer applies and writing what does go in together.
        // Apart, a failure between them leaves the asset holding fewer findings
        // than it has, which on an audit reads as an image that is fine.
        $db->transaction(function() use ($db, $asset, $ruleIds, $condition): void {
            $now = Db::prepareDateForDb(new DateTime());

            $db->createCommand()->delete('{{%accessibilityaudit_asset_issues}}', $condition)->execute();

            foreach ($ruleIds as $ruleId) {
                $db->createCommand()->upsert('{{%accessibilityaudit_asset_issues}}', [
                    'assetId' => $asset->id,
                    'ruleId' => $ruleId,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ], [
                    'dateUpdated' => $now,
                ])->execute();
            }
        });
    }

    /**
     * Removes every stored audit row for one asset. Called when an image is
     * hard-deleted, so its findings don't linger as orphans.
     *
     * @param int $assetId The deleted asset's id.
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function updateStoredStats(int $totalImages): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());

        // One row, no key to upsert against, and this runs after every batch
        // of a sweep. Asking whether the row exists and then writing gives two
        // queue workers a window to both find it missing and both insert; the
        // update's own row count answers the same question without one. The
        // update carries no condition because the table holds a single row.
        $affected = $db->createCommand()
            ->update('{{%accessibilityaudit_asset_stats}}', [
                'totalImages' => $totalImages,
                'dateScanned' => $now,
                'dateUpdated' => $now,
            ])
            ->execute();

        if ($affected > 0) {
            return;
        }

        $db->createCommand()->insert('{{%accessibilityaudit_asset_stats}}', [
            'totalImages' => $totalImages,
            'dateScanned' => $now,
            'dateCreated' => $now,
            'dateUpdated' => $now,
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
        $totalQuery->andWhere($excludedVolumes);
        $liveTotal = (int) $totalQuery->count();

        // Both joins matter: assets/kind keeps the id pointing at a real image,
        // and elements.dateDeleted drops trashed assets, which keep their
        // assets row. Decorative images' stored missing-alt rows are excluded
        // at query time, so toggling decorative reflects immediately.
        $decorativeIds = (new Query())
            ->select(['assetId'])
            ->from('{{%accessibilityaudit_asset_flags}}')
            ->where(['isDecorative' => true]);

        $liveIssues = static function() use ($decorativeIds, $excludedVolumes): Query {
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
            $query->andWhere($excludedVolumes);

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
        $query->andWhere($excludedVolumes);

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
            $volume = $volumes->getVolumeByUid($uid);
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
     * @return AssetQuery<int, Asset>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.1
     */
    private function _decorativeQuery(?string $volume, ?string $search): AssetQuery
    {
        $query = $this->_imageQuery($volume, $search);

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _isExcludedVolume(Asset $asset): bool
    {
        // A saved asset with no volume is in Craft's temporary uploads folder:
        // a working file on its way to a volume, which the audit does not
        // cover. Skipping it here is what stops a row being stored the moment
        // somebody drops a file on a field.
        //
        // The id is part of the test, not decoration. An asset that was never
        // saved has no volume yet rather than no volume at all, and the paths
        // that judge an element in hand pass exactly that.
        if ($asset->volumeId === null) {
            return $asset->id !== null;
        }

        return in_array((int)$asset->volumeId, $this->excludedVolumeIds(), true);
    }

    /**
     * The "counts towards the audit" condition for the raw stored-audit
     * queries, which read the assets table directly rather than through an
     * element query.
     *
     * Two things are left out, matching {@see self::imageQuery()} so the
     * figures on screen describe the library the sweep actually covers. A NULL
     * volumeId is a temporary upload and is dropped; it is not, as this once
     * assumed, a row a query synthesised without one. Excluded volumes are
     * dropped when any are configured.
     *
     * @param string $column The volumeId column, qualified where a join needs it.
     * @return array<int|string, mixed> A Yii condition.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _excludedVolumeCondition(string $column = 'volumeId'): array
    {
        $condition = ['and', ['not', [$column => null]]];
        $excludedIds = $this->excludedVolumeIds();

        if (!empty($excludedIds)) {
            $condition[] = ['not', [$column => $excludedIds]];
        }

        return $condition;
    }
}
