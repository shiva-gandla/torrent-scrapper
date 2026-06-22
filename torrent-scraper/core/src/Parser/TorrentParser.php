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
 * File: TorrentParser.php
 * Component: Torrent File Parser
 * Description: Decodes torrent files, validates their integrity, and calculates infohashes.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Parser;

use TorrentScraper\Core\Exception\TorrentParseException;

/**
 * Parses a raw .torrent file binary into a ParsedTorrent DTO.
 *
 * Handles:
 *   - Single-file torrents (info dict has 'length' key).
 *   - Multi-file torrents (info dict has 'files' key).
 *   - Private torrents (info dict has 'private' = 1).
 *   - Announce lists (BEP 12 multi-tracker, tiers).
 *   - UTF-8 and Latin-1 encoded names.
 *
 * This class does NOT validate whether the file is a valid .torrent beyond
 * what is required to extract the fields — that is the uploader's responsibility.
 */
final class TorrentParser
{
    public function __construct(
        private readonly BencodeDecoder $decoder,
    ) {}

    /**
     * Parse raw binary .torrent file content.
     *
     * @param string $raw Raw binary content from the uploaded .torrent file.
     * @throws TorrentParseException
     */
    public function parse(string $raw): ParsedTorrent
    {
        /** @var array<string, mixed> $torrent */
        $torrent = $this->decoder->decode($raw);

        if (!is_array($torrent) || !isset($torrent['info']) || !is_array($torrent['info'])) {
            throw new TorrentParseException('Missing or invalid "info" dictionary in torrent.');
        }

        /** @var array<string, mixed> $info */
        $info = $torrent['info'];

        $infoHash    = $this->computeInfoHash($raw);
        $name        = $this->extractName($info);
        $isPrivate   = isset($info['private']) && (int) $info['private'] === 1;
        $pieceLength = isset($info['piece length']) ? (int) $info['piece length'] : 0;
        $pieces      = isset($info['pieces']) ? (string) $info['pieces'] : '';
        $pieceCount  = $pieceLength > 0 && strlen($pieces) > 0
            ? (int) ceil(strlen($pieces) / 20)
            : 0;

        [$files, $totalSize] = $this->extractFiles($info, $name);

        $trackerTiers = $this->extractTrackerTiers($torrent);
        $magnetLink   = $this->buildMagnetLink($infoHash, $name, $trackerTiers);

        return new ParsedTorrent(
            infoHash:     $infoHash,
            name:         $name,
            totalSize:    $totalSize,
            fileCount:    count($files),
            pieceLength:  $pieceLength,
            pieceCount:   $pieceCount,
            isPrivate:    $isPrivate,
            comment:      isset($torrent['comment']) ? (string) $torrent['comment'] : null,
            createdBy:    isset($torrent['created by']) ? (string) $torrent['created by'] : null,
            createdAt:    isset($torrent['creation date']) ? (int) $torrent['creation date'] : null,
            magnetLink:   $magnetLink,
            files:        $files,
            trackerTiers: $trackerTiers,
        );
    }

    // -------------------------------------------------------------------------
    // Info hash
    // -------------------------------------------------------------------------

    /**
     * Compute the SHA1 info hash by re-encoding the 'info' dict from the raw data.
     *
     * We locate the 'info' value inside the raw bencode string and SHA1 hash the
     * raw bytes directly — this ensures we hash exactly the same bytes the torrent
     * client uses, even if the info dict is not canonically sorted.
     *
     * @throws TorrentParseException
     */
    private function computeInfoHash(string $raw): string
    {
        // Find the "4:info" key in the raw bencode string.
        $marker = '4:info';
        $offset = strpos($raw, $marker);

        if ($offset === false) {
            throw new TorrentParseException('Cannot locate "info" dictionary in raw torrent data.');
        }

        $infoStart = $offset + strlen($marker);

        // The info value is a bencoded dict starting at $infoStart.
        // We need to find where it ends by re-parsing from that offset.
        $infoBytes = $this->extractBencodedValue($raw, $infoStart);

        return sha1($infoBytes);
    }

    /**
     * Extract the raw bencoded bytes of the value starting at $start in $data.
     *
     * @throws TorrentParseException
     */
    private function extractBencodedValue(string $data, int $start): string
    {
        $pos    = $start;
        $length = strlen($data);

        if ($pos >= $length) {
            throw new TorrentParseException('Unexpected end of data while extracting info dict.');
        }

        $end = $this->skipBencodedValue($data, $pos, $length);

        return substr($data, $start, $end - $start);
    }

    /**
     * Skip over one bencoded value in $data starting at $pos and return the new position.
     *
     * @throws TorrentParseException
     */
    private function skipBencodedValue(string $data, int $pos, int $length): int
    {
        if ($pos >= $length) {
            throw new TorrentParseException("Unexpected EOF while skipping bencode at pos {$pos}.");
        }

        $ch = $data[$pos];

        if ($ch === 'i') {
            // Integer: i<digits>e
            $end = strpos($data, 'e', $pos + 1);
            if ($end === false) {
                throw new TorrentParseException("Unterminated integer at pos {$pos}.");
            }
            return $end + 1;
        }

        if ($ch === 'l' || $ch === 'd') {
            // List or dict: consume tokens until 'e'
            $pos++;
            while ($pos < $length && $data[$pos] !== 'e') {
                $pos = $this->skipBencodedValue($data, $pos, $length);
            }
            return $pos + 1; // consume the 'e'
        }

        if (ctype_digit($ch)) {
            // String: <length>:<data>
            $colon = strpos($data, ':', $pos);
            if ($colon === false) {
                throw new TorrentParseException("Malformed string at pos {$pos}.");
            }
            $strLength = (int) substr($data, $pos, $colon - $pos);
            return $colon + 1 + $strLength;
        }

        throw new TorrentParseException("Unknown bencode type '{$ch}' at pos {$pos}.");
    }

