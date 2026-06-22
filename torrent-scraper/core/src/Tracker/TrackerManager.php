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
 * File: TrackerManager.php
 * Component: Tracker Client Scraper
 * Description: Resolves tracker URIs to determine client selection and performs concurrent scraping.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Tracker;

use TorrentScraper\Core\Exception\TrackerException;
use TorrentScraper\Core\Logger\Contracts\LoggerInterface;
use TorrentScraper\Core\Tracker\Contracts\TrackerClientInterface;

/**
 * Dispatches tracker scrape requests to the correct client implementation.
 *
 * TrackerManager:
 *   - Selects the right client (UDP or HTTP/HTTPS) based on the URL scheme.
 *   - Applies retry logic with configurable attempts and delay.
 *   - Catches all TrackerExceptions and logs them; callers receive empty results.
 *   - Provides batch scraping across multiple tracker URLs for the same info hash.
 *
 * Retry strategy:
 *   - Attempt 1: immediate.
 *   - Attempt 2: 500 ms wait.
 *   - Attempt 3: 1000 ms wait.
 *   - On final failure: returns empty results and logs the error.
 */
final class TrackerManager
{
    /** Retry wait times in microseconds between attempts. */
    private const RETRY_DELAYS_US = [0, 500_000, 1_000_000];

    /** @var TrackerClientInterface[] */
    private array $clients;

    /**
     * @param TrackerClientInterface[] $clients  Ordered list of available clients.
     */
    public function __construct(
        array  $clients,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSec = 10,
        private readonly int $maxAttempts = 3,
    ) {
        $this->clients = $clients;
    }

