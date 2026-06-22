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
 * File: TorrentScraperException.php
 * Component: Exception Handling
 * Description: Base exception class for all errors generated within the Torrent Scraper plugin.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Exception;

/**
 * Base exception for all Torrent Scraper errors.
 * All domain exceptions must extend this class.
 */
class TorrentScraperException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
