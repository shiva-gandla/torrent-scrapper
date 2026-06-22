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
 * File: DatabaseInterface.php
 * Component: Database Contract
 * Description: Interface defining database query execution and management abstraction to decouple the core scraper from the WordPress database API.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Database\Contracts;

use TorrentScraper\Core\Exception\DatabaseException;

/**
 * Platform-agnostic database interface.
 *
 * Implementations:
 *   - WordPressDatabase  → wraps $wpdb
 *   - (Phase 2) XenForoDatabase → wraps XF\Db\AbstractAdapter
 *
 * Rules:
 *   - ALL queries must use parameterized placeholders. ZERO string interpolation.
 *   - SELECT uses query(); INSERT/UPDATE/DELETE uses execute().
 *   - Transactions must be explicit (begin → commit/rollback).
 */
interface DatabaseInterface
{
    /**
     * Run a SELECT query and return all rows as associative arrays.
     *
     * @param  array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Run an INSERT, UPDATE, or DELETE and return the number of affected rows.
     *
     * @param  array<int|string, mixed> $params
     * @throws DatabaseException
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Insert a row into a table and return the new auto-increment ID.
     *
     * This method handles NULL values correctly (via the platform's native
     * insert mechanism) and bypasses custom placeholder preparation entirely.
     *
     * @param  string                   $table   Full table name (with prefix).
     * @param  array<string, mixed>     $data    Column => value map. NULL values are inserted as SQL NULL.
     * @return int  The new row's auto-increment ID.
     * @throws DatabaseException
     */
    public function insertRow(string $table, array $data): int;

    /**
     * Return the auto-increment ID of the last INSERT.
     */
    public function lastInsertId(): int;

    /**
     * Begin a database transaction.
     * @throws DatabaseException
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction.
     * @throws DatabaseException
     */
    public function commit(): void;

    /**
     * Roll back the current transaction.
     * @throws DatabaseException
     */
    public function rollback(): void;

    /**
     * Return the table prefix including the 'tp_' suffix.
     * Examples:
     *   WordPress → 'wp_tp_'
     *   XenForo   → 'tp_'
     */
    public function tablePrefix(): string;

    /**
     * Get the database engine server version string.
     */
    public function serverVersion(): string;
}
