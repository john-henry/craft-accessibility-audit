<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use johnhenry\accessibilityaudit\exceptions\UnsafeUrlException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

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
 * @author JohnHenry <info@johnhenry.ie>
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

    // Public Methods
    // =========================================================================

    /**
     * Asserts that the given URL is safe to fetch server-side.
     *
     * @param string $url The URL to validate.
     * @return void
     * @throws UnsafeUrlException If the URL is malformed, uses a disallowed
     *                            scheme, or resolves to a private/reserved IP.
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

        self::assertHostIsPublic($parts['host']);
    }

    /**
     * Asserts that a hostname (or IP literal) resolves only to public addresses.
     *
     * @param string $host The hostname or IP literal to validate.
     * @return void
     * @throws UnsafeUrlException If the host resolves to a private/reserved IP
     *                            or cannot be resolved at all.
     */
    public static function assertHostIsPublic(string $host): void
    {
        // Exempt hosts this install actually serves: the site's own hostname
        // comes from Craft's site config, not from user input, and local/intranet
        // installs legitimately resolve to private addresses.
        if (self::isOwnSiteHost($host)) {
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
     * @return string[] Every address the host resolves to, all of them public.
     * @throws UnsafeUrlException If the host is private, reserved, or will not
     *                            resolve.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function publicAddressesFor(string $host): array
    {
        self::assertHostIsPublic($host);

        // A host this install serves is exempt from the private-range check
        // (a local or intranet install legitimately resolves to one), so its
        // addresses still have to be looked up here.
        return self::resolveHost(trim($host, '[]'));
    }

    /**
     * Whether the host is one this install serves.
     *
     * Matched against the hostname of each configured site's base URL, exactly:
     * a suffix match would let `evil-example.com` past a site on `example.com`,
     * and a wildcard would hand an attacker every subdomain of it.
     *
     * @param string $host The hostname to test.
     * @return bool
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private static function isOwnSiteHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteHost = parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);

            if (is_string($siteHost) && strtolower($siteHost) === $host) {
                return true;
            }
        }

        return false;
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
     */
    public static function isPrivateIp(string $ip): bool
    {
        // Unwrap IPv4-mapped IPv6 addresses (e.g. ::ffff:169.254.169.254) so
        // the private-range check below applies to the embedded IPv4 address.
        if (stripos($ip, '::ffff:') === 0) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $mapped;
            }
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            // Not a parseable IP, treat as unsafe.
            return true;
        }

        // PHP's own reserved/private range filter covers the common cases for
        // both IPv4 and IPv6 (RFC 1918, loopback, link-local, unique-local).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // Belt-and-braces explicit ranges that some PHP builds miss.
        return self::isInReservedRange($ip);
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
     * @return ResponseInterface The final response.
     * @throws UnsafeUrlException If any hop is unsafe, or there are too many.
     * @throws GuzzleException If the request itself fails.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function fetch(string $url, array $clientConfig = []): ResponseInterface
    {
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
            $addresses = self::publicAddressesFor($host);

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

            $response = $client->get($url, $options);
            $status = $response->getStatusCode();
            $location = $response->getHeaderLine('Location');

            if ($status < 300 || $status > 399 || $location === '') {
                return $response;
            }

            if (++$hops > self::MAX_REDIRECTS) {
                throw new UnsafeUrlException('The URL redirected too many times.');
            }

            // A Location may be relative, and is resolved against the hop it
            // came from before the next pass validates it.
            $url = (string)UriResolver::resolve(new Uri($url), new Uri($location));
        }
    }

    /**
     * Returns Guzzle client options that re-validate the host on every redirect
     * hop, preventing a public URL from redirecting to an internal target.
     *
     * @return array<string, mixed>
     */
    public static function guzzleRedirectConfig(): array
    {
        return [
            'allow_redirects' => [
                'max' => self::MAX_REDIRECTS,
                'strict' => true,
                'referer' => false,
                'protocols' => self::ALLOWED_SCHEMES,
                'track_redirects' => false,
                'on_redirect' => static function(
                    RequestInterface $request,
                    ResponseInterface $response,
                    UriInterface $uri,
                ): void {
                    // Throws UnsafeUrlException if the redirect target host
                    // resolves to a private/reserved address.
                    self::assertHostIsPublic($uri->getHost());
                },
            ],
        ];
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
     */
    public static function resolveHost(string $host): array
    {
        // Strip brackets from IPv6 literals (e.g. [::1]).
        $host = trim($host, '[]');

        // Already an IP literal, nothing to resolve.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        // IPv4 (A records).
        $records = @dns_get_record($host, DNS_A);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        // IPv6 (AAAA records).
        $records6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($records6)) {
            foreach ($records6 as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        // Fallback to gethostbyname when DNS records are unavailable.
        if (empty($ips)) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
                $ips[] = $resolved;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Explicit range checks for addresses some PHP filter builds do not flag.
     *
     * @param string $ip The IP address to test.
     * @return bool
     */
    private static function isInReservedRange(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }

            $reserved = [
                ['10.0.0.0', 8],
                ['172.16.0.0', 12],
                ['192.168.0.0', 16],
                ['127.0.0.0', 8],
                ['169.254.0.0', 16],
                ['0.0.0.0', 8],
                ['100.64.0.0', 10],
                ['192.0.0.0', 24],
                ['198.18.0.0', 15],
                ['192.0.2.0', 24],
                ['240.0.0.0', 4],
            ];

            foreach ($reserved as [$subnet, $mask]) {
                $subnetLong = ip2long($subnet);
                $maskLong = -1 << (32 - $mask);
                if (($long & $maskLong) === ($subnetLong & $maskLong)) {
                    return true;
                }
            }

            return false;
        }

        // IPv6 loopback / unspecified / unique-local / link-local.
        $normalised = strtolower($ip);
        return $normalised === '::1'
            || $normalised === '::'
            || str_starts_with($normalised, 'fc')
            || str_starts_with($normalised, 'fd')
            || str_starts_with($normalised, 'fe8')
            || str_starts_with($normalised, 'fe9')
            || str_starts_with($normalised, 'fea')
            || str_starts_with($normalised, 'feb');
    }
}
