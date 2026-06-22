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
 * File: UdpTrackerClient.php
 * Component: Tracker Client Scraper
 * Description: Direct socket UDP communication client for scraping trackers with minimized network overhead.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Tracker;

use TorrentScraper\Core\Exception\TrackerException;
use TorrentScraper\Core\Exception\UdpConnectionException;
use TorrentScraper\Core\Exception\TrackerTimeoutException;
use TorrentScraper\Core\Security\SecurityLayer;
use TorrentScraper\Core\Tracker\Contracts\TrackerClientInterface;

/**
 * UDP Tracker Scrape Client — implements BEP 15.
 *
 * Protocol overview (all multi-byte integers are big-endian):
 *
 * Step 1 — Connect Request (16 bytes sent):
 *   [0-7]  int64  connection_id  = 0x41727101980 (magic)
 *   [8-11] int32  action         = 0 (connect)
 *   [12-15]int32  transaction_id = random
 *
 * Step 2 — Connect Response (16 bytes received):
 *   [0-3]  int32  action         = 0 (connect)
 *   [4-7]  int32  transaction_id = must match
 *   [8-15] int64  connection_id  (use for scrape)
 *
 * Step 3 — Scrape Request (16 + 20×N bytes sent):
 *   [0-7]  int64  connection_id  (from step 2)
 *   [8-11] int32  action         = 2 (scrape)
 *   [12-15]int32  transaction_id = new random
 *   [16+]  bytes  info_hash × N (20 bytes each, raw binary)
 *
 * Step 4 — Scrape Response (8 + 12×N bytes received):
 *   [0-3]  int32  action         = 2 (scrape)
 *   [4-7]  int32  transaction_id = must match
 *   Per torrent (12 bytes each):
 *     [0-3] int32  seeders    (complete)
 *     [4-7] int32  completed  (downloaded)
 *     [8-11]int32  leechers   (incomplete)
 *
 * Requires: sockets extension (fsockopen with udp://).
 * Falls back gracefully if sockets are not available (checked in supports()).
 */
final class UdpTrackerClient implements TrackerClientInterface
{
    /** BEP 15 magic connection ID for the initial connect request. */
    private const MAGIC_CONNECTION_ID = 0x41727101980;

    /** Maximum number of info hashes per single scrape request (BEP 15 unspecified, practical limit). */
    private const MAX_HASHES_PER_REQUEST = 74;

    public function supports(string $trackerUrl): bool
    {
        // fsockopen('udp://...') uses PHP streams, NOT the sockets extension.
        // No need to check extension_loaded('sockets').
        return str_starts_with($trackerUrl, 'udp://');
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

        [$host, $port] = $this->parseHostPort($trackerUrl);

        $socket = $this->openSocket($host, $port, $timeoutSec);

        try {
            $connectionId = $this->connect($socket, $timeoutSec);

            // BEP 15 allows up to 74 hashes per request in practice; chunk if needed.
            $results = [];
            foreach (array_chunk($infoHashes, self::MAX_HASHES_PER_REQUEST) as $chunk) {
                $chunkResults = $this->scrapeChunk($socket, $connectionId, $trackerUrl, $chunk, $timeoutSec);
                $results      = array_merge($results, $chunkResults);
            }

            return $results;
        } finally {
            fclose($socket);
        }
    }

    // -------------------------------------------------------------------------
    // Protocol steps
    // -------------------------------------------------------------------------

    /**
     * Step 1+2: Send connect request, receive and verify connect response.
     * Returns the 64-bit connection_id from the tracker.
     *
     * @param  resource $socket
     * @throws UdpConnectionException
     * @throws TrackerTimeoutException
     */
    private function connect(mixed $socket, int $timeoutSec): int
    {
        $transactionId = random_int(0, 0x7FFFFFFF);

        // Pack connect request: int64 magic + int32 action=0 + int32 transaction_id
        $request = pack('NNnN',
            (self::MAGIC_CONNECTION_ID >> 32) & 0xFFFFFFFF,   // high 32 bits
            self::MAGIC_CONNECTION_ID & 0xFFFFFFFF,            // low 32 bits
            0,                                                  // action = 0 (connect)
            $transactionId,
        );
        // Note: pack 'NN' gives two uint32 big-endian, but magic is int64.
        // Rebuild correctly:
        $request = $this->packInt64(self::MAGIC_CONNECTION_ID)
                 . pack('NN', 0, $transactionId);

        $sent = fwrite($socket, $request);
        if ($sent === false || $sent !== strlen($request)) {
            throw new UdpConnectionException('Failed to send UDP connect request.');
        }

        $response = $this->readWithTimeout($socket, 16, $timeoutSec);

        if (strlen($response) < 16) {
            throw new UdpConnectionException(
                'UDP connect response too short: ' . strlen($response) . ' bytes.'
            );
        }

        $unpacked = unpack('Naction/Ntransaction/NhighConn/NlowConn', $response);
        if ($unpacked === false) {
            throw new UdpConnectionException('Failed to unpack UDP connect response.');
        }

        if ((int) $unpacked['action'] !== 0) {
            throw new UdpConnectionException(
                'UDP connect response has unexpected action: ' . $unpacked['action']
            );
        }

        if ((int) $unpacked['transaction'] !== $transactionId) {
            throw new UdpConnectionException('UDP connect response transaction_id mismatch.');
        }

        // Reconstruct 64-bit connection_id from high/low 32-bit halves.
        return ((int) $unpacked['highConn'] << 32) | ((int) $unpacked['lowConn'] & 0xFFFFFFFF);
    }

