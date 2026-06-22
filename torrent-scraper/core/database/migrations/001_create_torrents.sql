-- Migration 001: Create tp_torrents table
-- Run by SchemaInstaller in order. Do not modify once deployed; create a new migration instead.

CREATE TABLE IF NOT EXISTS `{prefix}tp_torrents` (
    `id`                    INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `info_hash`             CHAR(40)            NOT NULL COMMENT 'SHA1 hex, lowercase',
    `name`                  VARCHAR(255)        NOT NULL,
    `category_id`           INT UNSIGNED        NULL,
    `description`           LONGTEXT            NULL,
    `total_size`            BIGINT UNSIGNED     NOT NULL DEFAULT 0  COMMENT 'Total bytes',
    `file_count`            INT UNSIGNED        NOT NULL DEFAULT 0,
    `piece_length`          INT UNSIGNED        NOT NULL DEFAULT 0  COMMENT 'Bytes per piece',
    `piece_count`           INT UNSIGNED        NOT NULL DEFAULT 0,
    `comment`               TEXT                NULL,
    `created_by`            VARCHAR(255)        NULL    COMMENT 'Torrent created_by field',
    `torrent_created_at`    DATETIME            NULL    COMMENT 'Timestamp from torrent metadata',
    `is_private`            TINYINT(1)          NOT NULL DEFAULT 0,
    `magnet_link`           TEXT                NULL,
    `torrent_filename`      VARCHAR(500)        NULL    COMMENT 'Stored filename on disk, relative path',
    `platform`              ENUM('wordpress','xenforo') NOT NULL,
    `platform_post_id`      BIGINT UNSIGNED     NULL    COMMENT 'WP post ID or XF thread ID',
    `platform_user_id`      BIGINT UNSIGNED     NULL    COMMENT 'Author on the platform',
    `status`                ENUM('active','pending','deleted') NOT NULL DEFAULT 'pending',
    `seeders`               INT UNSIGNED        NOT NULL DEFAULT 0  COMMENT 'Best aggregated seeder count',
    `leechers`              INT UNSIGNED        NOT NULL DEFAULT 0  COMMENT 'Best aggregated leecher count',
    `completed`             INT UNSIGNED        NOT NULL DEFAULT 0  COMMENT 'Total completed downloads',
    `stats_checked_at`      DATETIME            NULL,
    `added_at`              DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_info_hash`     (`info_hash`),
    KEY         `idx_category`     (`category_id`),
    KEY         `idx_platform_post`(`platform`, `platform_post_id`),
    KEY         `idx_status`       (`status`),
    KEY         `idx_added_at`     (`added_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
