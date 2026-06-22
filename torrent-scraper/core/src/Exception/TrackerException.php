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
 * File: TrackerException.php
 * Component: Exception Handling
 * Description: Exception thrown during failures in communication or scraping of torrent trackers.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Exception;

/** Thrown when a tracker connection or scrape request fails. */
class TrackerException extends TorrentScraperException {}

/** Thrown when a UDP socket connection to a tracker fails. */
class UdpConnectionException extends TrackerException {}

/** Thrown when an HTTP/HTTPS connection to a tracker fails. */
class HttpConnectionException extends TrackerException {}

/** Thrown when a tracker does not respond within the configured timeout. */
class TrackerTimeoutException extends TrackerException {}
