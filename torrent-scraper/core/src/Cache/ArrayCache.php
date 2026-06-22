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
 * File: ArrayCache.php
 * Component: Cache Implementation
 * Description: In-memory cache implementation storing data in a PHP array for request-lifetime scoping, deduplication, and testing.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Cache;

use TorrentScraper\Core\Cache\Contracts\CacheInterface;

/**
 * In-memory cache — lives only for the duration of a single PHP request.
 *
 * Use cases:
 *   - Unit testing (no disk/DB required).
 *   - Per-request deduplication of repeated lookups.
 *   - Fallback when FileCache and DatabaseCache are unavailable.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires: int}> */
    private array $store = [];

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }

        return $this->store[$key]['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $this->store[$key] = [
            'value'   => $value,
            'expires' => $ttl === 0 ? PHP_INT_MAX : (time() + $ttl),
        ];
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }

    public function flush(): void
    {
        $this->store = [];
    }

    public function has(string $key): bool
    {
        if (!isset($this->store[$key])) {
            return false;
        }

        if (time() > $this->store[$key]['expires']) {
            unset($this->store[$key]);
            return false;
        }

        return true;
    }
}
