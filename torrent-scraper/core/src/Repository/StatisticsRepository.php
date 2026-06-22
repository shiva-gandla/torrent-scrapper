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
 * File: StatisticsRepository.php
 * Component: Database Repositories
 * Description: Manages storage and retrieval of daily/hourly seeder, leecher, and download counts.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Repository;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;
use TorrentScraper\Core\Exception\DatabaseException;

/**
 * Data access layer for the tp_torrent_statistics table.
 *
 * Each row represents the result of a single tracker scrape.
 * One torrent has one row per tracker (UNIQUE on torrent_id + tracker_id).
 */
final class StatisticsRepository
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all statistics rows for a torrent.
     *
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function findByTorrentId(int $torrentId): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->query(
            "SELECT s.*, t.tracker_url, t.tracker_type
               FROM `{$prefix}tp_torrent_statistics` s
               JOIN `{$prefix}tp_torrent_trackers` t ON s.tracker_id = t.id
              WHERE s.torrent_id = ?
              ORDER BY s.seeders DESC",
            [$torrentId],
        );
    }

    /**
     * Return the statistics row for a specific torrent + tracker combination.
     *
     * @return array<string, mixed>|null
     * @throws DatabaseException
     */
    public function findByTorrentAndTracker(int $torrentId, int $trackerId): ?array
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrent_statistics`
              WHERE `torrent_id` = ? AND `tracker_id` = ? LIMIT 1",
            [$torrentId, $trackerId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Return the batch of torrent+tracker pairs that are due for a stats check.
     * Used by the scheduler to build the scrape queue.
     *
     * @param  int $batchSize Maximum number of rows to return.
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function findDueForCheck(int $batchSize = 50): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->query(
            "SELECT s.*, t.tracker_url, t.tracker_type, tor.info_hash
               FROM `{$prefix}tp_torrent_statistics` s
               JOIN `{$prefix}tp_torrent_trackers` t   ON s.tracker_id = t.id
               JOIN `{$prefix}tp_torrents`         tor ON s.torrent_id = tor.id
              WHERE (s.next_check IS NULL OR s.next_check <= NOW())
                AND t.is_active = 1
                AND tor.status  = 'active'
              ORDER BY s.next_check ASC
              LIMIT ?",
            [$batchSize],
        );
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Upsert a statistics row after a successful scrape.
     *
     * @throws DatabaseException
     */
    public function upsert(
        int    $torrentId,
        int    $trackerId,
        int    $seeders,
        int    $leechers,
        int    $completed,
        int    $nextCheckInterval = 300,
    ): void {
        $prefix    = $this->db->tablePrefix();
        $nextCheck = date('Y-m-d H:i:s', time() + $nextCheckInterval);

        $this->db->execute(
            "INSERT INTO `{$prefix}tp_torrent_statistics`
                (`torrent_id`, `tracker_id`, `seeders`, `leechers`, `completed`,
                 `last_checked`, `next_check`, `check_interval`, `consecutive_failures`, `last_error`)
             VALUES
                (?, ?, ?, ?, ?, NOW(), ?, ?, 0, NULL)
             ON DUPLICATE KEY UPDATE
                `seeders`               = VALUES(`seeders`),
                `leechers`              = VALUES(`leechers`),
                `completed`             = VALUES(`completed`),
                `last_checked`          = NOW(),
                `next_check`            = VALUES(`next_check`),
                `check_interval`        = VALUES(`check_interval`),
                `consecutive_failures`  = 0,
                `last_error`            = NULL",
            [$torrentId, $trackerId, $seeders, $leechers, $completed, $nextCheck, $nextCheckInterval],
        );
    }

    /**
     * Record a failed scrape attempt.
     * Increments consecutive_failures and backs off the next_check exponentially.
     *
     * @throws DatabaseException
     */
    public function recordFailure(
        int    $torrentId,
        int    $trackerId,
        string $error,
    ): void {
        $prefix = $this->db->tablePrefix();

        // Exponential back-off: base interval × 2^failures, capped at 24 hours.
        $this->db->execute(
            "INSERT INTO `{$prefix}tp_torrent_statistics`
                (`torrent_id`, `tracker_id`, `seeders`, `leechers`, `completed`,
                 `last_checked`, `next_check`, `check_interval`, `consecutive_failures`, `last_error`)
             VALUES
                (?, ?, 0, 0, 0, NOW(),
                 DATE_ADD(NOW(), INTERVAL LEAST(check_interval * POWER(2, consecutive_failures), 86400) SECOND),
                 300, 1, ?)
             ON DUPLICATE KEY UPDATE
                `consecutive_failures`  = `consecutive_failures` + 1,
                `last_checked`          = NOW(),
                `next_check`            = DATE_ADD(
                    NOW(),
                    INTERVAL LEAST(`check_interval` * POWER(2, `consecutive_failures`), 86400) SECOND
                ),
                `last_error`            = VALUES(`last_error`)",
            [$torrentId, $trackerId, $error],
        );
    }

    /**
     * Delete all statistics rows for a torrent.
     *
     * @throws DatabaseException
     */
    public function deleteByTorrentId(int $torrentId): int
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->execute(
            "DELETE FROM `{$prefix}tp_torrent_statistics` WHERE `torrent_id` = ?",
            [$torrentId],
        );
    }

    /**
     * Initialize statistics rows for a newly added torrent.
     * Creates one row per tracker (with next_check = NOW() for immediate scrape).
     *
     * @param  int[] $trackerIds
     * @throws DatabaseException
     */
    public function initializeForTorrent(int $torrentId, array $trackerIds): void
    {
        if (empty($trackerIds)) {
            return;
        }

        $prefix = $this->db->tablePrefix();

        foreach ($trackerIds as $trackerId) {
            $this->db->execute(
                "INSERT IGNORE INTO `{$prefix}tp_torrent_statistics`
                    (`torrent_id`, `tracker_id`, `seeders`, `leechers`, `completed`,
                     `next_check`, `check_interval`)
                 VALUES (?, ?, 0, 0, 0, NOW(), 300)",
                [$torrentId, (int) $trackerId],
            );
        }
    }
}
