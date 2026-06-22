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
 * File: StatisticsService.php
 * Component: Business Logic Services
 * Description: Aggregates tracker stats and handles cache logic for seeder/leecher counts.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Service;

use TorrentScraper\Core\Exception\DatabaseException;
use TorrentScraper\Core\Logger\Contracts\LoggerInterface;
use TorrentScraper\Core\Repository\StatisticsRepository;
use TorrentScraper\Core\Repository\TorrentRepository;

/**
 * Handles aggregation of tracker scrape results.
 *
 * After TrackerService scrapes a tracker, it calls StatisticsService to:
 *   1. Persist the per-tracker result to tp_torrent_statistics.
 *   2. Roll up the aggregated seeders/leechers/completed to tp_torrents.
 */
final class StatisticsService
{
    /** Back-off thresholds: disable tracker after this many consecutive failures. */
    private const MAX_CONSECUTIVE_FAILURES = 5;

    public function __construct(
        private readonly StatisticsRepository $statsRepo,
        private readonly TorrentRepository    $torrentRepo,
        private readonly LoggerInterface      $logger,
    ) {}

    /**
     * Record a successful scrape result.
     *
     * Persists per-tracker row, then updates the aggregate on tp_torrents.
     *
     * @throws DatabaseException
     */
    public function recordSuccess(
        int $torrentId,
        int $trackerId,
        int $seeders,
        int $leechers,
        int $completed,
    ): void {
        // Adaptive check interval: healthy torrents are checked less often,
        // dead torrents are checked every 5 minutes to detect revival.
        $checkInterval = match (true) {
            $seeders >= 20 => 21600,  // 6 hours  — healthy, users see real counts in client
            $seeders >= 6  => 1800,   // 30 min   — moderate health
            $seeders >= 1  => 900,    // 15 min   — low health, needs attention
            default        => 300,    // 5 min    — dead, check frequently
        };

        $this->statsRepo->upsert(
            torrentId:         $torrentId,
            trackerId:         $trackerId,
            seeders:           $seeders,
            leechers:          $leechers,
            completed:         $completed,
            nextCheckInterval: $checkInterval,
        );

        // Refresh the aggregated stats on the parent torrent row.
        $this->torrentRepo->refreshAggregatedStats($torrentId);

        $this->logger->debug(
            "Stats updated: torrent_id={$torrentId} tracker_id={$trackerId} "
            . "seeders={$seeders} leechers={$leechers}",
            ['event_type' => 'stats.success', 'torrent_id' => $torrentId],
        );
    }

    /**
     * Record a failed scrape attempt.
     *
     * Applies exponential back-off via the repository.
     * If the tracker has exceeded MAX_CONSECUTIVE_FAILURES, it should be deactivated
     * by TrackerService — this service only records the data.
     *
     * @throws DatabaseException
     */
    public function recordFailure(
        int    $torrentId,
        int    $trackerId,
        string $errorMessage,
    ): void {
        $this->statsRepo->recordFailure(
            torrentId: $torrentId,
            trackerId: $trackerId,
            error:     $errorMessage,
        );

        // Check consecutive failures — caller may use this to decide deactivation.
        $row = $this->statsRepo->findByTorrentAndTracker($torrentId, $trackerId);
        $failures = (int) ($row['consecutive_failures'] ?? 0);

        $this->logger->warning(
            "Tracker scrape failed: torrent_id={$torrentId} tracker_id={$trackerId} "
            . "failures={$failures} error={$errorMessage}",
            ['event_type' => 'stats.failure', 'torrent_id' => $torrentId],
        );
    }

    /**
     * Should this tracker be deactivated based on its failure count?
     *
     * @throws DatabaseException
     */
    public function shouldDeactivateTracker(int $torrentId, int $trackerId): bool
    {
        $row = $this->statsRepo->findByTorrentAndTracker($torrentId, $trackerId);
        $failures = (int) ($row['consecutive_failures'] ?? 0);

        return $failures >= self::MAX_CONSECUTIVE_FAILURES;
    }

    /**
     * Return recent statistics for a torrent, for display in admin or frontend.
     *
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function getForTorrent(int $torrentId): array
    {
        return $this->statsRepo->findByTorrentId($torrentId);
    }

    /**
     * Return the scheduler's next batch of torrent+tracker pairs due for a scrape.
     *
     * @param  int $batchSize  Maximum number of pairs to return.
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function getDueForScrape(int $batchSize = 50): array
    {
        return $this->statsRepo->findDueForCheck($batchSize);
    }
}
