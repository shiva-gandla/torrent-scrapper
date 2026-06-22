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
 * File: DatabaseLogger.php
 * Component: Log Management
 * Description: Database-backed logger writing operational details and error logs to a custom database log table.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Logger;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;
use TorrentScraper\Core\Logger\Contracts\LoggerInterface;

/**
 * Writes log entries to the tp_torrent_logs database table.
 *
 * Log levels in order of verbosity (lowest → highest):
 *   error → warning → info → debug
 *
 * Only entries at or above the configured log_level are written.
 */
final class DatabaseLogger implements LoggerInterface
{
    /** Map log level strings to numeric priority (lower = more severe). */
    private const LEVEL_PRIORITY = [
        'error'   => 0,
        'warning' => 1,
        'info'    => 2,
        'debug'   => 3,
    ];

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $logLevel = 'warning',
        private readonly ?int $torrentId = null,
    ) {}

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /**
     * Determine the event_type from the calling context.
     * Falls back to 'system' if the call stack cannot be determined.
     */
    private function resolveEventType(array $context): string
    {
        return $context['event_type'] ?? 'system';
    }

    /**
     * Write a log entry if the given level meets the configured minimum.
     *
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $eventType = $this->resolveEventType($context);

        // Remove internal meta keys before storing context JSON.
        unset($context['event_type']);

        $prefix = $this->db->tablePrefix();

        try {
            $this->db->insertRow("{$prefix}tp_torrent_logs", [
                'torrent_id' => $this->torrentId,
                'event_type' => $eventType,
                'level'      => $level,
                'message'    => $message,
                'context'    => empty($context) ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Fallback to standard PHP error log if database logging fails (e.g. table not created yet).
            error_log("TorrentScraper database logger failed: {$e->getMessage()} | Message: {$message}");
        }
    }

    /**
     * Returns true if the given level should be written given the configured minimum.
     */
    private function shouldLog(string $level): bool
    {
        $configuredPriority = self::LEVEL_PRIORITY[$this->logLevel] ?? 1;
        $levelPriority = self::LEVEL_PRIORITY[$level] ?? 1;

        // Log the entry only if its priority is <= the configured threshold.
        // e.g. logLevel='warning' (priority 1) → log 'error' (0) and 'warning' (1), skip 'info' (2) and 'debug' (3).
        return $levelPriority <= $configuredPriority;
    }
}
