<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;
use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;

/**
 * Clears contrast findings recorded against decorative markup.
 *
 * axe measured the contrast of `aria-hidden="true"` elements, which this
 * plugin's own contrast pass has always skipped, so a page could be asked
 * about a glyph its other engine had deliberately passed over. Those rows are
 * questions about correct markup and were never going to be answered usefully.
 *
 * Genuine findings are untouched, and anything cleared here that turns out to
 * be real comes back on the next scan.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260827_160000_clear_decorative_contrast_findings extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $rows = (new Query())
            ->select(['id', 'scanId', 'context'])
            ->from('{{%accessibilityaudit_issues}}')
            ->where([
                'ruleId' => [
                    'potential:contrast-unmeasurable',
                    'axe:color-contrast',
                ],
            ])
            // Narrowed in SQL, decided in PHP: the needs-review rows store raw
            // markup and the violation rows store JSON, so the quoting differs
            // and only the attribute name is reliably the same in both.
            ->andWhere(['like', 'context', 'aria-hidden'])
            ->all();

        $ids = [];
        $scanIds = [];

        foreach ($rows as $row) {
            // The optional backslash covers the JSON-encoded form, where the
            // attribute's quotes arrive escaped.
            if (preg_match('/aria-hidden\s*=\s*\\\\?["\']true/i', (string)$row['context']) !== 1) {
                continue;
            }

            $ids[] = (int)$row['id'];
            $scanIds[(int)$row['scanId']] = true;
        }

        if (empty($ids)) {
            return true;
        }

        $this->delete('{{%accessibilityaudit_issues}}', ['id' => $ids]);

        // A cleared contrast violation counted against the score, so the scans
        // it was on are worked out again rather than being left low.
        $audit = AccessibilityAudit::getInstance()?->audit;

        if ($audit !== null) {
            foreach (array_keys($scanIds) as $scanId) {
                $audit->recalculateScanScore($scanId);
            }
        }

        echo '    > cleared ' . count($ids) . " contrast findings on decorative markup\n";

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260827_160000_clear_decorative_contrast_findings cannot be reverted.\n";

        return false;
    }
}
