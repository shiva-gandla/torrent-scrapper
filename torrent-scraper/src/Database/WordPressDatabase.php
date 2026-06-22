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
 * File: WordPressDatabase.php
 * Component: WordPress Database Adapter
 * Description: Implements the DatabaseInterface using the WordPress `$wpdb` global object to execute database actions securely.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Database;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;
use TorrentScraper\Core\Exception\DatabaseException;

/**
 * WordPress database adapter — wraps $wpdb.
 */
final class WordPressDatabase implements DatabaseInterface
{
    private bool $inTransaction = false;

    /**
     * @inheritDoc
     */
    public function query(string $sql, array $params = []): array
    {
        global $wpdb;

        $prepared = $this->prepare($sql, $params);

        $wpdb->suppress_errors(true);
        try {
            $results = $wpdb->get_results($prepared, ARRAY_A);
        } finally {
            $wpdb->suppress_errors(false);
        }

        if ($wpdb->last_error !== '') {
            throw new DatabaseException("Query failed: {$wpdb->last_error}");
        }

        return is_array($results) ? $results : [];
    }

    /**
     * @inheritDoc
     */
    public function execute(string $sql, array $params = []): int
    {
        global $wpdb;

        $prepared = $this->prepare($sql, $params);

        $wpdb->suppress_errors(true);
        try {
            $result = $wpdb->query($prepared);
        } finally {
            $wpdb->suppress_errors(false);
        }

        if ($result === false) {
            throw new DatabaseException("Execute failed: {$wpdb->last_error}");
        }

        return (int) $result;
    }

    /**
     * @inheritDoc
     *
     * Uses $wpdb->insert() directly — the ONLY reliable way to insert NULL
     * values through WordPress's database layer. Our custom prepare() method
     * cannot correctly handle NULLs because $wpdb->prepare() converts PHP null
     * to empty string '' via vsprintf(), which breaks typed (INT, DATETIME)
     * nullable columns in MySQL strict mode.
     *
     * $wpdb->insert() in WordPress 3.9+ handles null natively by emitting
     * the SQL keyword NULL (without quotes) for PHP null values.
     */
    public function insertRow(string $table, array $data): int
    {
        global $wpdb;

        // Build format array: %d for ints/bools, %f for floats, %s for everything else.
        // NULL values get no format entry — wpdb->insert() emits SQL NULL for them.
        $formats = [];
        foreach ($data as $value) {
            if ($value === null) {
                $formats[] = '%s'; // wpdb will handle null → NULL
            } elseif (is_int($value) || is_bool($value)) {
                $formats[] = '%d';
            } elseif (is_float($value)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }

        $wpdb->suppress_errors(true);
        try {
            $result = $wpdb->insert($table, $data, $formats);
        } finally {
            $wpdb->suppress_errors(false);
        }

        if ($result === false) {
            throw new DatabaseException("Insert failed: {$wpdb->last_error}");
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @inheritDoc
     */
    public function lastInsertId(): int
    {
        global $wpdb;
        return (int) $wpdb->insert_id;
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        global $wpdb;

        if ($this->inTransaction) {
            return;
        }

        $wpdb->query('START TRANSACTION');
        $this->inTransaction = true;
    }

    /**
     * @inheritDoc
     */
    public function commit(): void
    {
        global $wpdb;

        if (!$this->inTransaction) {
            return;
        }

        $wpdb->query('COMMIT');
        $this->inTransaction = false;
    }

    /**
     * @inheritDoc
     */
    public function rollback(): void
    {
        global $wpdb;

        if (!$this->inTransaction) {
            return;
        }

        $wpdb->query('ROLLBACK');
        $this->inTransaction = false;
    }

    /**
     * @inheritDoc
     */
    public function tablePrefix(): string
    {
        global $wpdb;
        // Return ONLY $wpdb->prefix (e.g. 'wpx5_').
        // All repository queries already include 'tp_' in the table name
        // (e.g. "{$prefix}tp_torrents"), so tablePrefix must NOT add 'tp_'
        // or every table name becomes doubled: 'wpx5_tp_tp_torrents'.
        return $wpdb->prefix;
    }

    /**
     * @inheritDoc
     */
    public function serverVersion(): string
    {
        global $wpdb;
        return (string) $wpdb->db_version();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Prepare a SQL statement with positional `?` placeholders for $wpdb.
     *
     * ROOT CAUSE FIX: Previously NULL values were injected as the raw string
     * 'NULL' directly into the SQL template BEFORE calling $wpdb->prepare().
     * This caused a placeholder count mismatch: $wpdb->prepare() received
     * fewer values than format specifiers, so it emitted an empty/invalid
     * prepared string. $wpdb->query('') returns 0 (not false), so no exception
     * was thrown but insert_id was 0 and no row was committed.
     *
     * Fix: ALL values — including null — are passed through $wpdb->prepare()
     * as %s placeholders. $wpdb handles PHP null correctly and emits SQL NULL.
     *
     * @param  array<int|string, mixed> $params
     */
    private function prepare(string $sql, array $params): string
    {
        global $wpdb;

        if (empty($params)) {
            return $sql;
        }

        $parts  = [];
        $values = [];
        $offset = 0;

        foreach ($params as $value) {
            $pos = strpos($sql, '?', $offset);
            if ($pos === false) {
                break;
            }

            $parts[] = substr($sql, $offset, $pos - $offset);

            if (is_int($value) || is_bool($value)) {
                $parts[]  = '%d';
                $values[] = (int) $value;
            } elseif (is_float($value)) {
                $parts[]  = '%f';
                $values[] = $value;
            } else {
                // Covers strings AND null. $wpdb->prepare() renders PHP null as SQL NULL.
                $parts[]  = '%s';
                $values[] = $value;
            }

            $offset = $pos + 1;
        }

        $parts[] = substr($sql, $offset);

        $rebuiltSql = implode('', $parts);

        if (empty($values)) {
            return $rebuiltSql;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->prepare($rebuiltSql, ...$values);
    }
}
