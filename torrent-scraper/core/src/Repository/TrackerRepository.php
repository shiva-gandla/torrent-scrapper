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
 * File: TrackerRepository.php
 * Component: Database Repositories
 * Description: Manages tracker endpoints, tracker load balancing, and active tracking stats.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Repository;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;
use TorrentScraper\Core\Exception\DatabaseException;

/**
 * Data access layer for the tp_torrent_trackers table.
 */
final class TrackerRepository
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all active trackers for a given torrent, ordered by tier.
     *
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function findByTorrentId(int $torrentId, bool $activeOnly = true): array
    {
        $prefix = $this->db->tablePrefix();
        $params = [$torrentId];
        $and    = $activeOnly ? 'AND `is_active` = 1' : '';

        return $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrent_trackers`
              WHERE `torrent_id` = ? {$and}
              ORDER BY `tier` ASC, `id` ASC",
            $params,
        );
    }

    /**
     * Find a specific tracker row by torrent + URL.
     *
     * @return array<string, mixed>|null
     * @throws DatabaseException
     */
    public function findByTorrentAndUrl(int $torrentId, string $url): ?array
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrent_trackers`
              WHERE `torrent_id` = ? AND `tracker_url` = ? LIMIT 1",
            [$torrentId, $url],
        );

        return $rows[0] ?? null;
    }

    /**
     * Return all distinct tracker URLs that are active across all torrents.
     * Used by the scheduler to batch-scrape unique hosts.
     *
     * @return string[]
     * @throws DatabaseException
     */
    public function findAllActiveUrls(): array
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT DISTINCT `tracker_url` FROM `{$prefix}tp_torrent_trackers`
              WHERE `is_active` = 1
              ORDER BY `tracker_url` ASC",
        );

        return array_column($rows, 'tracker_url');
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a tracker URL for a torrent.
     * Ignores duplicates (IGNORE INTO) — safe to call repeatedly.
     *
     * @throws DatabaseException
     */
    public function insertIfNotExists(
        int    $torrentId,
        string $url,
        string $type,
        int    $tier = 0,
    ): int {
        $prefix = $this->db->tablePrefix();

        // Normalise tracker type.
        $type = match (true) {
            str_starts_with($url, 'udp://')   => 'udp',
            str_starts_with($url, 'https://') => 'https',
            default                           => 'http',
        };

        return $this->db->execute(
            "INSERT IGNORE INTO `{$prefix}tp_torrent_trackers`
                (`torrent_id`, `tracker_url`, `tracker_type`, `tier`, `is_active`)
             VALUES (?, ?, ?, ?, 1)",
            [$torrentId, $url, $type, $tier],
        );
    }

    /**
     * Mark a tracker as inactive after repeated failures.
     *
     * @throws DatabaseException
     */
    public function deactivate(int $trackerId): int
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->execute(
            "UPDATE `{$prefix}tp_torrent_trackers` SET `is_active` = 0 WHERE `id` = ?",
            [$trackerId],
        );
    }

    /**
     * Re-activate a previously deactivated tracker.
     *
     * @throws DatabaseException
     */
    public function reactivate(int $trackerId): int
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->execute(
            "UPDATE `{$prefix}tp_torrent_trackers` SET `is_active` = 1 WHERE `id` = ?",
            [$trackerId],
        );
    }

    /**
     * Re-enable ALL trackers for a torrent (reverse batch deactivation).
     * Called when reactivating a soft-deleted torrent.
     *
     * @throws DatabaseException
     */
    public function reactivateAll(int $torrentId): int
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->execute(
            "UPDATE `{$prefix}tp_torrent_trackers` SET `is_active` = 1 WHERE `torrent_id` = ?",
            [$torrentId],
        );
    }

    /**
     * Delete all trackers for a torrent.
     * Called during torrent deletion.
     *
     * @throws DatabaseException
     */
    public function deleteByTorrentId(int $torrentId): int
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->execute(
            "DELETE FROM `{$prefix}tp_torrent_trackers` WHERE `torrent_id` = ?",
            [$torrentId],
        );
    }
}
