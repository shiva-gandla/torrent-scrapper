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
 * File: TorrentUploadHandler.php
 * Component: Torrent Upload Engine
 * Description: Receives uploaded files, routes them to storage, parses metadata, and populates the database.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Upload;

use TorrentScraper\Core\Exception\FileValidationException;
use TorrentScraper\Core\Logger\Contracts\LoggerInterface;
use TorrentScraper\Core\Parser\TorrentParser;
use TorrentScraper\Core\Service\TorrentService;

/**
 * Orchestrates the full torrent upload workflow:
 *
 *   1. Validate the uploaded file (FileValidator)
 *   2. Read raw bytes
 *   3. Parse into ParsedTorrent DTO (TorrentParser, via TorrentService)
 *   4. Persist to database (TorrentService)
 *   5. Store the .torrent file on disk (FileStorage)
 *   6. Update the torrent record with the stored file path
 *
 * This class is platform-agnostic. The WordPress adapter calls this
 * after its own wp_check_filetype() and nonce verification.
 */
final class TorrentUploadHandler
{
    public function __construct(
        private readonly FileValidator  $validator,
        private readonly FileStorage    $storage,
        private readonly TorrentService $torrentService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Process a single .torrent file upload.
     *
     * @param  string               $tempPath     Absolute path to the temporary uploaded file.
     * @param  string               $originalName Original filename from the upload form.
     * @param  array<string, mixed> $meta         Platform-specific metadata (platform, user_id, post_id, etc.)
     * @return int                                New torrent ID.
     * @throws FileValidationException
     * @throws \RuntimeException                  If the info_hash already exists.
     */
    public function handleUpload(string $tempPath, string $originalName, array $meta = []): int
    {
        // Step 1: Validate.
        $this->validator->validate($tempPath, $originalName);

        // Step 2: Read raw bytes.
        $rawBytes = $this->validator->readContents($tempPath);

        // Step 3+4: Parse and persist via TorrentService (transaction inside).
        $meta['torrent_filename'] = basename($originalName);
        $torrentId = $this->torrentService->createFromTorrentFile($rawBytes, $meta);

        // Step 5: Store the file on disk.
        $torrent  = $this->torrentService->getById($torrentId);
        $infoHash = $torrent !== null ? (string) $torrent['info_hash'] : '';

        if ($infoHash !== '') {
            try {
                $relativePath = $this->storage->store($infoHash, $rawBytes);

                // Step 6: Update the record with the stored file path.
                // The torrent_filename column already has the original name.
                // We could also store the relative path in a separate column,
                // but for now the info_hash-based naming is deterministic.

                $this->logger->info(
                    "Torrent file stored: {$relativePath}",
                    ['event_type' => 'upload.stored', 'torrent_id' => $torrentId],
                );
            } catch (\Throwable $e) {
                // File storage failure is non-fatal — the torrent record exists,
                // and the magnet link is already usable. Log and continue.
                $this->logger->warning(
                    "Failed to store .torrent file on disk: {$e->getMessage()}",
                    ['event_type' => 'upload.storage_error', 'torrent_id' => $torrentId],
                );
            }
        }

        $this->logger->info(
            "Torrent uploaded successfully: {$originalName} (ID: {$torrentId})",
            ['event_type' => 'upload.complete', 'torrent_id' => $torrentId],
        );

        return $torrentId;
    }

    /**
     * Process a magnet link submission (no file upload).
     *
     * @param  string               $magnetUri
     * @param  array<string, mixed> $meta
     * @return int                  New torrent ID.
     */
    public function handleMagnet(string $magnetUri, array $meta = []): int
    {
        $torrentId = $this->torrentService->createFromMagnet($magnetUri, $meta);

        $this->logger->info(
            "Magnet submitted successfully (ID: {$torrentId})",
            ['event_type' => 'upload.magnet_complete', 'torrent_id' => $torrentId],
        );

        return $torrentId;
    }
}
