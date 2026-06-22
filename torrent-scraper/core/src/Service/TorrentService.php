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
 * File: TorrentService.php
 * Component: Business Logic Services
 * Description: Central service managing torrent creation, metadata validation, file storage organization, and deletes.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Service;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;
use TorrentScraper\Core\Exception\DatabaseException;
use TorrentScraper\Core\Exception\TorrentParseException;
use TorrentScraper\Core\Logger\Contracts\LoggerInterface;
use TorrentScraper\Core\Parser\ParsedTorrent;
use TorrentScraper\Core\Parser\TorrentParser;
use TorrentScraper\Core\Parser\MagnetParser;
use TorrentScraper\Core\Repository\TorrentRepository;
use TorrentScraper\Core\Repository\TrackerRepository;
use TorrentScraper\Core\Repository\StatisticsRepository;
use TorrentScraper\Core\Security\SecurityLayer;

/**
 * Orchestrates all torrent-related business logic.
 *
 * Design decisions:
 *   - NO database transactions. A single INSERT is already atomic.
 *     Wrapping multi-table inserts in transactions on shared hosting
 *     MySQL caused silent rollbacks that made inserts appear to succeed
 *     while the row was never actually committed.
 *   - Tracker and stats inserts are BEST-EFFORT. Their failure is logged
 *     but never prevents the torrent record from being saved.
 *   - Re-uploading a deleted torrent reactivates it instead of throwing.
 */
final class TorrentService
{
    /** Maximum number of trackers stored per torrent (prevent abuse). */
    private const MAX_TRACKERS = 30;

