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
 * File: RetryPolicy.php
 * Component: Cron Scheduler Task Queue
 * Description: Defines backoff policies and retry limits for scraper jobs that fail or encounter network issues.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Scheduler;

/**
 * Retry policy for failed tracker scrapes.
 *
 * Implements exponential back-off with jitter:
 *   Attempt 1: base interval (default 1800s = 30 min)
 *   Attempt 2: base × 2 + jitter
 *   Attempt 3: base × 4 + jitter
 *   ...capped at maxInterval (default 86400s = 24 hours)
 *
 * Jitter is a small random offset (+/- 10% of the interval) to prevent
 * thundering-herd when many torrents fail simultaneously.
 */
final class RetryPolicy
{
    public function __construct(
        /** Base retry interval in seconds. */
        private readonly int $baseInterval = 300,
        /** Maximum interval cap in seconds. */
        private readonly int $maxInterval = 86400,
        /** Maximum number of consecutive failures before giving up. */
        private readonly int $maxFailures = 5,
    ) {}

    /**
     * Calculate the next check interval for a given failure count.
     *
     * @param int $consecutiveFailures  Number of consecutive failures so far (0 = no failures).
     */
    public function nextInterval(int $consecutiveFailures): int
    {
        if ($consecutiveFailures <= 0) {
            return $this->baseInterval;
        }

        // Exponential: base × 2^failures
        $exponential = $this->baseInterval * (2 ** $consecutiveFailures);

        // Cap at maxInterval.
        $capped = min($exponential, $this->maxInterval);

        // Add ±10% jitter.
        $jitter = (int) ($capped * 0.1 * (mt_rand(0, 200) / 100 - 1));

        return max($this->baseInterval, $capped + $jitter);
    }

    /**
     * True if the tracker should be permanently deactivated.
     */
    public function shouldDeactivate(int $consecutiveFailures): bool
    {
        return $consecutiveFailures >= $this->maxFailures;
    }
}
