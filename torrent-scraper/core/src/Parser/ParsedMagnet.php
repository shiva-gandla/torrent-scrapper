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
 * File: ParsedMagnet.php
 * Component: Torrent Parser Models
 * Description: Data transfer object representing the components of a parsed magnet link.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Parser;

/**
 * Immutable value object produced by MagnetParser.
 */
final class ParsedMagnet
{
    /**
     * @param string        $infoHash    SHA1 hex (40 chars, lowercase). Always hex — base32 is converted.
     * @param string|null   $displayName The 'dn' parameter if present.
     * @param string[]      $trackers    All 'tr' parameters, in order.
     * @param string[]      $webSeeds    All 'ws' parameters (BEP 19), in order.
     */
    public function __construct(
        public readonly string  $infoHash,
        public readonly ?string $displayName,
        public readonly array   $trackers,
        public readonly array   $webSeeds,
    ) {}
}
