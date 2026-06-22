-- Migration 007: Create tp_post_links table
-- Tracks synced post/topic pairs across platforms (WP ↔ wpForo ↔ bbPress).

CREATE TABLE IF NOT EXISTS `{prefix}tp_post_links` (
    `id`                INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `source_platform`   ENUM('wp_post','wpforo_topic','bbpress_topic') NOT NULL,
    `source_id`         BIGINT UNSIGNED     NOT NULL,
    `target_platform`   ENUM('wp_post','wpforo_topic','bbpress_topic') NOT NULL,
    `target_id`         BIGINT UNSIGNED     NOT NULL,
    `sync_enabled`      TINYINT(1)          NOT NULL DEFAULT 1,
    `created_at`        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_source_target` (`source_platform`, `source_id`, `target_platform`),
    KEY `idx_target` (`target_platform`, `target_id`),
    KEY `idx_source` (`source_platform`, `source_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
