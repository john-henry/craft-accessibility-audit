<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Wrench\Client;
use Wrench\Exception\HandshakeException;

/**
 * A WebSocket client that waits for the opening handshake to actually arrive.
 *
 * The upstream client reads the handshake response exactly once, immediately
 * after sending the request. On loopback the reply is already waiting and that
 * read succeeds. Over a network it is not, so the read comes back empty and a
 * healthy server about to answer with a valid 101 is treated as unreachable.
 * The read has to be repeated until the response lands.
 *
 * Everything else about the handshake is left to the parent, including the
 * status-code and accept-header validation.
 *
 * It also supplies [[RemoteChromeSocket]], which keeps every subsequent
 * synchronous call off a blocking read. Both are needed for a remote browser to
 * be usable.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class RemoteChromeClient extends Client
{
    // Constants
    // =========================================================================

    /**
     * @var int Seconds a socket read may block. A backstop only, since reads go
     * through [[RemoteChromeSocket]] non-blocking; it bounds the cost of any
     * read that still ends up blocking. The upstream default is 5.
     */
    private const SOCKET_TIMEOUT = 1;

    /**
     * @var float Seconds to keep re-reading for the handshake response before
     * giving up. Generous because it only ever costs this much on a genuinely
     * dead endpoint; a healthy one answers on the second read.
     */
    private const HANDSHAKE_TIMEOUT = 15.0;

    /**
     * @var float Seconds to pause between reads while the response is still
     * outstanding, so a slow endpoint isn't hammered with a tight loop.
     */
    private const HANDSHAKE_POLL_INTERVAL = 0.1;

    // Public Methods
    // =========================================================================

    /**
     * @param string $uri The browser's WebSocket URI.
     * @param string $origin Origin to send on the opening handshake.
     * @param array $options Passed through to the underlying client.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function __construct(string $uri, string $origin, array $options = [])
    {
        parent::__construct($uri, $origin, $options + [
            'socket_class' => RemoteChromeSocket::class,
            'socket_options' => ['timeout_socket' => self::SOCKET_TIMEOUT],
        ]);
    }

    /**
     * Connects to the server and completes the opening handshake, re-reading
     * until the response arrives or the timeout expires.
     *
     * @return bool Whether the connection is established.
     * @throws HandshakeException If the server answers with anything other than
     * a valid 101 upgrade.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function connect(): bool
    {
        if ($this->isConnected()) {
            return false;
        }

        try {
            $this->socket->connect();
        } catch (\Exception) {
            return false;
        }

        $key = $this->protocol->generateKey();

        $this->socket->send($this->protocol->getRequestHandshake(
            $this->uri,
            $key,
            $this->origin,
            $this->headers,
        ));

        $response = $this->_readHandshakeResponse();

        if ($response === '') {
            return false;
        }

        return $this->connected = $this->protocol->validateResponseHandshake($response, $key);
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads from the socket until the handshake response arrives or the timeout
     * expires, returning an empty string if nothing ever came.
     *
     * @return string
     */
    private function _readHandshakeResponse(): string
    {
        $deadline = microtime(true) + self::HANDSHAKE_TIMEOUT;

        do {
            $response = (string)$this->socket->receive(
                self::MAX_HANDSHAKE_RESPONSE,
                self::HANDSHAKE_POLL_INTERVAL,
            );

            if ($response !== '') {
                return $response;
            }
        } while (microtime(true) < $deadline);

        return '';
    }
}
