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
 * File: PostLinkRepository.php
 * Component: Post Sync Utilities
 * Description: Manages database relationships connecting torrent entities with WP forum threads.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Sync;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;

/**
 * Data access layer for the tp_post_links table.
 * Tracks synced post/topic pairs across platforms.
 */
final class PostLinkRepository
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Find all targets linked from a source post.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findTargets(string $sourcePlatform, int $sourceId): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->query(
            "SELECT * FROM `{$prefix}tp_post_links`
             WHERE source_platform = ? AND source_id = ? AND sync_enabled = 1",
            [$sourcePlatform, $sourceId],
        );
    }

    /**
     * Find the source that created a given target.
     *
     * @return array<string, mixed>|null
     */
    public function findSource(string $targetPlatform, int $targetId): ?array
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT * FROM `{$prefix}tp_post_links`
             WHERE target_platform = ? AND target_id = ? AND sync_enabled = 1
             LIMIT 1",
            [$targetPlatform, $targetId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Find all linked posts (both directions) for a given platform+postId.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllLinked(string $platform, int $postId): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->query(
            "SELECT * FROM `{$prefix}tp_post_links`
             WHERE (source_platform = ? AND source_id = ?)
                OR (target_platform = ? AND target_id = ?)",
            [$platform, $postId, $platform, $postId],
        );
    }

    /**
     * Create a link between two posts/topics.
     */
    public function createLink(
        string $sourcePlatform,
        int    $sourceId,
        string $targetPlatform,
        int    $targetId,
    ): int {
        $prefix = $this->db->tablePrefix();

        return $this->db->insertRow("{$prefix}tp_post_links", [
            'source_platform' => $sourcePlatform,
            'source_id'       => $sourceId,
            'target_platform' => $targetPlatform,
            'target_id'       => $targetId,
            'sync_enabled'    => 1,
        ]);
    }

    /**
     * Delete all links involving a specific post (both as source and target).
     */
    public function deleteAllForPost(string $platform, int $postId): int
    {
        $prefix = $this->db->tablePrefix();

        $count = $this->db->execute(
            "DELETE FROM `{$prefix}tp_post_links`
             WHERE (source_platform = ? AND source_id = ?)",
            [$platform, $postId],
        );

        $count += $this->db->execute(
            "DELETE FROM `{$prefix}tp_post_links`
             WHERE (target_platform = ? AND target_id = ?)",
            [$platform, $postId],
        );

        return $count;
    }

    /**
     * Check if a link already exists between source and target.
     */
    public function linkExists(string $sourcePlatform, int $sourceId, string $targetPlatform): bool
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT 1 FROM `{$prefix}tp_post_links`
             WHERE source_platform = ? AND source_id = ? AND target_platform = ?
             LIMIT 1",
            [$sourcePlatform, $sourceId, $targetPlatform],
        );

        return !empty($rows);
    }
}