    /**
     * Scrape a single tracker URL for one or more info hashes.
     *
     * On failure, returns an empty array (never throws — caller sees empty results).
     * @param  string   $trackerUrl
     * @param  string[] $infoHashes  40-char hex strings
     * @param  int|null $customTimeout
     * @param  int|null $customMaxAttempts
     * @return array<string, ScrapeResult>  Keyed by info_hash.
     */
    public function scrape(
        string $trackerUrl,
        array  $infoHashes,
        ?int   $customTimeout = null,
        ?int   $customMaxAttempts = null
    ): array {
        $client = $this->resolveClient($trackerUrl);

        if ($client === null) {
            $this->logger->warning(
                "No client available for tracker: {$trackerUrl}",
                ['event_type' => 'tracker.no_client'],
            );
            return [];
        }

        $timeout = $customTimeout ?? $this->timeoutSec;
        $maxAttempts = $customMaxAttempts ?? $this->maxAttempts;

        $results = $this->attemptScrape($client, $trackerUrl, $infoHashes, $timeout, $maxAttempts);

        // HTTP fallback: if UDP scrape failed, try the same host via HTTP.
        if (empty($results) && str_starts_with($trackerUrl, 'udp://')) {
            $httpFallbackUrl = $this->buildHttpFallbackUrl($trackerUrl);
            if ($httpFallbackUrl !== null) {
                $httpClient = $this->resolveClient($httpFallbackUrl);
                if ($httpClient !== null) {
                    $this->logger->info(
                        "UDP scrape failed for {$trackerUrl}. Trying HTTP fallback: {$httpFallbackUrl}",
                        ['event_type' => 'tracker.udp_http_fallback'],
                    );

                    $results = $this->attemptScrape($httpClient, $httpFallbackUrl, $infoHashes, $timeout, $maxAttempts);

                    if (empty($results)) {
                        $this->logger->warning(
                            "Both UDP and HTTP scrape failed for {$trackerUrl}. "
                            . "If this is a shared host, outbound UDP may be blocked by your server firewall.",
                            ['event_type' => 'tracker.both_failed'],
                        );
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Attempt scraping with retries.
     *
     * @param  TrackerClientInterface $client
     * @param  string   $trackerUrl
     * @param  string[] $infoHashes
     * @param  int      $timeout
     * @param  int      $maxAttempts
     * @return array<string, ScrapeResult>
     */
    private function attemptScrape(
        TrackerClientInterface $client,
        string $trackerUrl,
        array  $infoHashes,
        int    $timeout,
        int    $maxAttempts,
    ): array {
        $lastException = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0 && isset(self::RETRY_DELAYS_US[$attempt])) {
                usleep(self::RETRY_DELAYS_US[$attempt]);
            }

            try {
                $results = $client->scrape($trackerUrl, $infoHashes, $timeout);

                if ($attempt > 0) {
                    $this->logger->info(
                        "Tracker scrape succeeded on attempt " . ($attempt + 1) . ": {$trackerUrl}",
                        ['event_type' => 'tracker.retry_success'],
                    );
                }

                return $results;
            } catch (TrackerException $e) {
                $lastException = $e;

                $this->logger->debug(
                    "Tracker scrape attempt " . ($attempt + 1) . " failed: {$trackerUrl} — {$e->getMessage()}",
                    ['event_type' => 'tracker.attempt_failed'],
                );
            }
        }

        $this->logger->warning(
            "All {$maxAttempts} scrape attempts failed for {$trackerUrl}: "
            . ($lastException?->getMessage() ?? 'unknown error'),
            ['event_type' => 'tracker.scrape_failed'],
        );

        return [];
    }

    /**
     * Build an HTTP fallback URL from a UDP tracker URL.
     * e.g. udp://tracker.opentrackr.org:1337/announce → http://tracker.opentrackr.org:1337/announce
     */
    private function buildHttpFallbackUrl(string $udpUrl): ?string
    {
        $parsed = parse_url($udpUrl);
        if ($parsed === false || !isset($parsed['host'])) {
            return null;
        }

        $host = $parsed['host'];
        $port = $parsed['port'] ?? 80;
        $path = $parsed['path'] ?? '/announce';

        return "http://{$host}:{$port}{$path}";
    }

    /**
     * Scrape multiple tracker URLs for the same info hash.
     * Returns the best result (highest seeders) among all successful responses.
     *
     * @param  string[] $trackerUrls
     * @param  string   $infoHash    40-char hex string
     * @return ScrapeResult|null     Null if all trackers failed.
     */
    public function scrapeAllTrackers(array $trackerUrls, string $infoHash): ?ScrapeResult
    {
        $best = null;

        foreach ($trackerUrls as $url) {
            $results = $this->scrape($url, [$infoHash]);

            if (!isset($results[$infoHash])) {
                continue;
            }

            $result = $results[$infoHash];

            if ($best === null || $result->seeders > $best->seeders) {
                $best = $result;
            }
        }

        return $best;
    }

    /**
     * Batch scrape: scrape a map of trackerUrl → [infoHashes].
     * Returns merged results from all successful responses.
     *
     * @param  array<string, string[]> $batch  trackerUrl => [infoHash, ...]
     * @return array<string, ScrapeResult>     Keyed by info_hash (best result wins).
     */
    public function scrapeBatch(array $batch): array
    {
        $allResults = [];

        foreach ($batch as $trackerUrl => $hashes) {
            $results = $this->scrape($trackerUrl, $hashes);

            foreach ($results as $hash => $result) {
                // Keep the result with the highest seeders count for each hash.
                if (!isset($allResults[$hash]) || $result->seeders > $allResults[$hash]->seeders) {
                    $allResults[$hash] = $result;
                }
            }
        }

        return $allResults;
    }

    // -------------------------------------------------------------------------
    // Client resolution
    // -------------------------------------------------------------------------

    /**
     * Find the first registered client that supports the given tracker URL.
     */
    private function resolveClient(string $trackerUrl): ?TrackerClientInterface
    {
        foreach ($this->clients as $client) {
            if ($client->supports($trackerUrl)) {
                return $client;
            }
        }

        return null;
    }
}
