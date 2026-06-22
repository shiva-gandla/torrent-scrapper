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
 * File: FileValidator.php
 * Component: Torrent Upload Engine
 * Description: Inspects file sizes, mime-types, and headers of uploaded files to ensure they are valid `.torrent` files.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Upload;

use TorrentScraper\Core\Exception\FileValidationException;

/**
 * Validates an uploaded .torrent file before any parsing occurs.
 *
 * Validation order (fail-fast):
 *   1. File exists and is readable.
 *   2. File size is within limits.
 *   3. Extension is .torrent.
 *   4. Magic bytes: first non-whitespace byte is 'd' (bencoded dictionary).
 *   5. finfo_file() MIME check as secondary signal (not relied upon alone).
 *   6. Filename is safe (no path traversal, no null bytes).
 *
 * This class is platform-agnostic. The WordPress adapter adds wp_check_filetype()
 * as an additional layer before calling this.
 */
final class FileValidator
{
    /** Default max upload size: 512 KB. Configurable via settings. */
    private const DEFAULT_MAX_SIZE = 524288; // 512 * 1024

    public function __construct(
        private readonly int $maxSizeBytes = self::DEFAULT_MAX_SIZE,
    ) {}

    /**
     * Validate an uploaded file.
     *
     * @param  string $filePath     Absolute path to the temporary uploaded file.
     * @param  string $originalName Original filename from the upload (user-provided).
     * @throws FileValidationException
     */
    public function validate(string $filePath, string $originalName): void
    {
        $this->assertFileExists($filePath);
        $this->assertFileSize($filePath);
        $this->assertExtension($originalName);
        $this->assertMagicBytes($filePath);
        $this->assertMimeType($filePath);
        $this->assertSafeFilename($originalName);
    }

    /**
     * Read the raw bytes of a validated file.
     *
     * @throws FileValidationException
     */
    public function readContents(string $filePath): string
    {
        $contents = @file_get_contents($filePath);

        if ($contents === false) {
            throw new FileValidationException("Cannot read file: {$filePath}");
        }

        return $contents;
    }

    // -------------------------------------------------------------------------
    // Individual checks
    // -------------------------------------------------------------------------

    private function assertFileExists(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new FileValidationException('Uploaded file does not exist or is not readable.');
        }
    }

    private function assertFileSize(string $filePath): void
    {
        $size = @filesize($filePath);

        if ($size === false || $size === 0) {
            throw new FileValidationException('Uploaded file is empty.');
        }

        if ($size > $this->maxSizeBytes) {
            throw new FileValidationException(sprintf(
                'File exceeds maximum size: %s bytes (limit: %s bytes).',
                number_format($size),
                number_format($this->maxSizeBytes),
            ));
        }
    }

    private function assertExtension(string $originalName): void
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext !== 'torrent') {
            throw new FileValidationException(
                "Invalid file extension: .{$ext}. Only .torrent files are accepted."
            );
        }
    }

    /**
     * Check magic bytes: a valid .torrent file is a bencoded dictionary,
     * which MUST start with 'd' (0x64).
     *
     * This is the single most reliable check — MIME headers and file extensions
     * can both be spoofed, but the first byte cannot be faked without making
     * the file unparseable.
     */
    private function assertMagicBytes(string $filePath): void
    {
        $handle = @fopen($filePath, 'rb');

        if ($handle === false) {
            throw new FileValidationException('Cannot open file for magic byte inspection.');
        }

        // Read first 10 bytes to skip any BOM or leading whitespace.
        $header = fread($handle, 10);
        fclose($handle);

        if ($header === false || $header === '') {
            throw new FileValidationException('Cannot read file header.');
        }

        // Trim any leading whitespace/BOM.
        $trimmed = ltrim($header);

        if ($trimmed === '' || $trimmed[0] !== 'd') {
            throw new FileValidationException(
                'File does not start with bencoded dictionary marker "d". '
                . 'This is not a valid .torrent file.'
            );
        }
    }

    /**
     * Secondary MIME type check using finfo (fileinfo extension).
     * Not relied upon alone because .torrent has no official MIME type,
     * but useful as an extra signal to catch obviously wrong files.
     */
    private function assertMimeType(string $filePath): void
    {
        if (!extension_loaded('fileinfo')) {
            return; // Skip on hosts without fileinfo — magic bytes check is sufficient.
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($filePath);

        if ($mime === false) {
            return; // Cannot determine — skip.
        }

        // Known MIME types for .torrent files.
        $allowed = [
            'application/x-bittorrent',
            'application/octet-stream',  // Generic binary — many servers return this.
            'text/plain',                 // Some servers misdetect bencode as text.
        ];

        if (!in_array($mime, $allowed, strict: true)) {
            throw new FileValidationException(
                "Suspicious MIME type: {$mime}. Expected a .torrent file."
            );
        }
    }

    /**
     * Ensure the original filename has no path traversal attacks.
     */
    private function assertSafeFilename(string $originalName): void
    {
        // Strip null bytes.
        if (str_contains($originalName, "\0")) {
            throw new FileValidationException('Filename contains null bytes.');
        }

        // Must not contain directory separators.
        if (str_contains($originalName, '/') || str_contains($originalName, '\\')) {
            throw new FileValidationException('Filename contains path separators.');
        }

        // Must not start with a dot (hidden files).
        $basename = basename($originalName);
        if (str_starts_with($basename, '.')) {
            throw new FileValidationException('Filename starts with a dot.');
        }

        // Must not exceed 255 characters.
        if (strlen($basename) > 255) {
            throw new FileValidationException('Filename exceeds 255 characters.');
        }
    }
}
