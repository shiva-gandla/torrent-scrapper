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
 * File: InputSanitizer.php
 * Component: Security Protection
 * Description: Sanitizes input parameters, parameters in search forms, and queries to prevent XSS and SQL injection.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Security;

/**
 * Input sanitization utilities.
 *
 * Platform-agnostic by design: does NOT call WordPress functions directly.
 * The WordPress Adapter wraps these AND additionally calls WP functions
 * (sanitize_text_field, absint, esc_attr, etc.) before sending to services.
 *
 * Rules:
 *   - All methods are pure: no side effects, no DB calls.
 *   - Return sanitized values — never throw on bad input.
 *   - Callers decide what to do with empty/invalid returns.
 */
final class InputSanitizer
{
    /**
     * Sanitize a plain text string.
     * Strips tags, null bytes, normalizes whitespace.
     */
    public static function text(string $input): string
    {
        // Remove null bytes.
        $input = str_replace("\0", '', $input);
        // Strip HTML/PHP tags.
        $input = strip_tags($input);
        // Normalize whitespace.
        $input = preg_replace('/\s+/', ' ', $input) ?? $input;

        return trim($input);
    }

    /**
     * Sanitize to a non-negative integer.
     * Returns 0 for invalid input.
     */
    public static function absInt(mixed $input): int
    {
        return max(0, (int) $input);
    }

    /**
     * Sanitize a URL.
     * Returns empty string if the URL is not valid HTTP/HTTPS/UDP.
     */
    public static function url(string $input): string
    {
        $input = trim($input);

        // Allow udp:// for tracker URLs in addition to http/https.
        if (str_starts_with($input, 'udp://')) {
            // Basic UDP tracker URL validation.
            return preg_match('#^udp://[a-zA-Z0-9._\-]+(:\d+)?(/.*)?$#', $input) ? $input : '';
        }

        $filtered = filter_var($input, FILTER_SANITIZE_URL);
        if ($filtered === false) {
            return '';
        }

        return filter_var($filtered, FILTER_VALIDATE_URL) !== false ? $filtered : '';
    }

    /**
     * Sanitize a slug (used for category slugs).
     * Lowercase, alphanumeric and hyphens only.
     */
    public static function slug(string $input): string
    {
        $input = strtolower(trim($input));
        $input = preg_replace('/[^a-z0-9\-]/', '-', $input) ?? $input;
        $input = preg_replace('/-+/', '-', $input) ?? $input;

        return trim($input, '-');
    }

    /**
     * Sanitize an info hash.
     * Must be a 40-char lowercase hex string.
     * Returns empty string if invalid.
     */
    public static function infoHash(string $input): string
    {
        $input = strtolower(trim($input));

        return preg_match('/^[0-9a-f]{40}$/', $input) ? $input : '';
    }

    /**
     * Sanitize free HTML content using an allowlist.
     * Only allows safe tags (no script, no iframe, no event attributes).
     * For WordPress: the WordPress adapter should additionally call wp_kses_post().
     */
    public static function html(string $input): string
    {
        return strip_tags(
            $input,
            allowed_tags: '<p><br><a><strong><em><ul><ol><li><h2><h3><h4><pre><code>'
        );
    }

    /**
     * Sanitize an enum-like string field to one of an allowed set of values.
     * Returns the default value if input does not match.
     *
     * @param string[] $allowed
     */
    public static function enum(string $input, array $allowed, string $default = ''): string
    {
        $input = strtolower(trim($input));

        return in_array($input, $allowed, strict: true) ? $input : $default;
    }

    /**
     * Sanitize a file extension string.
     * Returns lowercase extension without dot, or empty string if invalid.
     */
    public static function fileExtension(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : '';
    }
}
