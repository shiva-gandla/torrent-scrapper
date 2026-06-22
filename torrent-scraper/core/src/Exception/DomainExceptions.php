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
 * File: DomainExceptions.php
 * Component: Exception Handling
 * Description: Specific domain exception classes (such as InvalidArgumentException and NotFoundException) indicating logical constraints.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Exception;

/** Thrown when a database query or transaction fails. */
class DatabaseException extends TorrentScraperException {}

/** Thrown when the schema installer encounters a migration error. */
class InstallException extends TorrentScraperException {}

/** Thrown for general security violations (input validation, IP blocking, etc.). */
class SecurityException extends TorrentScraperException {}

/** Thrown when a rate limit is exceeded. */
class RateLimitException extends SecurityException {}

/** Thrown when the scheduler encounters an unrecoverable error. */
class SchedulerException extends TorrentScraperException {}

/** Thrown when an uploaded file fails validation (bad extension, magic bytes, size, etc.). */
class FileValidationException extends TorrentScraperException {}

/** Thrown when storing or reading a .torrent file from disk fails. */
class FileStorageException extends TorrentScraperException {}
