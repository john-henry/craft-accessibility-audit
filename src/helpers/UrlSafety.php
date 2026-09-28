<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use johnhenry\ipguard\Dns;
use johnhenry\ipguard\IpRange;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guards server-side URL fetches against SSRF (Server-Side Request Forgery).
 *
 * Any outbound fetch that uses a URL derived from user input must be validated
 * here first. The guard:
 *
 *  - Restricts the scheme to http / https only;
 *  - Resolves the host to every IPv4 and IPv6 address it maps to; and
 *  - Rejects the request if any resolved address falls inside a private,
 *    loopback, link-local, or otherwise reserved range (e.g. the cloud
 *    metadata endpoint 169.254.169.254, RFC 1918 ranges, or ::1).
 *
 * Validation alone is not the whole guard. A name can answer with a public
 * address when it is checked and a private one when it is connected to, so
 * {@see self::fetch()} hands the addresses it validated straight to curl and
 * follows redirects itself, one checked and pinned hop at a time. Anything
 * fetching a URL that came from outside this codebase should go through it.
 *
 * This is intentionally NOT the same check as AltController::isLocalUrl(): that
 * method blocks local URLs from being *sent to Anthropic* (an inverse,
 * TLD-based heuristic) and is not a real SSRF guard.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class UrlSafety
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[] The only URL schemes permitted for outbound fetches.
     */
    public const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * @var int The maximum number of redirects a guarded fetch may follow.
     */
    public const MAX_REDIRECTS = 5;

    /**
     * @var int The most body bytes a guarded fetch will read.
     *
     * The body is read into memory to be parsed, and how big it is is decided
     * by whatever is at the other end. A sweep runs unattended against pages
     * that may be broken, generated, or hostile, so the read is bounded here
     * rather than trusted. Well past any real HTML page.
     */
    public const MAX_BODY_BYTES = 10485760;

    /**
     * @var int The seconds a guarded fetch of one page may take.
     *
     * Held here rather than at each call site so the sweeps can work out what a
     * batch of them costs. A job that reserves less time than its own batch can
     * take is handed to the next worker part way through and starts again from
     * the top, fetching every page in it a second time.
     */
    public const FETCH_TIMEOUT = 15;

    /**
     * @var int The seconds a guarded fetch may spend reaching a host.
     */
    public const CONNECT_TIMEOUT = 5;

    // Public Methods
    // =========================================================================

    /**
     * Asserts that the given URL is safe to fetch server-side.
     *
     * @param string $url The URL to validate.
     * @return void
     * @throws UnsafeUrlException If the URL is malformed, uses a disallowed
     *                            scheme, or resolves to a private/reserved IP.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UnsafeUrlException('The URL is not valid.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new UnsafeUrlException('Only http and https URLs may be fetched.');
        }

        self::assertHostIsPublic(
            $parts['host'],
            $scheme,
            isset($parts['port']) ? (int)$parts['port'] : null,
        );
    }

    /**
     * Asserts that a hostname (or IP literal) resolves only to public addresses.
     *
     * @param string $host The hostname or IP literal to validate.
     * @param string $scheme The URL's scheme, which has to match the site's for
     *                       the own-site exemption to apply.
     * @param int|null $port The URL's explicit port, or null for the scheme's
     *                       default.
     * @return void
     * @throws UnsafeUrlException If the host resolves to a private/reserved IP
     *                            or cannot be resolved at all.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function assertHostIsPublic(string $host, string $scheme = 'https', ?int $port = null): void
    {
        // A local or intranet install legitimately resolves to a private
        // address, so its own sites are exempt, on their exact scheme, host
        // and port only, and never when @web comes from the request.
        if (IpRange::isOwnSiteOrigin($scheme, $host, $port)) {
            return;
        }

        $ips = self::resolveHost($host);

        if (empty($ips)) {
            throw new UnsafeUrlException('The host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                throw new UnsafeUrlException('The host resolves to a private or reserved network address.');
            }
        }
    }

    /**
     * Validates a host and hands back the addresses it was validated against.
     *
     * @param string $host The hostname or IP literal.
     * @param string $scheme The URL's scheme.
     * @param int|null $port The URL's explicit port, or null for the default.
     * @return string[] Every address the host resolves to, all of them public.
     * @throws UnsafeUrlException If the host is private, reserved, or will not
     *                            resolve.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function publicAddressesFor(string $host, string $scheme = 'https', ?int $port = null): array
    {
        self::assertHostIsPublic($host, $scheme, $port);

        // A host this install serves is exempt from the private-range check
        // (a local or intranet install legitimately resolves to one), so its
        // addresses still have to be looked up here.
        return self::resolveHost(trim($host, '[]'));
    }

    /**
     * Request options that pin a single request to the addresses the guard
     * approved, for a request that isn't a plain GET through fetch().
     *
     * The URL is validated first. The caller must also turn redirects off, or
     * a redirect would reach a host that was never checked.
     *
     * @param string $url The URL about to be requested.
     * @return array<string, mixed> Guzzle request options.
     * @throws UnsafeUrlException If the URL is not safe to request.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function pinnedRequestOptions(string $url): array
    {
        self::assertSafeUrl($url);

        $parts = parse_url($url);
        $host = (string)($parts['host'] ?? '');
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $explicitPort = isset($parts['port']) ? (int)$parts['port'] : null;
        $port = $explicitPort ?? ($scheme === 'http' ? 80 : 443);
        $addresses = self::publicAddressesFor($host, $scheme, $explicitPort);
        $options = ['allow_redirects' => false];

        if (!empty($addresses) && extension_loaded('curl')) {
            $options['curl'] = [
                CURLOPT_RESOLVE => [
                    sprintf('%s:%d:%s', trim($host, '[]'), $port, implode(',', $addresses)),
                ],
            ];
        }

        return $options;
    }

    /**
     * Returns whether the given IP address is in a private, loopback,
     * link-local, or otherwise reserved range.
     *
     * Covers (non-exhaustively): 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16,
     * 127.0.0.0/8, 169.254.0.0/16, 0.0.0.0/8, ::1, fc00::/7, fe80::/10, and
     * IPv4-mapped IPv6 addresses.
     *
     * @param string $ip The IP address to test.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function isPrivateIp(string $ip): bool
    {
        return IpRange::isPrivate($ip);
    }

    /**
     * Fetches a URL, connecting only to addresses that were checked.
     *
     * Validating a host and then handing the URL to an HTTP client leaves a
     * gap: the client resolves the name again when it connects, and a name
     * under someone else's control can answer with a public address the first
     * time and a private one the second. The check passes, the connection goes
     * somewhere else. That is DNS rebinding, and no amount of re-checking the
     * name closes it, because the check and the connection are two separate
     * lookups.
     *
     * So the addresses this class validated are the addresses curl is given,
     * through CURLOPT_RESOLVE. The name is still sent as the Host header and
     * as the TLS server name, so virtual hosts and certificates work as usual;
     * only the address lookup is taken out of curl's hands.
     *
     * Redirects are followed here rather than by the client, because each hop
     * is a new name needing the same treatment, and a client following them
     * internally gives no opportunity to pin the ones it finds.
     *
     * @param string $url The URL to fetch. Validated before each hop.
     * @param array<string, mixed> $clientConfig Guzzle client options, e.g.
     *                                           timeout and headers.
     * @param string|null $finalUrl Set to the URL the last hop actually landed
     *                               on, which is the address the body belongs
     *                               to. Storing a result against the URL that
     *                               was asked for instead files a redirect's
     *                               content under an address it does not live
     *                               at, and the same page is then reported
     *                               twice under two names.
     * @return ResponseInterface The final response.
     * @throws UnsafeUrlException If any hop is unsafe, if there are too many,
     *                            or if the body runs past the byte cap.
     * @throws GuzzleException If the request itself fails.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function fetch(string $url, array $clientConfig = [], ?string &$finalUrl = null): ResponseInterface
    {
        $finalUrl = $url;

        // Redirects are followed by the loop below, one validated hop at a
        // time, so the client must not follow them itself.
        $clientConfig['allow_redirects'] = false;

        $client = Craft::createGuzzleClient($clientConfig);
        $hops = 0;

        while (true) {
            self::assertSafeUrl($url);

            $parts = parse_url($url);
            $host = (string)($parts['host'] ?? '');
            $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
            $port = (int)($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

            $options = [];
            $addresses = self::publicAddressesFor($host, $scheme, isset($parts['port']) ? (int)$parts['port'] : null);

            // Without curl there is nothing to pin to: the stream handler
            // resolves the name itself and takes no address list. The hop is
            // still validated, so this is the guard the plugin has always had
            // rather than a new gap, but it is not the stronger one.
            if (!empty($addresses) && extension_loaded('curl')) {
                $options['curl'] = [
                    CURLOPT_RESOLVE => [
                        sprintf('%s:%d:%s', trim($host, '[]'), $port, implode(',', $addresses)),
                    ],
                ];
            }

            // The body is written into a capped sink as it arrives, so an
            // endless response is cut off mid-transfer instead of being taken
            // into memory whole. Not the `stream` request option: that routes
            // the request to Guzzle's stream handler, which ignores the cURL
            // options the address pinning above depends on.
            $options['sink'] = new CappedStream(
                Utils::streamFor(fopen('php://temp', 'r+')),
                self::MAX_BODY_BYTES,
            );

            try {
                $response = $client->get($url, $options);
            } catch (Throwable $e) {
                // The sink's refusal surfaces wrapped in the client's own
                // transfer exception.
                for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                    if ($cause instanceof UnsafeUrlException) {
                        throw $cause;
                    }
                }

                throw $e;
            }

            $status = $response->getStatusCode();
            $location = $response->getHeaderLine('Location');

            if ($status < 300 || $status > 399 || $location === '') {
                $finalUrl = $url;

                $body = $response->getBody();

                if ($body->isSeekable()) {
                    $body->rewind();
                }

                return $response;
            }

            // The hop's own body is of no interest and holds the connection
            // open until it is let go.
            $response->getBody()->close();

            if (++$hops > self::MAX_REDIRECTS) {
                throw new UnsafeUrlException('The URL redirected too many times.');
            }

            // A Location may be relative, and is resolved against the hop it
            // came from before the next pass validates it.
            $url = (string)UriResolver::resolve(new Uri($url), new Uri($location));
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves a hostname to every IPv4 and IPv6 address it maps to.
     *
     * IP literals are returned as-is (a single-element list).
     *
     * @param string $host The hostname or IP literal to resolve.
     * @return string[] The resolved IP addresses.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function resolveHost(string $host): array
    {
        // Strip brackets from IPv6 literals (e.g. [::1]).
        $host = trim($host, '[]');

        // Already an IP literal, nothing to resolve.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        return Dns::addressesFor($host);
    }
}
