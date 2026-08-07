<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Wrench\Socket\ClientSocket;

/**
 * A client socket whose reads return what has arrived instead of waiting.
 *
 * The upstream socket only switches to non-blocking for continuation reads, so
 * the first read of every call is blocking, bounded by the stream timeout.
 * `fread()` on a blocking socket waits for its full buffer (1400 bytes), and a
 * DevTools protocol reply is a fraction of that, so a read holds until the
 * timeout expires rather than returning the reply already sitting there. Only
 * synchronous calls pay it, since the rest wait on the stream instead.
 *
 * Reading non-blocking is safe here because the caller already polls: chrome-php
 * waits on the socket in 50ms slices and re-checks until its response arrives.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class RemoteChromeSocket extends ClientSocket
{
    // Public Methods
    // =========================================================================

    /**
     * Reads from the socket without blocking, then hands blocking mode back.
     *
     * Both halves matter. It is set per call, not once at connect, because the
     * parent restores blocking itself after any read it has to split. It is
     * restored afterwards because sends must stay blocking: `send()` gives up
     * the moment `fwrite()` reports nothing written, which is what a
     * non-blocking socket does once its buffer is full, so a non-blocking
     * stream cannot carry a large payload. axe-core is ~600KB.
     *
     * @param int $length Maximum bytes to read.
     * @param float $waitSeconds Seconds to wait for data before reading.
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function receive(int $length = self::DEFAULT_RECEIVE_LENGTH, float $waitSeconds = 0.0): string
    {
        if (!\is_resource($this->socket)) {
            return parent::receive($length, $waitSeconds);
        }

        \stream_set_blocking($this->socket, false);

        try {
            return parent::receive($length, $waitSeconds);
        } finally {
            if (\is_resource($this->socket)) {
                \stream_set_blocking($this->socket, true);
            }
        }
    }
}
