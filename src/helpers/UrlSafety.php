<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use Craft;
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
 * Because DNS can resolve to a public address on the first lookup but a private
 * one on a later hop (DNS rebinding) or via a redirect, the {@see self::guzzleRedirectConfig()}
 * helper re-validates the host on every redirect hop as well.
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
    private static function resolveHost(string $host): array
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
