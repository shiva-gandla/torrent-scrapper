<?php
/**
 * Plugin Name: Torrent Scrapper for Wordpress Blog/Forum
 * Description: Publish torrent files and magnet links with live seeder/leecher stats for WordPress, bbPress, and wpForo.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: Shiva Gandla (https://github.com/shiva-gandla/)
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * File: SyncService.php
 * Component: Post Sync Utilities
 * Description: Synchronizes torrent meta fields, tags, categories, and titles with their respective forum topics.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Sync;

use TorrentScraper\Core\Logger\Contracts\LoggerInterface;

/**
 * Cross-platform post synchronization service.
 *
 * Handles creating, editing, deleting, and moving counterpart posts/topics
 * when a torrent is attached, detached, or modified.
 *
 * Sync ONLY triggers when a torrent is attached to a post/topic.
 * No torrent = no sync.
 *
 * Supports: WordPress Posts, wpForo Topics, bbPress Topics.
 */
final class SyncService
{
    /** Guard flag to prevent infinite recursion during sync. */
    private bool $syncing = false;

    public function __construct(
        private readonly PostLinkRepository       $postLinkRepo,
        private readonly TorrentPostMapRepository  $postMapRepo,
        private readonly LoggerInterface           $logger,
    ) {}

    // ─── Guards ─────────────────────────────────────────────────────────

    /**
     * Check if we are currently inside a sync operation (prevents recursion).
     */
    public function isSyncing(): bool
    {
        return $this->syncing;
    }

    // ─── Torrent attached to post → create counterparts ─────────────────

    /**
     * Called when a torrent is attached to a post/topic.
     * Creates counterpart posts on other platforms if they don't exist yet.
     *
     * @param string   $platform       Source platform ('wp_post', 'wpforo_topic', 'bbpress_topic')
     * @param int      $postId         Source post/topic ID
     * @param int      $torrentId      The torrent being attached
     * @param int|null $userId         The user performing the action
     * @param array    $targetConfig   ['wpforo_forum_id' => int, 'wpforo_cat_id' => int,
     *                                  'bbpress_forum_id' => int, 'wp_category_id' => int]
     */
    public function onTorrentAttached(
        string $platform,
        int    $postId,
        int    $torrentId,
        ?int   $userId = null,
        array  $targetConfig = [],
    ): void {
        if ($this->syncing) {
            return;
        }

        $this->syncing = true;

        try {
            // Get post title and content from source.
            $postData = $this->getPostData($platform, $postId);
            if ($postData === null) {
                return;
            }

            // Determine which target platforms to sync to.
            $targets = $this->getTargetPlatforms($platform);

            foreach ($targets as $targetPlatform) {
                // Check if a counterpart already exists.
                if ($this->postLinkRepo->linkExists($platform, $postId, $targetPlatform)) {
                    // Link exists — just sync the torrent attachment.
                    $links = $this->postLinkRepo->findTargets($platform, $postId);
                    foreach ($links as $link) {
                        if ($link['target_platform'] === $targetPlatform) {
                            $this->postMapRepo->attach($torrentId, $targetPlatform, (int) $link['target_id'], $userId);
                        }
                    }
                    continue;
                }

                // Check if integration is enabled.
                if (!$this->isIntegrationEnabled($targetPlatform)) {
                    continue;
                }

                // Create the counterpart post/topic.
                $targetId = $this->createCounterpart(
                    $targetPlatform,
                    $postData['title'],
                    $postData['content'],
                    $userId,
                    $targetConfig,
                );

                if ($targetId > 0) {
                    // Record the link.
                    $this->postLinkRepo->createLink($platform, $postId, $targetPlatform, $targetId);

                    // Attach the torrent to the counterpart too.
                    $this->postMapRepo->attach($torrentId, $targetPlatform, $targetId, $userId);

                    $this->logger->info(
                        "Sync: Created {$targetPlatform} #{$targetId} as counterpart of {$platform} #{$postId}",
                        ['event_type' => 'sync.create', 'source' => $platform, 'target' => $targetPlatform],
                    );
                }
            }
        } finally {
            $this->syncing = false;
        }
    }

