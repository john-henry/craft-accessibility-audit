<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\errors\ElementNotFoundException;
use craft\errors\ImageTransformException;
use craft\errors\SiteNotFoundException;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use GuzzleHttp\Exception\GuzzleException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\AltTextPrompt;
use johnhenry\accessibilityaudit\helpers\ImageSource;
use johnhenry\accessibilityaudit\helpers\QueuedJobs;
use johnhenry\accessibilityaudit\helpers\VisionImage;
use johnhenry\accessibilityaudit\jobs\DraftAltText;
use johnhenry\accessibilityaudit\models\SettingsModel;
use JsonException;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\db\IntegrityException;

/**
 * Keeps the AI alt text drafts that wait for an editor's review.
 *
 * Drafts are written in the background, by a drafting run over the library or
 * for each new upload, and none of them is alt text until somebody approves
 * it. One row per image holds the three versions Claude wrote and its notes on
 * what to check, or the reason no draft could be written.
 *
 * @phpstan-import-type AltDraft from AltTextPrompt
 * @phpstan-type DraftRow array{assetId: int, shortAlt: string, standardAlt: string, detailedAlt: string, imageType: string, reviewReasons: list<string>, status: string, error: string, dateUpdated: string}
 * @phpstan-type Suggestion array{alt: string, imageType: string, variants: list<array{key: string, alt: string, tooLong: bool}>, reviewReasons: list<array{reason: string, label: string}>}
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class AltDrafts extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The drafts table.
     */
    public const TABLE = '{{%accessibilityaudit_alt_drafts}}';

    /**
     * @var string The Assets screen filter that lists the drafts to review.
     */
    public const FILTER = 'drafts';

    /**
     * @var string A draft ready for an editor to review.
     */
    public const STATUS_READY = 'ready';

    /**
     * @var string An image Claude could not draft for.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * @var string The failure stored when the image could not be read to send.
     */
    public const FAILURE_NO_IMAGE = 'no-image';

    /**
     * @var string The failure stored when an SVG could not be rendered to send.
     */
    public const FAILURE_VECTOR = 'vector';

    /**
     * @var string A new upload's draft written straight to the image.
     */
    public const OUTCOME_SAVED = 'saved';

    /**
     * @var string A new upload's draft dropped, as the image has alt text by now.
     */
    public const OUTCOME_SKIPPED = 'skipped';

    /**
     * @var array<string, string> Each version's key, mapped to its column.
     */
    private const VERSION_COLUMNS = [
        'short' => 'shortAlt',
        'standard' => 'standardAlt',
        'detailed' => 'detailedAlt',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Stores what came back from Claude for one image, replacing any earlier
     * draft for it.
     *
     * @param int $assetId The image's asset id.
     * @param AltDraft $draft The draft, as AltTextPrompt::draft() returns it.
     * @return string The stored status: ready, or failed where Claude refused
     *         or the reply could not be read.
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function store(int $assetId, array $draft): string
    {
        if ($draft['failure'] !== null) {
            $this->storeFailure($assetId, $draft['failure']);

            return self::STATUS_FAILED;
        }

        $versions = ['short' => '', 'standard' => $draft['alt'], 'detailed' => ''];
        foreach ($draft['variants'] as $variant) {
            if (array_key_exists($variant['key'], $versions)) {
                $versions[$variant['key']] = $variant['alt'];
            }
        }

        $this->_upsert($assetId, [
            'shortAlt' => $versions['short'] !== '' ? $versions['short'] : null,
            'standardAlt' => $versions['standard'],
            'detailedAlt' => $versions['detailed'] !== '' ? $versions['detailed'] : null,
            'imageType' => $draft['imageType'] !== '' ? $draft['imageType'] : null,
            'reviewReasons' => $draft['reviewReasons'] !== [] ? Json::encode($draft['reviewReasons']) : null,
            'status' => self::STATUS_READY,
            'error' => null,
        ]);

        return self::STATUS_READY;
    }

    /**
     * Records that no draft could be written for an image, replacing an
     * earlier failure but never a draft that is ready.
     *
     * A ready draft can come from a second job drafting the same image, and a
     * failure landing after it would throw away a draft somebody paid for. So
     * the update is limited to rows that are not ready, and a row is only
     * inserted where there is none at all. An update that changes nothing (the
     * same failure in the same second) also reports no rows, which is why any
     * existing row ends it rather than a ready one alone.
     *
     * @param int $assetId The image's asset id.
     * @param string $error Why, as a failure code or the API's own message.
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function storeFailure(int $assetId, string $error): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());
        $columns = [
            'shortAlt' => null,
            'standardAlt' => null,
            'detailedAlt' => null,
            'imageType' => null,
            'reviewReasons' => null,
            'status' => self::STATUS_FAILED,
            'error' => mb_substr($error, 0, 1000),
        ];

        $updated = $db->createCommand()->update(
            self::TABLE,
            array_merge($columns, ['dateUpdated' => $now]),
            ['and', ['assetId' => $assetId], ['not', ['status' => self::STATUS_READY]]],
        )->execute();

        if ($updated > 0 || (new Query())->from(self::TABLE)->where(['assetId' => $assetId])->exists()) {
            return;
        }

        try {
            $db->createCommand()->insert(self::TABLE, array_merge($columns, [
                'assetId' => $assetId,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]))->execute();
        } catch (IntegrityException) {
            // Another job stored a row for this image in between; its row stands.
        }
    }

    /**
     * Drafts alt text for one image and stores the result.
     *
     * Nothing is written to the asset. The image is judged again once the
     * reply is in, as settleNewUpload() does: the round trip takes a few
     * seconds, and alt text written or a decorative mark made in that time
     * means the draft has nothing left to fill.
     *
     * @param Asset $asset The image to draft for.
     * @param string $apiKey The resolved Anthropic API key.
     * @return string The stored status, or skipped where the image no longer
     *         needs a draft.
     * @throws GuzzleException When the API refuses the request.
     * @throws JsonException
     * @throws ImageTransformException
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function draftAsset(Asset $asset, string $apiKey): string
    {
        $draft = $this->requestDraft($asset, $apiKey);

        if ($draft === null) {
            return self::STATUS_FAILED;
        }

        AccessibilityAudit::getInstance()->getAssets()->forgetDecorative();
        $current = AssetScanner::findAsset((int)$asset->id);

        if (!$current instanceof Asset || !$this->needsDraft($current)) {
            return self::OUTCOME_SKIPPED;
        }

        return $this->store((int)$current->id, $draft);
    }

    /**
     * Asks Claude for one image's draft.
     *
     * Asks for all three versions, so the review shows the same choice the
     * Generate with AI button does. An image that cannot be read to send is
     * stored as a failed draft here, as there is no reply to settle.
     *
     * @param Asset $asset The image to draft for.
     * @param string $apiKey The resolved Anthropic API key.
     * @return AltDraft|null The draft, or null where the image could not be read.
     * @throws GuzzleException When the API refuses the request.
     * @throws JsonException
     * @throws ImageTransformException
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function requestDraft(Asset $asset, string $apiKey): ?array
    {
        $assetId = (int)$asset->id;
        $imageSource = ImageSource::for($asset, 'alt job');

        if (!$imageSource) {
            $vector = VisionImage::isVector($asset);
            Craft::warning(
                $vector
                    ? "A11y: could not render SVG asset {$assetId} for an alt text draft."
                    : "A11y: could not resolve an image source for asset {$assetId} for an alt text draft.",
                'accessibility-audit',
            );
            $this->storeFailure($assetId, $vector ? self::FAILURE_VECTOR : self::FAILURE_NO_IMAGE);

            return null;
        }

        return AltTextPrompt::draft($apiKey, $imageSource, $asset, AccessibilityAudit::getInstance()->getSettings(), true);
    }

    /**
     * Settles a new upload's draft the way the New uploads setting asks.
     *
     * In save mode, a draft with nothing for an editor to check, and a Standard
     * version inside the long-alt length, has that version written to the
     * image; any other draft, and every draft
     * in review mode, waits under Drafts to review. The image is judged again
     * first, since the API round trip takes a few seconds and in that time
     * somebody may have written alt text, marked it decorative or excluded its
     * volume, or another job may have drafted it. Any of those means nothing is
     * written and this draft is not kept. The decorative set is read afresh, as
     * a queue worker's memo of it can be older than the mark.
     *
     * @param Asset $asset The image the draft is for.
     * @param AltDraft $draft The draft.
     * @param string $mode The New uploads setting: review or save.
     * @return string saved, skipped, or the stored status.
     * @throws ElementNotFoundException When the asset's id no longer resolves.
     * @throws SiteNotFoundException
     * @throws \yii\base\Exception When the asset supports no sites.
     * @throws Exception
     * @throws Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function settleNewUpload(Asset $asset, array $draft, string $mode): string
    {
        AccessibilityAudit::getInstance()->getAssets()->forgetDecorative();
        $current = AssetScanner::findAsset((int)$asset->id);

        if (!$current instanceof Asset || !$this->needsDraft($current)) {
            return self::OUTCOME_SKIPPED;
        }

        if ($mode === SettingsModel::NEW_ASSET_ALT_SAVE && $draft['failure'] === null && $draft['reviewReasons'] === []) {
            $standard = $draft['alt'];
            foreach ($draft['variants'] as $variant) {
                if ($variant['key'] === 'standard') {
                    $standard = $variant['alt'];
                }
            }

            if ($standard !== '' && !AltTextPrompt::exceedsLimit($standard) && $this->approve($current, $standard)) {
                return self::OUTCOME_SAVED;
            }
        }

        return $this->store((int)$current->id, $draft);
    }

    /**
     * The hidden marker the asset edit screen's alt text help reads: which
     * image the screen is for, whether it is decorative, whether Generate
     * with AI can run, and any draft waiting for it.
     *
     * Carried in the screen's own markup rather than a page-wide setting, so
     * a slideout opened for one image never reads what was set for the last.
     *
     * @param Asset $asset The asset being edited.
     * @return string|null The marker, or null where the screen gets no help:
     *         not an image, or an image with no key to generate with, not
     *         decorative and with no draft waiting.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function editScreenMarker(Asset $asset): ?string
    {
        if (!$asset->id || $asset->kind !== Asset::KIND_IMAGE) {
            return null;
        }

        $plugin = AccessibilityAudit::getInstance();
        $canGenerate = $plugin->canGenerateAltText();
        $isDecorative = $plugin->getAssets()->isDecorative((int)$asset->id);

        // A draft costs nothing to show, so it appears with or without an API
        // key, though not on Lite, which has no review queue.
        $draft = null;
        if (!$isDecorative && $plugin->isStandard()) {
            $ready = $this->getReady((int)$asset->id);
            $draft = $ready !== null ? $this->suggestion($ready) : null;
        }

        if (!$canGenerate && !$isDecorative && $draft === null) {
            return null;
        }

        $data = [
            'asset-id' => (int)$asset->id,
            'decorative' => $isDecorative ? '1' : '0',
            'generate' => $canGenerate ? '1' : '0',
        ];

        if ($draft !== null) {
            $data['draft'] = Json::encode($draft);
        }

        return Html::tag('div', '', [
            'class' => 'accessibility-audit-asset-alt',
            'hidden' => true,
            'data' => $data,
        ]);
    }

    /**
     * One image's stored draft, ready or failed.
     *
     * @param int $assetId The image's asset id.
     * @return DraftRow|null The draft, or null where there is none.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function get(int $assetId): ?array
    {
        return $this->forAssets([$assetId])[$assetId] ?? null;
    }

    /**
     * One image's draft, where there is one ready for review.
     *
     * @param int $assetId The image's asset id.
     * @return DraftRow|null The draft, or null where none is ready.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function getReady(int $assetId): ?array
    {
        $draft = $this->get($assetId);

        return $draft !== null && $draft['status'] === self::STATUS_READY ? $draft : null;
    }

    /**
     * The stored drafts for a set of images, in one query.
     *
     * @param int[] $assetIds The images' asset ids.
     * @return array<int, DraftRow> The drafts, keyed by asset id.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function forAssets(array $assetIds): array
    {
        $assetIds = array_values(array_filter(array_map('intval', $assetIds)));
        if ($assetIds === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['assetId', 'shortAlt', 'standardAlt', 'detailedAlt', 'imageType', 'reviewReasons', 'status', 'error', 'dateUpdated'])
            ->from(self::TABLE)
            ->where(['assetId' => $assetIds])
            ->all();

        $drafts = [];
        foreach ($rows as $row) {
            $drafts[(int)$row['assetId']] = self::_hydrate($row);
        }

        return $drafts;
    }

    /**
     * The drafts ready for review, one page at a time, in the shape the
     * Assets screen reads.
     *
     * Scoped like the other image listings: excluded volumes are left out, and
     * the volume filter and filename search apply.
     *
     * @param int $page The 1-based page number.
     * @param int $perPage Rows per page.
     * @param string|null $volume A volume handle to restrict to, or null for every volume.
     * @param string|null $search A partial filename, or null for no filename filter.
     * @return array{results: array<int, array{asset: Asset, draft: DraftRow, notes: string[]}>, total: int, page: int, perPage: int, totalPages: int}
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function listReadyPaged(int $page = 1, int $perPage = 100, ?string $volume = null, ?string $search = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $query = $this->_listingQuery(self::STATUS_READY, $volume, $search);
        $total = (int)$query->count();

        $assets = (clone $query)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        $drafts = $this->forAssets(array_map(static fn(Asset $asset): int => (int)$asset->id, $assets));

        $results = [];
        foreach ($assets as $asset) {
            $draft = $drafts[(int)$asset->id] ?? null;
            if ($draft !== null) {
                $results[] = [
                    'asset' => $asset,
                    'draft' => $draft,
                    'notes' => array_column(self::reasonEntries($draft['reviewReasons']), 'label'),
                ];
            }
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
     * How many drafts are ready for review, for the Drafts to review badge.
     *
     * @param string|null $volume A volume handle to restrict to, or null for every volume.
     * @param string|null $search A partial filename, or null for no filename filter.
     * @return int
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function countReady(?string $volume = null, ?string $search = null): int
    {
        return (int)$this->_listingQuery(self::STATUS_READY, $volume, $search)->count();
    }

    /**
     * How many images Claude could not draft for.
     *
     * @param string|null $volume A volume handle to restrict to, or null for every volume.
     * @param string|null $search A partial filename, or null for no filename filter.
     * @return int
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function countFailed(?string $volume = null, ?string $search = null): int
    {
        return (int)$this->_listingQuery(self::STATUS_FAILED, $volume, $search)->count();
    }

    /**
     * The images a drafting run would draft for, as an unexecuted query of
     * asset ids: those on the stored missing-alt list that are not decorative
     * and have no draft ready. An image whose draft failed is tried again.
     *
     * Read from the stored audit rather than the images themselves, so it is
     * one indexed query whichever field holds the alt text, and it agrees with
     * the Missing alt count beside it.
     *
     * @param int|null $volumeId A volume to restrict to, or null for every volume.
     * @return Query<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function pendingQuery(?int $volumeId = null): Query
    {
        $query = (new Query())
            ->select(['ai.assetId'])
            ->from(['ai' => '{{%accessibilityaudit_asset_issues}}'])
            ->innerJoin(['a' => Table::ASSETS], '[[a.id]] = [[ai.assetId]]')
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[ai.assetId]]')
            ->where([
                'ai.ruleId' => 'asset-alt-missing',
                'a.kind' => Asset::KIND_IMAGE,
                'e.dateDeleted' => null,
            ])
            ->andWhere(['not', ['ai.assetId' => self::_decorativeIds()]])
            ->andWhere(['not', ['ai.assetId' => $this->_assetIdsWith(self::STATUS_READY)]])
            ->andWhere(['not', ['a.volumeId' => null]]);

        $excluded = AccessibilityAudit::getInstance()->getAssets()->excludedVolumeIds();
        if ($excluded !== []) {
            $query->andWhere(['not', ['a.volumeId' => $excluded]]);
        }

        if ($volumeId !== null) {
            $query->andWhere(['a.volumeId' => $volumeId]);
        }

        return $query;
    }

    /**
     * How many images a drafting run would draft for.
     *
     * @param int|null $volumeId A volume to restrict to, or null for every volume.
     * @return int
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function pendingCount(?int $volumeId = null): int
    {
        return (int)$this->pendingQuery($volumeId)->count();
    }

    /**
     * Whether one image still wants a draft, judged on the image itself rather
     * than the stored audit: an image in the audited library with no alt
     * text, not decorative, and with no draft ready.
     *
     * @param Asset $asset The image.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function needsDraft(Asset $asset): bool
    {
        if (!$asset->id || $asset->kind !== Asset::KIND_IMAGE || $asset->volumeId === null) {
            return false;
        }

        $assets = AccessibilityAudit::getInstance()->getAssets();

        if (in_array((int)$asset->volumeId, $assets->excludedVolumeIds(), true)) {
            return false;
        }

        if (trim((string)$assets->altText($asset)) !== '' || $assets->isDecorative((int)$asset->id)) {
            return false;
        }

        return $this->getReady((int)$asset->id) === null;
    }

    /**
     * Queues a drafting run, unless one is already queued or running.
     *
     * A run on Craft's database queue is found in the queue itself. The
     * running key is claimed with add(), which only one request can win, so
     * two presses landing together cannot both queue a run in the moment
     * before either job reaches the queue. A key with no live run behind it is
     * a leftover from a run that ended without clearing it, and is taken over
     * once it is older than that moment. On any other queue driver the key is
     * all there is to go on.
     *
     * @param int|null $volumeId A volume to restrict to, or null for every volume.
     * @return int|null How many images were queued for drafting, 0 where there
     *         was nothing to draft, or null where a run is already under way.
     * @throws Throwable When the job cannot be pushed; the key is released first.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function queueRun(?int $volumeId = null): ?int
    {
        $live = QueuedJobs::isLive(DraftAltText::class);
        if ($live === true) {
            return null;
        }

        $cache = Craft::$app->getCache();

        if (!$cache->add(DraftAltText::RUNNING_KEY, time(), DraftAltText::RUNNING_TTL)) {
            $claimed = (int)$cache->get(DraftAltText::RUNNING_KEY);
            if ($live === null || time() - $claimed < DraftAltText::CLAIM_GRACE) {
                return null;
            }

            $cache->set(DraftAltText::RUNNING_KEY, time(), DraftAltText::RUNNING_TTL);
        }

        $count = $this->pendingCount($volumeId);
        if ($count === 0) {
            $cache->delete(DraftAltText::RUNNING_KEY);

            return 0;
        }

        try {
            Craft::$app->getQueue()->push(new DraftAltText(['volumeId' => $volumeId]));
        } catch (Throwable $e) {
            $cache->delete(DraftAltText::RUNNING_KEY);
            throw $e;
        }

        return $count;
    }

    /**
     * Deletes one image's draft, ready or failed.
     *
     * @param int $assetId The image's asset id.
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function discard(int $assetId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['assetId' => $assetId])
            ->execute();
    }

    /**
     * Saves an editor's alt text to the image and retires its draft.
     *
     * @param Asset $asset The image.
     * @param string $altText The alt text, as the editor approved it.
     * @return bool Whether the asset saved. Where it did not, the draft stays.
     * @throws ElementNotFoundException When the asset's id no longer resolves.
     * @throws \yii\base\Exception When the asset supports no sites.
     * @throws Exception
     * @throws Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function approve(Asset $asset, string $altText): bool
    {
        if (!$this->writeAlt($asset, $altText)) {
            return false;
        }

        $this->discard((int)$asset->id);
        AccessibilityAudit::getInstance()->getAssets()->syncAssetAudit($asset);

        return true;
    }

    /**
     * Approves the Standard version of each image's ready draft in one go,
     * for the bulk action on the drafts list.
     *
     * Only drafts Claude raised nothing about are approved: a draft carrying
     * review reasons needs a person to read it first. Images the current user
     * cannot save, images marked decorative and images that have alt text by
     * now are passed over too, so a bulk action never overwrites alt text
     * somebody wrote. A draft whose save fails stays where it is.
     *
     * @param int[] $assetIds The images' asset ids.
     * @return array{approved: list<int>, skipped: int} The ids approved, and
     *         how many of the rest were left for review.
     * @throws ElementNotFoundException When an asset's id no longer resolves.
     * @throws SiteNotFoundException
     * @throws \yii\base\Exception When an asset supports no sites.
     * @throws Exception
     * @throws Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function approveReady(array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_filter(
            array_map('intval', $assetIds),
            static fn(int $id): bool => $id > 0,
        )));
        $drafts = $this->forAssets($assetIds);
        $assets = AccessibilityAudit::getInstance()->getAssets();
        $elements = Craft::$app->getElements();
        $approved = [];

        foreach ($assetIds as $assetId) {
            $draft = $drafts[$assetId] ?? null;
            if ($draft === null || $draft['status'] !== self::STATUS_READY || $draft['reviewReasons'] !== [] || $draft['standardAlt'] === '') {
                continue;
            }

            $asset = AssetScanner::findAsset($assetId);
            if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE || !$elements->canSave($asset)) {
                continue;
            }

            if ($assets->isDecorative($assetId) || trim((string)$assets->altText($asset)) !== '') {
                continue;
            }

            if ($this->approve($asset, $draft['standardAlt'])) {
                $approved[] = $assetId;
            }
        }

        return [
            'approved' => $approved,
            'skipped' => count($assetIds) - count($approved),
        ];
    }

    /**
     * Writes alt text to whichever field the settings point at and saves the
     * asset without validation, so an unrelated invalid field on the asset
     * cannot block the alt text.
     *
     * @param Asset $asset The asset to write to.
     * @param string $altText The alt text.
     * @return bool Whether the asset saved.
     * @throws ElementNotFoundException When the asset's id no longer resolves.
     * @throws \yii\base\Exception When the asset supports no sites.
     * @throws Throwable
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function writeAlt(Asset $asset, string $altText): bool
    {
        $field = AccessibilityAudit::getInstance()->getSettings()->altTextField ?: 'alt';

        if ($field === 'alt') {
            $asset->alt = $altText;
        } else {
            $asset->setFieldValue($field, $altText);
        }

        return Craft::$app->getElements()->saveElement($asset, false);
    }

    /**
     * A stored draft in the shape the alt text panel renders suggestions
     * from, the same shape the Generate with AI request returns.
     *
     * @param DraftRow $draft The stored draft.
     * @return Suggestion
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function suggestion(array $draft): array
    {
        $variants = [];
        foreach (self::VERSION_COLUMNS as $key => $column) {
            if ($draft[$column] !== '') {
                $variants[] = ['key' => $key, 'alt' => $draft[$column], 'tooLong' => AltTextPrompt::exceedsLimit($draft[$column])];
            }
        }

        return [
            'alt' => $draft['standardAlt'],
            'imageType' => $draft['imageType'],
            'variants' => $variants,
            'reviewReasons' => self::reasonEntries($draft['reviewReasons']),
        ];
    }

    // Public Static Methods
    // =========================================================================

    /**
     * Review reasons with the sentence an editor reads for each.
     *
     * @param list<string> $reasons The reason codes.
     * @return list<array{reason: string, label: string}>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function reasonEntries(array $reasons): array
    {
        return array_map(static fn(string $reason): array => [
            'reason' => $reason,
            'label' => AltTextPrompt::reasonLabel($reason),
        ], $reasons);
    }

    // Private Methods
    // =========================================================================

    /**
     * Inserts or replaces one image's draft row.
     *
     * @param int $assetId The image's asset id.
     * @param array<string, mixed> $columns The draft columns.
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _upsert(int $assetId, array $columns): void
    {
        $now = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()->upsert(self::TABLE, array_merge($columns, [
            'assetId' => $assetId,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]), array_merge($columns, [
            'dateUpdated' => $now,
        ]))->execute();
    }

    /**
     * The asset ids holding a draft of one status, as a subquery.
     *
     * @param string $status The status.
     * @return Query<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _assetIdsWith(string $status): Query
    {
        return (new Query())
            ->select(['assetId'])
            ->from(self::TABLE)
            ->where(['status' => $status]);
    }

    /**
     * The images holding a draft of one status, as an asset query scoped the
     * way the other image listings are. Decorative images are left out even
     * though marking one drops its draft: a draft stored by a job already in
     * flight when the mark was made would otherwise be offered for review.
     * Images no longer on the stored missing-alt list are left out for the
     * same reason: saving alt text drops the draft too, but a draft stored
     * just after that save would outlive it.
     *
     * @param string $status The status.
     * @param string|null $volume A volume handle, or null for every volume.
     * @param string|null $search A partial filename, or null for no filter.
     * @return AssetQuery<int, Asset>
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _listingQuery(string $status, ?string $volume, ?string $search): AssetQuery
    {
        return AccessibilityAudit::getInstance()->getAssets()
            ->listingQuery($volume, $search)
            ->andWhere(['elements.id' => $this->_assetIdsWith($status)])
            ->andWhere(['elements.id' => self::_missingAltIds()])
            ->andWhere(['not', ['elements.id' => self::_decorativeIds()]]);
    }

    /**
     * The asset ids on the stored missing-alt list, as a subquery.
     *
     * @return Query<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _missingAltIds(): Query
    {
        return (new Query())
            ->select(['assetId'])
            ->from('{{%accessibilityaudit_asset_issues}}')
            ->where(['ruleId' => 'asset-alt-missing']);
    }

    /**
     * The decorative asset ids, as a subquery.
     *
     * @return Query<int, array<string, mixed>>
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _decorativeIds(): Query
    {
        return (new Query())
            ->select(['assetId'])
            ->from('{{%accessibilityaudit_asset_flags}}')
            ->where(['isDecorative' => true]);
    }

    /**
     * A stored row in the shape the rest of the plugin reads.
     *
     * @param array<string, mixed> $row The row as the database returns it.
     * @return DraftRow
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _hydrate(array $row): array
    {
        $reasons = [];
        if (is_string($row['reviewReasons']) && $row['reviewReasons'] !== '') {
            $decoded = Json::decodeIfJson($row['reviewReasons']);
            $reasons = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        return [
            'assetId' => (int)$row['assetId'],
            'shortAlt' => (string)$row['shortAlt'],
            'standardAlt' => (string)$row['standardAlt'],
            'detailedAlt' => (string)$row['detailedAlt'],
            'imageType' => (string)$row['imageType'],
            'reviewReasons' => $reasons,
            'status' => (string)$row['status'],
            'error' => (string)$row['error'],
            'dateUpdated' => (string)$row['dateUpdated'],
        ];
    }
}
