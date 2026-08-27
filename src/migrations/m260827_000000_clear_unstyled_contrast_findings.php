<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;
use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;

/**
 * Clears colour-contrast findings recorded against an unstyled page.
 *
 * Nobody chooses #0000EE: it is what an anchor computes to when no author
 * styles apply, so a finding against it was measured before the page was
 * styled. Dropping them is safe either way, since a genuine failure comes
 * back on the next scan.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.1.1
 */
class m260827_000000_clear_unstyled_contrast_findings extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws \yii\db\Exception
     */
    public function safeUp(): bool
    {
        $condition = [
            'and',
            ['ruleId' => 'color-contrast'],
            ['source' => 'contrast'],
            ['like', 'context', '"fg":"#0000EE"'],
        ];

        // Captured before the delete: the scans holding these rows have scores
        // and counts that were worked out with them included.
        $scanIds = (new Query())
            ->select(['scanId'])
            ->distinct()
            ->from('{{%accessibilityaudit_issues}}')
            ->where($condition)
            ->column();

        if (empty($scanIds)) {
            return true;
        }

        $deleted = $this->db->createCommand()
            ->delete('{{%accessibilityaudit_issues}}', $condition)
            ->execute();

        echo "    > cleared {$deleted} contrast finding(s) recorded against unstyled pages\n";

        $audit = AccessibilityAudit::getInstance()?->getAudit();

        if ($audit === null) {
            return true;
        }

        foreach ($scanIds as $scanId) {
            $audit->recalculateScanScore((int)$scanId);
        }

        echo '    > recalculated ' . count($scanIds) . " scan score(s)\n";

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260827_000000_clear_unstyled_contrast_findings cannot be reverted.\n";

        return false;
    }
}
