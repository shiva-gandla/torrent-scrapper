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
 * File: TrackerService.php
 * Component: Business Logic Services
 * Description: High-level tracker querying service coordination for Udp and Http scraper clients.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Service;

use TorrentScraper\Core\Logger\Contracts\LoggerInterface;
use TorrentScraper\Core\Repository\TrackerRepository;
use TorrentScraper\Core\Tracker\TrackerManager;

/**
 * Orchestrates tracker scraping for one or many torrents.
 *
 * Coordinates between:
 *   - TrackerManager   (network layer — dispatches to UDP/HTTP clients)
 *   - StatisticsService (data layer  — persists results and aggregates)
 *   - TrackerRepository (deactivates bad trackers)
 *
 * This is what the WP-Cron scheduler calls every 5 minutes.
 */
final class TrackerService
{
    public function __construct(
        private readonly TrackerManager    $trackerManager,
        private readonly StatisticsService $statsService,
        private readonly TrackerRepository $trackerRepo,
        private readonly LoggerInterface   $logger,
    ) {}

    /**
     * Scrape a single torrent across all its active trackers.
     *
     * For each tracker:
     *   1. Scrape using TrackerManager (handles retries internally).
     *   2. On success: recordSuccess → aggregate rollup.
     *   3. On failure: recordFailure → back-off → deactivate if too many failures.
     */
    public function scrapeOne(int $torrentId, string $infoHash, bool $isSynchronous = false): void
    {
        $trackers = $this->trackerRepo->findByTorrentId($torrentId, activeOnly: true);

        if (empty($trackers)) {
            $this->logger->debug(
                "No active trackers for torrent_id={$torrentId}",
                ['event_type' => 'tracker.scrape_skip', 'torrent_id' => $torrentId],
            );
            return;
        }

        // Sort trackers: UDP first (fastest on dedicated servers), then HTTPS, then HTTP.
        usort($trackers, static function (array $a, array $b): int {
            $order = ['udp' => 0, 'https' => 1, 'http' => 2];
            $aType = $order[$a['tracker_type'] ?? 'http'] ?? 2;
            $bType = $order[$b['tracker_type'] ?? 'http'] ?? 2;
            return $aType <=> $bType;
        });

        // Cap trackers for synchronous requests (manual reload / upload scrape)
        // to prevent 40+ second page loads.
        if ($isSynchronous) {
            $trackers = array_slice($trackers, 0, 5);
        }

        $timeout = $isSynchronous ? 3 : null;
        $maxAttempts = $isSynchronous ? 1 : null;

        foreach ($trackers as $tracker) {
            $trackerId  = (int) $tracker['id'];
            $trackerUrl = (string) $tracker['tracker_url'];

            $results = $this->trackerManager->scrape($trackerUrl, [$infoHash], $timeout, $maxAttempts);

            if (isset($results[$infoHash])) {
                $result = $results[$infoHash];

                $this->statsService->recordSuccess(
                    torrentId: $torrentId,
                    trackerId: $trackerId,
                    seeders:   $result->seeders,
                    leechers:  $result->leechers,
                    completed: $result->completed,
                );

                $this->logger->debug(
                    "Scraped torrent_id={$torrentId} from {$trackerUrl}: "
                    . "S={$result->seeders} L={$result->leechers} C={$result->completed}",
                    ['event_type' => 'tracker.scrape_ok', 'torrent_id' => $torrentId],
                );
            } else {
                // TrackerManager already retried and logged. Record the failure.
                $this->statsService->recordFailure(
                    torrentId:    $torrentId,
                    trackerId:    $trackerId,
                    errorMessage: "No result returned from {$trackerUrl}",
                );

                // Deactivate the tracker if it has failed too many times.
                if ($this->statsService->shouldDeactivateTracker($torrentId, $trackerId)) {
                    $this->trackerRepo->deactivate($trackerId);

                    $this->logger->warning(
                        "Tracker deactivated after repeated failures: {$trackerUrl} "
                        . "(torrent_id={$torrentId})",
                        ['event_type' => 'tracker.deactivated', 'torrent_id' => $torrentId],
                    );
                }
            }
        }
    }

    /**
     * Process a batch of torrent+tracker pairs due for scraping.
     *
     * Called by the WP-Cron handler with results from StatisticsService::getDueForScrape().
     *
     * @param  array<int, array<string, mixed>> $dueItems  Rows from findDueForCheck().
     */
    public function scrapeBatch(array $dueItems): void
    {
        if (empty($dueItems)) {
            return;
        }

        $this->logger->info(
            'Scheduler batch scrape started: ' . count($dueItems) . ' items.',
            ['event_type' => 'tracker.batch_start'],
        );

        // Group by tracker URL for efficient multi-hash scrape requests.
        /** @var array<string, array{hashes: string[], torrentIds: array<string,int>, trackerIds: array<string,int>}> $grouped */
        $grouped = [];

        foreach ($dueItems as $item) {
            $url       = (string) $item['tracker_url'];
            $hash      = (string) $item['info_hash'];
            $torrentId = (int) $item['torrent_id'];
            $trackerId = (int) $item['tracker_id'];

            if (!isset($grouped[$url])) {
                $grouped[$url] = [
                    'hashes'     => [],
                    'torrentIds' => [],
                    'trackerIds' => [],
                ];
            }

            $grouped[$url]['hashes'][]           = $hash;
            $grouped[$url]['torrentIds'][$hash]   = $torrentId;
            $grouped[$url]['trackerIds'][$hash]   = $trackerId;
        }

        // Execute batched scrapes.
        foreach ($grouped as $trackerUrl => $group) {
            $results = $this->trackerManager->scrape($trackerUrl, $group['hashes']);

            foreach ($group['hashes'] as $hash) {
                $torrentId = $group['torrentIds'][$hash] ?? null;
                $trackerId = (int) ($group['trackerIds'][$hash] ?? 0);

                if ($torrentId === null) {
                    continue;
                }

                if (isset($results[$hash])) {
                    $result = $results[$hash];

                    $this->statsService->recordSuccess(
                        torrentId: $torrentId,
                        trackerId: $trackerId,
                        seeders:   $result->seeders,
                        leechers:  $result->leechers,
                        completed: $result->completed,
                    );
                } else {
                    $this->statsService->recordFailure(
                        torrentId:    $torrentId,
                        trackerId:    $trackerId,
                        errorMessage: "No result in batch scrape from {$trackerUrl}",
                    );

                    if ($this->statsService->shouldDeactivateTracker($torrentId, $trackerId)) {
                        $this->trackerRepo->deactivate($trackerId);
                    }
                }
            }
        }

        $this->logger->info(
            'Scheduler batch scrape complete.',
            ['event_type' => 'tracker.batch_end'],
        );
    }
}
