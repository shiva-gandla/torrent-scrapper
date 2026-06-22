-- Migration 002: Create tp_torrent_files table
-- Lists individual files inside multi-file torrents.

CREATE TABLE IF NOT EXISTS `{prefix}tp_torrent_files` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `torrent_id`    INT UNSIGNED    NOT NULL,
    `file_path`     VARCHAR(2000)   NOT NULL COMMENT 'Full path from torrent metadata',
    `file_size`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `file_index`    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Original order in torrent',

    PRIMARY KEY (`id`),
    KEY `idx_torrent_id` (`torrent_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
