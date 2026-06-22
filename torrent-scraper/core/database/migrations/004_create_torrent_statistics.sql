-- Migration 004: Create tp_torrent_statistics table
-- Stores per-tracker scrape results and scheduling state.

CREATE TABLE IF NOT EXISTS `{prefix}tp_torrent_statistics` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `torrent_id`            INT UNSIGNED    NOT NULL,
    `tracker_id`            INT UNSIGNED    NOT NULL,
    `seeders`               INT UNSIGNED    NOT NULL DEFAULT 0,
    `leechers`              INT UNSIGNED    NOT NULL DEFAULT 0,
    `completed`             INT UNSIGNED    NOT NULL DEFAULT 0,
    `last_checked`          DATETIME        NULL,
    `next_check`            DATETIME        NULL,
    `check_interval`        INT UNSIGNED    NOT NULL DEFAULT 300 COMMENT 'Seconds between checks',
    `consecutive_failures`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error`            VARCHAR(500)    NULL,
    `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_torrent_tracker_stat` (`torrent_id`, `tracker_id`),
    KEY `idx_next_check`  (`next_check`),
    KEY `idx_torrent_id`  (`torrent_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
