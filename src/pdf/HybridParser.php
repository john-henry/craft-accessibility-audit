<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\pdf;

use Com\Tecnick\Pdf\Parser\Exception as ParserException;
use Com\Tecnick\Pdf\Parser\Parser;

/**
 * tc-lib's parser, taught to read the /XRefStm of a hybrid-reference file.
 *
 * A hybrid file (PDF 32000-1, 7.5.8.4) carries a classic cross-reference
 * table for older readers and a cross-reference stream, named by /XRefStm in
 * the trailer, for everything in an object stream. The classic table marks
 * those compressed objects free, so a reader that never opens the stream loses
 * every one of them without an error. Word writes files this way, and its
 * structure tree lives in object streams: without this the tree reads as empty.
 *
 * The stream is decoded before /Prev is followed, because it belongs to the
 * same revision as the table and has to win over older revisions. The numbers
 * the table freed are reopened for it; everything the table actually stored
 * keeps the table's answer.
 *
 * @phpstan-import-type XrefData from \Com\Tecnick\Pdf\Parser\Process\XrefStream
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class HybridParser extends Parser
{
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param array<string, mixed> $xref Xref data gathered so far.
     * @param string $trailer_data The trailer dictionary's source.
     * @return XrefData Xref and trailer data.
     * @throws ParserException
     */
    protected function getTrailerData(array $xref, string $trailer_data): array
    {
        if (preg_match('/XRefStm[\s]+([0-9]+)/i', $trailer_data, $matches) === 1) {
            $offset = (int)$matches[1];

            if ($offset > 0 && $offset < strlen($this->pdfdata)) {
                $xref = $this->_mergeXrefStream($offset, $xref);
            }
        }

        /** @var XrefData $xref */
        return parent::getTrailerData($xref, $trailer_data);
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads the cross-reference stream a hybrid trailer points at into the
     * entries gathered so far.
     *
     * The stream's own dictionary need not carry /Root or /Info, so the classic
     * trailer stays the trailer. Where none had been read yet, the slot is
     * emptied again for the classic trailer to fill. A stream that will not
     * decode leaves the classic table as the answer.
     *
     * @param int $offset Where the stream object starts.
     * @param array<string, mixed> $xref Xref data gathered so far.
     * @return array<string, mixed> The xref data with the stream's entries added.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private function _mergeXrefStream(int $offset, array $xref): array
    {
        $stored = [];

        foreach (array_keys((array)($xref['xref'] ?? [])) as $key) {
            $stored[(int)explode('_', (string)$key)[0]] = true;
        }

        $done = $this->xrefdone;
        $this->xrefdone = $stored;
        $trailer = $xref['trailer'] ?? null;

        try {
            /** @var array{trailer?: array<string, mixed>, xref?: array<string, int|string>} $xref */
            $xref = $this->decodeXrefStream($offset, $xref);
        } catch (ParserException) {
        }

        if ($trailer === null || $trailer === []) {
            unset($xref['trailer']);
        } else {
            $xref['trailer'] = $trailer;
        }

        $this->xrefdone += $done;

        return $xref;
    }
}
