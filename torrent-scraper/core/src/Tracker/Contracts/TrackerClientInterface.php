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
 * File: TrackerClientInterface.php
 * Component: Tracker Client Scraper
 * Description: Interface definition for tracker query execution clients.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Tracker\Contracts;

/**
 * Contract for all tracker clients (UDP and HTTP/HTTPS).
 *
 * Scrape = ask a tracker for seeder/leecher/completed counts for one or more info_hashes.
 * This is READ-ONLY — we never announce peers; we only scrape public stats.
 */
interface TrackerClientInterface
{
    /**
     * Scrape one or more info hashes from a tracker URL.
     *
     * @param  string   $trackerUrl  Full tracker URL (udp:// or http:// or https://).
     * @param  string[] $infoHashes  One or more 40-char lowercase hex info hashes.
     * @param  int      $timeoutSec  Socket/HTTP timeout in seconds.
     *
     * @return array<string, ScrapeResult>  Keyed by info_hash (lowercase hex).
     *
     * @throws \TorrentScraper\Core\Exception\TrackerException  On any connection or protocol error.
     */
    public function scrape(
        string $trackerUrl,
        array  $infoHashes,
        int    $timeoutSec = 10,
    ): array;

    /**
     * Whether this client can handle the given tracker URL.
     * Used by TrackerManager to dispatch to the correct implementation.
     */
    public function supports(string $trackerUrl): bool;
}
