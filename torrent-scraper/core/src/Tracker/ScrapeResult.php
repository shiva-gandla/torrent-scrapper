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
 * File: ScrapeResult.php
 * Component: Tracker Client Scraper
 * Description: Container representing the scraping result from a tracker client.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Tracker;

/**
 * Immutable result of a single tracker scrape for one info hash.
 */
final class ScrapeResult
{
    public function __construct(
        /** 40-char lowercase hex info hash. */
        public readonly string $infoHash,
        /** Number of currently connected seeders. */
        public readonly int    $seeders,
        /** Number of currently connected leechers. */
        public readonly int    $leechers,
        /** Total number of completed downloads (ever). */
        public readonly int    $completed,
        /** URL of the tracker that returned this result. */
        public readonly string $trackerUrl,
    ) {}

    /** True if the tracker returned non-zero peer data. */
    public function hasActivity(): bool
    {
        return $this->seeders > 0 || $this->leechers > 0 || $this->completed > 0;
    }
}
