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
 * File: HttpTrackerClient.php
 * Component: Tracker Client Scraper
 * Description: Connects to HTTP/HTTPS tracker servers to scrape seeder, leecher, and completed download numbers.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Tracker;

use TorrentScraper\Core\Exception\HttpConnectionException;
use TorrentScraper\Core\Exception\TrackerTimeoutException;
use TorrentScraper\Core\Parser\BencodeDecoder;
use TorrentScraper\Core\Security\SecurityLayer;
use TorrentScraper\Core\Tracker\Contracts\TrackerClientInterface;

/**
 * HTTP/HTTPS Tracker Scrape Client.
 *
 * Protocol (BEP 3 scrape convention):
 *   - Replace '/announce' with '/scrape' in the URL (if not already /scrape).
 *   - Append ?info_hash=<binary_20_bytes> for each hash (URL-encoded).
 *   - GET response is bencoded:
 *       d
 *         "files"
 *         d
 *           <20_byte_hash>
 *           d
 *             "complete"   <int>  (seeders)
 *             "downloaded" <int>  (completed)
 *             "incomplete" <int>  (leechers)
 *           e
 *         e
 *       e
 *
 * Requires: curl extension.
 *
 * Shared hosting safety:
 *   - Uses cURL only (never file_get_contents).
 *   - CURLOPT_FOLLOWLOCATION is limited to 3 redirects.
 *   - CURLOPT_SSL_VERIFYPEER = true (no SSL bypass).
 *   - CURLOPT_TIMEOUT enforced.
 */
final class HttpTrackerClient implements TrackerClientInterface
{
    /** Maximum number of info hashes to append per HTTP scrape request. */
    private const MAX_HASHES_PER_REQUEST = 50;

    public function __construct(
        private readonly BencodeDecoder $decoder,
    ) {}

    public function supports(string $trackerUrl): bool
    {
        $scheme = strtolower(parse_url($trackerUrl, PHP_URL_SCHEME) ?? '');
        return in_array($scheme, ['http', 'https'], strict: true) && extension_loaded('curl');
    }

    /**
     * @inheritDoc
     */
    public function scrape(
        string $trackerUrl,
        array  $infoHashes,
        int    $timeoutSec = 10,
    ): array {
        SecurityLayer::validateOutboundHost($trackerUrl);

        if (empty($infoHashes)) {
            return [];
        }

        $scrapeUrl = $this->buildScrapeUrl($trackerUrl);
        $results   = [];

        // Chunk to avoid overly long URLs.
        foreach (array_chunk($infoHashes, self::MAX_HASHES_PER_REQUEST) as $chunk) {
            $chunkResults = $this->scrapeChunk($scrapeUrl, $trackerUrl, $chunk, $timeoutSec);
            $results      = array_merge($results, $chunkResults);
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Protocol implementation
    // -------------------------------------------------------------------------

    /**
     * Build the scrape URL by replacing /announce with /scrape.
     * If the URL already ends with /scrape (or no announce path), use as-is.
     */
    private function buildScrapeUrl(string $trackerUrl): string
    {
        // Common case: tracker.example.com/announce → tracker.example.com/scrape
        if (str_ends_with($trackerUrl, '/announce')) {
            return substr($trackerUrl, 0, -strlen('/announce')) . '/scrape';
        }

        // Already /scrape.
        if (str_contains($trackerUrl, '/scrape')) {
            return $trackerUrl;
        }

        // Unknown path — append /scrape.
        return rtrim($trackerUrl, '/') . '/scrape';
    }

    /**
     * Perform a single HTTP scrape request for a chunk of info hashes.
     *
     * @param  string[] $infoHashes  40-char hex strings
     * @return array<string, ScrapeResult>
     * @throws HttpConnectionException
     * @throws TrackerTimeoutException
     */
    private function scrapeChunk(
        string $scrapeUrl,
        string $originalUrl,
        array  $infoHashes,
        int    $timeoutSec,
    ): array {
        $url = $this->appendInfoHashes($scrapeUrl, $infoHashes);

        $responseBody = $this->curlGet($url, $timeoutSec);
        $parsed       = $this->decodeScrapeResponse($responseBody, $url);

        $results = [];

        foreach ($infoHashes as $hex) {
            // HTTP response has raw 20-byte binary keys, not hex.
            $binaryHash = hex2bin($hex);
            if ($binaryHash === false) {
                continue;
            }

            $entry = $parsed[$binaryHash] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $results[$hex] = new ScrapeResult(
                infoHash:   $hex,
                seeders:    (int) ($entry['complete'] ?? 0),
                leechers:   (int) ($entry['incomplete'] ?? 0),
                completed:  (int) ($entry['downloaded'] ?? 0),
                trackerUrl: $originalUrl,
            );
        }

        return $results;
    }

    /**
     * Append URL-encoded binary info hashes as ?info_hash= query parameters.
     *
     * @param string[] $infoHashes  40-char hex strings
     */
    private function appendInfoHashes(string $baseUrl, array $infoHashes): string
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        $params    = [];

        foreach ($infoHashes as $hex) {
            $binary   = hex2bin($hex);
            if ($binary === false) {
                continue;
            }
            $params[] = 'info_hash=' . urlencode($binary);
        }

        return $baseUrl . $separator . implode('&', $params);
    }

    /**
     * Perform a cURL GET and return the raw response body.
     *
     * @throws HttpConnectionException
     * @throws TrackerTimeoutException
     */
    private function curlGet(string $url, int $timeoutSec): string
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new HttpConnectionException('Failed to initialize cURL handle.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSec, 5),
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'TorrentScraper/1.0',
            CURLOPT_ENCODING       => '',   // Accept compressed responses.
            CURLOPT_BINARYTRANSFER => true, // Response contains binary bencode.
        ]);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new TrackerTimeoutException("HTTP tracker request timed out: {$url}");
        }

        if ($body === false || $errno !== 0) {
            throw new HttpConnectionException(
                "cURL error {$errno} for {$url}: {$error}"
            );
        }

        if ($code !== 200) {
            throw new HttpConnectionException(
                "HTTP tracker returned status {$code} for {$url}"
            );
        }

        return (string) $body;
    }

    /**
     * Decode a bencoded HTTP scrape response.
     *
     * Expected structure: d"files"d<20-byte-hash>d"complete"<N>"downloaded"<N>"incomplete"<N>eee
     *
     * @return array<string, mixed>  Map of raw 20-byte binary hash → entry dict.
     * @throws HttpConnectionException
     */
    private function decodeScrapeResponse(string $body, string $url): array
    {
        if ($body === '') {
            throw new HttpConnectionException("Empty response from HTTP tracker: {$url}");
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = $this->decoder->decode($body);
        } catch (\Throwable $e) {
            // Some trackers return failure reason as plain text inside bencode.
            if (str_contains($body, 'failure reason')) {
                throw new HttpConnectionException(
                    "Tracker returned failure response: {$body}"
                );
            }
            throw new HttpConnectionException(
                "Failed to decode HTTP scrape response from {$url}: {$e->getMessage()}"
            );
        }

        if (!is_array($decoded) || !isset($decoded['files']) || !is_array($decoded['files'])) {
            throw new HttpConnectionException(
                "HTTP scrape response missing 'files' key from {$url}"
            );
        }

        return $decoded['files'];
    }
}
