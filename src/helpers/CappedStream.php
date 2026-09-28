<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use Psr\Http\Message\StreamInterface;

/**
 * A write stream that refuses once more than a set number of bytes is put
 * through it.
 *
 * Used as the sink an HTTP response body is written into, so the transfer is
 * stopped partway rather than a page of any size being taken into memory and
 * measured afterwards. Refusing as it writes is the point: by the time a
 * finished body can be sized, the memory it costs has already been spent.
 *
 * The cap is applied on writes only. Reading back what was written is
 * unrestricted, so a capped sink is an ordinary readable body once the
 * response is in hand.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    // Private Properties
    // =========================================================================

    /**
     * @var StreamInterface The stream being wrapped.
     *
     * Declared rather than left to the decorator trait, which otherwise has
     * the constructor create it dynamically.
     */
    protected StreamInterface $stream;

    /**
     * @var int The most bytes that may be written.
     */
    private int $_max;

    /**
     * @var int How many bytes have been written so far.
     */
    private int $_written = 0;

    // Public Methods
    // =========================================================================

    /**
     * Wraps a stream and stops reading once the cap is reached.
     *
     * @param StreamInterface $stream The stream being wrapped.
     * @param int $max The most bytes that may be written to it.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public function __construct(StreamInterface $stream, int $max)
    {
        $this->stream = $stream;
        $this->_max = $max;
    }

    /**
     * @inheritdoc
     * @throws UnsafeUrlException If the write would carry the total past the cap.
     */
    public function write(string $string): int
    {
        $this->_written += strlen($string);

        if ($this->_written > $this->_max) {
            throw new UnsafeUrlException('The page is too large to read.');
        }

        return $this->stream->write($string);
    }
}
