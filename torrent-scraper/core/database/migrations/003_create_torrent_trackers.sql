-- Migration 003: Create tp_torrent_trackers table
-- Maps tracker URLs associated with each torrent.

CREATE TABLE IF NOT EXISTS `{prefix}tp_torrent_trackers` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `torrent_id`    INT UNSIGNED    NOT NULL,
    `tracker_url`   VARCHAR(500)    NOT NULL,
    `tracker_type`  ENUM('udp','http','https') NOT NULL,
    `tier`          TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Announce tier from .torrent',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `added_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_torrent_tracker` (`torrent_id`, `tracker_url`(400)),
    KEY `idx_torrent_id` (`torrent_id`),
    KEY `idx_active`     (`is_active`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
