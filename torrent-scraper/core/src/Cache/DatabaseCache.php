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
 * File: DatabaseCache.php
 * Component: Cache Implementation
 * Description: Database-backed cache implementation that serializes and stores cache items in a custom database table.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Cache;

use TorrentScraper\Core\Cache\Contracts\CacheInterface;
use TorrentScraper\Core\Database\Contracts\DatabaseInterface;

/**
 * Database-backed cache — stores serialized values in a dedicated table.
 *
 * Uses the `{prefix}tp_cache` table (created by SchemaInstaller migration 007
 * when added, or can use an existing options table pattern).
 *
 * For WordPress: this wraps WordPress transients API via wp_set_transient /
 * wp_get_transient so the WordPress object cache (Memcached/Redis) is
 * transparently used if available. See WordPressCacheAdapter for that bridge.
 *
 * This pure DB implementation is the fallback when no WP context is available.
 *
 * Table structure (created inline if not exists):
 *   cache_key   VARCHAR(191) PRIMARY KEY
 *   value       LONGTEXT
 *   expires_at  DATETIME
 */
final class DatabaseCache implements CacheInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }

        $prefix = $this->db->tablePrefix();
        $rows   = $this->db->query(
            "SELECT `value` FROM `{$prefix}tp_cache` WHERE `cache_key` = ? LIMIT 1",
            [$key],
        );

        if (empty($rows)) {
            return null;
        }

        $raw = $rows[0]['value'] ?? null;

        return $raw !== null ? unserialize((string) $raw) : null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $prefix    = $this->db->tablePrefix();
        $expiresAt = $ttl === 0
            ? '9999-12-31 23:59:59'
            : date('Y-m-d H:i:s', time() + $ttl);

        // REPLACE INTO handles both insert and update atomically.
        $this->db->execute(
            "REPLACE INTO `{$prefix}tp_cache`
                (`cache_key`, `value`, `expires_at`)
             VALUES
                (?, ?, ?)",
            [$key, serialize($value), $expiresAt],
        );
    }

    public function delete(string $key): void
    {
        $prefix = $this->db->tablePrefix();

        $this->db->execute(
            "DELETE FROM `{$prefix}tp_cache` WHERE `cache_key` = ?",
            [$key],
        );
    }

    public function flush(): void
    {
        $prefix = $this->db->tablePrefix();

        $this->db->execute("TRUNCATE TABLE `{$prefix}tp_cache`");
    }

    public function has(string $key): bool
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT 1 FROM `{$prefix}tp_cache`
              WHERE `cache_key` = ?
                AND `expires_at` > NOW()
              LIMIT 1",
            [$key],
        );

        return !empty($rows);
    }
}
