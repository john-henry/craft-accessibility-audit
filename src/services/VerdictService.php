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
 * @author John Henry Donovan <info@johnhenry.ie>
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

    /**
     * @var int How many issue rows an every-page ruling reads or updates at a
     * time.
     */
    private const SITE_VERDICT_BATCH = 1000;

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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function targetHash(?int $elementId, ?string $url = null): string
    {
        return $elementId !== null && $elementId > 0
            ? sha1('element:' . $elementId)
            : sha1('url:' . (string)$url);
    }

    /**
     * The target an every-page ruling is filed under.
     *
     * Stored in the same table and under the same unique index as a page's
     * rulings, with the site's own id in its siteId column, so one question
     * answered for the whole site can only be answered once.
     *
     * @return string A 40-character hash.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function siteTargetHash(): string
    {
        return sha1('site');
    }

    /**
     * What identifies an occurrence across every page, or null when nothing
     * does.
     *
     * The markup is used when it says something of its own: a class, an
     * attribute, some text. A bare tag says nothing, since `<dt>` matches
     * every `<dt>` on the site, so there the browser's selector is used, and
     * only when it names a class or an id. A selector of tag names alone
     * ("dl > dt") is as unspecific as the bare tag. With neither, there is no
     * telling this element from others like it, and no every-page ruling is
     * offered.
     *
     * @param string|null $context The occurrence's context snippet.
     * @param string|null $selector The browser's selector for the element.
     * @return string|null A 40-character hash, or null.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function siteKey(?string $context, ?string $selector): ?string
    {
        if ($this->_isDistinctive($context)) {
            return 'c' . substr($this->stableContextHash($context), 1);
        }

        $selector = trim((string)$selector);

        if ($selector !== '' && preg_match('/[.#]/', $selector) === 1) {
            return 's' . substr(sha1('selector:' . $selector), 1);
        }

        return null;
    }

    /**
     * Records (or clears) a "not an issue" ruling on every page of a site, and
     * brings the stored issue rows into line.
     *
     * A page's own ruling always wins: the rows of a page where this occurrence
     * was confirmed, or dismissed on its own, are left as they are. Clearing
     * puts back only the rows this ruling had answered.
     *
     * @param int $siteId The site.
     * @param string $ruleId The potential rule.
     * @param string|null $context The occurrence's context snippet.
     * @param string|null $selector The browser's selector for the element.
     * @param string|null $verdict self::VERDICT_DISMISSED, or null to clear.
     * @param bool $deferScoring Skip the score recalculation and hand back the
     *                           scans that need one.
     * @param callable|null $canApply Given the ids of the elements whose rows
     *                                the ruling would change, returns whether
     *                                it may go ahead. Nothing is recorded when
     *                                it returns false.
     * @return array{key: string|null, pages: int, scanIds: int[], refused: bool}
     *         The key the ruling was filed under (null when the occurrence has
     *         nothing to identify it), how many pages it changed, the scans
     *         whose scores are now out of date, and whether $canApply refused.
     * @throws Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function setSiteVerdict(
        int $siteId,
        string $ruleId,
        ?string $context,
        ?string $selector,
        ?string $verdict,
        bool $deferScoring = false,
        ?callable $canApply = null,
    ): array {
        $key = $this->siteKey($context, $selector);

        if ($key === null || ($verdict !== null && $verdict !== self::VERDICT_DISMISSED)) {
            return ['key' => null, 'pages' => 0, 'scanIds' => [], 'refused' => false];
        }

        $matches = $this->_matchSiteVerdict($siteId, $ruleId, $key, $verdict);

        if ($canApply !== null && !$canApply($matches['elementIds'])) {
            return ['key' => $key, 'pages' => 0, 'scanIds' => [], 'refused' => true];
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());
        $match = [
            'siteId' => $siteId,
            'targetHash' => $this->siteTargetHash(),
            'ruleId' => $ruleId,
            'contextHash' => $key,
        ];

        $db->transaction(function() use ($db, $match, $verdict, $now, $matches): void {
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
                        'userId' => Craft::$app->getUser()->getId(),
                        'dateUpdated' => $now,
                    ], ['id' => $existing])->execute();
                } else {
                    $db->createCommand()->insert('{{%accessibilityaudit_verdicts}}', $match + [
                        'elementId' => null,
                        'url' => null,
                        'verdict' => $verdict,
                        'userId' => Craft::$app->getUser()->getId(),
                        'dateCreated' => $now,
                        'dateUpdated' => $now,
                        'uid' => StringHelper::UUID(),
                    ])->execute();
                }
            }

            foreach (array_chunk($matches['ids'], self::SITE_VERDICT_BATCH) as $ids) {
                $db->createCommand()->update('{{%accessibilityaudit_issues}}', [
                    'verdict' => $verdict,
                    'dateUpdated' => $now,
                ], [
                    'id' => $ids,
                    // A row a page ruled on since it was read keeps that ruling.
                    'verdict' => $verdict !== null ? null : self::VERDICT_DISMISSED,
                ])->execute();
            }
        });

        if (!$deferScoring) {
            foreach ($matches['scanIds'] as $scanId) {
                AccessibilityAudit::getInstance()->getAudit()->recalculateScoreForScan($scanId);
            }
        }

        return [
            'key' => $key,
            'pages' => $matches['pages'],
            'scanIds' => $matches['scanIds'],
            'refused' => false,
        ];
    }

    /**
     * @var string[] Attributes holding an element id, or a reference to one.
     *
     *      Some form builders mint a fresh token into every id on the page at
     *      each render, Formie among them: the same textarea comes back as
     *      "fui-support-kqjyvj-fields-details", then "-jbuoir-", then
     *      "-jhaqor-". Keyed on anything carrying that, an occurrence is a new
     *      occurrence every scan, and every ruling made on it is orphaned.
     */
    private const IDREF_ATTRIBUTES = [
        'id', 'for', 'form', 'list', 'headers', 'aria-labelledby', 'aria-describedby',
        'aria-controls', 'aria-owns', 'aria-activedescendant', 'aria-flowto',
        'aria-details', 'aria-errormessage',
    ];

    /**
     * The hash an occurrence is keyed on, ignoring anything that changes
     * between two renders of the same page.
     *
     * No attempt is made to tell a generated id from a hand-written one. There
     * is no reliable test for it: "kqjyvj" and "submit" are both six lowercase
     * letters, and guessing wrong the other way is worse than the problem,
     * because two genuinely separate occurrences would collapse into one and a
     * ruling on either would silently answer both. So no id is trusted at all,
     * and identity comes from what is left: the tag, its classes, the field
     * name, and the text.
     *
     * @param string|null $context The occurrence's context snippet.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function stableContextHash(?string $context): string
    {
        return sha1($this->_normalisedContext($context));
    }

    /**
     * The older key a ruling may still be stored under.
     *
     * Superseded by {@see self::stableContextHash()}, which strips the ids that
     * move between renders. Kept because rulings filed before that change are
     * still keyed this way, and they are read on the way past so a reader is
     * never asked a question they have already answered.
     *
     * Line endings are normalised first: the browser's form encoding turns a
     * multi-line context's \n into \r\n on the way up, and without this a
     * ruling on any multi-line snippet hashed to a value no stored row could
     * match. It saved, matched nothing, and the question came straight back.
     *
     * @param string|null $context The occurrence's context snippet.
     * @return string The sha1 key.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @param bool $deferScoring Skip the score recalculation and hand back the
     *                           scans that need one. A caller ruling on fifty
     *                           occurrences at once would otherwise recompute
     *                           the same scan fifty times.
     * @return int[] The scans whose scores are now out of date.
     * @throws Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
        bool $deferScoring = false,
    ): array {
        $db = Craft::$app->getDb();
        $hash = $this->stableContextHash($context);
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

        // The ruling and the issue rows it settles go in together. Apart, a
        // failure between them leaves a question answered in one table and
        // unanswered in the other, and the reader is asked it again next time
        // they open the page.
        return $db->transaction(function() use (
            $db,
            $match,
            $verdict,
            $note,
            $now,
            $elementId,
            $url,
            $siteId,
            $ruleId,
            $hash,
            $deferScoring
        ): array {
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

            return $this->applyToIssues($siteId, $elementId, $ruleId, $hash, $verdict, $url, $deferScoring);
        });
    }

    /**
     * Puts dismissed potential issues back into the review queue.
     *
     * The scoping is the authorisation, so it lives with the operation rather
     * than at whichever surface asked for it. An id belonging to another site,
     * one carrying a verdict that is not a dismissal, and one on an ordinary
     * rule rather than a potential, are each no business of this call whatever
     * it is handed, and a caller cannot widen that by passing different ids.
     *
     * Scoring is deferred and done once per affected scan at the end. Taking
     * back forty rulings is the case this exists for, and every occurrence in a
     * group shares one scan, so scoring per ruling recomputes the same scan
     * over and over.
     *
     * @param int $siteId The site the rulings belong to.
     * @param int[] $ids Stored issue ids to clear the ruling on.
     * @return int How many were put back.
     * @throws Exception
     * @throws \Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function restoreDismissedPotentials(int $siteId, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));

        if ($ids === []) {
            return 0;
        }

        $rows = (new Query())
            ->select(['i.elementId', 'i.ruleId', 'i.context', 'i.selector', 's.url'])
            ->from(['i' => '{{%accessibilityaudit_issues}}'])
            ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], '[[s.id]] = [[i.scanId]]')
            ->where([
                'i.id' => $ids,
                'i.siteId' => $siteId,
                'i.verdict' => self::VERDICT_DISMISSED,
            ])
            ->andWhere(['like', 'i.ruleId', 'potential:%', false])
            ->all();

        $needScoring = [];
        $clearedSite = [];

        foreach ($rows as $row) {
            $elementId = $row['elementId'] !== null ? (int)$row['elementId'] : null;
            $url = $row['url'] !== null ? (string)$row['url'] : null;
            $context = $row['context'] !== null ? (string)$row['context'] : null;

            // A row answered by an every-page ruling, with nothing recorded on
            // its own page, is put back by taking that ruling back: clearing
            // only this page would last until its next scan stamped it again.
            if (!$this->_hasPageVerdict($siteId, $elementId, $url, (string)$row['ruleId'], $context)) {
                $key = $this->siteKey($context, $row['selector'] ?? null);

                if ($key !== null && $this->_hasSiteVerdict($siteId, (string)$row['ruleId'], $key)) {
                    if (!isset($clearedSite[$row['ruleId'] . '|' . $key])) {
                        $clearedSite[$row['ruleId'] . '|' . $key] = true;
                        $result = $this->setSiteVerdict($siteId, (string)$row['ruleId'], $context, $row['selector'] ?? null, null, true);

                        foreach ($result['scanIds'] as $scanId) {
                            $needScoring[$scanId] = true;
                        }
                    }

                    continue;
                }
            }

            $scanIds = $this->setVerdict(
                $siteId,
                $row['elementId'] !== null ? (int)$row['elementId'] : null,
                (string)$row['ruleId'],
                $row['context'] !== null ? (string)$row['context'] : null,
                // Null clears the ruling and puts the question back.
                null,
                null,
                $row['url'] !== null ? (string)$row['url'] : null,
                deferScoring: true,
            );

            foreach ($scanIds as $scanId) {
                $needScoring[$scanId] = true;
            }
        }

        foreach (array_keys($needScoring) as $scanId) {
            AccessibilityAudit::getInstance()->getAudit()->recalculateScoreForScan($scanId);
        }

        return count($rows);
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
     * @param bool $deferScoring Whether to leave the recalculation to the caller.
     * @return int[] The scans whose scores are now out of date.
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function applyToIssues(
        int $siteId,
        ?int $elementId,
        string $ruleId,
        string $hash,
        ?string $verdict,
        ?string $url = null,
        bool $deferScoring = false,
    ): array {
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
            // Compared on the stable hash, and on the two older forms as
            // well: a ruling made before this changed is still that ruling.
            if (
                $this->stableContextHash($row['context']) === $hash
                || $this->contextHash($row['context']) === $hash
                || $this->_legacyContextHash($row['context']) === $hash
            ) {
                $ids[] = (int)$row['id'];
                $scanIds[(int)$row['scanId']] = true;
            }
        }

        if (empty($ids)) {
            return [];
        }

        Craft::$app->getDb()->createCommand()->update('{{%accessibilityaudit_issues}}', [
            'verdict' => $verdict,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $ids])->execute();

        $affected = array_map('intval', array_keys($scanIds));

        // Confirming promotes the issue into a real failure, so the score has
        // to be redone. Clearing or dismissing can equally take one back out.
        if ($deferScoring) {
            return $affected;
        }

        foreach ($affected as $scanId) {
            AccessibilityAudit::getInstance()->getAudit()->recalculateScoreForScan($scanId);
        }

        return $affected;
    }

    /**
     * Every ruling recorded for an element, keyed so a scan can stamp its fresh
     * issue rows in one pass instead of a query per issue.
     *
     * @param int|null $elementId The element, or null for a URL page.
     * @param int $siteId The site.
     * @param string|null $url The scanned URL, when there is no element.
     * @return array<string, string> "ruleId|contextHash" => verdict.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function mapForElement(?int $elementId, int $siteId, ?string $url = null): array
    {
        $siteTarget = $this->siteTargetHash();
        $rows = (new Query())
            ->select(['targetHash', 'ruleId', 'contextHash', 'verdict'])
            ->from('{{%accessibilityaudit_verdicts}}')
            ->where(['targetHash' => [$this->targetHash($elementId, $url), $siteTarget], 'siteId' => $siteId])
            ->all();

        // Every-page rulings travel in the same map under their own prefix, so
        // each caller that stamps a scan picks them up without knowing they
        // exist.
        $map = [];
        foreach ($rows as $row) {
            $prefix = $row['targetHash'] === $siteTarget ? 'site|' : '';
            $map[$prefix . $row['ruleId'] . '|' . $row['contextHash']] = $row['verdict'];
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
     *
     * @author John Henry Donovan <info@johnhenry.ie>
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
            ->where(['targetHash' => array_merge($targetHashes, [$this->siteTargetHash()]), 'siteId' => $siteId])
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
     * @param string|null $selector The browser's selector for the element.
     * @return array{userId: int|null, date: string|null, everyPage: bool}|null
     *         Null when nothing was recorded. everyPage is true when the
     *         ruling came from one made for every page.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function lookupMeta(array $map, string $targetHash, string $ruleId, ?string $context, ?string $selector = null): ?array
    {
        foreach ($this->_candidateHashes($context) as $hash) {
            $meta = $map[$targetHash . '|' . $ruleId . '|' . $hash] ?? null;

            if ($meta !== null) {
                return $meta + ['everyPage' => false];
            }
        }

        $key = $this->siteKey($context, $selector);
        $meta = $key !== null ? ($map[$this->siteTargetHash() . '|' . $ruleId . '|' . $key] ?? null) : null;

        return $meta !== null ? $meta + ['everyPage' => true] : null;
    }

    /**
     * The ruling for one occurrence, from a map built by
     * {@see self::mapForElement()}.
     *
     * The page's own ruling is read first, so a page where the occurrence was
     * confirmed stays confirmed whatever was ruled for every page.
     *
     * @param array<string, string> $map The element's rulings.
     * @param string $ruleId The rule.
     * @param string|null $context The occurrence's context snippet.
     * @param string|null $selector The browser's selector for the element,
     *                              where the scan recorded one.
     * @return string|null The ruling, or null if unreviewed.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function lookup(array $map, string $ruleId, ?string $context, ?string $selector = null): ?string
    {
        foreach ($this->_candidateHashes($context) as $hash) {
            $verdict = $map[$ruleId . '|' . $hash] ?? null;

            if ($verdict !== null) {
                return $verdict;
            }
        }

        $key = $this->siteKey($context, $selector);

        return $key !== null ? ($map['site|' . $ruleId . '|' . $key] ?? null) : null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Finds the stored issue rows an every-page ruling applies to.
     *
     * Rows a page has its own ruling for are skipped both ways: dismissing
     * leaves a confirmed row confirmed, and clearing leaves a row dismissed on
     * its own page dismissed. The rows are read in id order a batch at a time,
     * since a common element on a large site has a row on every page of every
     * retained scan.
     *
     * @param int $siteId The site.
     * @param string $ruleId The potential rule.
     * @param string $key The every-page key, from self::siteKey().
     * @param string|null $verdict self::VERDICT_DISMISSED, or null to clear.
     * @return array{ids: int[], pages: int, scanIds: int[], elementIds: int[]}
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _matchSiteVerdict(int $siteId, string $ruleId, string $key, ?string $verdict): array
    {
        // The pages' own rulings for this rule, loaded once rather than per row.
        $own = [];
        $ownRows = (new Query())
            ->select(['targetHash', 'contextHash'])
            ->from('{{%accessibilityaudit_verdicts}}')
            ->where(['siteId' => $siteId, 'ruleId' => $ruleId])
            ->andWhere(['not', ['targetHash' => $this->siteTargetHash()]])
            ->all();

        foreach ($ownRows as $ownRow) {
            $own[$ownRow['targetHash'] . '|' . $ownRow['contextHash']] = true;
        }

        $ids = [];
        $scanIds = [];
        $pages = [];
        $elementIds = [];
        $keys = [];
        $lastId = 0;

        do {
            $rows = (new Query())
                ->select(['i.id', 'i.context', 'i.selector', 'i.scanId', 'i.elementId', 'i.verdict', 's.url'])
                ->from(['i' => '{{%accessibilityaudit_issues}}'])
                ->innerJoin(['s' => '{{%accessibilityaudit_scans}}'], '[[s.id]] = [[i.scanId]]')
                ->where([
                    'i.siteId' => $siteId,
                    'i.ruleId' => $ruleId,
                    // Dismissing touches unanswered rows, clearing dismissed ones.
                    'i.verdict' => $verdict !== null ? null : self::VERDICT_DISMISSED,
                ])
                ->andWhere(['>', 'i.id', $lastId])
                ->orderBy(['i.id' => SORT_ASC])
                ->limit(self::SITE_VERDICT_BATCH)
                ->all();

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];

                // The same markup repeats on every page, so its key is worked
                // out once.
                $rowKey = $keys[$row['context'] . "\0" . $row['selector']] ??= $this->siteKey($row['context'], $row['selector']);

                if ($rowKey !== $key) {
                    continue;
                }

                $elementId = $row['elementId'] !== null ? (int)$row['elementId'] : null;
                $target = $this->targetHash($elementId, $row['url'] !== null ? (string)$row['url'] : null);

                foreach ($this->_candidateHashes($row['context']) as $hash) {
                    if (isset($own[$target . '|' . $hash])) {
                        continue 2;
                    }
                }

                $ids[] = $lastId;
                $scanIds[(int)$row['scanId']] = true;
                $pages[$target] = true;

                if ($elementId !== null) {
                    $elementIds[$elementId] = true;
                }
            }
        } while (count($rows) === self::SITE_VERDICT_BATCH);

        return [
            'ids' => $ids,
            'pages' => count($pages),
            'scanIds' => array_map('intval', array_keys($scanIds)),
            'elementIds' => array_map('intval', array_keys($elementIds)),
        ];
    }

    /**
     * Whether a page has its own ruling on an occurrence.
     *
     * @param int $siteId The site.
     * @param int|null $elementId The element, or null for a URL page.
     * @param string|null $url The scanned URL, when there is no element.
     * @param string $ruleId The rule.
     * @param string|null $context The occurrence's context snippet.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _hasPageVerdict(int $siteId, ?int $elementId, ?string $url, string $ruleId, ?string $context): bool
    {
        return (new Query())
            ->from('{{%accessibilityaudit_verdicts}}')
            ->where([
                'siteId' => $siteId,
                'targetHash' => $this->targetHash($elementId, $url),
                'ruleId' => $ruleId,
                'contextHash' => $this->_candidateHashes($context),
            ])
            ->exists();
    }

    /**
     * Whether an every-page ruling is on record.
     *
     * @param int $siteId The site.
     * @param string $ruleId The rule.
     * @param string $key The every-page key, from self::siteKey().
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _hasSiteVerdict(int $siteId, string $ruleId, string $key): bool
    {
        return (new Query())
            ->from('{{%accessibilityaudit_verdicts}}')
            ->where([
                'siteId' => $siteId,
                'targetHash' => $this->siteTargetHash(),
                'ruleId' => $ruleId,
                'contextHash' => $key,
            ])
            ->exists();
    }

    /**
     * Whether an occurrence's markup tells it apart from other elements of
     * the same kind.
     *
     * A bare opening tag with no attributes and no text does not: it is every
     * element of that type on the site.
     *
     * @param string|null $context The occurrence's context snippet.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _isDistinctive(?string $context): bool
    {
        $context = trim((string)$context);
        $decoded = json_decode($context, true);

        if (is_array($decoded) && array_key_exists('html', $decoded)) {
            $context = trim((string)$decoded['html']);
        }

        $context = trim($this->_stripIdReferences($context));

        if ($context === '' || $context === '…') {
            return false;
        }

        return preg_match('/^<[a-z][a-z0-9-]*\s*\/?>\s*(?:…)?$/iu', $context) !== 1;
    }

    /**
     * Every hash a ruling on this occurrence could have been stored under,
     * newest form first.
     *
     * Rulings are written under the stable hash now. Anything decided before
     * that was stored under the raw context, and before that under a shorter
     * snippet again, so both are still tried on the way past. A reader who has
     * already answered a question should never be asked it twice because the
     * key changed underneath them.
     *
     * @param string|null $context The occurrence's context snippet.
     * @return string[]
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _candidateHashes(?string $context): array
    {
        $hashes = [$this->stableContextHash($context), $this->contextHash($context)];
        $legacy = $this->_legacyContextHash($context);

        if ($legacy !== null) {
            $hashes[] = $legacy;
        }

        return array_values(array_unique($hashes));
    }

    /**
     * A context snippet reduced to what identifies it, ready to hash.
     *
     * Line endings are settled first, then a JSON context is taken apart: its
     * CSS path is for highlighting rather than identity, so it comes out
     * whole, the markup has its id references stripped, and the keys are
     * sorted so two encodings of the same data cannot hash differently. Every
     * other key stays part of the identity.
     *
     * @param string|null $context The occurrence's context snippet.
     * @return string The normalised form, empty where nothing was given.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _normalisedContext(?string $context): string
    {
        $context = trim(str_replace(["\r\n", "\r"], "\n", (string)$context));

        if ($context === '') {
            return '';
        }

        // Contrast findings store JSON carrying a CSS path built from ids,
        // alongside the markup. The path is for highlighting, never for
        // identity, so it comes out whole. Keys are sorted so two encodings of
        // the same data cannot hash differently.
        $decoded = json_decode($context, true);

        if (is_array($decoded) && array_key_exists('html', $decoded)) {
            unset($decoded['selector']);
            $decoded['html'] = $this->_stripIdReferences((string)$decoded['html']);
            ksort($decoded);

            return (string)json_encode($decoded);
        }

        return $this->_stripIdReferences($context);
    }

    /**
     * Strips id-bearing attributes from a markup snippet.
     *
     * Matched on whitespace before the attribute name, so "data-id" and the
     * like are left alone: only the attribute itself goes, never a fragment of
     * a longer name.
     *
     * @param string $html The snippet.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _stripIdReferences(string $html): string
    {
        $names = implode('|', array_map(
            static fn(string $name): string => preg_quote($name, '/'),
            self::IDREF_ATTRIBUTES,
        ));

        return (string)preg_replace(
            '/\s+(?:' . $names . ')\s*=\s*(["\'])(?:(?!\g{1}).)*\g{1}/i',
            '',
            $html,
        );
    }

    /**
     * The key a ruling was stored under when snippets were cut at 150
     * characters, or null where the two forms cannot differ.
     *
     * At 150 characters or under both forms are identical, so there is no
     * second hash to try. From 151 up they part: the older form cut at 150 and
     * added an ellipsis where the current one keeps the snippet whole.
     *
     * @param string|null $context The occurrence's context snippet.
     * @return string|null The legacy key, or null where none can exist.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
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
