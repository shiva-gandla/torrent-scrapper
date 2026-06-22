-- Migration 008: Create tp_edit_locks table
-- Prevents simultaneous editing of synced posts by multiple admins/moderators.

CREATE TABLE IF NOT EXISTS `{prefix}tp_edit_locks` (
    `id`            INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `platform`      ENUM('wp_post','wpforo_topic','bbpress_topic') NOT NULL,
    `post_id`       BIGINT UNSIGNED     NOT NULL,
    `user_id`       BIGINT UNSIGNED     NOT NULL,
    `user_name`     VARCHAR(255)        NOT NULL DEFAULT '' COMMENT 'Cached display name for lock messages',
    `locked_at`     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`    DATETIME            NOT NULL COMMENT 'Auto-expire after 5 min inactivity',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_platform_post` (`platform`, `post_id`),
    KEY `idx_expires` (`expires_at`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
