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
 * File: Scheduler.php
 * Component: Cron Scheduler Task Queue
 * Description: Orchestrates the cron-like execution of queued tasks, managing concurrency and background scraping threads.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Scheduler;

use TorrentScraper\Core\Logger\Contracts\LoggerInterface;
use TorrentScraper\Core\Service\StatisticsService;
use TorrentScraper\Core\Service\TrackerService;

/**
 * Core scheduler — builds and dispatches the scrape queue.
 *
 * Called by platform cron handlers:
 *   WordPress → WpCronIntegration → Scheduler::run()
 *   XenForo   → XenForoCronEntry  → Scheduler::run()  (Phase 2)
 *
 * Execution flow:
 *   1. Query StatisticsService for all torrent+tracker pairs due for a check.
 *   2. Convert rows to QueueItems.
 *   3. Hand off to TrackerService::scrapeBatch().
 *
 * The scheduler itself does NOT do network I/O — that belongs to TrackerService.
 * Keeping them separate makes the scheduler unit-testable without network.
 *
 * PHP execution time on shared hosting is typically 30–60 seconds.
 * Default batch size (50) is chosen to stay well within that limit.
 */
final class Scheduler
{
    /** Maximum items processed per cron run. Shared hosting safe. */
    private const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly StatisticsService $statsService,
        private readonly TrackerService    $trackerService,
        private readonly LoggerInterface   $logger,
        private readonly int               $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {}

    /**
     * Run one scheduling cycle:
     *   1. Fetch due items.
     *   2. Build queue.
     *   3. Dispatch to TrackerService.
     */
    public function run(): void
    {
        $this->logger->info(
            'Scheduler run started.',
            ['event_type' => 'scheduler.run_start'],
        );

        $dueItems = $this->statsService->getDueForScrape($this->batchSize);

        if (empty($dueItems)) {
            $this->logger->debug(
                'Scheduler: no items due for scraping.',
                ['event_type' => 'scheduler.nothing_due'],
            );
            return;
        }

        $this->logger->info(
            'Scheduler dispatching ' . count($dueItems) . ' items.',
            ['event_type' => 'scheduler.dispatching'],
        );

        $this->trackerService->scrapeBatch($dueItems);

        $this->logger->info(
            'Scheduler run complete.',
            ['event_type' => 'scheduler.run_end'],
        );
    }

    /**
     * Run a manual (admin-initiated) scrape cycle with reduced batch and fast timeouts.
     * Designed to complete in < 15 seconds so the admin page doesn't hang.
     */
    public function runManual(): void
    {
        $this->logger->info(
            'Scheduler manual run started.',
            ['event_type' => 'scheduler.manual_start'],
        );

        $dueItems = $this->statsService->getDueForScrape(10);

        if (empty($dueItems)) {
            $this->logger->debug(
                'Scheduler manual: no items due for scraping.',
                ['event_type' => 'scheduler.manual_nothing_due'],
            );
            return;
        }

        $this->logger->info(
            'Scheduler manual dispatching ' . count($dueItems) . ' items.',
            ['event_type' => 'scheduler.manual_dispatching'],
        );

        // Use synchronous mode for each item (3s timeout, 1 attempt, max 5 trackers).
        foreach ($dueItems as $item) {
            $torrentId = (int) $item['torrent_id'];
            $infoHash  = (string) $item['info_hash'];

            $this->trackerService->scrapeOne($torrentId, $infoHash, isSynchronous: true);
        }

        $this->logger->info(
            'Scheduler manual run complete.',
            ['event_type' => 'scheduler.manual_end'],
        );
    }
}
