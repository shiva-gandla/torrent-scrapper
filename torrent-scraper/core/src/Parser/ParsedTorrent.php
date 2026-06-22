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
 * File: ParsedTorrent.php
 * Component: Torrent Parser Models
 * Description: Data transfer object representing the contents, trackers, size, and metadata of a parsed torrent.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Parser;

/**
 * Immutable value object produced by TorrentParser.
 * Contains all data extracted from a .torrent file.
 */
final class ParsedTorrent
{
    /**
     * @param string              $infoHash     SHA1 hex of the info dict (40 chars, lowercase).
     * @param string              $name         Torrent display name.
     * @param int                 $totalSize    Total size in bytes across all files.
     * @param int                 $fileCount    Number of files (1 for single-file torrents).
     * @param int                 $pieceLength  Bytes per piece.
     * @param int                 $pieceCount   Number of pieces.
     * @param bool                $isPrivate    True if the private flag is set in info dict.
     * @param string|null         $comment      Optional comment field from metadata.
     * @param string|null         $createdBy    Optional creator application string.
     * @param int|null            $createdAt    Unix timestamp from torrent metadata (not upload time).
     * @param string              $magnetLink   Ready-to-use magnet:? URI.
     * @param ParsedTorrentFile[] $files        File list (single-file torrents have one entry).
     * @param string[][]          $trackerTiers Announce list grouped by tier: [[url, url], [url], ...].
     */
    public function __construct(
        public readonly string  $infoHash,
        public readonly string  $name,
        public readonly int     $totalSize,
        public readonly int     $fileCount,
        public readonly int     $pieceLength,
        public readonly int     $pieceCount,
        public readonly bool    $isPrivate,
        public readonly ?string $comment,
        public readonly ?string $createdBy,
        public readonly ?int    $createdAt,
        public readonly string  $magnetLink,
        public readonly array   $files,
        public readonly array   $trackerTiers,
    ) {}

    /**
     * Flat list of all tracker URLs across all tiers (deduplicated, order preserved).
     *
     * @return string[]
     */
    public function allTrackerUrls(): array
    {
        $urls = [];
        foreach ($this->trackerTiers as $tier) {
            foreach ($tier as $url) {
                if (!in_array($url, $urls, strict: true)) {
                    $urls[] = $url;
                }
            }
        }
        return $urls;
    }
}
