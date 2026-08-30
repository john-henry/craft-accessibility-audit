<?php

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;
use craft\db\Query;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;

/**
 * Takes the plugin's own marks back out of stored occurrences.
 *
 * The report marks an element in its preview to highlight it, with a
 * data-accessibility-audit-* attribute. The browser pass then reads that same
 * preview back, so an element that had been highlighted was reported carrying
 * the mark:
 *
 *     <span class="token scalar string" data-accessibility-audit-hl="first">
 *
 * That is a different string from the element itself, so it hashed differently
 * and became a second occurrence. Clicking "Show on page" and re-scanning
 * therefore turned a question already answered into a fresh one, which is why
 * a dismissal looked like it would not stick: it had stuck, to an element the
 * scanner no longer recognised as the same one.
 *
 * The scanner strips its own marks now. This clears the ones already stored
 * and moves any ruling made against a marked form onto the clean key.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260830_110000_strip_own_marks_from_contexts extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $verdicts = AccessibilityAudit::getInstance()->verdicts;

        $stored = [];

        foreach ((new Query())
            ->select(['id', 'targetHash', 'ruleId', 'contextHash', 'dateCreated'])
            ->from('{{%accessibilityaudit_verdicts}}')
            ->each() as $row) {
            $stored[$row['targetHash'] . '|' . $row['ruleId'] . '|' . $row['contextHash']] = [
                'id' => (int)$row['id'],
                'date' => (string)$row['dateCreated'],
            ];
        }

        $cleaned = 0;
        $moved = 0;
        $merged = 0;
        $seen = [];

        // Only rows actually carrying a mark, so this is a narrow pass rather
        // than a walk over every occurrence on the site.
        foreach ((new Query())
            ->select(['i.id', 'i.elementId', 'i.ruleId', 'i.context', 's.url'])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->leftJoin(['s' => '{{%accessibilityaudit_scans}}'], '[[s.id]] = [[i.scanId]]')
            ->where(['like', 'i.context', 'data-accessibility-audit'])
            ->each() as $issue) {
            $context = (string)($issue['context'] ?? '');
            $clean = AuditService::openingTagOf($context);

            if ($clean === $context || $clean === '') {
                continue;
            }

            $this->update('{{%accessibilityaudit_issues}}', ['context' => $clean], ['id' => (int)$issue['id']]);
            $cleaned++;

            $targetHash = $verdicts->targetHash(
                !empty($issue['elementId']) ? (int)$issue['elementId'] : null,
                $issue['url'] ?? null,
            );

            $dedupe = $targetHash . '|' . $issue['ruleId'] . '|' . md5($context);

            if (isset($seen[$dedupe])) {
                continue;
            }

            $seen[$dedupe] = true;

            $oldKey = $targetHash . '|' . $issue['ruleId'] . '|' . $verdicts->stableContextHash($context);
            $newKey = $targetHash . '|' . $issue['ruleId'] . '|' . $verdicts->stableContextHash($clean);

            if ($oldKey === $newKey || !isset($stored[$oldKey])) {
                continue;
            }

            // Both forms were ruled on: same question, so the later answer
            // stands and the other row goes.
            if (isset($stored[$newKey])) {
                $this->delete('{{%accessibilityaudit_verdicts}}', ['id' => $stored[$oldKey]['id']]);
                unset($stored[$oldKey]);
                $merged++;

                continue;
            }

            $this->update(
                '{{%accessibilityaudit_verdicts}}',
                ['contextHash' => $verdicts->stableContextHash($clean)],
                ['id' => $stored[$oldKey]['id']],
            );

            $stored[$newKey] = $stored[$oldKey];
            unset($stored[$oldKey]);
            $moved++;
        }

        echo "    > cleaned {$cleaned} occurrence(s), moved {$moved} ruling(s), merged {$merged}\n";

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // The marks were the plugin's own and never belonged in the data.
        echo "m260830_110000_strip_own_marks_from_contexts cannot be reverted.\n";

        return true;
    }
}
