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
 * File: MagnetParser.php
 * Component: Magnet URI Parser
 * Description: Extracts tracker URLs, infohashes, display names, and details from magnet links.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Parser;

use TorrentScraper\Core\Exception\MagnetParseException;

/**
 * Parses a magnet URI string into a ParsedMagnet DTO.
 *
 * Supported magnet URI forms:
 *   magnet:?xt=urn:btih:<40-char-hex>&dn=...&tr=...
 *   magnet:?xt=urn:btih:<32-char-base32>&dn=...&tr=...
 *
 * BEP 9: xt (exact topic) must be urn:btih:<hash>.
 * BEP 12: Multiple tr parameters are allowed.
 * BEP 19: ws (web seed) parameters are captured.
 *
 * Rules:
 *   - Always returns infoHash as 40-char lowercase hex.
 *   - Base32 info hashes (32 chars) are converted to hex.
 *   - URL-decodes all parameter values.
 *   - Never throws on missing optional fields (dn, tr, ws).
 *   - Throws MagnetParseException only if xt is missing or unparseable.
 */
final class MagnetParser
{
    /** Valid base32 alphabet (case-insensitive). */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Parse a magnet URI string.
     *
     * @throws MagnetParseException
     */
    public function parse(string $magnetUri): ParsedMagnet
    {
        $magnetUri = trim($magnetUri);

        if (!str_starts_with($magnetUri, 'magnet:?')) {
            throw new MagnetParseException(
                "Invalid magnet URI: must start with 'magnet:?'. Got: " . substr($magnetUri, 0, 30)
            );
        }

        $queryString = substr($magnetUri, strlen('magnet:?'));
        $params      = $this->parseQueryString($queryString);

        $infoHash    = $this->extractInfoHash($params);
        $displayName = $this->extractDisplayName($params);
        $trackers    = $this->extractMultiParam($params, 'tr');
        $webSeeds    = $this->extractMultiParam($params, 'ws');

        return new ParsedMagnet(
            infoHash:    $infoHash,
            displayName: $displayName,
            trackers:    $trackers,
            webSeeds:    $webSeeds,
        );
    }

    // -------------------------------------------------------------------------
    // Info hash extraction
    // -------------------------------------------------------------------------

    /**
     * Extract and normalise the info hash from the 'xt' parameter.
     *
     * @param  array<string, string[]> $params
     * @throws MagnetParseException
     */
    private function extractInfoHash(array $params): string
    {
        $xtValues = $params['xt'] ?? [];

        foreach ($xtValues as $xt) {
            // Expected form: urn:btih:<hash>
            if (!str_starts_with($xt, 'urn:btih:')) {
                continue;
            }

            $hash = substr($xt, strlen('urn:btih:'));
            $hash = strtolower(trim($hash));

            // 40-char hex hash (standard).
            if (preg_match('/^[0-9a-f]{40}$/', $hash)) {
                return $hash;
            }

            // 32-char base32 hash — convert to hex.
            $upperHash = strtoupper($hash);
            if (strlen($upperHash) === 32 && $this->isValidBase32($upperHash)) {
                return $this->base32ToHex($upperHash);
            }
        }

        throw new MagnetParseException(
            "No valid 'xt=urn:btih:' parameter found in magnet URI."
        );
    }

    // -------------------------------------------------------------------------
    // Parameter helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the display name (dn), URL-decoded.
     *
     * @param array<string, string[]> $params
     */
    private function extractDisplayName(array $params): ?string
    {
        $values = $params['dn'] ?? [];
        $first  = $values[0] ?? null;

        return ($first !== null && $first !== '') ? $first : null;
    }

    /**
     * Extract all values for a repeated parameter key (e.g. tr, ws).
     *
     * @param  array<string, string[]> $params
     * @return string[]
     */
    private function extractMultiParam(array $params, string $key): array
    {
        return array_values(
            array_filter(
                $params[$key] ?? [],
                static fn (string $v): bool => $v !== '',
            )
        );
    }

    // -------------------------------------------------------------------------
    // Query string parsing
    // -------------------------------------------------------------------------

    /**
     * Parse the magnet query string into a multi-value parameter map.
     * Magnet URIs can have multiple values for the same key (e.g. multiple &tr=).
     *
     * @return array<string, string[]>
     */
    private function parseQueryString(string $queryString): array
    {
        $params = [];

        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $key   = rawurldecode($parts[0]);
            $value = isset($parts[1]) ? rawurldecode($parts[1]) : '';

            $params[$key][] = $value;
        }

        return $params;
    }

    // -------------------------------------------------------------------------
    // Base32 conversion
    // -------------------------------------------------------------------------

    /**
     * Validate that the string contains only valid base32 characters.
     */
    private function isValidBase32(string $str): bool
    {
        return strspn($str, self::BASE32_ALPHABET) === strlen($str);
    }

    /**
     * Convert a 32-character base32-encoded SHA1 to a 40-character lowercase hex string.
     *
     * Base32 encodes 5 bits per character.
     * 32 chars × 5 bits = 160 bits = 20 bytes = 40 hex chars. ✓
     *
     * @throws MagnetParseException
     */
    private function base32ToHex(string $base32): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $bits     = '';

        foreach (str_split($base32) as $char) {
            $position = strpos($alphabet, $char);
            if ($position === false) {
                throw new MagnetParseException("Invalid base32 character '{$char}' in info hash.");
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        // 160 bits → 20 bytes → 40 hex chars
        $hex = '';
        foreach (str_split($bits, 8) as $byte) {
            $hex .= str_pad(dechex((int) bindec($byte)), 2, '0', STR_PAD_LEFT);
        }

        return $hex;
    }
}
