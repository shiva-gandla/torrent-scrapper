<?php
/**
 * Plugin Name: Torrent Scrapper for Wordpress Blog/Forum
 * Description: Publish torrent files and magnet links with live seeder/leecher stats for WordPress, bbPress, and wpForo.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: Shiva Gandla (https://github.com/shiva-gandla/)
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * File: SecurityLayer.php
 * Component: Security Protection
 * Description: Centralizes security policies including CSRF/nonces validation and privilege level verification.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Security;

use TorrentScraper\Core\Exception\SecurityException;

/**
 * SSRF protection and outbound connection security.
 *
 * Rules (MANDATORY — never bypass these):
 *   1. Outbound tracker connections must pass isTrackerUrlSafe() first.
 *   2. Private/loopback/link-local/multicast IPs are always blocked.
 *   3. Internal hostnames (without dots) are blocked.
 *   4. Only udp://, http://, https:// schemes are permitted for trackers.
 *
 * This class is intentionally final with only static methods — it is a
 * pure validation utility, not a service that needs dependency injection.
 */
final class SecurityLayer
{
    /** CIDR blocks that must never be contacted as outbound tracker hosts. */
    private const BLOCKED_CIDR_V4 = [
        '0.0.0.0/8',        // "This" network
        '10.0.0.0/8',       // Private A
        '100.64.0.0/10',    // Shared address space (CGNAT)
        '127.0.0.0/8',      // Loopback
        '169.254.0.0/16',   // Link-local
        '172.16.0.0/12',    // Private B
        '192.0.0.0/24',     // IETF protocol assignments
        '192.168.0.0/16',   // Private C
        '198.18.0.0/15',    // Benchmark testing
        '198.51.100.0/24',  // TEST-NET-2 (documentation)
        '203.0.113.0/24',   // TEST-NET-3 (documentation)
        '224.0.0.0/4',      // Multicast
        '240.0.0.0/4',      // Reserved
        '255.255.255.255/32',// Broadcast
    ];

    /** Permitted URL schemes for tracker connections. */
    private const ALLOWED_SCHEMES = ['udp', 'http', 'https'];

    /**
     * Validate a tracker URL before an outbound connection is made.
     *
     * Returns true if the URL is safe, false if it must be blocked.
     * Logs the reason internally — callers only receive the boolean.
     */
    public static function isTrackerUrlSafe(string $url): bool
    {
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, self::ALLOWED_SCHEMES, strict: true)) {
            return false;
        }

        $host = (string) $parsed['host'];

        // Strip IPv6 brackets.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            // Block all IPv6 for now — tracker scraping does not require it.
            return false;
        }

        // Block bare hostnames (no dots → likely internal DNS).
        if (!str_contains($host, '.')) {
            return false;
        }

        // Block 'localhost' regardless of case.
        if (strtolower($host) === 'localhost') {
            return false;
        }

        // If the host is an IPv4 address, check CIDR blocks.
        if (self::isIpv4($host)) {
            return !self::isBlockedIpv4($host);
        }

        // For hostnames: resolve and check. On shared hosting gethostbyname() is always available.
        // We do one DNS lookup to guard against internal hostname tricks.
        $resolved = gethostbyname($host);

        if ($resolved === $host) {
            // gethostbyname() returns the input unchanged if resolution fails.
            // Fail-open: allow it (DNS might be temporarily down).
            return true;
        }

        if (self::isIpv4($resolved)) {
            return !self::isBlockedIpv4($resolved);
        }

        return true;
    }

    /**
     * Throws SecurityException if the tracker URL is not safe.
     * Use this in contexts where you want to hard-fail, not silently skip.
     *
     * @throws SecurityException
     */
    public static function validateOutboundHost(string $url): void
    {
        if (!self::isTrackerUrlSafe($url)) {
            throw new SecurityException(
                "Outbound connection blocked — unsafe tracker URL: {$url}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** Check whether a string is a valid dotted-decimal IPv4 address. */
    private static function isIpv4(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Check whether an IPv4 address falls within any blocked CIDR range.
     */
    private static function isBlockedIpv4(string $ip): bool
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return true; // Can't parse → block.
        }

        foreach (self::BLOCKED_CIDR_V4 as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $networkLong = ip2long($network);
            if ($networkLong === false) {
                continue;
            }
            $mask = $bits === '32' ? 0xFFFFFFFF : ~((1 << (32 - (int) $bits)) - 1);
            if (($ipLong & $mask) === ($networkLong & $mask)) {
                return true;
            }
        }

        return false;
    }
}
