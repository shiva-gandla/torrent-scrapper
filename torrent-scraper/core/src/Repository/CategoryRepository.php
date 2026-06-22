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
 * File: CategoryRepository.php
 * Component: Database Repositories
 * Description: Database repository handling categorization of torrents and grouping logic.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Repository;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;
use TorrentScraper\Core\Exception\DatabaseException;

/**
 * Data access layer for the tp_torrent_categories table.
 */
final class CategoryRepository
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all active categories.
     *
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function findAll(bool $activeOnly = true): array
    {
        $prefix = $this->db->tablePrefix();
        $and    = $activeOnly ? 'WHERE `is_active` = 1' : '';

        return $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrent_categories`
              {$and}
              ORDER BY `parent_id` ASC, `sort_order` ASC, `name` ASC",
        );
    }

    /**
     * Find a category by its primary key.
     *
     * @return array<string, mixed>|null
     * @throws DatabaseException
     */
    public function findById(int $id): ?array
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrent_categories` WHERE `id` = ? LIMIT 1",
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Find a category by its slug.
     *
     * @return array<string, mixed>|null
     * @throws DatabaseException
     */
    public function findBySlug(string $slug): ?array
    {
        $prefix = $this->db->tablePrefix();

        $rows = $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrent_categories`
              WHERE `slug` = ? LIMIT 1",
            [$slug],
        );

        return $rows[0] ?? null;
    }

    /**
     * Return all top-level categories (parent_id IS NULL).
     *
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function findTopLevel(): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrent_categories`
              WHERE `parent_id` IS NULL AND `is_active` = 1
              ORDER BY `sort_order` ASC, `name` ASC",
        );
    }

    /**
     * Return children of a given parent category.
     *
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function findChildren(int $parentId): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->query(
            "SELECT * FROM `{$prefix}tp_torrent_categories`
              WHERE `parent_id` = ? AND `is_active` = 1
              ORDER BY `sort_order` ASC, `name` ASC",
            [$parentId],
        );
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a new category and return its new ID.
     *
     * @param  array<string, mixed> $data
     * @throws DatabaseException
     */
    public function insert(array $data): int
    {
        $prefix = $this->db->tablePrefix();

        $this->db->execute(
            "INSERT INTO `{$prefix}tp_torrent_categories`
                (`name`, `slug`, `parent_id`, `description`, `icon`, `sort_order`, `is_active`)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                (string) ($data['name'] ?? ''),
                (string) ($data['slug'] ?? ''),
                isset($data['parent_id']) ? (int) $data['parent_id'] : null,
                $data['description'] ?? null,
                $data['icon'] ?? null,
                (int) ($data['sort_order'] ?? 0),
                isset($data['is_active']) && $data['is_active'] ? 1 : 1,
            ],
        );

        return $this->db->lastInsertId();
    }

    /**
     * Update a category by ID.
     *
     * @param  array<string, mixed> $data
     * @throws DatabaseException
     */
    public function update(int $id, array $data): int
    {
        $prefix  = $this->db->tablePrefix();
        $allowed = ['name', 'slug', 'parent_id', 'description', 'icon', 'sort_order', 'is_active'];
        $sets    = [];
        $params  = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]   = "`{$col}` = ?";
                $params[] = $data[$col];
            }
        }

        if (empty($sets)) {
            return 0;
        }

        $params[] = $id;

        return $this->db->execute(
            "UPDATE `{$prefix}tp_torrent_categories` SET " . implode(', ', $sets) . " WHERE `id` = ?",
            $params,
        );
    }

    /**
     * Delete a category.
     * Note: orphaned child categories will have parent_id pointing to a missing row —
     * the caller (CategoryService) must re-parent or delete children first.
     *
     * @throws DatabaseException
     */
    public function delete(int $id): int
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->execute(
            "DELETE FROM `{$prefix}tp_torrent_categories` WHERE `id` = ?",
            [$id],
        );
    }
}
