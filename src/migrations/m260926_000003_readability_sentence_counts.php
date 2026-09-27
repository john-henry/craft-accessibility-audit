<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;

/**
 * Adds counts of hard and very hard sentences to stored readability results.
 *
 * Both are null on existing rows until the page is analysed again: they are
 * counted from the page's text, which the stored scores don't keep.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class m260926_000003_readability_sentence_counts extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function safeUp(): bool
    {
        $table = '{{%accessibilityaudit_readability}}';

        if (!$this->db->columnExists($table, 'hardSentences')) {
            $this->addColumn($table, 'hardSentences', $this->integer()->null()->after('avgWordsPerSentence'));
        }

        if (!$this->db->columnExists($table, 'veryHardSentences')) {
            $this->addColumn($table, 'veryHardSentences', $this->integer()->null()->after('hardSentences'));
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always true.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function safeDown(): bool
    {
        $table = '{{%accessibilityaudit_readability}}';

        if ($this->db->columnExists($table, 'veryHardSentences')) {
            $this->dropColumn($table, 'veryHardSentences');
        }

        if ($this->db->columnExists($table, 'hardSentences')) {
            $this->dropColumn($table, 'hardSentences');
        }

        return true;
    }
}
