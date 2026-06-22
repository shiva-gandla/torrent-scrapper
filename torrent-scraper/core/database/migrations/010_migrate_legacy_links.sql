-- Migration 010: Migrate legacy torrent links into tp_torrent_post_map
-- Copies from wp_options (wpForo) and wp_postmeta (WP posts / bbPress).
-- Old entries are preserved as a safety net but will no longer be the primary source.

-- 1. Migrate wpForo topic links from wp_options (tp_wpforo_topic_X → torrent_id)
INSERT IGNORE INTO `{prefix}tp_torrent_post_map` (`torrent_id`, `platform`, `post_id`, `sort_order`, `added_by`, `added_at`)
SELECT
    CAST(o.option_value AS UNSIGNED) AS torrent_id,
    'wpforo_topic'                   AS platform,
    CAST(REPLACE(o.option_name, 'tp_wpforo_topic_', '') AS UNSIGNED) AS post_id,
    0                                AS sort_order,
    NULL                             AS added_by,
    NOW()                            AS added_at
FROM `{prefix}options` o
WHERE o.option_name LIKE 'tp\_wpforo\_topic\_%'
  AND CAST(o.option_value AS UNSIGNED) > 0
  AND CAST(REPLACE(o.option_name, 'tp_wpforo_topic_', '') AS UNSIGNED) > 0;

-- 2. Migrate WP post/page links from wp_postmeta (tp_torrent_id → torrent_id)
--    Distinguishes bbPress topics from regular WP posts by checking post_type.
INSERT IGNORE INTO `{prefix}tp_torrent_post_map` (`torrent_id`, `platform`, `post_id`, `sort_order`, `added_by`, `added_at`)
SELECT
    CAST(pm.meta_value AS UNSIGNED) AS torrent_id,
    CASE
        WHEN p.post_type = 'topic' THEN 'bbpress_topic'
        ELSE 'wp_post'
    END                             AS platform,
    pm.post_id                      AS post_id,
    0                               AS sort_order,
    p.post_author                   AS added_by,
    NOW()                           AS added_at
FROM `{prefix}postmeta` pm
JOIN `{prefix}posts` p ON p.ID = pm.post_id
WHERE pm.meta_key = 'tp_torrent_id'
  AND CAST(pm.meta_value AS UNSIGNED) > 0;