    public function __construct(
        private readonly TorrentRepository    $torrentRepo,
        private readonly TrackerRepository    $trackerRepo,
        private readonly StatisticsRepository $statsRepo,
        private readonly TorrentParser        $torrentParser,
        private readonly MagnetParser         $magnetParser,
        private readonly DatabaseInterface    $db,
        private readonly LoggerInterface      $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    /**
     * Parse a .torrent binary, persist everything to the database, return the torrent ID.
     *
     * Idempotent: re-uploading a deleted torrent reactivates it.
     * Returns the existing ID if the same info_hash is already active.
     *
     * @param  array<string, mixed> $meta  Additional metadata (platform, user_id, status, etc.)
     * @throws TorrentParseException  If the file cannot be parsed.
     * @throws DatabaseException      If the core torrent INSERT fails.
     */
    public function createFromTorrentFile(string $rawBytes, array $meta = []): int
    {
        $parsed = $this->torrentParser->parse($rawBytes);

        return $this->persistParsedTorrent($parsed, $meta);
    }

    /**
     * Parse a magnet URI and create a torrent record.
     *
     * Idempotent: re-submitting a deleted torrent's magnet reactivates it.
     *
     * @param  array<string, mixed> $meta
     * @throws DatabaseException
     */
    public function createFromMagnet(string $magnetUri, array $meta = []): int
    {
        $parsedMagnet = $this->magnetParser->parse($magnetUri);

        // Reactivate if previously deleted, return ID if already active.
        $existing = $this->torrentRepo->findByInfoHashIncludingDeleted($parsedMagnet->infoHash);
        if ($existing !== null) {
            $existingId = (int) $existing['id'];
            if ($existing['status'] !== 'deleted') {
                return $existingId;
            }
            $this->torrentRepo->update($existingId, [
                'magnet_link'      => $magnetUri,
                'status'           => $meta['status'] ?? 'active',
                'seeders'          => 0,
                'leechers'         => 0,
                'completed'        => 0,
                'stats_checked_at' => null,
            ]);
            $this->trackerRepo->reactivateAll($existingId);
            $this->bestEffortPersistTrackers($existingId, $parsedMagnet->trackers);
            return $existingId;
        }

        $torrentId = $this->torrentRepo->insert([
            'info_hash'        => $parsedMagnet->infoHash,
            'name'             => $parsedMagnet->displayName ?? 'Unknown (' . substr($parsedMagnet->infoHash, 0, 8) . ')',
            'magnet_link'      => $magnetUri,
            'platform'         => $meta['platform'] ?? 'wordpress',
            'platform_post_id' => $meta['platform_post_id'] ?? null,
            'platform_user_id' => $meta['platform_user_id'] ?? null,
            'status'           => $meta['status'] ?? 'pending',
            'total_size'       => 0,
            'file_count'       => 0,
            'is_private'       => false,
        ]);

        $this->bestEffortPersistTrackers($torrentId, $parsedMagnet->trackers);

        $this->logger->info(
            "Torrent created from magnet: {$parsedMagnet->infoHash}",
            ['event_type' => 'torrent.create', 'torrent_id' => $torrentId],
        );

        return $torrentId;
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    /**
     * Activate a torrent (pending → active).
     *
     * @throws DatabaseException
     */
    public function activate(int $torrentId): void
    {
        $this->torrentRepo->update($torrentId, ['status' => 'active']);

        $this->logger->info(
            "Torrent #{$torrentId} activated.",
            ['event_type' => 'torrent.activate', 'torrent_id' => $torrentId],
        );
    }

    /**
     * Soft-delete a torrent (status → deleted).
     *
     * @throws DatabaseException
     */
    public function delete(int $torrentId): void
    {
        $this->torrentRepo->softDelete($torrentId);

        $this->logger->info(
            "Torrent #{$torrentId} soft-deleted.",
            ['event_type' => 'torrent.delete', 'torrent_id' => $torrentId],
        );
    }

    /**
     * Permanently delete a torrent — removes DB rows, .torrent file, and all legacy links.
     * This is irreversible.
     *
     * @param string|null $uploadBaseDir  Absolute path to WP uploads base dir (for file deletion).
     * @throws DatabaseException
     */
    public function hardDelete(int $torrentId, ?string $uploadBaseDir = null): void
    {
        $torrent = $this->torrentRepo->findById($torrentId);

        // Delete the physical .torrent file from disk.
        if ($torrent !== null && !empty($torrent['torrent_filename']) && $uploadBaseDir !== null) {
            $filePath = rtrim($uploadBaseDir, '/\\') . '/' . ltrim((string) $torrent['torrent_filename'], '/');
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        // Clean up legacy wp_options links (wpForo topic mappings).
        $prefix = $this->db->tablePrefix();
        $this->db->execute(
            "DELETE FROM `{$prefix}options` WHERE `option_name` LIKE 'tp\_wpforo\_topic\_%' AND `option_value` = ?",
            [(string) $torrentId],
        );

        // Clean up legacy wp_postmeta links (WP posts / bbPress topics).
        $this->db->execute(
            "DELETE FROM `{$prefix}postmeta` WHERE `meta_key` = 'tp_torrent_id' AND `meta_value` = ?",
            [(string) $torrentId],
        );

        // Hard delete from all plugin tables.
        $this->torrentRepo->hardDelete($torrentId);

        $this->logger->info(
            "Torrent #{$torrentId} permanently deleted.",
            ['event_type' => 'torrent.hard_delete', 'torrent_id' => $torrentId],
        );
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     * @throws DatabaseException
     */
    public function getById(int $id): ?array
    {
        return $this->torrentRepo->findById($id);
    }

    /**
     * @param  array<string, string> $filters
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function list(
        array $filters = [],
        int   $limit = 20,
        int   $offset = 0,
    ): array {
        return $this->torrentRepo->findAll($filters, $limit, $offset);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Core persist logic used by createFromTorrentFile.
     *
     * NO transaction wrapper — a single INSERT is already atomic.
     * File/tracker/stats inserts are best-effort: their failure is logged
     * but never rolls back the successfully-created torrent record.
     *
     * @throws TorrentParseException
     * @throws DatabaseException  Only if the main torrent INSERT itself fails.
     */
    private function persistParsedTorrent(ParsedTorrent $parsed, array $meta): int
    {
        // ── Idempotency check ─────────────────────────────────────────────────
        $existing = $this->torrentRepo->findByInfoHashIncludingDeleted($parsed->infoHash);

        if ($existing !== null) {
            $existingId     = (int) $existing['id'];
            $existingStatus = (string) ($existing['status'] ?? '');

            if ($existingStatus !== 'deleted') {
                // Already active or pending — return its ID unchanged.
                return $existingId;
            }

            // Soft-deleted: reactivate with fresh data from the re-uploaded file.
            $createdAt = $parsed->createdAt !== null ? date('Y-m-d H:i:s', $parsed->createdAt) : null;

            $this->torrentRepo->update($existingId, [
                'name'               => $parsed->name,
                'total_size'         => $parsed->totalSize,
                'file_count'         => $parsed->fileCount,
                'piece_length'       => $parsed->pieceLength,
                'piece_count'        => $parsed->pieceCount,
                'comment'            => $parsed->comment,
                'created_by'         => $parsed->createdBy,
                'torrent_created_at' => $createdAt,
                'magnet_link'        => $parsed->magnetLink,
                'is_private'         => $parsed->isPrivate,
                'torrent_filename'   => $meta['torrent_filename'] ?? $existing['torrent_filename'],
                'platform'           => $meta['platform']          ?? $existing['platform'],
                'platform_post_id'   => $meta['platform_post_id'] ?? $existing['platform_post_id'],
                'platform_user_id'   => $meta['platform_user_id'] ?? $existing['platform_user_id'],
                'status'             => $meta['status']            ?? 'active',
                'seeders'            => 0,
                'leechers'           => 0,
                'completed'          => 0,
                'stats_checked_at'   => null,
            ]);

            $this->trackerRepo->reactivateAll($existingId);
            $this->bestEffortPersistTrackers($existingId, $parsed->allTrackerUrls());

            $this->logger->info(
                "Reactivated torrent #{$existingId}: {$parsed->infoHash}",
                ['event_type' => 'torrent.reactivate', 'torrent_id' => $existingId],
            );

            return $existingId;
        }

        // ── New torrent: single INSERT ────────────────────────────────────────
        $createdAt = $parsed->createdAt !== null ? date('Y-m-d H:i:s', $parsed->createdAt) : null;

        $torrentId = $this->torrentRepo->insert([
            'info_hash'          => $parsed->infoHash,
            'name'               => $parsed->name,
            'total_size'         => $parsed->totalSize,
            'file_count'         => $parsed->fileCount,
            'piece_length'       => $parsed->pieceLength,
            'piece_count'        => $parsed->pieceCount,
            'comment'            => $parsed->comment,
            'created_by'         => $parsed->createdBy,
            'torrent_created_at' => $createdAt,
            'is_private'         => $parsed->isPrivate,
            'magnet_link'        => $parsed->magnetLink,
            'platform'           => $meta['platform']          ?? 'wordpress',
            'platform_post_id'   => $meta['platform_post_id'] ?? null,
            'platform_user_id'   => $meta['platform_user_id'] ?? null,
            'torrent_filename'   => $meta['torrent_filename'] ?? null,
            'status'             => $meta['status']            ?? 'pending',
        ]);

        // File list — best-effort, never fails the torrent save.
        $prefix = $this->db->tablePrefix();
        foreach ($parsed->files as $file) {
            try {
                $this->db->execute(
                    "INSERT INTO `{$prefix}tp_torrent_files`
                         (`torrent_id`, `file_path`, `file_size`, `file_index`)
                      VALUES (?, ?, ?, ?)",
                    [$torrentId, $file->path, $file->size, $file->index],
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "Could not save file entry for torrent #{$torrentId}: {$e->getMessage()}",
                    ['event_type' => 'torrent.file_insert_error', 'torrent_id' => $torrentId],
                );
            }
        }

        // Trackers + stats — best-effort.
        $this->bestEffortPersistTrackers($torrentId, $parsed->allTrackerUrls());

        $this->logger->info(
            "Torrent created: {$parsed->name} ({$parsed->infoHash})",
            ['event_type' => 'torrent.create', 'torrent_id' => $torrentId],
        );

        return $torrentId;
    }

    /**
     * Insert tracker URLs and initialize stats rows.
     * Every error is caught and logged — never propagated to the caller.
     *
     * @param string[] $urls
     */
    private function bestEffortPersistTrackers(int $torrentId, array $urls): void
    {
        $trackerIds = [];
        $count      = 0;
        $tier       = 0;

        foreach ($urls as $url) {
            if ($count >= self::MAX_TRACKERS) {
                break;
            }

            if (!SecurityLayer::isTrackerUrlSafe($url)) {
                $this->logger->warning(
                    "Blocked unsafe tracker URL: {$url}",
                    ['event_type' => 'security.tracker_blocked'],
                );
                continue;
            }

            try {
                $affected = $this->trackerRepo->insertIfNotExists(
                    torrentId: $torrentId,
                    url: $url,
                    type: 'http',
                    tier: $tier,
                );

                // Whether it was newly inserted or already existed, get the row ID for stats init.
                $row = $this->trackerRepo->findByTorrentAndUrl($torrentId, $url);
                if ($row !== null) {
                    $trackerIds[] = (int) $row['id'];
                    if ($affected > 0) {
                        $count++;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "Could not insert tracker {$url}: {$e->getMessage()}",
                    ['event_type' => 'torrent.tracker_insert_error', 'torrent_id' => $torrentId],
                );
            }

            $tier++;
        }

        if (!empty($trackerIds)) {
            try {
                $this->statsRepo->initializeForTorrent($torrentId, $trackerIds);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "Could not initialize stats for torrent #{$torrentId}: {$e->getMessage()}",
                    ['event_type' => 'torrent.stats_init_error', 'torrent_id' => $torrentId],
                );
            }
        }
    }
}
