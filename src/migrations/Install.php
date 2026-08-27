<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use Craft;
use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->createTables()) {
            $this->createIndexes();
            $this->addForeignKeys();
            Craft::$app->db->schema->refresh();
        }

        return true;
    }

    public function safeDown(): bool
    {
        // The issues table first: it has a foreign key to the scans table.
        $this->dropTableIfExists('{{%accessibilityaudit_verdicts}}');
        $this->dropTableIfExists('{{%accessibilityaudit_issues}}');
        $this->dropTableIfExists('{{%accessibilityaudit_scans}}');
        $this->dropTableIfExists('{{%accessibilityaudit_vpat}}');
        $this->dropTableIfExists('{{%accessibilityaudit_statement}}');
        $this->dropTableIfExists('{{%accessibilityaudit_organisation}}');
        $this->dropTableIfExists('{{%accessibilityaudit_readability}}');
        $this->dropTableIfExists('{{%accessibilityaudit_asset_issues}}');
        $this->dropTableIfExists('{{%accessibilityaudit_asset_stats}}');
        $this->dropTableIfExists('{{%accessibilityaudit_asset_flags}}');
        return true;
    }

    protected function createTables(): bool
    {
        if (!$this->db->tableExists('{{%accessibilityaudit_scans}}')) {
            $this->createTable('{{%accessibilityaudit_scans}}', [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'elementType' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'score' => $this->smallInteger()->unsigned()->notNull()->defaultValue(100),
                'scoreA' => $this->smallInteger()->unsigned()->notNull()->defaultValue(100),
                'scoreAA' => $this->smallInteger()->unsigned()->notNull()->defaultValue(100),
                'scoreAAA' => $this->smallInteger()->unsigned()->notNull()->defaultValue(100),
                'errorCount' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
                'warningCount' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
                'noticeCount' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
                'dateScanned' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists('{{%accessibilityaudit_issues}}')) {
            $this->createTable('{{%accessibilityaudit_issues}}', [
                'id' => $this->primaryKey(),
                'scanId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'elementType' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'ruleId' => $this->string(50)->notNull(),
                'wcagCriterion' => $this->string(20)->null(),
                'wcagLevel' => $this->char(3)->null(),
                'severity' => $this->enum('severity', ['error', 'warning', 'notice'])->notNull()->defaultValue('warning'),
                'message' => $this->text()->notNull(),
                'context' => $this->text()->null(),
                'helpUrl' => $this->string(255)->null(),
                'source' => $this->enum('source', ['php', 'axe', 'contrast'])->notNull()->defaultValue('php'),
                'viewport' => $this->string(10)->null(),
                'firstDetected' => $this->dateTime()->null(),
                'isResolved' => $this->boolean()->notNull()->defaultValue(false),
                'dateResolved' => $this->dateTime()->null(),
                // The author's ruling on a potential issue, copied here from the
                // durable verdicts table. Denormalised so scoring/listing queries
                // don't need a join. Null means unreviewed.
                'verdict' => $this->enum('verdict', ['dismissed', 'confirmed'])->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        // Rulings on potential issues, kept apart from the issues table since
        // issue rows are rebuilt on every scan and a verdict has to outlive them.
        // Keyed by target, rule, and a hash of the offending markup, not by
        // issue row id. The target is an element or, for a page scanned by URL,
        // the URL: targetHash holds whichever, and is never null, because both
        // MySQL and Postgres treat nulls in a unique index as distinct and
        // uniqueness would stop being enforced for the rows that need it.
        if (!$this->db->tableExists('{{%accessibilityaudit_verdicts}}')) {
            $this->createTable('{{%accessibilityaudit_verdicts}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->null(),
                'url' => $this->string(2048)->null(),
                'targetHash' => $this->char(40)->notNull(),
                'ruleId' => $this->string(50)->notNull(),
                'contextHash' => $this->char(40)->notNull(),
                'verdict' => $this->enum('verdict', ['dismissed', 'confirmed'])->notNull(),
                'note' => $this->text()->null(),
                'userId' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists('{{%accessibilityaudit_vpat}}')) {
            $this->createTable('{{%accessibilityaudit_vpat}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'meta' => $this->text()->null(),
                'overrides' => $this->text()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists('{{%accessibilityaudit_organisation}}')) {
            // Shared by every compliance document: the VPAT today, an
            // accessibility statement next. Kept out of the vpat table so a
            // second document reads the same contact and evaluation details
            // instead of asking for them again and drifting.
            $this->createTable('{{%accessibilityaudit_organisation}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'meta' => $this->text()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists('{{%accessibilityaudit_statement}}')) {
            // One published accessibility statement per site. `profile` is its own
            // column so statements can be found by jurisdiction without unpacking
            // JSON. `sourceScanDate` records the scan the derived sections were built
            // from, so the CP can tell when a statement has drifted from the site.
            $this->createTable('{{%accessibilityaudit_statement}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'profile' => $this->string(20)->notNull()->defaultValue('generic'),
                'meta' => $this->text()->null(),
                'exclusions' => $this->text()->null(),
                'sourceScanDate' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists('{{%accessibilityaudit_asset_issues}}')) {
            $this->createTable('{{%accessibilityaudit_asset_issues}}', [
                'id' => $this->primaryKey(),
                'assetId' => $this->integer()->notNull(),
                'ruleId' => $this->string(30)->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists('{{%accessibilityaudit_asset_stats}}')) {
            $this->createTable('{{%accessibilityaudit_asset_stats}}', [
                'id' => $this->primaryKey(),
                'totalImages' => $this->integer()->notNull()->defaultValue(0),
                'dateScanned' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists('{{%accessibilityaudit_asset_flags}}')) {
            // Author-set judgements about an image the scanner cannot make for
            // itself. The first is "decorative": a decorative image correctly
            // carries an empty alt, so its missing alt is not flagged.
            $this->createTable('{{%accessibilityaudit_asset_flags}}', [
                'id' => $this->primaryKey(),
                'assetId' => $this->integer()->notNull(),
                'isDecorative' => $this->boolean()->notNull()->defaultValue(false),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists('{{%accessibilityaudit_readability}}')) {
            $this->createTable('{{%accessibilityaudit_readability}}', [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer(),
                'siteId' => $this->integer(),
                'url' => $this->string(500)->notNull()->defaultValue(''),
                'title' => $this->string(500),
                'readingEase' => $this->float(),
                'readingEaseLabel' => $this->string(50),
                'gradeLevel' => $this->float(),
                'readingAge' => $this->integer(),
                'wordCount' => $this->integer(),
                'sentenceCount' => $this->integer(),
                'avgWordsPerSentence' => $this->float(),
                'wcag315Pass' => $this->boolean()->defaultValue(false),
                'dateAnalysed' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        return true;
    }

    protected function createIndexes(): void
    {
        $this->createIndex(null, '{{%accessibilityaudit_scans}}', ['elementId', 'siteId', 'dateScanned']);
        $this->createIndex(null, '{{%accessibilityaudit_scans}}', ['siteId', 'score']);
        $this->createIndex(null, '{{%accessibilityaudit_vpat}}', ['siteId'], true);
        $this->createIndex(null, '{{%accessibilityaudit_organisation}}', ['siteId'], true);
        $this->createIndex(null, '{{%accessibilityaudit_statement}}', ['siteId'], true);
        $this->createIndex(null, '{{%accessibilityaudit_issues}}', ['scanId']);
        $this->createIndex(null, '{{%accessibilityaudit_issues}}', ['elementId', 'siteId']);
        $this->createIndex(null, '{{%accessibilityaudit_issues}}', ['severity']);
        $this->createIndex(null, '{{%accessibilityaudit_issues}}', ['ruleId']);
        $this->createIndex(null, '{{%accessibilityaudit_issues}}', ['elementId', 'ruleId', 'siteId']);
        $this->createIndex(null, '{{%accessibilityaudit_issues}}', ['isResolved', 'dateResolved']);
        $this->createIndex(null, '{{%accessibilityaudit_readability}}', ['elementId', 'siteId']);
        $this->createIndex(null, '{{%accessibilityaudit_readability}}', ['dateAnalysed']);
        $this->createIndex(null, '{{%accessibilityaudit_asset_issues}}', ['assetId', 'ruleId'], true);
        $this->createIndex(null, '{{%accessibilityaudit_asset_issues}}', ['ruleId']);
        $this->createIndex(null, '{{%accessibilityaudit_asset_flags}}', ['assetId'], true);
        $this->createIndex(null, '{{%accessibilityaudit_issues}}', ['verdict']);
        // One ruling per target + rule + occurrence.
        $this->createIndex(null, '{{%accessibilityaudit_verdicts}}', ['siteId', 'targetHash', 'ruleId', 'contextHash'], true);
        $this->createIndex(null, '{{%accessibilityaudit_verdicts}}', ['siteId', 'targetHash']);
        $this->createIndex(null, '{{%accessibilityaudit_verdicts}}', ['elementId', 'siteId']);
    }

    protected function addForeignKeys(): void
    {
        $this->addForeignKey(
            null,
            '{{%accessibilityaudit_scans}}', 'elementId',
            '{{%elements}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%accessibilityaudit_scans}}', 'siteId',
            '{{%sites}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%accessibilityaudit_issues}}', 'scanId',
            '{{%accessibilityaudit_scans}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%accessibilityaudit_vpat}}', 'siteId',
            '{{%sites}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%accessibilityaudit_organisation}}', 'siteId',
            '{{%sites}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%accessibilityaudit_statement}}', 'siteId',
            '{{%sites}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%accessibilityaudit_asset_issues}}', 'assetId',
            '{{%elements}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%accessibilityaudit_asset_flags}}', 'assetId',
            '{{%elements}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%accessibilityaudit_verdicts}}', 'elementId',
            '{{%elements}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%accessibilityaudit_verdicts}}', 'siteId',
            '{{%sites}}', 'id',
            'CASCADE', 'CASCADE'
        );
        // Keep the ruling if the author who made it is deleted: the judgement
        // still stands, we just lose the attribution.
        $this->addForeignKey(
            null,
            '{{%accessibilityaudit_verdicts}}', 'userId',
            '{{%users}}', 'id',
            'SET NULL', 'CASCADE'
        );
    }
}
