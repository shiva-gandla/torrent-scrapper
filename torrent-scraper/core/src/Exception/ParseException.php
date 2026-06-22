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
 * File: ParseException.php
 * Component: Exception Handling
 * Description: Exception thrown when parsing of torrent files or magnet links fails due to corrupt or invalid data formats.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Exception;

/** Thrown when a .torrent file or magnet link cannot be parsed. */
class ParseException extends TorrentScraperException {}

/** Thrown when bencode decoding fails. */
class TorrentParseException extends ParseException {}

/** Thrown when a magnet URI is malformed or missing required fields. */
class MagnetParseException extends ParseException {}
