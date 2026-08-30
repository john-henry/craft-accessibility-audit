<?php

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;
use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;

/**
 * Re-keys existing rulings onto the id-independent hash.
 *
 * A ruling is keyed on a hash of the markup it was made against. That markup
 * used to include the element's id, and some form builders mint a fresh token
 * into every id each time a page is drawn: Formie does, so the same field
 * arrives as "fui-contactForm-npsmjc-fields-message", then "-hzebiw-", then
 * "-psggtb-". Every scan therefore produced a key no stored ruling matched,
 * and a question answered yesterday was asked again today, for good.
 *
 * Ids are out of the key now, but that only helps rulings made from here on.
 * The ones already stored are keyed on markup nobody has a copy of any more,
 * so they cannot be found and cannot be re-keyed from the verdicts table
 * alone: it holds the hash, never the markup.
 *
 * The issues table does hold it. Every occurrence keeps the markup it was
 * found in, so the original context can be recovered from there, hashed both
 * ways, and any ruling stored under the old hash moved onto the new one.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260830_090000_restable_verdict_keys extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $verdicts = AccessibilityAudit::getInstance()->verdicts;

        // Keyed by "targetHash|ruleId|contextHash", so a candidate can be
        // looked at without a query each time round.
        $stored = [];

        foreach ((new Query())
            ->select(['id', 'targetHash', 'ruleId', 'contextHash'])
            ->from('{{%accessibilityaudit_verdicts}}')
            ->each() as $row) {
            $stored[$row['targetHash'] . '|' . $row['ruleId'] . '|' . $row['contextHash']] = (int)$row['id'];
        }

        if (empty($stored)) {
            return true;
        }

        $moved = 0;
        $merged = 0;
        $seen = [];

        // One pass over the occurrences, which is where the markup lives. The
        // same markup repeats across scans of a page, so each distinct target,
        // rule and context is only worth working out once.
        foreach ((new Query())
            ->select(['i.elementId', 'i.ruleId', 'i.context', 's.url'])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->leftJoin(['s' => '{{%accessibilityaudit_scans}}'], '[[s.id]] = [[i.scanId]]')
            ->where(['like', 'i.ruleId', 'potential:%', false])
            ->each() as $issue) {
            $context = (string)($issue['context'] ?? '');

            if ($context === '') {
                continue;
            }

            $targetHash = $verdicts->targetHash(
                !empty($issue['elementId']) ? (int)$issue['elementId'] : null,
                $issue['url'] ?? null,
            );

            $dedupe = $targetHash . '|' . $issue['ruleId'] . '|' . md5($context);

            if (isset($seen[$dedupe])) {
                continue;
            }

            $seen[$dedupe] = true;

            $old = $verdicts->contextHash($context);
            $new = $verdicts->stableContextHash($context);

            // Markup with no id in it hashes the same both ways, which is most
            // of the site: nothing to move.
            if ($old === $new) {
                continue;
            }

            $oldKey = $targetHash . '|' . $issue['ruleId'] . '|' . $old;
            $newKey = $targetHash . '|' . $issue['ruleId'] . '|' . $new;

            if (!isset($stored[$oldKey])) {
                continue;
            }

            // A ruling already sits under the new key, made since the change.
            // That one is current, so the old row is a duplicate of an answer
            // already given and goes.
            if (isset($stored[$newKey])) {
                $this->delete('{{%accessibilityaudit_verdicts}}', ['id' => $stored[$oldKey]]);
                unset($stored[$oldKey]);
                $merged++;

                continue;
            }

            $this->update(
                '{{%accessibilityaudit_verdicts}}',
                ['contextHash' => $new],
                ['id' => $stored[$oldKey]],
            );

            $stored[$newKey] = $stored[$oldKey];
            unset($stored[$oldKey]);
            $moved++;
        }

        echo "    > moved {$moved} ruling(s) onto the id-independent key, merged {$merged} duplicate(s)\n";

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // The old keys were built from markup that no longer exists anywhere,
        // so there is nothing to put back. Leaving the rulings where they are
        // costs nothing: on an older build they simply go unmatched again,
        // which is the state this migration found them in.
        echo "m260830_090000_restable_verdict_keys cannot be reverted.\n";

        return true;
    }
}
