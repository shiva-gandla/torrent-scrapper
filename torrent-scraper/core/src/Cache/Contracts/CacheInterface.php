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
 * File: CacheInterface.php
 * Component: Cache Contract
 * Description: Interface defining core cache operations (get, set, delete, flush, has) for pluggable cache adapters.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Cache\Contracts;

/**
 * Platform-agnostic cache interface.
 *
 * Implementations:
 *   ArrayCache    — in-memory per-request (testing / fallback)
 *   FileCache     — filesystem-based (shared hosting with no object cache)
 *   DatabaseCache — uses tp_torrent_logs-adjacent table via DatabaseInterface
 */
interface CacheInterface
{
    /**
     * Retrieve a cached value by key.
     * Returns null if the key does not exist or has expired.
     */
    public function get(string $key): mixed;

    /**
     * Store a value under a key with a TTL in seconds.
     * A TTL of 0 means "never expire" (use sparingly).
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void;

    /**
     * Delete a single cached entry.
     */
    public function delete(string $key): void;

    /**
     * Wipe all entries in this cache store.
     */
    public function flush(): void;

    /**
     * Check if a key exists and has not expired.
     */
    public function has(string $key): bool;
}