    // ─── Torrent detached from post → sync removal ──────────────────────

    /**
     * Called when a torrent is detached (soft unlinked) from a post/topic.
     * Syncs the detachment to all counterpart posts.
     */
    public function onTorrentDetached(string $platform, int $postId, int $torrentId): void
    {
        if ($this->syncing) {
            return;
        }

        $this->syncing = true;

        try {
            $links = $this->postLinkRepo->findAllLinked($platform, $postId);

            foreach ($links as $link) {
                // Determine the counterpart side.
                $counterPlatform = null;
                $counterId       = 0;

                if ($link['source_platform'] === $platform && (int) $link['source_id'] === $postId) {
                    $counterPlatform = $link['target_platform'];
                    $counterId       = (int) $link['target_id'];
                } elseif ($link['target_platform'] === $platform && (int) $link['target_id'] === $postId) {
                    $counterPlatform = $link['source_platform'];
                    $counterId       = (int) $link['source_id'];
                }

                if ($counterPlatform && $counterId > 0) {
                    $this->postMapRepo->detach($torrentId, $counterPlatform, $counterId);
                }
            }
        } finally {
            $this->syncing = false;
        }
    }

    // ─── Post edited → sync changes ────────────────────────────────────

    /**
     * Called when a post/topic is edited.
     * Syncs title and content changes to counterpart posts.
     */
    public function onPostEdited(string $platform, int $postId, string $newTitle, string $newContent): void
    {
        if ($this->syncing) {
            return;
        }

        // Only sync if this post has counterpart links.
        $links = $this->postLinkRepo->findAllLinked($platform, $postId);
        if (empty($links)) {
            return;
        }

        $this->syncing = true;

        try {
            foreach ($links as $link) {
                $counterPlatform = null;
                $counterId       = 0;

                if ($link['source_platform'] === $platform && (int) $link['source_id'] === $postId) {
                    $counterPlatform = $link['target_platform'];
                    $counterId       = (int) $link['target_id'];
                } elseif ($link['target_platform'] === $platform && (int) $link['target_id'] === $postId) {
                    $counterPlatform = $link['source_platform'];
                    $counterId       = (int) $link['source_id'];
                }

                if ($counterPlatform && $counterId > 0) {
                    $this->updateCounterpart($counterPlatform, $counterId, $newTitle, $newContent);

                    $this->logger->info(
                        "Sync: Updated {$counterPlatform} #{$counterId} from {$platform} #{$postId}",
                        ['event_type' => 'sync.update'],
                    );
                }
            }
        } finally {
            $this->syncing = false;
        }
    }

    // ─── Post deleted → delete counterparts ─────────────────────────────

