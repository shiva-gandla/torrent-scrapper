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
 * File: TorrentRepository.php
 * Component: Database Repositories
 * Description: Handles CRUD operations and complex queries for custom torrent post relationships.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Repository;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;
use TorrentScraper\Core\Exception\DatabaseException;

/**
 * Data access layer for the tp_torrents table.
 *
 * Rules:
 *   - All queries go through $db (never use $wpdb directly here).
 *   - Return typed arrays or null/int — never raw DB row objects.
 *   - No business logic here — repositories only read/write data.
 *
 * @phpstan-type TorrentRow array{
 *     id: int,
 *     info_hash: string,
 *     name: string,
 *     category_id: int|null,
 *     description: string|null,
 *     total_size: int,
 *     file_count: int,
 *     piece_length: int,
 *     piece_count: int,
 *     comment: string|null,
 *     created_by: string|null,
 *     torrent_created_at: string|null,
 *     is_private: bool,
 *     magnet_link: string|null,
 *     torrent_filename: string|null,
 *     platform: string,
 *     platform_post_id: int|null,
 *     platform_user_id: int|null,
 *     status: string,
 *     seeders: int,
 *     leechers: int,
 *     completed: int,
 *     stats_checked_at: string|null,
 *     added_at: string,
 *     updated_at: string
 * }
 */
