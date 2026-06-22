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
 * File: TorrentPostMapRepository.php
 * Component: Post Sync Utilities
 * Description: Repository mapping torrent attachment records to parent forum posts.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Sync;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;

/**
 * Data access layer for the tp_torrent_post_map table.
 * Manages many-to-many relationships between torrents and posts/topics.
 */
final class TorrentPostMapRepository
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Find all torrents attached to a specific post/topic.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByPost(string $platform, int $postId): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->query(
            "SELECT m.*, t.name, t.info_hash, t.seeders, t.leechers, t.completed,
                    t.total_size, t.magnet_link, t.torrent_filename, t.status
             FROM `{$prefix}tp_torrent_post_map` m
             JOIN `{$prefix}tp_torrents` t ON t.id = m.torrent_id AND t.status != 'deleted'
             WHERE m.platform = ? AND m.post_id = ?
             ORDER BY m.sort_order ASC, m.added_at ASC",
            [$platform, $postId],
        );
    }

    /**
     * Find all posts/topics a torrent is attached to.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByTorrent(int $torrentId): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrent_post_map`
             WHERE torrent_id = ?
             ORDER BY platform, post_id",
            [$torrentId],
        );
    }

    /**
     * Attach a torrent to a post/topic. Returns true if newly inserted, false if already existed.
     */
    public function attach(int $torrentId, string $platform, int $postId, ?int $addedBy = null): bool
    {
        $prefix = $this->db->tablePrefix();

        // Check if already attached.
        $existing = $this->db->query(
            "SELECT id FROM `{$prefix}tp_torrent_post_map`
             WHERE torrent_id = ? AND platform = ? AND post_id = ? LIMIT 1",
            [$torrentId, $platform, $postId],
        );

        if (!empty($existing)) {
            return false;
        }

        // Find the next sort order for this post.
        $maxOrder = $this->db->query(
            "SELECT COALESCE(MAX(sort_order), -1) AS max_order
             FROM `{$prefix}tp_torrent_post_map`
             WHERE platform = ? AND post_id = ?",
            [$platform, $postId],
        );
        $nextOrder = ((int) ($maxOrder[0]['max_order'] ?? -1)) + 1;

        $this->db->insertRow("{$prefix}tp_torrent_post_map", [
            'torrent_id' => $torrentId,
            'platform'   => $platform,
            'post_id'    => $postId,
            'sort_order' => $nextOrder,
            'added_by'   => $addedBy,
        ]);

        return true;
    }

    /**
     * Detach (soft unlink) a torrent from a post/topic.
     * The torrent record itself remains in tp_torrents.
     */
    public function detach(int $torrentId, string $platform, int $postId): int
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->execute(
            "DELETE FROM `{$prefix}tp_torrent_post_map`
             WHERE torrent_id = ? AND platform = ? AND post_id = ?",
            [$torrentId, $platform, $postId],
        );
    }

    /**
     * Detach all torrents from a specific post/topic.
     */
    public function detachAllFromPost(string $platform, int $postId): int
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->execute(
            "DELETE FROM `{$prefix}tp_torrent_post_map`
             WHERE platform = ? AND post_id = ?",
            [$platform, $postId],
        );
    }

    /**
     * Check if a torrent is attached to any post/topic.
     */
    public function isAttachedAnywhere(int $torrentId): bool
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT 1 FROM `{$prefix}tp_torrent_post_map`
             WHERE torrent_id = ? LIMIT 1",
            [$torrentId],
        );

        return !empty($rows);
    }

    /**
     * Count all attached torrents (for the "Attached" dashboard tab).
     */
    public function countAttached(): int
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT COUNT(DISTINCT m.torrent_id) AS cnt
             FROM `{$prefix}tp_torrent_post_map` m
             JOIN `{$prefix}tp_torrents` t ON t.id = m.torrent_id AND t.status = 'active'",
            [],
        );

        return (int) ($rows[0]['cnt'] ?? 0);
    }

    /**
     * List all attached torrents (paginated).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAttached(int $limit = 50, int $offset = 0): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->query(
            "SELECT t.*
             FROM `{$prefix}tp_torrents` t
             WHERE t.status = 'active'
               AND t.id IN (SELECT DISTINCT torrent_id FROM `{$prefix}tp_torrent_post_map`)
             ORDER BY t.added_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset],
        );
    }
}
