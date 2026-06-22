-- Migration 009: Create tp_torrent_post_map table
-- Many-to-many mapping: multiple torrents can attach to one post/topic,
-- and one torrent can attach to multiple posts/topics.

CREATE TABLE IF NOT EXISTS `{prefix}tp_torrent_post_map` (
    `id`            INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `torrent_id`    INT UNSIGNED        NOT NULL,
    `platform`      ENUM('wp_post','wpforo_topic','bbpress_topic') NOT NULL,
    `post_id`       BIGINT UNSIGNED     NOT NULL,
    `sort_order`    SMALLINT UNSIGNED   NOT NULL DEFAULT 0 COMMENT 'Display order within the topic',
    `added_by`      BIGINT UNSIGNED     NULL     COMMENT 'WP user ID who attached it',
    `added_at`      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_torrent_platform_post` (`torrent_id`, `platform`, `post_id`),
    KEY `idx_platform_post` (`platform`, `post_id`),
    KEY `idx_torrent` (`torrent_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
