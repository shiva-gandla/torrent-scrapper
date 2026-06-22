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
 * File: ParsedTorrentFile.php
 * Component: Torrent Parser Models
 * Description: Data transfer object representing individual file structures inside a multi-file torrent.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Parser;

/**
 * Immutable value object representing a single file inside a torrent.
 */
final class ParsedTorrentFile
{
    public function __construct(
        /** Full path from torrent metadata (may include directory separators). */
        public readonly string $path,
        /** File size in bytes. */
        public readonly int $size,
        /** Zero-based index — original position in the torrent file list. */
        public readonly int $index,
    ) {}
}
