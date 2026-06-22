-- Migration 006: Create tp_torrent_logs table
-- Application event log. Entries are created by DatabaseLogger.

CREATE TABLE IF NOT EXISTS `{prefix}tp_torrent_logs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `torrent_id`    INT UNSIGNED    NULL     COMMENT 'NULL = system-level log, not torrent-specific',
    `event_type`    VARCHAR(100)    NOT NULL COMMENT 'e.g. tracker.scrape, parse.torrent, scheduler.run',
    `level`         ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
    `message`       TEXT            NOT NULL,
    `context`       JSON            NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_torrent_id` (`torrent_id`),
    KEY `idx_level`      (`level`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
