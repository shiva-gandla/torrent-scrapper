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
 * File: CheckResult.php
 * Component: Environment Verification
 * Description: Value object representing environment verification results, containing pass status and error messages.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Installer;

/** Status values for a single environment check result. */
enum CheckStatus
{
    case Pass;
    case Warning;
    case Fail;
}

/**
 * Result of a single EnvironmentChecker check.
 */
final class CheckResult
{
    public function __construct(
        public readonly string $check,
        public readonly CheckStatus $status,
        public readonly string $message,
        public readonly ?string $requiredValue = null,
        public readonly ?string $actualValue = null,
    ) {}

    /** True if this result would block plugin operation. */
    public function isBlocking(): bool
    {
        return $this->status === CheckStatus::Fail;
    }

    /** Human-readable status label. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            CheckStatus::Pass    => 'Pass',
            CheckStatus::Warning => 'Warning',
            CheckStatus::Fail    => 'Fail',
        };
    }
}
