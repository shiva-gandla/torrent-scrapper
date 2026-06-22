-- Migration 005: Create tp_torrent_categories table
-- Hierarchical category tree for organizing torrents.

CREATE TABLE IF NOT EXISTS `{prefix}tp_torrent_categories` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100)    NOT NULL,
    `slug`          VARCHAR(100)    NOT NULL,
    `parent_id`     INT UNSIGNED    NULL     COMMENT 'NULL = top-level category',
    `description`   TEXT            NULL,
    `icon`          VARCHAR(255)    NULL     COMMENT 'CSS class or relative image path',
    `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug`    (`slug`),
    KEY        `idx_parent` (`parent_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
