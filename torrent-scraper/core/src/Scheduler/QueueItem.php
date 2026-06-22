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
 * File: QueueItem.php
 * Component: Cron Scheduler Task Queue
 * Description: Represents a task item in the scheduler queue, such as a tracker scrape task.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Scheduler;

/**
 * Item in the scrape queue.
 * Represents one torrent+tracker pair waiting to be scraped.
 */
final class QueueItem
{
    public function __construct(
        public readonly int    $torrentId,
        public readonly string $infoHash,
        public readonly int    $trackerId,
        public readonly string $trackerUrl,
        public readonly string $trackerType,
    ) {}
}
