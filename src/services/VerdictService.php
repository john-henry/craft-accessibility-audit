<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use yii\base\Component;
use yii\db\Exception;

/**
 * Records the author's ruling on a potential issue.
 *
 * Potential issues are the checks the scanner cannot settle on its own, so each
 * is phrased as a question. This service stores the answer: dismissed (looked
 * at, not a problem) or confirmed (a real failure, count it against the score).
 *
 * Rulings are kept in their own table rather than on the issue rows, because
 * issue rows are rebuilt on every scan and a verdict stored there would be
 * wiped by the next one. They are keyed by the things that survive a re-scan:
 * the site, the page, the rule, and a hash of the offending markup. The page is
 * an element, or a URL for one Craft does not back with an element, and both
 * reduce to a single non-null targetHash so the index enforcing one ruling per
 * question actually holds either way. The
 * matching issue rows carry a copy so the scoring and listing queries stay
 * join-free; this service keeps that copy in step.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class VerdictService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The author looked at this and it is not a problem. It stays
     * out of the potential list and never affects the score.
     */
    public const VERDICT_DISMISSED = 'dismissed';

    /**
     * @var string The author judged this a real failure. It leaves the
     * potential list and counts against the score like any definite issue.
     */
    public const VERDICT_CONFIRMED = 'confirmed';

    /**
     * @var string[] Every ruling a caller may set.
     */
    public const VERDICTS = [self::VERDICT_DISMISSED, self::VERDICT_CONFIRMED];

    // Public Methods
    // =========================================================================

    /**
     * Identifies the page a ruling belongs to: an element, or a URL for a page
     * Craft does not back with one.
     *
     * A single non-null value rather than a nullable elementId beside a
     * nullable url, because the unique index that stops one question being
     * answered twice is built on it, and both MySQL and Postgres treat nulls
     * in a unique index as distinct. Keyed on a nullable column the index
     * would enforce nothing on exactly the rows that need it.
     *
     * @param int|null $elementId The element, when there is one.
     * @param string|null $url The scanned URL, when there is not.
     * @return string A 40-character hash.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function targetHash(?int $elementId, ?string $url = null): string
    {
        return $elementId !== null && $elementId > 0
            ? sha1('element:' . $elementId)
            : sha1('url:' . (string)$url);
    }

    /**
     * Hashes the markup an occurrence was found in, so two occurrences of the
     * same rule on the same page can be ruled on separately.
     *
     * The context is the scanner's snippet of the offending element. It is
     * stable for as long as that markup is, which is exactly the lifetime a
     * ruling should have: edit the markup and the question is worth asking
     * again. A null or empty context collapses to one ruling for the rule on
     * that element, which is the best that can be done without a snippet.
     *
     * @param string|null $context The stored context snippet, if any.
     * @return string A 40-character hash.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function contextHash(?string $context): string
    {
        // Line endings are normalised before hashing: the browser's form
        // encoding converts a multi-line context's \n to \r\n on the way up,
        // so without this a ruling on any multi-line snippet hashed to a
        // value no stored row could ever match: saved, matched nothing,
        // and the question came straight back.
        return sha1(trim(str_replace(["\r\n", "\r"], "\n", (string)$context)));
    }

    /**
     * Records (or clears) the ruling on one potential issue, and brings the
     * stored issue rows into line so the change shows without a re-scan.
     *
     * @param int $siteId The site the issue belongs to.
     * @param int|null $elementId The element the issue was found on, or null
     *                            for a page scanned by URL.
     * @param string $ruleId The potential rule, e.g. `potential:decorative-image`.
     * @param string|null $context The occurrence's context snippet.
     * @param string|null $verdict One of self::VERDICTS, or null to clear.
     * @param string|null $note The author's reasoning, kept for the audit trail.
     * @param string|null $url The scanned URL, when there is no element.
     * @throws Exception
     * @throws \Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function setVerdict(
        int $siteId,
        ?int $elementId,
        string $ruleId,
        ?string $context,
        ?string $verdict,
        ?string $note = null,
        ?string $url = null,
    ): void {
        $db = Craft::$app->getDb();
        $hash = $this->contextHash($context);
        $now = Db::prepareDateForDb(new DateTime());
        $elementId = $elementId !== null && $elementId > 0 ? $elementId : null;

        // Matched on the target hash, not on elementId and url separately: it
        // is the one column that is never null and the unique index is on it.
        $match = [
            'siteId' => $siteId,
            'targetHash' => $this->targetHash($elementId, $url),
            'ruleId' => $ruleId,
            'contextHash' => $hash,
        ];

        if ($verdict === null) {
            $db->createCommand()->delete('{{%accessibilityaudit_verdicts}}', $match)->execute();
        } else {
            $existing = (new Query())
                ->select(['id'])
                ->from('{{%accessibilityaudit_verdicts}}')
                ->where($match)
                ->scalar();

            if ($existing) {
                $db->createCommand()->update('{{%accessibilityaudit_verdicts}}', [
                    'verdict' => $verdict,
                    'note' => $note,
                    'userId' => Craft::$app->getUser()->getId(),
                    'dateUpdated' => $now,
                ], ['id' => $existing])->execute();
            } else {
                $db->createCommand()->insert('{{%accessibilityaudit_verdicts}}', $match + [
                    'elementId' => $elementId,
                    'url' => $elementId === null ? $url : null,
                    'verdict' => $verdict,
                    'note' => $note,
                    'userId' => Craft::$app->getUser()->getId(),
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ])->execute();
            }
        }

        $this->applyToIssues($siteId, $elementId, $ruleId, $hash, $verdict, $url);
    }

    /**
     * Stamps the ruling onto every stored issue row it applies to.
     *
     * Matching on a hash of the stored context is what ties a ruling to one
     * occurrence; the hash is recomputed here rather than stored on the issue
     * row, so no schema is spent on a value that is derivable.
     *
     * @param int $siteId The site.
     * @param int|null $elementId The element, or null for a URL page.
     * @param string $ruleId The potential rule.
     * @param string $hash The context hash the ruling applies to.
     * @param string|null $verdict The ruling, or null to clear it.
     * @param string|null $url The scanned URL, when there is no element.
     * @throws Exception
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function applyToIssues(
        int $siteId,
        ?int $elementId,
        string $ruleId,
        string $hash,
        ?string $verdict,
        ?string $url = null,
    ): void {
        $query = (new Query())
            ->select(['i.id', 'i.context', 'i.scanId'])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->where(['i.siteId' => $siteId, 'i.ruleId' => $ruleId]);

        if ($elementId !== null && $elementId > 0) {
            $query->andWhere(['i.elementId' => $elementId]);
        } else {
            // A URL page's issue rows carry no element, so they are reached
            // through the scan that holds the URL.
            $query
                ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], '[[s.id]] = [[i.scanId]]')
                ->andWhere(['s.url' => $url]);
        }

        $rows = $query->all();

        $ids = [];
        $scanIds = [];
        foreach ($rows as $row) {
            if ($this->contextHash($row['context']) === $hash) {
                $ids[] = (int)$row['id'];
                $scanIds[(int)$row['scanId']] = true;
            }
        }

        if (empty($ids)) {
            return;
        }

        Craft::$app->getDb()->createCommand()->update('{{%accessibilityaudit_issues}}', [
            'verdict' => $verdict,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $ids])->execute();

        // Confirming promotes the issue into a real failure, so the score has
        // to be redone. Clearing or dismissing can equally take one back out.
        foreach (array_keys($scanIds) as $scanId) {
            AccessibilityAudit::getInstance()->audit->recalculateScoreForScan($scanId);
        }
    }

    /**
     * Every ruling recorded for an element, keyed so a scan can stamp its fresh
     * issue rows in one pass instead of a query per issue.
     *
     * @param int|null $elementId The element, or null for a URL page.
     * @param int $siteId The site.
     * @param string|null $url The scanned URL, when there is no element.
     * @return array<string, string> "ruleId|contextHash" => verdict.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function mapForElement(?int $elementId, int $siteId, ?string $url = null): array
    {
        $rows = (new Query())
            ->select(['ruleId', 'contextHash', 'verdict'])
            ->from('{{%accessibilityaudit_verdicts}}')
            ->where(['targetHash' => $this->targetHash($elementId, $url), 'siteId' => $siteId])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['ruleId'] . '|' . $row['contextHash']] = $row['verdict'];
        }

        return $map;
    }

    /**
     * Who recorded each ruling, and when, for a batch of pages.
     *
     * Attribution lives only on the verdicts table: the copy stamped onto the
     * issues row carries the ruling but not its author. There is no join to be
     * had either, because the issues row stores the raw context and the hash
     * that keys a verdict is derived in PHP, so a listing resolves the two in
     * one batched query and matches in memory rather than querying per row.
     *
     * @param string[] $targetHashes The pages on the current page of results,
     *                                from {@see self::targetHash()}.
     * @param int $siteId The site.
     * @return array<string, array{userId: int|null, date: string|null}> Keyed
     *         "targetHash|ruleId|contextHash".
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function metaForTargets(array $targetHashes, int $siteId): array
    {
        if (empty($targetHashes)) {
            return [];
        }

        $rows = (new Query())
            ->select(['targetHash', 'ruleId', 'contextHash', 'userId', 'dateUpdated'])
            ->from('{{%accessibilityaudit_verdicts}}')
            ->where(['targetHash' => $targetHashes, 'siteId' => $siteId])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $key = $row['targetHash'] . '|' . $row['ruleId'] . '|' . $row['contextHash'];
            $map[$key] = [
                'userId' => $row['userId'] !== null ? (int)$row['userId'] : null,
                'date' => $row['dateUpdated'] ?? null,
            ];
        }

        return $map;
    }

    /**
     * Attribution for one occurrence, from a map built by
     * {@see self::metaForElements()}.
     *
     * @param array<string, array{userId: int|null, date: string|null}> $map The batch.
     * @param string $targetHash The page, from {@see self::targetHash()}.
     * @param string $ruleId The rule.
     * @param string|null $context The occurrence's context snippet.
     * @return array{userId: int|null, date: string|null}|null Null when nothing was recorded.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function lookupMeta(array $map, string $targetHash, string $ruleId, ?string $context): ?array
    {
        $meta = $map[$targetHash . '|' . $ruleId . '|' . $this->contextHash($context)] ?? null;
        if ($meta !== null) {
            return $meta;
        }

        $legacy = $this->_legacyContextHash($context);

        return $legacy !== null
            ? ($map[$targetHash . '|' . $ruleId . '|' . $legacy] ?? null)
            : null;
    }

    /**
     * The ruling for one occurrence, from a map built by
     * {@see self::mapForElement()}.
     *
     * @param array<string, string> $map The element's rulings.
     * @param string $ruleId The rule.
     * @param string|null $context The occurrence's context snippet.
     * @return string|null The ruling, or null if unreviewed.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function lookup(array $map, string $ruleId, ?string $context): ?string
    {
        $verdict = $map[$ruleId . '|' . $this->contextHash($context)] ?? null;
        if ($verdict !== null) {
            return $verdict;
        }

        $legacy = $this->_legacyContextHash($context);

        return $legacy !== null
            ? ($map[$ruleId . '|' . $legacy] ?? null)
            : null;
    }

    // Private Methods
    // =========================================================================

    /**
     * The hash a pre-1.0.11 scan would have stored for this context, or null
     * when the two could not differ.
     *
     * Image contexts were capped at 150 characters before 1.0.11 and keep 300
     * now, so the src URL survives (see PotentialScanner). Rulings stored
     * against the shorter snippet are still keyed on it, so a longer context
     * also tries the reconstruction: the first 150 characters plus the
     * truncation ellipsis. Without it every dismissed image question returns.
     *
     * @param string|null $context The current (longer) context snippet.
     * @return string|null The legacy hash, or null when the context is short
     *                     enough that both builders stored it identically.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _legacyContextHash(?string $context): ?string
    {
        // Same line-ending normalisation as contextHash(), before measuring:
        // the reconstruction must cut at the same position.
        $context = trim(str_replace(["\r\n", "\r"], "\n", (string)$context));

        // At 150 or under both forms are identical, so there is no second
        // hash. From 151 up they differ: the shorter form cut at 150 plus the
        // ellipsis where the current one keeps the snippet whole.
        if (mb_strlen($context) <= 150) {
            return null;
        }

        $body = str_ends_with($context, '…') ? mb_substr($context, 0, -1) : $context;

        return $this->contextHash(mb_substr($body, 0, 150) . '…');
    }
}
