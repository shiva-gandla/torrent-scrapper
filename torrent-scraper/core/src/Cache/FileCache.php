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
 * File: FileCache.php
 * Component: Cache Implementation
 * Description: Filesystem-backed cache implementation storing serialized data on disk under the plugin's cache directory.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Cache;

use TorrentScraper\Core\Cache\Contracts\CacheInterface;

/**
 * Filesystem cache — stores serialized PHP values in a cache directory.
 *
 * Use cases:
 *   - Shared hosting where no object cache (Memcached/Redis) is available.
 *   - Persists across requests unlike ArrayCache.
 *   - More performant than DatabaseCache for high-read items.
 *
 * File naming: sha256(<key>) to avoid filesystem-unsafe characters.
 * File format: <expires_timestamp>\n<serialized_value>
 */
final class FileCache implements CacheInterface
{
    public function __construct(
        /** Absolute path to the cache directory. Must be writable. */
        private readonly string $cacheDir,
    ) {}

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }

        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $newlinePos = strpos($raw, "\n");
        if ($newlinePos === false) {
            return null;
        }

        $payload = substr($raw, $newlinePos + 1);

        return unserialize($payload);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $this->ensureDir();

        $expires = $ttl === 0 ? PHP_INT_MAX : (time() + $ttl);
        $content = $expires . "\n" . serialize($value);

        @file_put_contents($this->path($key), $content, LOCK_EX);
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function flush(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        foreach (glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function has(string $key): bool
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return false;
        }

        $raw = @file_get_contents($path, length: 20); // read just the timestamp line

        if ($raw === false) {
            return false;
        }

        $newlinePos = strpos($raw, "\n");
        if ($newlinePos === false) {
            return false;
        }

        $expires = (int) substr($raw, 0, $newlinePos);

        if (time() > $expires) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /** Compute the full file path for a given cache key. */
    private function path(string $key): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.cache';
    }

    /** Create the cache directory if it does not exist. */
    private function ensureDir(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, recursive: true);
        }
    }
}