final class TorrentRepository
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    // -------------------------------------------------------------------------
    // Read operations
    // -------------------------------------------------------------------------

    /**
     * Find a torrent by its primary key.
     *
     * @return array<string, mixed>|null
     * @throws DatabaseException
     */
    public function findById(int $id): ?array
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrents` WHERE `id` = ? AND `status` != 'deleted' LIMIT 1",
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Find a torrent by its info hash (SHA1 hex, lowercase).
     *
     * @return array<string, mixed>|null
     * @throws DatabaseException
     */
    public function findByInfoHash(string $infoHash): ?array
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrents` WHERE `info_hash` = ? AND `status` != 'deleted' LIMIT 1",
            [strtolower($infoHash)],
        );

        return $rows[0] ?? null;
    }

    /**
     * Find a torrent by its info hash — including soft-deleted records.
     * Used when re-uploading a previously deleted torrent.
     *
     * @return array<string, mixed>|null
     * @throws DatabaseException
     */
    public function findByInfoHashIncludingDeleted(string $infoHash): ?array
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrents` WHERE `info_hash` = ? LIMIT 1",
            [strtolower($infoHash)],
        );

        return $rows[0] ?? null;
    }

    /**
     * Return a paginated list of active torrents.
     *
     * @param  array<string, string> $filters  Supported: category_id, status, platform
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function findAll(
        int $limit = 20,
        int $offset = 0,
        string $orderBy = 'added_at',
        string $direction = 'DESC',
        array $filters = [],
    ): array {
        $prefix  = $this->db->tablePrefix();
        $where   = ['`status` = ?'];
        $params  = [$filters['status'] ?? 'active'];

        if (isset($filters['category_id'])) {
            $where[]  = '`category_id` = ?';
            $params[] = (int) $filters['category_id'];
        }

        if (isset($filters['platform'])) {
            $where[]  = '`platform` = ?';
            $params[] = $filters['platform'];
        }

        if (!empty($filters['search'])) {
            $where[]  = '`name` LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['platform_user_id'])) {
            $where[]  = '`platform_user_id` = ?';
            $params[] = (int) $filters['platform_user_id'];
        }

        // Whitelist sort columns to prevent injection.
        $allowedOrder = ['added_at', 'seeders', 'leechers', 'completed', 'name', 'total_size'];
        $orderCol     = in_array($orderBy, $allowedOrder, strict: true) ? $orderBy : 'added_at';
        $dir          = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $whereClause = implode(' AND ', $where);
        $params[]    = $limit;
        $params[]    = $offset;

        return $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrents`
              WHERE {$whereClause}
              ORDER BY `{$orderCol}` {$dir}
              LIMIT ? OFFSET ?",
            $params,
        );
    }

    /**
     * Count torrents matching the given filters.
     *
     * @param  array<string, string> $filters
     * @throws DatabaseException
     */
    public function count(array $filters = []): int
    {
        $prefix  = $this->db->tablePrefix();
        $where   = ['`status` = ?'];
        $params  = [$filters['status'] ?? 'active'];

        if (isset($filters['category_id'])) {
            $where[]  = '`category_id` = ?';
            $params[] = (int) $filters['category_id'];
        }

        if (!empty($filters['search'])) {
            $where[]  = '`name` LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['platform_user_id'])) {
            $where[]  = '`platform_user_id` = ?';
            $params[] = (int) $filters['platform_user_id'];
        }

        $rows = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM `{$prefix}tp_torrents` WHERE " . implode(' AND ', $where),
            $params,
        );

        return (int) ($rows[0]['cnt'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // Write operations
    // -------------------------------------------------------------------------

    /**
     * Insert a new torrent row and return its new ID.
     *
     * @param  array<string, mixed> $data
     * @throws DatabaseException
     */
    public function insert(array $data): int
    {
        $table = $this->db->tablePrefix() . 'tp_torrents';

        return $this->db->insertRow($table, [
            'info_hash'          => strtolower((string) ($data['info_hash'] ?? '')),
            'name'               => (string) ($data['name'] ?? ''),
            'category_id'        => isset($data['category_id'])    ? (int) $data['category_id']    : null,
            'description'        => $data['description']   ?? null,
            'total_size'         => (int) ($data['total_size']     ?? 0),
            'file_count'         => (int) ($data['file_count']     ?? 0),
            'piece_length'       => (int) ($data['piece_length']   ?? 0),
            'piece_count'        => (int) ($data['piece_count']    ?? 0),
            'comment'            => $data['comment']        ?? null,
            'created_by'         => $data['created_by']    ?? null,
            'torrent_created_at' => $data['torrent_created_at'] ?? null,
            'is_private'         => (isset($data['is_private']) && $data['is_private']) ? 1 : 0,
            'magnet_link'        => $data['magnet_link']   ?? null,
            'torrent_filename'   => $data['torrent_filename'] ?? null,
            'platform'           => (string) ($data['platform']    ?? 'wordpress'),
            'platform_post_id'   => isset($data['platform_post_id']) ? (int) $data['platform_post_id'] : null,
            'platform_user_id'   => isset($data['platform_user_id']) ? (int) $data['platform_user_id'] : null,
            'status'             => (string) ($data['status']      ?? 'pending'),
        ]);
    }

    /**
     * Update specific columns of a torrent row by ID.
     *
     * @param  array<string, mixed> $data  Columns to update.
     * @throws DatabaseException
     */
    public function update(int $id, array $data): int
    {
        if (empty($data)) {
            return 0;
        }

        $prefix  = $this->db->tablePrefix();
        $allowed = [
            'name', 'category_id', 'description', 'status',
            'seeders', 'leechers', 'completed', 'stats_checked_at',
            'platform_post_id', 'magnet_link', 'torrent_filename',
        ];

        $sets   = [];
        $params = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]   = "`{$col}` = ?";
                $params[] = $data[$col];
            }
        }

        if (empty($sets)) {
            return 0;
        }

        $params[] = $id;

        return $this->db->execute(
            "UPDATE `{$prefix}tp_torrents` SET " . implode(', ', $sets) . " WHERE `id` = ?",
            $params,
        );
    }

    /**
     * Soft-delete a torrent (set status = 'deleted').
     *
     * @throws DatabaseException
     */
    public function softDelete(int $id): int
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->execute(
            "UPDATE `{$prefix}tp_torrents` SET `status` = 'deleted' WHERE `id` = ?",
            [$id],
        );
    }

    /**
     * Update aggregated seeder/leecher/completed counts from the statistics table.
     * Uses MAX(seeders) aggregation strategy — picks the best response across trackers.
     *
     * @throws DatabaseException
     */
    public function refreshAggregatedStats(int $torrentId): int
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->execute(
            "UPDATE `{$prefix}tp_torrents` t
                JOIN (
                    SELECT
                        torrent_id,
                        MAX(seeders)   AS max_seeders,
                        MAX(leechers)  AS max_leechers,
                        MAX(completed) AS max_completed
                    FROM `{$prefix}tp_torrent_statistics`
                    WHERE torrent_id = ?
                    GROUP BY torrent_id
                ) s ON t.id = s.torrent_id
             SET
                t.seeders          = s.max_seeders,
                t.leechers         = s.max_leechers,
                t.completed        = s.max_completed,
                t.stats_checked_at = NOW()
             WHERE t.id = ?",
            [$torrentId, $torrentId],
        );
    }

    /**
     * Permanently delete a torrent and ALL related data from the database.
     * This is irreversible — removes stats, trackers, files, post mappings, and the torrent row.
     *
     * @throws DatabaseException
     */
    public function hardDelete(int $id): void
    {
        $prefix = $this->db->tablePrefix();

        // Delete in dependency order (children first).
        $this->db->execute("DELETE FROM `{$prefix}tp_torrent_statistics` WHERE `torrent_id` = ?", [$id]);
        $this->db->execute("DELETE FROM `{$prefix}tp_torrent_trackers`   WHERE `torrent_id` = ?", [$id]);
        $this->db->execute("DELETE FROM `{$prefix}tp_torrent_files`      WHERE `torrent_id` = ?", [$id]);
        $this->db->execute("DELETE FROM `{$prefix}tp_torrent_post_map`   WHERE `torrent_id` = ?", [$id]);

        // Delete the torrent row itself.
        $this->db->execute("DELETE FROM `{$prefix}tp_torrents` WHERE `id` = ?", [$id]);
    }

    // -------------------------------------------------------------------------
    // Aggregate / frontend queries
    // -------------------------------------------------------------------------

    /**
     * Return aggregate stats for all active, non-private torrents.
     * Used for the global stats widget (public trackers only).
     *
     * @return array{total_torrents: int, total_seeders: int, total_leechers: int, total_completed: int}
     * @throws DatabaseException
     */
    public function getGlobalStats(): array
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT
                COUNT(*)        AS total_torrents,
                COALESCE(SUM(`seeders`), 0)   AS total_seeders,
                COALESCE(SUM(`leechers`), 0)  AS total_leechers,
                COALESCE(SUM(`completed`), 0) AS total_completed
             FROM `{$prefix}tp_torrents`
             WHERE `status` = 'active' AND `is_private` = 0",
            [],
        );

        $row = $rows[0] ?? [];

        return [
            'total_torrents'  => (int) ($row['total_torrents']  ?? 0),
            'total_seeders'   => (int) ($row['total_seeders']   ?? 0),
            'total_leechers'  => (int) ($row['total_leechers']  ?? 0),
            'total_completed' => (int) ($row['total_completed'] ?? 0),
        ];
    }

    /**
     * Return torrents uploaded by a specific user.
     *
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function findByUserId(
        int $userId,
        int $limit = 20,
        int $offset = 0,
        string $orderBy = 'added_at',
        string $direction = 'DESC',
    ): array {
        return $this->findAll(
            limit:     $limit,
            offset:    $offset,
            orderBy:   $orderBy,
            direction: $direction,
            filters:   ['status' => 'active', 'platform_user_id' => $userId],
        );
    }

    /**
     * Count torrents uploaded by a specific user.
     *
     * @throws DatabaseException
     */
    public function countByUserId(int $userId): int
    {
        return $this->count(['status' => 'active', 'platform_user_id' => $userId]);
    }
}