    // -------------------------------------------------------------------------
    // Name extraction
    // -------------------------------------------------------------------------

    /**
     * Extract and sanitise the torrent name.
     * Tries 'name.utf-8' first (BEP 52), then 'name'.
     *
     * @param array<string, mixed> $info
     * @throws TorrentParseException
     */
    private function extractName(array $info): string
    {
        $raw = $info['name.utf-8'] ?? $info['name'] ?? null;

        if (!is_string($raw) || $raw === '') {
            throw new TorrentParseException('Missing or empty "name" field in info dictionary.');
        }

        return $this->sanitiseName($raw);
    }

    /**
     * Ensure the name is valid UTF-8, falling back to Latin-1.
     * Strip null bytes and limit to 255 characters.
     */
    private function sanitiseName(string $raw): string
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        }

        // Remove null bytes and trim whitespace.
        $raw = str_replace("\0", '', $raw);
        $raw = trim($raw);

        // Enforce max length.
        return mb_substr($raw, 0, 255, 'UTF-8');
    }

    // -------------------------------------------------------------------------
    // File extraction
    // -------------------------------------------------------------------------

    /**
     * Extract file list and compute total size.
     *
     * Single-file torrent:  info has 'length' key — creates one ParsedTorrentFile.
     * Multi-file torrent:   info has 'files'  key — iterates the file list.
     *
     * @param  array<string, mixed> $info
     * @return array{0: ParsedTorrentFile[], 1: int}  [files, totalSize]
     * @throws TorrentParseException
     */
    private function extractFiles(array $info, string $torrentName): array
    {
        if (isset($info['length'])) {
            // Single-file torrent.
            $size  = (int) $info['length'];
            $file  = new ParsedTorrentFile(path: $torrentName, size: $size, index: 0);
            return [[$file], $size];
        }

        if (!isset($info['files']) || !is_array($info['files'])) {
            throw new TorrentParseException('Neither "length" nor "files" key found in info dict.');
        }

        $files     = [];
        $totalSize = 0;

        foreach ($info['files'] as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $size      = isset($entry['length']) ? (int) $entry['length'] : 0;
            $totalSize += $size;

            // 'path' is an array of path components.
            $pathComponents = $entry['path.utf-8'] ?? $entry['path'] ?? [];

            if (!is_array($pathComponents)) {
                $pathComponents = [(string) $pathComponents];
            }

            $sanitised = array_map(
                fn (mixed $part): string => $this->sanitiseName((string) $part),
                $pathComponents,
            );

            // Join with forward slash — platform-neutral.
            $filePath = $torrentName . '/' . implode('/', array_filter($sanitised));

            $files[] = new ParsedTorrentFile(
                path:  $filePath,
                size:  $size,
                index: (int) $index,
            );
        }

        if (empty($files)) {
            throw new TorrentParseException('Torrent "files" list is empty.');
        }

        return [$files, $totalSize];
    }

    // -------------------------------------------------------------------------
    // Tracker extraction
    // -------------------------------------------------------------------------

    /**
     * Build a tier list from 'announce-list' (BEP 12) or fall back to 'announce'.
     *
     * @param  array<string, mixed> $torrent
     * @return string[][] [tier_index => [url, url, ...]]
     */
    private function extractTrackerTiers(array $torrent): array
    {
        // BEP 12: announce-list is an array of tiers, each tier is an array of URLs.
        if (isset($torrent['announce-list']) && is_array($torrent['announce-list'])) {
            $tiers = [];

            foreach ($torrent['announce-list'] as $tier) {
                if (!is_array($tier)) {
                    continue;
                }

                $urls = [];
                foreach ($tier as $url) {
                    $clean = trim((string) $url);
                    if ($clean !== '') {
                        $urls[] = $clean;
                    }
                }

                if (!empty($urls)) {
                    $tiers[] = $urls;
                }
            }

            if (!empty($tiers)) {
                return $tiers;
            }
        }

        // Fallback: single announce URL → single tier with one URL.
        if (isset($torrent['announce']) && is_string($torrent['announce'])) {
            $url = trim($torrent['announce']);
            if ($url !== '') {
                return [[$url]];
            }
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Magnet link
    // -------------------------------------------------------------------------

    /**
     * Build a magnet URI from the parsed components.
     * Format: magnet:?xt=urn:btih:<hash>&dn=<name>&tr=<url>&tr=<url>...
     *
     * @param string[][] $trackerTiers
     */
    private function buildMagnetLink(string $infoHash, string $name, array $trackerTiers): string
    {
        $params   = [];
        $params[] = 'xt=urn:btih:' . $infoHash;
        $params[] = 'dn=' . rawurlencode($name);

        foreach ($trackerTiers as $tier) {
            foreach ($tier as $url) {
                $params[] = 'tr=' . rawurlencode($url);
            }
        }

        return 'magnet:?' . implode('&', $params);
    }
}
