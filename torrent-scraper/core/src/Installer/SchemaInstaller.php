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
 * File: SchemaInstaller.php
 * Component: Database Migration
 * Description: Manages creation, modification, and dropping of custom database tables for torrents, statistics, and queues.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Installer;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;
use TorrentScraper\Core\Exception\InstallException;

/**
 * Installs and uninstalls the plugin database schema.
 *
 * Migration files live in core/database/migrations/ and are numbered sequentially.
 * The current installed version is tracked in the platform's options store
 * (e.g. WordPress get_option / update_option), abstracted via the callback pattern.
 *
 * Rules:
 *   - Migrations run in order (001 → 002 → ...).
 *   - Each migration is only run once (version tracked).
 *   - IF NOT EXISTS guards in SQL make migrations safe to re-run in emergencies.
 *   - Uninstall drops tables in reverse dependency order.
 */
final class SchemaInstaller
{
    /** Current schema version — bump this when adding new migrations. */
    private const CURRENT_VERSION = 3;

    /** Ordered list of migration files relative to $migrationsDir. */
    private const MIGRATIONS = [
        1 => [
            '001_create_torrents.sql',
            '002_create_torrent_files.sql',
            '003_create_torrent_trackers.sql',
            '004_create_torrent_statistics.sql',
            '005_create_torrent_categories.sql',
        ],
        2 => [
            '006_create_torrent_logs.sql',
        ],
        3 => [
            '007_create_post_links.sql',
            '008_create_edit_locks.sql',
            '009_create_torrent_post_map.sql',
            '010_migrate_legacy_links.sql',
        ],
    ];

    /**
     * @param DatabaseInterface $db             Platform database adapter.
     * @param string            $migrationsDir  Absolute path to the migrations/ folder.
     * @param callable          $getVersion     Returns the currently installed schema version (int).
     * @param callable          $saveVersion    Saves a new schema version (int).
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $migrationsDir,
        private readonly mixed $getVersion,
        private readonly mixed $saveVersion,
    ) {}

    /**
     * Run all pending migrations to bring the schema up to the current version.
     *
     * @throws InstallException
     */
    public function install(): void
    {
        $installedVersion = (int) ($this->getVersion)();

        if ($installedVersion >= self::CURRENT_VERSION) {
            return; // Already up to date.
        }

        $prefix = $this->db->tablePrefix();

        for ($version = $installedVersion + 1; $version <= self::CURRENT_VERSION; $version++) {
            $files = self::MIGRATIONS[$version] ?? [];

            foreach ($files as $filename) {
                $this->runMigrationFile($filename, $prefix);
            }

            ($this->saveVersion)($version);
        }
    }

    /**
     * Drop all plugin tables in reverse dependency order.
     * Called on plugin uninstall — irreversible.
     *
     * @throws InstallException
     */
    public function uninstall(): void
    {
        $prefix = $this->db->tablePrefix();

        // Drop in reverse dependency order so FK constraints (if any) don't block.
        $tables = [
            'tp_edit_locks',
            'tp_post_links',
            'tp_torrent_post_map',
            'tp_torrent_logs',
            'tp_torrent_statistics',
            'tp_torrent_trackers',
            'tp_torrent_files',
            'tp_torrent_categories',
            'tp_torrents',
        ];

        foreach ($tables as $table) {
            $this->db->execute("DROP TABLE IF EXISTS `{$prefix}{$table}`");
        }
    }

    /**
     * Load a migration SQL file, replace the {prefix} placeholder, and execute it.
     *
     * @throws InstallException
     */
    private function runMigrationFile(string $filename, string $prefix): void
    {
        $path = rtrim($this->migrationsDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!is_file($path) || !is_readable($path)) {
            throw new InstallException(
                "Migration file not found or not readable: {$path}"
            );
        }

        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new InstallException("Failed to read migration file: {$path}");
        }

        // Replace the {prefix} placeholder with the actual table prefix.
        $sql = str_replace('{prefix}', $prefix, $sql);

        // Remove SQL comments and split on semicolons to handle multi-statement files.
        $statements = $this->parseSqlStatements($sql);

        foreach ($statements as $statement) {
            if (trim($statement) === '') {
                continue;
            }
            $this->db->execute($statement);
        }
    }

    /**
     * Split SQL into individual statements and strip line comments.
     *
     * @return string[]
     */
    private function parseSqlStatements(string $sql): array
    {
        // Strip single-line comments (-- ...).
        $sql = (string) preg_replace('/--[^\n]*\n/', "\n", $sql);

        // Split on semicolons.
        return explode(';', $sql);
    }
}
