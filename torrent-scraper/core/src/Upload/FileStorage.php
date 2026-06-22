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
 * File: FileStorage.php
 * Component: Torrent Upload Engine
 * Description: Manages physical storage, folder paths, and naming protocols for uploaded torrent files.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Upload;

use TorrentScraper\Core\Exception\FileStorageException;

/**
 * Handles safe storage of uploaded .torrent files.
 *
 * Storage layout:
 *   <baseDir>/
 *     <year>/<month>/
 *       <info_hash>.torrent
 *
 * Rules:
 *   - Files are stored by info_hash, not by original filename (prevents collisions).
 *   - Original filename is stored in the database (tp_torrents.torrent_filename).
 *   - Directories are created with 0755, files with 0644.
 *   - An .htaccess is created in the base dir to block direct access.
 */
final class FileStorage
{
    public function __construct(
        /** Absolute path to the storage base directory. */
        private readonly string $baseDir,
    ) {}

    /**
     * Store a .torrent file and return the relative path from baseDir.
     *
     * @param  string $infoHash   40-char hex info hash (used as filename).
     * @param  string $contents   Raw binary contents of the .torrent file.
     * @return string             Relative path: "2026/06/<info_hash>.torrent"
     * @throws FileStorageException
     */
    public function store(string $infoHash, string $contents): string
    {
        $year  = date('Y');
        $month = date('m');

        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month;

        $this->ensureDirectory($dir);
        $this->ensureHtaccess();

        $filename     = strtolower($infoHash) . '.torrent';
        $absolutePath = $dir . DIRECTORY_SEPARATOR . $filename;
        $relativePath = $year . '/' . $month . '/' . $filename;

        // Don't overwrite existing files (same info_hash = same content).
        if (is_file($absolutePath)) {
            return $relativePath;
        }

        $written = @file_put_contents($absolutePath, $contents, LOCK_EX);

        if ($written === false) {
            throw new FileStorageException("Failed to write torrent file: {$absolutePath}");
        }

        // Set restrictive permissions.
        @chmod($absolutePath, 0644);

        return $relativePath;
    }

    /**
     * Read a stored .torrent file by its relative path.
     *
     * @throws FileStorageException
     */
    public function read(string $relativePath): string
    {
        $absolutePath = $this->resolve($relativePath);

        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            throw new FileStorageException("Cannot read torrent file: {$absolutePath}");
        }

        return $contents;
    }

    /**
     * Check if a .torrent file exists in storage.
     */
    public function exists(string $relativePath): bool
    {
        $absolutePath = $this->resolve($relativePath);
        return is_file($absolutePath);
    }

    /**
     * Delete a stored .torrent file.
     */
    public function delete(string $relativePath): bool
    {
        $absolutePath = $this->resolve($relativePath);

        if (!is_file($absolutePath)) {
            return false;
        }

        return @unlink($absolutePath);
    }

    /**
     * Return the absolute path to the storage base directory.
     */
    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a relative path to an absolute path, with path traversal check.
     *
     * @throws FileStorageException
     */
    private function resolve(string $relativePath): string
    {
        // Prevent path traversal.
        if (str_contains($relativePath, '..')) {
            throw new FileStorageException('Path traversal detected in file path.');
        }

        $absolutePath = $this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        // Verify the resolved path is still within the base directory.
        $realBase = realpath($this->baseDir);
        $realPath = realpath(dirname($absolutePath));

        if ($realBase !== false && $realPath !== false && !str_starts_with($realPath, $realBase)) {
            throw new FileStorageException('File path escapes storage directory.');
        }

        return $absolutePath;
    }

    /**
     * Create a directory with proper permissions.
     *
     * @throws FileStorageException
     */
    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        $created = @mkdir($dir, 0755, recursive: true);

        if (!$created && !is_dir($dir)) {
            throw new FileStorageException("Cannot create storage directory: {$dir}");
        }
    }

    /**
     * Create an .htaccess file in the base dir to block direct web access.
     * This prevents anyone from downloading stored .torrent files by guessing URLs.
     */
    private function ensureHtaccess(): void
    {
        $htaccess = $this->baseDir . DIRECTORY_SEPARATOR . '.htaccess';

        if (is_file($htaccess)) {
            return;
        }

        $this->ensureDirectory($this->baseDir);

        $content = <<<HTACCESS
        # Torrent Scraper — block direct file access
        Order deny,allow
        Deny from all
        HTACCESS;

        @file_put_contents($htaccess, $content);
    }
}