    /**
     * Step 3+4: Send scrape request for a chunk of info hashes, return results.
     *
     * @param  resource  $socket
     * @param  string[]  $infoHashes  40-char hex strings
     * @return array<string, ScrapeResult>
     * @throws TrackerException
     */
    private function scrapeChunk(
        mixed  $socket,
        int    $connectionId,
        string $trackerUrl,
        array  $infoHashes,
        int    $timeoutSec,
    ): array {
        $transactionId = random_int(0, 0x7FFFFFFF);

        // Build scrape request.
        $request = $this->packInt64($connectionId)  // connection_id
                 . pack('NN', 2, $transactionId);   // action=2 (scrape) + transaction_id

        // Append raw binary info hashes (20 bytes each).
        foreach ($infoHashes as $hex) {
            $request .= hex2bin($hex);
        }

        $sent = fwrite($socket, $request);
        if ($sent === false || $sent !== strlen($request)) {
            throw new UdpConnectionException('Failed to send UDP scrape request.');
        }

        // Expected response size: 8 header bytes + 12 bytes per hash.
        $expectedSize = 8 + (count($infoHashes) * 12);
        $response     = $this->readWithTimeout($socket, $expectedSize, $timeoutSec);

        if (strlen($response) < 8) {
            throw new UdpConnectionException(
                'UDP scrape response too short: ' . strlen($response) . ' bytes.'
            );
        }

        $header = unpack('Naction/Ntransaction', substr($response, 0, 8));
        if ($header === false) {
            throw new UdpConnectionException('Failed to unpack UDP scrape response header.');
        }

        if ((int) $header['action'] !== 2) {
            // action=3 means error — try to read the error string.
            if ((int) $header['action'] === 3) {
                $errorMsg = substr($response, 8);
                throw new UdpConnectionException("Tracker returned error: {$errorMsg}");
            }
            throw new UdpConnectionException(
                'UDP scrape response has unexpected action: ' . $header['action']
            );
        }

        if ((int) $header['transaction'] !== $transactionId) {
            throw new UdpConnectionException('UDP scrape response transaction_id mismatch.');
        }

        // Parse peer data — 12 bytes per hash.
        $results = [];
        $offset  = 8;

        foreach ($infoHashes as $hex) {
            if ($offset + 12 > strlen($response)) {
                break;
            }

            $data = unpack('Nseeders/Ncompleted/Nleechers', substr($response, $offset, 12));
            if ($data === false) {
                $offset += 12;
                continue;
            }

            $results[$hex] = new ScrapeResult(
                infoHash:   $hex,
                seeders:    (int) $data['seeders'],
                leechers:   (int) $data['leechers'],
                completed:  (int) $data['completed'],
                trackerUrl: $trackerUrl,
            );

            $offset += 12;
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Open a non-blocking UDP socket to host:port.
     *
     * @return resource
     * @throws UdpConnectionException
     */
    private function openSocket(string $host, int $port, int $timeoutSec): mixed
    {
        $errno  = 0;
        $errstr = '';

        $socket = @fsockopen(
            'udp://' . $host,
            $port,
            $errno,
            $errstr,
            (float) $timeoutSec,
        );

        if ($socket === false) {
            throw new UdpConnectionException(
                "Could not open UDP socket to {$host}:{$port} — {$errstr} (errno {$errno})"
            );
        }

        stream_set_timeout($socket, $timeoutSec);

        return $socket;
    }

    /**
     * Read up to $length bytes from the socket with a timeout guard.
     *
     * @param  resource $socket
     * @throws TrackerTimeoutException
     * @throws UdpConnectionException
     */
    private function readWithTimeout(mixed $socket, int $length, int $timeoutSec): string
    {
        stream_set_timeout($socket, $timeoutSec);

        $data = fread($socket, $length);

        if ($data === false) {
            throw new UdpConnectionException('Failed to read from UDP socket.');
        }

        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) {
            throw new TrackerTimeoutException('UDP socket read timed out.');
        }

        return $data;
    }

    /**
     * Parse host and port from a UDP tracker URL.
     * e.g. udp://tracker.opentrackr.org:1337/announce → ['tracker.opentrackr.org', 1337]
     *
     * @return array{0: string, 1: int}
     * @throws UdpConnectionException
     */
    private function parseHostPort(string $url): array
    {
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['host'])) {
            throw new UdpConnectionException("Cannot parse host from UDP URL: {$url}");
        }

        $host = (string) $parsed['host'];
        $port = (int) ($parsed['port'] ?? 80);

        if ($port <= 0 || $port > 65535) {
            throw new UdpConnectionException("Invalid port {$port} in UDP URL: {$url}");
        }

        return [$host, $port];
    }

    /**
     * Pack a PHP integer as a big-endian 64-bit signed integer (8 bytes).
     * PHP's pack() has no native int64 big-endian format on 32-bit builds,
     * so we split into two 32-bit halves.
     */
    private function packInt64(int $value): string
    {
        $high = ($value >> 32) & 0xFFFFFFFF;
        $low  = $value & 0xFFFFFFFF;

        return pack('NN', $high, $low);
    }
}
