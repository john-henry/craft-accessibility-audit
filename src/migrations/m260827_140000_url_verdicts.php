<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;

/**
 * Lets a ruling on a potential issue belong to a URL rather than an element.
 *
 * A verdict was keyed on (siteId, elementId, ruleId, contextHash). Pages
 * scanned by URL have no element, and simply allowing a null there would not
 * work: both MySQL and Postgres treat nulls in a unique index as distinct, so
 * uniqueness would stop being enforced for exactly the rows that need it, and
 * the same question answered twice would store two rows.
 *
 * So the target gets its own column. targetHash is never null: an element
 * hashes to "element:{id}", a URL to "url:{url}", and the unique index moves
 * onto that. Existing rows are backfilled from the element they already name.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260827_140000_url_verdicts extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $table = '{{%accessibilityaudit_verdicts}}';

        $this->alterColumn($table, 'elementId', $this->integer()->null());

        if (!$this->db->columnExists($table, 'url')) {
            $this->addColumn($table, 'url', $this->string(2048)->null()->after('elementId'));
        }

        // Nullable to begin with so existing rows can be filled in before the
        // not-null constraint lands.
        if (!$this->db->columnExists($table, 'targetHash')) {
            $this->addColumn($table, 'targetHash', $this->char(40)->null()->after('url'));
        }

        $this->_backfillTargetHashes($table);

        $this->alterColumn($table, 'targetHash', $this->char(40)->notNull());

        // The replacement goes in first. MySQL uses the old unique index to
        // back the foreign key on siteId, and will not drop an index a
        // constraint still needs; leading the new one with siteId as well
        // gives the constraint something to fall back on.
        $this->createIndex(null, $table, ['siteId', 'targetHash', 'ruleId', 'contextHash'], true);
        $this->createIndex(null, $table, ['siteId', 'targetHash']);

        // Only then the old one, which names an elementId that is now null on
        // URL rows and so enforces nothing for them.
        $this->dropIndexIfExists($table, ['siteId', 'elementId', 'ruleId', 'contextHash'], true);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260827_140000_url_verdicts cannot be reverted.\n";

        return false;
    }

    // Private Methods
    // =========================================================================

    /**
     * Fills in targetHash for rows written before the column existed.
     *
     * Done row by row in PHP rather than in SQL: MySQL and Postgres do not
     * agree on how to hash a string (Postgres needs pgcrypto for digest()),
     * and this table holds one row per answered question, so there is no
     * volume here worth writing two dialects for.
     *
     * @param string $table The verdicts table.
     * @return void
     */
    private function _backfillTargetHashes(string $table): void
    {
        $rows = (new Query())
            ->select(['id', 'elementId'])
            ->from($table)
            ->where(['targetHash' => null])
            ->all();

        $now = Db::prepareDateForDb(new DateTime());

        foreach ($rows as $row) {
            // Matches VerdictService::targetHash(). Every existing row names an
            // element, since URLs could not be ruled on before now.
            $this->update($table, [
                'targetHash' => sha1('element:' . (int)$row['elementId']),
                'dateUpdated' => $now,
            ], ['id' => $row['id']], [], false);
        }
    }
}