    /**
     * Called when a post/topic is deleted.
     * Deletes all counterpart posts and cleans up links.
     */
    public function onPostDeleted(string $platform, int $postId): void
    {
        try {
            $this->logger->warning("SyncService::onPostDeleted: entered for platform={$platform}, postId={$postId}");
        } catch (\Throwable $e) {}

        if ($this->syncing) {
            try {
                $this->logger->warning("SyncService::onPostDeleted: already syncing, aborting.");
            } catch (\Throwable $e) {}
            return;
        }

        // Only sync if this post has counterpart links.
        $links = $this->postLinkRepo->findAllLinked($platform, $postId);
        try {
            $this->logger->warning(sprintf("SyncService::onPostDeleted: links found count=%d for platform=%s, postId=%d", count($links), $platform, $postId));
        } catch (\Throwable $e) {}

        if (empty($links)) {
            return;
        }

        $this->syncing = true;

        try {
            foreach ($links as $link) {
                $counterPlatform = null;
                $counterId       = 0;

                if ($link['source_platform'] === $platform && (int) $link['source_id'] === $postId) {
                    $counterPlatform = $link['target_platform'];
                    $counterId       = (int) $link['target_id'];
                } elseif ($link['target_platform'] === $platform && (int) $link['target_id'] === $postId) {
                    $counterPlatform = $link['source_platform'];
                    $counterId       = (int) $link['source_id'];
                }

                if ($counterPlatform && $counterId > 0) {
                    // Detach all torrents from the counterpart first.
                    $this->postMapRepo->detachAllFromPost($counterPlatform, $counterId);

                    // Delete the counterpart post/topic.
                    $this->deletePost($counterPlatform, $counterId);

                    $this->logger->info(
                        "Sync: Deleted {$counterPlatform} #{$counterId} (counterpart of deleted {$platform} #{$postId})",
                        ['event_type' => 'sync.delete'],
                    );
                }
            }

            // Clean up all links for this post.
            $this->postLinkRepo->deleteAllForPost($platform, $postId);

            // Detach all torrents from the deleted post.
            $this->postMapRepo->detachAllFromPost($platform, $postId);
        } finally {
            $this->syncing = false;
        }
    }

    // ─── Category change → move counterpart ─────────────────────────────

    /**
     * Called when a post's category/forum is changed.
     * Moves the counterpart to the corresponding target category.
     *
     * @param array $targetConfig  Same structure as onTorrentAttached.
     */
    public function onCategoryChanged(string $platform, int $postId, array $targetConfig): void
    {
        if ($this->syncing) {
            return;
        }

        $this->syncing = true;

        try {
            $links = $this->postLinkRepo->findAllLinked($platform, $postId);

            foreach ($links as $link) {
                $counterPlatform = null;
                $counterId       = 0;

                if ($link['source_platform'] === $platform && (int) $link['source_id'] === $postId) {
                    $counterPlatform = $link['target_platform'];
                    $counterId       = (int) $link['target_id'];
                } elseif ($link['target_platform'] === $platform && (int) $link['target_id'] === $postId) {
                    $counterPlatform = $link['source_platform'];
                    $counterId       = (int) $link['source_id'];
                }

                if ($counterPlatform && $counterId > 0) {
                    $this->moveCounterpart($counterPlatform, $counterId, $targetConfig);
                }
            }
        } finally {
            $this->syncing = false;
        }
    }

    // ─── Platform-specific operations ───────────────────────────────────

    /**
     * Get title and content from a post/topic on any platform.
     *
     * @return array{title: string, content: string}|null
     */
    private function getPostData(string $platform, int $postId): ?array
    {
        return match ($platform) {
            'wp_post' => $this->getWpPostData($postId),
            'wpforo_topic' => $this->getWpForoTopicData($postId),
            'bbpress_topic' => $this->getBbPressTopicData($postId),
            default => null,
        };
    }

    private function getWpPostData(int $postId): ?array
    {
        $post = get_post($postId);
        if (!$post) {
            return null;
        }
        return ['title' => $post->post_title, 'content' => $post->post_content];
    }

    private function getWpForoTopicData(int $topicId): ?array
    {
        if (!function_exists('WPF')) {
            return null;
        }

        $topic = WPF()->topic->get_topic($topicId);
        if (!$topic || empty($topic['title'])) {
            return null;
        }

        // Get the first post body of this topic.
        $posts = WPF()->post->get_posts(['topicid' => $topicId, 'orderby' => 'created', 'order' => 'ASC', 'row_count' => 1]);
        $body = '';
        if (!empty($posts)) {
            $first = is_array($posts) ? reset($posts) : null;
            $body = $first['body'] ?? '';
        }

        return ['title' => $topic['title'], 'content' => $body];
    }

    private function getBbPressTopicData(int $topicId): ?array
    {
        if (!function_exists('bbpress')) {
            return null;
        }

        $post = get_post($topicId);
        if (!$post || $post->post_type !== 'topic') {
            return null;
        }

        return ['title' => $post->post_title, 'content' => $post->post_content];
    }

