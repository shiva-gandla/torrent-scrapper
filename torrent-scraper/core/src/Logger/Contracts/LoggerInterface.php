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
 * File: LoggerInterface.php
 * Component: Logger Contract
 * Description: Interface defining core logging levels and operations.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Logger\Contracts;

/**
 * Logger interface — platform-agnostic.
 * Implementations: DatabaseLogger (core), WordPress uses wp_die / error_log bridge.
 */
interface LoggerInterface
{
    /**
     * Log a debug-level message.
     * Only recorded when log_level is set to 'debug'.
     *
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Log an informational message.
     *
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * Log a warning that does not stop execution.
     *
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Log an error condition.
     *
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;
}
