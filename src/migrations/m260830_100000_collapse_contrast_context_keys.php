<?php

namespace johnhenry\accessibilityaudit\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\AuditService;

/**
 * Collapses contrast occurrences onto their opening tag.
 *
 * A contrast occurrence was keyed on whatever markup the engine handed over,
 * and axe only reduces an element to its bare opening tag once the full markup
 * runs past its own limit. Which side of that limit an element falls on
 * depends on how much of the page has rendered when the check runs: a code
 * block that a highlighter expands to nine lines is under the limit before the
 * highlighter finishes and over it after.
 *
 * So the same element arrived as two different strings, hashed two different
 * ways, and became two occurrences. A ruling given to one never reached the
 * other, and a question answered this morning came back on the next scan
 * looking identical, because it was the same element wearing a different key.
 *
 * The scanner stores the opening tag alone now. This brings the rows and the
 * rulings already stored onto the same footing: the longer form is shortened,
 * and any ruling made against it moves onto the key the shortened form has. A
 * page carrying several elements with one opening tag between them collapses
 * to a single question, which is the intended trade: they are the same
 * question with the same answer.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260830_100000_collapse_contrast_context_keys extends Migration
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[] The rules whose context is a markup snippet keyed this way.
     */
    private const CONTRAST_RULES = [
        'potential:contrast-unmeasurable',
        'color-contrast',
        'contrast-hover',
        'contrast-focus',
        'contrast-selection',
    ];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $verdicts = AccessibilityAudit::getInstance()->verdicts;

        // Every ruling already stored, by the key it is filed under, so a
        // candidate can be checked without a query each time round.
        $stored = [];

        foreach ((new Query())
            ->select(['id', 'targetHash', 'ruleId', 'contextHash', 'dateCreated'])
            ->from('{{%accessibilityaudit_verdicts}}')
            ->where(['ruleId' => self::CONTRAST_RULES])
            ->each() as $row) {
            $stored[$row['targetHash'] . '|' . $row['ruleId'] . '|' . $row['contextHash']] = [
                'id' => (int)$row['id'],
                'date' => (string)$row['dateCreated'],
            ];
        }

        $moved = 0;
        $merged = 0;
        $shortened = 0;
        $seen = [];

        foreach ((new Query())
            ->select(['i.id', 'i.elementId', 'i.ruleId', 'i.context', 's.url'])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->leftJoin(['s' => '{{%accessibilityaudit_scans}}'], '[[s.id]] = [[i.scanId]]')
            ->where(['i.ruleId' => self::CONTRAST_RULES])
            ->each() as $issue) {
            $context = (string)($issue['context'] ?? '');

            // The client-side pass stores JSON rather than bare markup, and
            // that shape is keyed on its own fields. Left alone.
            if ($context === '' || str_starts_with(ltrim($context), '{')) {
                continue;
            }

            $short = AuditService::openingTagOf($context);

            if ($short === $context) {
                continue;
            }

            // Bring the row itself onto the shortened form, so the report and
            // the next scan agree about what this occurrence is.
            $this->update('{{%accessibilityaudit_issues}}', ['context' => $short], ['id' => (int)$issue['id']]);
            $shortened++;

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
            $newKey = $targetHash . '|' . $issue['ruleId'] . '|' . $verdicts->stableContextHash($short);

            if ($oldKey === $newKey || !isset($stored[$oldKey])) {
                continue;
            }

            // Both forms were ruled on. They are the same question, so the
            // later answer stands and the older row goes: the unique index
            // would refuse the move anyway.
            if (isset($stored[$newKey])) {
                $loser = $stored[$oldKey]['date'] > $stored[$newKey]['date'] ? $newKey : $oldKey;
                $winner = $loser === $oldKey ? $newKey : $oldKey;

                $this->delete('{{%accessibilityaudit_verdicts}}', ['id' => $stored[$loser]['id']]);

                if ($winner === $oldKey) {
                    $this->update(
                        '{{%accessibilityaudit_verdicts}}',
                        ['contextHash' => $verdicts->stableContextHash($short)],
                        ['id' => $stored[$oldKey]['id']],
                    );
                    $stored[$newKey] = $stored[$oldKey];
                }

                unset($stored[$loser === $oldKey ? $oldKey : $newKey]);
                $merged++;

                continue;
            }

            $this->update(
                '{{%accessibilityaudit_verdicts}}',
                [
                    'contextHash' => $verdicts->stableContextHash($short),
                    'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                ],
                ['id' => $stored[$oldKey]['id']],
            );

            $stored[$newKey] = $stored[$oldKey];
            unset($stored[$oldKey]);
            $moved++;
        }

        echo "    > shortened {$shortened} occurrence(s), moved {$moved} ruling(s), merged {$merged}\n";

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // The longer form was whatever the engine happened to return that run.
        // There is nothing to restore it from, and nothing worth restoring.
        echo "m260830_100000_collapse_contrast_context_keys cannot be reverted.\n";

        return true;
    }
}