    /**
     * Create a counterpart post/topic on the target platform.
     *
     * @return int The new post/topic ID, or 0 on failure.
     */
    private function createCounterpart(
        string $targetPlatform,
        string $title,
        string $content,
        ?int   $userId,
        array  $targetConfig,
    ): int {
        return match ($targetPlatform) {
            'wp_post'        => $this->createWpPost($title, $content, $userId, $targetConfig),
            'wpforo_topic'   => $this->createWpForoTopic($title, $content, $userId, $targetConfig),
            'bbpress_topic'  => $this->createBbPressTopic($title, $content, $userId, $targetConfig),
            default          => 0,
        };
    }

    private function createWpPost(string $title, string $content, ?int $userId, array $config): int
    {
        $categoryId = (int) ($config['wp_category_id'] ?? 0);
        if ($categoryId <= 0) {
            $categoryId = (int) get_option('default_category', 0);
        }

        $postData = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_author'  => $userId ?: get_current_user_id(),
        ];

        if ($categoryId > 0) {
            $postData['post_category'] = [$categoryId];
        }

        $postId = wp_insert_post($postData, true);

        return is_wp_error($postId) ? 0 : $postId;
    }

    private function createWpForoTopic(string $title, string $content, ?int $userId, array $config): int
    {
        if (!function_exists('WPF')) {
            return 0;
        }

        $forumId = (int) ($config['wpforo_forum_id'] ?? 0);
        if ($forumId <= 0) {
            // Try to get the first available forum as fallback.
            $forums = WPF()->forum->get_forums(['type' => 'forum']);
            if (!empty($forums)) {
                $first = reset($forums);
                $forumId = (int) ($first['forumid'] ?? 0);
            }
        }

        if ($forumId <= 0) {
            return 0;
        }

        $topicData = [
            'forumid' => $forumId,
            'title'   => $title,
            'body'    => $content,
            'userid'  => $userId ?: get_current_user_id(),
            'status'  => 0, // 0 = open
        ];

        $topicId = WPF()->topic->add($topicData);

        return is_numeric($topicId) ? (int) $topicId : 0;
    }

    private function createBbPressTopic(string $title, string $content, ?int $userId, array $config): int
    {
        if (!function_exists('bbpress')) {
            return 0;
        }

        $forumId = (int) ($config['bbpress_forum_id'] ?? 0);
        if ($forumId <= 0) {
            // Get first forum as fallback.
            $forums = get_posts(['post_type' => 'forum', 'numberposts' => 1, 'post_status' => 'publish']);
            if (!empty($forums)) {
                $forumId = $forums[0]->ID;
            }
        }

        if ($forumId <= 0) {
            return 0;
        }

        $topicId = bbp_insert_topic([
            'post_parent'  => $forumId,
            'post_title'   => $title,
            'post_content' => $content,
            'post_author'  => $userId ?: get_current_user_id(),
        ]);

        return is_wp_error($topicId) ? 0 : (int) $topicId;
    }

    /**
     * Update a counterpart post/topic with new title and content.
     */
    private function updateCounterpart(string $platform, int $postId, string $title, string $content): void
    {
        match ($platform) {
            'wp_post'        => wp_update_post(['ID' => $postId, 'post_title' => $title, 'post_content' => $content]),
            'wpforo_topic'   => $this->updateWpForoTopic($postId, $title, $content),
            'bbpress_topic'  => wp_update_post(['ID' => $postId, 'post_title' => $title, 'post_content' => $content]),
            default          => null,
        };
    }

    private function updateWpForoTopic(int $topicId, string $title, string $content): void
    {
        if (!function_exists('WPF')) {
            return;
        }

        WPF()->topic->edit(['topicid' => $topicId, 'title' => $title]);

        // Update the first post body.
        $posts = WPF()->post->get_posts(['topicid' => $topicId, 'orderby' => 'created', 'order' => 'ASC', 'row_count' => 1]);
        if (!empty($posts)) {
            $first = reset($posts);
            $postId = (int) ($first['postid'] ?? 0);
            if ($postId > 0) {
                WPF()->post->edit(['postid' => $postId, 'body' => $content]);
            }
        }
    }

    /**
     * Delete a post/topic on any platform.
     */
    private function deletePost(string $platform, int $postId): void
    {
        match ($platform) {
            'wp_post'        => wp_delete_post($postId, true),
            'wpforo_topic'   => $this->deleteWpForoTopic($postId),
            'bbpress_topic'  => wp_delete_post($postId, true),
            default          => null,
        };
    }

    private function deleteWpForoTopic(int $topicId): void
    {
        if (!function_exists('WPF')) {
            return;
        }

        $deleted = false;
        try {
            $deleted = WPF()->topic->delete($topicId);
        } catch (\Throwable $e) {
            $deleted = false;
        }

        // Fallback: if API returned false/failed (e.g. permission block), delete directly from database
        if (!$deleted) {
            global $wpdb;
            try {
                $topicsTable = $wpdb->prefix . 'wpforo_topics';
                $postsTable  = $wpdb->prefix . 'wpforo_posts';
                
                $wpdb->query($wpdb->prepare("DELETE FROM `$topicsTable` WHERE `topicid` = %d", $topicId));
                $wpdb->query($wpdb->prepare("DELETE FROM `$postsTable` WHERE `topicid` = %d", $topicId));
                
                if (isset(WPF()->forum) && method_exists(WPF()->forum, 'rebuild_stats')) {
                    WPF()->forum->rebuild_stats();
                }
            } catch (\Throwable $ex) {
                // ignore database errors
            }
        }
    }

    /**
     * Move a counterpart to a different category/forum.
     */
    private function moveCounterpart(string $platform, int $postId, array $config): void
    {
        match ($platform) {
            'wp_post' => $this->moveWpPost($postId, $config),
            'wpforo_topic' => $this->moveWpForoTopic($postId, $config),
            'bbpress_topic' => $this->moveBbPressTopic($postId, $config),
            default => null,
        };
    }

    private function moveWpPost(int $postId, array $config): void
    {
        $catId = (int) ($config['wp_category_id'] ?? 0);
        if ($catId > 0) {
            wp_set_post_categories($postId, [$catId]);
        }
    }

    private function moveWpForoTopic(int $topicId, array $config): void
    {
        if (!function_exists('WPF')) {
            return;
        }

        $forumId = (int) ($config['wpforo_forum_id'] ?? 0);
        if ($forumId > 0) {
            WPF()->topic->edit(['topicid' => $topicId, 'forumid' => $forumId]);
        }
    }

    private function moveBbPressTopic(int $topicId, array $config): void
    {
        $forumId = (int) ($config['bbpress_forum_id'] ?? 0);
        if ($forumId > 0) {
            wp_update_post(['ID' => $topicId, 'post_parent' => $forumId]);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Determine which target platforms to sync to based on the source platform.
     *
     * @return string[]
     */
    private function getTargetPlatforms(string $sourcePlatform): array
    {
        $all = ['wp_post', 'wpforo_topic', 'bbpress_topic'];
        return array_filter($all, fn(string $p) => $p !== $sourcePlatform);
    }

    /**
     * Check if a platform's integration is enabled in plugin settings.
     */
    private function isIntegrationEnabled(string $platform): bool
    {
        $settings = get_option('tp_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        return match ($platform) {
            'wp_post'       => true, // WP is always available
            'wpforo_topic'  => ($settings['enable_wpforo'] ?? 'yes') === 'yes' && function_exists('WPF'),
            'bbpress_topic' => ($settings['enable_bbpress'] ?? 'yes') === 'yes' && function_exists('bbpress'),
            default         => false,
        };
    }
}
