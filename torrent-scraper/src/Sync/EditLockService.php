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
 * File: EditLockService.php
 * Component: Post Sync Utilities
 * Description: Manages locking mechanisms during concurrent torrent updates to avoid data overwriting.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Sync;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;

/**
 * Manages edit locks for cross-platform synced posts.
 * Prevents simultaneous editing by multiple admins/moderators.
 *
 * Locks auto-expire after 5 minutes. The WP Heartbeat API is used
 * to renew locks while the user is actively editing.
 */
final class EditLockService
{
    /** Lock duration in seconds before auto-expiry. */
    private const LOCK_DURATION = 300; // 5 minutes

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Attempt to acquire an edit lock. Returns null on success,
     * or an array with lock holder info if already locked by another user.
     *
     * @return array{user_id: int, user_name: string, locked_at: string}|null
     */
    public function acquireLock(string $platform, int $postId, int $userId): ?array
    {
        // Clean expired locks first.
        $this->cleanExpired();

        $prefix = $this->db->tablePrefix();

        // Check for existing lock by another user.
        $existing = $this->db->query(
            "SELECT * FROM `{$prefix}tp_edit_locks`
             WHERE platform = ? AND post_id = ? AND expires_at > NOW()
             LIMIT 1",
            [$platform, $postId],
        );

        if (!empty($existing)) {
            $lock = $existing[0];
            if ((int) $lock['user_id'] !== $userId) {
                // Locked by someone else.
                return [
                    'user_id'   => (int) $lock['user_id'],
                    'user_name' => (string) $lock['user_name'],
                    'locked_at' => (string) $lock['locked_at'],
                ];
            }

            // Same user — renew the lock.
            $this->renewLock($platform, $postId, $userId);
            return null;
        }

        // No active lock — acquire it.
        $userName = '';
        $user = get_userdata($userId);
        if ($user) {
            $userName = $user->display_name ?: $user->user_login;
        }

        $expiresAt = date('Y-m-d H:i:s', time() + self::LOCK_DURATION);

        // Use REPLACE to handle race conditions (UNIQUE key on platform+post_id).
        $this->db->execute(
            "REPLACE INTO `{$prefix}tp_edit_locks`
             (`platform`, `post_id`, `user_id`, `user_name`, `locked_at`, `expires_at`)
             VALUES (?, ?, ?, ?, NOW(), ?)",
            [$platform, $postId, $userId, $userName, $expiresAt],
        );

        return null; // Lock acquired successfully.
    }

    /**
     * Renew an existing lock (extend expiry by LOCK_DURATION from now).
     */
    public function renewLock(string $platform, int $postId, int $userId): void
    {
        $prefix    = $this->db->tablePrefix();
        $expiresAt = date('Y-m-d H:i:s', time() + self::LOCK_DURATION);

        $this->db->execute(
            "UPDATE `{$prefix}tp_edit_locks`
             SET expires_at = ?
             WHERE platform = ? AND post_id = ? AND user_id = ?",
            [$expiresAt, $platform, $postId, $userId],
        );
    }

    /**
     * Release a lock held by a specific user.
     */
    public function releaseLock(string $platform, int $postId, int $userId): void
    {
        $prefix = $this->db->tablePrefix();

        $this->db->execute(
            "DELETE FROM `{$prefix}tp_edit_locks`
             WHERE platform = ? AND post_id = ? AND user_id = ?",
            [$platform, $postId, $userId],
        );
    }

    /**
     * Check if a post is currently locked. Returns lock info or null.
     *
     * @return array{user_id: int, user_name: string, locked_at: string}|null
     */
    public function checkLock(string $platform, int $postId): ?array
    {
        $this->cleanExpired();

        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT * FROM `{$prefix}tp_edit_locks`
             WHERE platform = ? AND post_id = ? AND expires_at > NOW()
             LIMIT 1",
            [$platform, $postId],
        );

        if (empty($rows)) {
            return null;
        }

        return [
            'user_id'   => (int) $rows[0]['user_id'],
            'user_name' => (string) $rows[0]['user_name'],
            'locked_at' => (string) $rows[0]['locked_at'],
        ];
    }

    /**
     * Remove all expired locks from the database.
     */
    public function cleanExpired(): void
    {
        $prefix = $this->db->tablePrefix();

        $this->db->execute(
            "DELETE FROM `{$prefix}tp_edit_locks` WHERE expires_at <= NOW()",
            [],
        );
    }
}
