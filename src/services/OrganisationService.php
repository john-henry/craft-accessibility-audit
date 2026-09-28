<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use johnhenry\accessibilityaudit\models\OrganisationMetaModel;
use yii\base\Component;
use yii\db\Exception;

/**
 * Reads and writes the organisation metadata shared by every compliance
 * document the plugin produces.
 *
 * One row per site. Kept apart from any single document's service so a second
 * document (an accessibility statement, say) reads the same contact and
 * evaluation details rather than asking an editor to type them twice and then
 * quietly disagreeing with the first one.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class OrganisationService extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the stored organisation metadata for a site.
     *
     * Reading never creates a row: an empty array means nothing has been saved
     * yet, which callers merge over as absent keys.
     *
     * @param int $siteId The site to read.
     * @return array<string, string|string[]> The stored values, or an empty array.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getMeta(int $siteId): array
    {
        $row = (new Query())
            ->select(['meta'])
            ->from('{{%accessibilityaudit_organisation}}')
            ->where(['siteId' => $siteId])
            ->one();

        if (!$row || !$row['meta']) {
            return [];
        }

        return Json::decode($row['meta']);
    }

    /**
     * Saves validated organisation metadata for a site.
     *
     * @param int $siteId The site the metadata belongs to.
     * @param OrganisationMetaModel $meta The validated metadata model.
     * @return void
     * @throws Exception When the insert or update fails.
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function saveMeta(int $siteId, OrganisationMetaModel $meta): void
    {
        $now = Db::prepareDateForDb(new DateTime());
        $encoded = Json::encode($meta->toStorageArray());

        // One statement rather than a check and then a write. Two people
        // saving the organisation details at once both found the row missing
        // under the old shape, and the unique index on siteId failed the
        // second one in front of whoever saved last.
        Db::upsert(
            '{{%accessibilityaudit_organisation}}',
            [
                'siteId' => $siteId,
                'meta' => $encoded,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ],
            ['meta' => $encoded, 'dateUpdated' => $now],
        );
    }
}
