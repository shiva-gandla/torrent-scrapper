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
 * File: WpForoAdapter.php
 * Component: wpForo Forum Adapter
 * Description: Integrates torrent publication, layouts, and statistic displays with wpForo forum threads.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\WpForo;

use TorrentScraper\Core\Repository\TorrentRepository;
use TorrentScraper\Core\Service\TorrentService;
use TorrentScraper\WordPress\Sync\TorrentPostMapRepository;
use TorrentScraper\WordPress\Sync\SyncService;
use TorrentScraper\WordPress\Sync\EditLockService;

/**
 * wpForo integration — attaches torrent metadata to forum topics.
 *
 * Spec:
 *   - Creates "Torrent Topic" via wpForo topic meta.
 *   - Hooks into wpForo's topic creation/edit actions.
 *   - Stores torrent reference in wpForo topic meta.
 *   - Widget: planned for future iteration.
 *   - No new database tables — uses the core tp_* tables.
 *
 * wpForo 2.x uses its own hook system:
 *   wpforo_after_add_topic  — fires after a new topic is saved.
 *   wpforo_after_edit_topic — fires after a topic is edited.
 *   wpforo_topic_content_bottom — allows appending content to topics.
 */
final class WpForoAdapter
{
    public function __construct(
        private readonly TorrentRepository         $torrentRepo,
        private readonly TorrentService            $torrentService,
        private readonly \TorrentScraper\Core\Service\TrackerService $trackerService,
        private readonly TorrentPostMapRepository   $postMapRepo,
        private readonly SyncService               $syncService,
        private readonly EditLockService            $editLockService,
    ) {}

    /**
     * Register all wpForo hooks.
     * Should only be called if wpForo is active.
     */
    public function register(): void
    {
        if (!class_exists('wpForo') && !function_exists('WPF')) {
            return;
        }

        add_action('wpforo_after_add_topic', [$this, 'onAddTopic'], 10, 1);
        add_action('wpforo_after_edit_topic', [$this, 'onEditTopic'], 10, 1);
        add_action('wpforo_after_delete_topic', [$this, 'onDeleteTopic'], 10, 1);

        // Try multiple hooks — different wpForo versions use different action names.
        add_action('wpforo_topic_content_after',          [$this, 'renderTorrentInfoInTopic'], 10, 1);
        add_action('wpforo_tpl_parts_topic_list_top',     [$this, 'renderTorrentInfoCurrentTopic'], 10, 0);
        add_action('wpforo_tpl_parts_topic_head_after',   [$this, 'renderTorrentInfoCurrentTopic'], 10, 0);

        // Reliable fallback: wp_footer + JS injection.
        // Works on ALL wpForo versions regardless of template hooks.
        add_action('wp_footer', [$this, 'renderTorrentCardViaFooter'], 20);
        add_action('wp_footer', [$this, 'renderTorrentListBadgesViaFooter'], 21);
        add_action('wp_footer', [$this, 'renderInlineReloadScript'], 22);
        add_action('wp_footer', [$this, 'renderWpForoEditLockScript'], 23);

        add_action('wpforo_topic_form_extra_fields_after', [$this, 'renderTopicFormField']);

        // Dedicated AJAX endpoint for linking torrent ↔ wpForo topic.
        // Needed because wpForo 2.x AJAX form strips unknown POST fields.
        add_action('wp_ajax_tp_link_wpforo_topic',   [$this, 'ajaxLinkTopic']);

        // Dedicated AJAX endpoint for uploading a .torrent file from the wpForo form.
        // Required because wpForo's AJAX form also strips file inputs (multipart).
        add_action('wp_ajax_tp_upload_wpforo_torrent', [$this, 'ajaxUploadAndLinkTopic']);

        // Dedicated AJAX endpoint for fetching/parsing a magnet link from the wpForo form.
        add_action('wp_ajax_tp_fetch_wpforo_magnet',   [$this, 'ajaxFetchMagnet']);

        // User profile — show uploaded torrents on wpForo member profile page.
        add_action('wpforo_member_profile_extra_content', [$this, 'renderProfileTorrents'], 10, 1);
    }

    // ─── Topic creation / edit ───────────────────────────────────────

    /**
     * Save torrent association on new topic creation.
     */
    public function onAddTopic($topic): void
    {
        if (is_object($topic)) {
            $topic = (array) $topic;
        }
        if (is_array($topic)) {
            $this->saveTorrentMeta($topic);

            // Trigger sync: create counterpart WP post if torrent is attached.
            $topicId = (int) ($topic['topicid'] ?? 0);
            if ($topicId > 0 && !$this->syncService->isSyncing()) {
                $attachments = $this->postMapRepo->findByPost('wpforo_topic', $topicId);
                if (!empty($attachments)) {
                    $torrentId = (int) $attachments[0]['torrent_id'];
                    $config = [
                        'wp_category_id'   => absint($_POST['tp_sync_wp_category'] ?? 0),
                        'bbpress_forum_id' => absint($_POST['tp_sync_bbpress_forum'] ?? 0),
                    ];
                    $this->syncService->onTorrentAttached('wpforo_topic', $topicId, $torrentId, get_current_user_id(), $config);
                }
            }
        }
    }

    /**
     * Update torrent association on topic edit.
     */
    public function onEditTopic($topic): void
    {
        if (is_object($topic)) {
            $topic = (array) $topic;
        }
        if (is_array($topic)) {
            $this->saveTorrentMeta($topic);

            // Trigger sync: update counterpart posts.
            $topicId = (int) ($topic['topicid'] ?? 0);
            if ($topicId > 0 && !$this->syncService->isSyncing()) {
                $title   = (string) ($topic['title'] ?? '');
                $content = (string) ($topic['body'] ?? '');
                $this->syncService->onPostEdited('wpforo_topic', $topicId, $title, $content);
            }
        }
    }

    /**
     * Handle topic deletion — sync deletion to counterparts.
     */
    public function onDeleteTopic($topic): void
    {
        if (is_object($topic)) {
            $topic = (array) $topic;
        }
        if (!is_array($topic)) {
            return;
        }

        $topicId = (int) ($topic['topicid'] ?? 0);
        if ($topicId > 0 && !$this->syncService->isSyncing()) {
            $this->syncService->onPostDeleted('wpforo_topic', $topicId);
        }

        // Clean up legacy wp_options link.
        delete_option('tp_wpforo_topic_' . $topicId);
    }

    /**
     * Save the tp_torrent_id meta value.
     * Uses the new tp_torrent_post_map table and keeps legacy wp_options for backward compat.
     */
    private function saveTorrentMeta($topic): void
    {
        if (is_object($topic)) {
            $topic = (array) $topic;
        }
        if (!is_array($topic)) {
            return;
        }

        $topicId = (int) ($topic['topicid'] ?? 0);
        if ($topicId <= 0) {
            return;
        }

        // wpForo 2.x submits via AJAX and only sends its own fields,
        // so tp_upload_nonce never arrives. Use capability check instead.
        // The hook itself (wpforo_after_add/edit_topic) only fires server-side
        // after wpForo has authenticated and validated the submission.
        if (!current_user_can('publish_posts') && !current_user_can('manage_options')) {
            return;
        }

        $torrentId = 0;
        if (isset($_POST['tp_torrent_id']) && (int)$_POST['tp_torrent_id'] > 0) {
            $torrentId = absint($_POST['tp_torrent_id']);
        } else {
            // Fallback to user meta
            $torrentId = (int) get_user_meta(get_current_user_id(), 'tp_pending_wpforo_torrent', true);
            if ($torrentId > 0) {
                delete_user_meta(get_current_user_id(), 'tp_pending_wpforo_torrent');
            }
        }

        if ($torrentId <= 0) {
            // Legacy cleanup - only if explicitly cleared (passed but is not empty string/rest request)
            if (isset($_POST['tp_torrent_id']) && $_POST['tp_torrent_id'] !== '') {
                delete_option('tp_wpforo_topic_' . $topicId);
            }
            return;
        }

        // Verify the torrent exists.
        $torrent = $this->torrentRepo->findById($torrentId);
        if ($torrent === null) {
            return;
        }

        // Store in the new multi-torrent post map table.
        $this->postMapRepo->attach($torrentId, 'wpforo_topic', $topicId, get_current_user_id());

        // Legacy: also store in wp_options for backward compat.
        update_option('tp_wpforo_topic_' . $topicId, $torrentId, false);
    }

    // ─── Dedicated AJAX link handler ─────────────────────────────────

    /**
     * AJAX: Link or unlink a torrent to a wpForo topic.
     * Called by the "Save Link" button in the topic form / topic view.
     * Works regardless of wpForo's AJAX form behaviour.
     */
    public function ajaxLinkTopic(): void
    {
        check_ajax_referer('tp_wpforo_link_nonce', 'nonce');

        if (!current_user_can('publish_posts') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $topicId   = absint($_POST['topic_id'] ?? 0);
        $torrentId = absint($_POST['torrent_id'] ?? 0);

        if ($topicId < 0) {
            wp_send_json_error(['message' => 'Invalid topic ID.'], 400);
        }

        if ($torrentId <= 0) {
            if ($topicId > 0) {
                delete_option('tp_wpforo_topic_' . $topicId);
            }
            wp_send_json_success(['message' => 'Torrent link removed.']);
        }

        $torrent = $this->torrentRepo->findById($torrentId);
        if ($torrent === null) {
            wp_send_json_error(['message' => 'Torrent ID ' . $torrentId . ' not found. Check the ID and try again.'], 404);
        }

        if ($topicId > 0) {
            update_option('tp_wpforo_topic_' . $topicId, $torrentId, false);
            // Also store in the new multi-torrent post map table.
            $this->postMapRepo->attach($torrentId, 'wpforo_topic', $topicId, get_current_user_id());
        } else {
            update_user_meta(get_current_user_id(), 'tp_pending_wpforo_torrent', $torrentId);
        }

        try {
            $this->trackerService->scrapeOne($torrentId, $torrent['info_hash'], true);
        } catch (\Throwable $e) {
            // Silence tracker errors so they don't block linking
        }

        wp_send_json_success([
            'message'    => $topicId > 0 ? 'Torrent "' . esc_html($torrent['name']) . '" linked to this topic.' : 'Torrent "' . esc_html($torrent['name']) . '" verified and prepared.',
            'torrent_id' => $torrentId,
            'name'       => $torrent['name'],
        ]);
    }

    public function ajaxUploadAndLinkTopic(): void
    {
        $topicId = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;
        check_ajax_referer('tp_upload_wpforo_torrent_' . $topicId, 'nonce');

        if (!current_user_can('publish_posts') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        // Validate the uploaded file.
        if (empty($_FILES['torrent_file']) || $_FILES['torrent_file']['error'] !== UPLOAD_ERR_OK) {
            $code = $_FILES['torrent_file']['error'] ?? -1;
            wp_send_json_error(['message' => 'File upload error (code ' . $code . '). Check PHP upload_max_filesize.'], 400);
        }

        $file     = $_FILES['torrent_file'];
        $tmpPath  = (string) $file['tmp_name'];
        $origName = sanitize_file_name((string) ($file['name'] ?? 'upload.torrent'));

        if (pathinfo($origName, PATHINFO_EXTENSION) !== 'torrent') {
            wp_send_json_error(['message' => 'Only .torrent files are accepted.'], 400);
        }

        // Move file to uploads directory.
        $uploadDir  = wp_upload_dir();
        $destDir    = trailingslashit($uploadDir['basedir']) . 'torrent-scraper/';
        if (!wp_mkdir_p($destDir)) {
            wp_send_json_error(['message' => 'Could not create upload directory.'], 500);
        }

        $uniqueName = wp_unique_filename($destDir, $origName);
        $destPath   = $destDir . $uniqueName;

        if (!move_uploaded_file($tmpPath, $destPath)) {
            wp_send_json_error(['message' => 'Could not save the uploaded file. Check directory permissions.'], 500);
        }

        $rawBytes = file_get_contents($destPath);
        if ($rawBytes === false) {
            @unlink($destPath);
            wp_send_json_error(['message' => 'Could not read the uploaded file.'], 500);
        }

        // Parse .torrent (pure PHP, no DB).
        try {
            $parser = new \TorrentScraper\Core\Parser\TorrentParser(
                new \TorrentScraper\Core\Parser\BencodeDecoder()
            );
            $parsed = $parser->parse($rawBytes);
        } catch (\Throwable $e) {
            @unlink($destPath);
            wp_send_json_error(['message' => 'Failed to parse .torrent: ' . $e->getMessage()], 500);
        }

        // ── All DB operations use $wpdb directly — bypasses the ORM entirely ──
        global $wpdb;
        $tbl = $wpdb->prefix . 'tp_';

        $infoHash  = strtolower($parsed->infoHash);
        $createdAt = $parsed->createdAt ? date('Y-m-d H:i:s', $parsed->createdAt) : null;

        // Check for existing row (including soft-deleted).
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT id, status FROM {$tbl}torrents WHERE info_hash = %s LIMIT 1", $infoHash),
            ARRAY_A
        );

        if ($existing && $existing['status'] !== 'deleted') {
            // Already exists and active — just link it.
            $torrentId = (int) $existing['id'];

        } elseif ($existing && $existing['status'] === 'deleted') {
            // Reactivate the soft-deleted row.
            $wpdb->update(
                $tbl . 'torrents',
                [
                    'name'               => $parsed->name,
                    'total_size'         => (int) $parsed->totalSize,
                    'file_count'         => (int) $parsed->fileCount,
                    'piece_length'       => (int) $parsed->pieceLength,
                    'piece_count'        => (int) $parsed->pieceCount,
                    'magnet_link'        => $parsed->magnetLink,
                    'torrent_filename'   => 'torrent-scraper/' . $uniqueName,
                    'status'             => 'active',
                    'seeders'            => 0,
                    'leechers'           => 0,
                    'completed'          => 0,
                    'stats_checked_at'   => null,
                ],
                ['id' => (int) $existing['id']]
            );
            $torrentId = (int) $existing['id'];

            // Re-enable its trackers.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$tbl}torrent_trackers SET is_active = 1 WHERE torrent_id = %d",
                $torrentId
            ));

        } else {
            // Brand new torrent — insert it.
            $result = $wpdb->insert(
                $tbl . 'torrents',
                [
                    'info_hash'          => $infoHash,
                    'name'               => $parsed->name,
                    'total_size'         => (int) $parsed->totalSize,
                    'file_count'         => (int) $parsed->fileCount,
                    'piece_length'       => (int) $parsed->pieceLength,
                    'piece_count'        => (int) $parsed->pieceCount,
                    'comment'            => $parsed->comment,
                    'created_by'         => $parsed->createdBy,
                    'torrent_created_at' => $createdAt,
                    'is_private'         => $parsed->isPrivate ? 1 : 0,
                    'magnet_link'        => $parsed->magnetLink,
                    'torrent_filename'   => 'torrent-scraper/' . $uniqueName,
                    'platform'           => 'wordpress',
                    'platform_user_id'   => (int) get_current_user_id(),
                    'status'             => 'active',
                ]
            );

            if ($result === false) {
                @unlink($destPath);
                wp_send_json_error([
                    'message' => 'Database insert failed: ' . ($wpdb->last_error ?: 'Unknown error. Check MySQL error log.'),
                ], 500);
            }

            $torrentId = (int) $wpdb->insert_id;
        }

        if ($torrentId <= 0) {
            @unlink($destPath);
            wp_send_json_error(['message' => 'Could not determine torrent ID after save.'], 500);
        }

        // Insert tracker URLs (best-effort — tracker failures never block the upload).
        $trackerUrls = $parsed->allTrackerUrls();
        $trackerIds  = [];
        foreach (array_slice($trackerUrls, 0, 30) as $tier => $url) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $type   = $scheme === 'udp' ? 'udp' : ($scheme === 'https' ? 'https' : 'http');
            $wpdb->insert(
                $tbl . 'torrent_trackers',
                ['torrent_id' => $torrentId, 'tracker_url' => $url, 'tracker_type' => $type, 'tier' => $tier, 'is_active' => 1],
                ['%d', '%s', '%s', '%d', '%d']
            );
            if ($wpdb->insert_id) {
                $trackerIds[] = (int) $wpdb->insert_id;
            }
        }

        // Initialize stats rows (best-effort).
        foreach ($trackerIds as $trackerId) {
            $wpdb->insert(
                $tbl . 'torrent_statistics',
                ['torrent_id' => $torrentId, 'tracker_id' => $trackerId, 'next_check' => current_time('mysql'), 'check_interval' => 300],
                ['%d', '%d', '%s', '%d']
            );
        }

        if ($topicId > 0) {
            update_option('tp_wpforo_topic_' . $topicId, $torrentId, false);
            // Also store in the new multi-torrent post map table.
            $this->postMapRepo->attach($torrentId, 'wpforo_topic', $topicId, get_current_user_id());
        } else {
            update_user_meta(get_current_user_id(), 'tp_pending_wpforo_torrent', $torrentId);
        }

        try {
            $this->trackerService->scrapeOne($torrentId, $infoHash, true);
        } catch (\Throwable $e) {
            // Silence tracker errors so they don't block uploads
        }

        wp_send_json_success([
            'torrent_id' => $torrentId,
            'message'    => $topicId > 0 ? 'Torrent uploaded and linked to topic #' . $topicId : 'Torrent uploaded and prepared.',
        ]);
    }

    /**
     * AJAX: Parse/fetch a magnet link and prepare/link it.
     */
    public function ajaxFetchMagnet(): void
    {
        check_ajax_referer('tp_wpforo_link_nonce', 'nonce');

        if (!current_user_can('publish_posts') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $topicId   = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;
        $magnetUri = trim($_POST['magnet_uri'] ?? '');

        if (empty($magnetUri) || !str_starts_with($magnetUri, 'magnet:?')) {
            wp_send_json_error(['message' => 'Invalid magnet URI.'], 400);
        }

        try {
            $settings = get_option('tp_settings', []);
            $maxSize = (int) ($settings['max_upload_size'] ?? '512') * 1024;
            
            $validator = new \TorrentScraper\Core\Upload\FileValidator(maxSizeBytes: $maxSize);
            $uploadDir = wp_upload_dir();
            $storage = new \TorrentScraper\Core\Upload\FileStorage($uploadDir['basedir'] . '/torrent-scraper');

            $handler = new \TorrentScraper\Core\Upload\TorrentUploadHandler(
                validator: $validator,
                storage: $storage,
                torrentService: $this->torrentService,
                logger: \TorrentScraper\WordPress\Adapter\WordPressAdapter::getInstance()->getLogger(),
            );

            $torrentId = $handler->handleMagnet($magnetUri, [
                'platform' => 'wordpress',
                'platform_user_id' => get_current_user_id(),
                'status' => current_user_can('manage_options') ? 'active' : 'pending',
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'Failed to parse magnet: ' . $e->getMessage()], 500);
        }

        if ($torrentId <= 0) {
            wp_send_json_error(['message' => 'Could not create torrent from magnet link.'], 500);
        }

        if ($topicId > 0) {
            update_option('tp_wpforo_topic_' . $topicId, $torrentId, false);
            // Also store in the new multi-torrent post map table.
            $this->postMapRepo->attach($torrentId, 'wpforo_topic', $topicId, get_current_user_id());
        } else {
            update_user_meta(get_current_user_id(), 'tp_pending_wpforo_torrent', $torrentId);
        }

        $torrent = $this->torrentRepo->findById($torrentId);
        wp_send_json_success([
            'torrent_id' => $torrentId,
            'name'       => $torrent ? $torrent['name'] : 'Magnet Torrent',
            'message'    => $topicId > 0 ? 'Magnet link fetched and linked.' : 'Magnet link fetched and prepared.',
        ]);
    }

    // ─── Topic form field ────────────────────────────────────────────

    /**
     * Render the "Attach Torrent" field in the wpForo topic form.
     * Uses a dedicated AJAX button instead of relying on wpForo's form submission.
     */
    public function renderTopicFormField(): void
    {
        $topicId = 0;
        if (function_exists('WPF')) {
            $topic = WPF()->current_object;
            if ($topic && isset($topic['topicid'])) {
                $topicId = (int) $topic['topicid'];
            }
        }
        if ($topicId <= 0 && isset($_GET['tid'])) {
            $topicId = absint($_GET['tid']);
        }

        $linkNonce  = wp_create_nonce('tp_wpforo_link_nonce');
        $ajaxUrl    = admin_url('admin-ajax.php');

        $attachments = [];
        if ($topicId) {
            try {
                $attachments = $this->postMapRepo->findByPost('wpforo_topic', $topicId);
            } catch (\Throwable $e) {
                $attachments = [];
            }
        }

        // Fallback to legacy
        if (empty($attachments) && $topicId > 0) {
            $legacyId = (int) get_option('tp_wpforo_topic_' . $topicId, 0);
            if ($legacyId > 0) {
                try {
                    $torrent = $this->torrentRepo->findById($legacyId);
                    if ($torrent !== null) {
                        $attachments = [[
                            'torrent_id' => $legacyId,
                            'name'       => $torrent['name'],
                            'total_size' => $torrent['total_size'],
                            'status'     => $torrent['status'],
                        ]];
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        wp_nonce_field('tp_upload_action', 'tp_upload_nonce');
        ?>
        <div class="wpf-field tp-forum-upload-section" style="border: 1px solid var(--wpf-color-border, #e2e8f0); padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; background: var(--wpf-color-bg, #ffffff);">
            <div class="wpf-field-wrap">
                <label style="font-weight: 600; font-size: 1.1em; display: block; margin-bottom: 0.75rem; color: var(--wpf-color-text, #2c3338);">
                    <?php echo esc_html__('Torrent Scraper Integration', 'torrent-scraper'); ?>
                </label>

                <?php if (!empty($attachments)): ?>
                <div class="tp-wpforo-attachments-list" style="margin-bottom:1rem;">
                    <?php foreach ($attachments as $att): 
                        $attId = (int) $att['torrent_id'];
                        $sizeStr = $this->formatBytes((int)$att['total_size']);
                        ?>
                        <div class="tp-meta-attachment-item" data-torrent-id="<?php echo $attId; ?>" style="background:#f0f7ff; border:1px solid #b3d4fc; border-radius:6px; padding:0.75rem 1rem; margin-bottom:0.5rem; display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; justify-content:space-between;">
                            <div style="flex:1; min-width:0; display:flex; align-items:center; gap:0.5rem;">
                                <span style="font-size:1.2em;">📦</span>
                                <div style="min-width:0;">
                                    <strong style="display:block; color:#1a1a2e; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;"><?php echo esc_html($att['name']); ?></strong>
                                    <span style="font-size:0.8em; color:#666;">
                                        ID: <?php echo $attId; ?> · <?php echo esc_html($sizeStr); ?>
                                    </span>
                                </div>
                            </div>
                            <?php if ($topicId > 0): ?>
                            <div style="display:flex; gap: 4px;">
                                <button type="button" class="tp-wpforo-detach-btn" data-torrent-id="<?php echo $attId; ?>"
                                        style="padding:4px 10px; background:#e65100; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:0.85em; white-space:nowrap;">
                                    <?php echo esc_html__('Remove', 'torrent-scraper'); ?>
                                </button>
                                <button type="button" class="tp-wpforo-delete-btn" data-torrent-id="<?php echo $attId; ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('tp_delete_torrent_' . $attId)); ?>"
                                        style="padding:4px 10px; background:#c62828; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:0.85em; white-space:nowrap;">
                                    <?php echo esc_html__('Delete', 'torrent-scraper'); ?>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>


                <div class="tp-forum-input-group" style="margin-bottom: 1rem;">
                    <label style="font-weight: 500; font-size: 0.9em; display: block; margin-bottom: 0.25rem;" for="tp_torrent_id_wpforo">
                        <?php echo esc_html__('Attach Torrent by ID', 'torrent-scraper'); ?>
                    </label>
                    <div style="display:flex; gap: 0.5rem; align-items:center; flex-wrap:wrap;">
                        <input type="number" name="tp_torrent_id" id="tp_torrent_id_wpforo"
                               value=""
                               min="0" style="max-width: 150px; padding: 6px 10px; border: 1px solid var(--wpf-color-border, #e2e8f0); border-radius: 4px;"
                               placeholder="<?php echo esc_attr__('e.g. 12', 'torrent-scraper'); ?>" />
                        <button type="button" id="tp-wpforo-link-btn"
                                style="padding: 6px 14px; background: #2271b1; color:#fff; border:none; border-radius:4px; cursor:pointer;">
                            <?php echo $topicId > 0 ? esc_html__('Save Link', 'torrent-scraper') : esc_html__('Link', 'torrent-scraper'); ?>
                        </button>
                        <span id="tp-wpforo-link-msg" style="font-size:0.85em; color:#2271b1;"></span>
                    </div>
                    <p class="description" style="font-size: 0.8em; margin-top: 0.25rem; color: var(--wpf-color-text-muted, #777777);">
                        <?php echo esc_html__('Enter the Torrent Scraper ID and click Link/Save Link. Find IDs in the Torrent Scraper admin dashboard.', 'torrent-scraper'); ?>
                    </p>
                </div>

                <div class="tp-forum-input-divider" style="margin: 1.5rem 0; border-bottom: 1px dashed var(--wpf-color-border, #e2e8f0); text-align: center; height: 10px; overflow: visible;">
                    <span style="background: var(--wpf-color-bg, #ffffff); padding: 0 10px; font-size: 0.85em; color: var(--wpf-color-text-muted, #777777); font-style: italic; position: relative; top: -2px;">
                        <?php echo esc_html__('OR Upload/Submit New', 'torrent-scraper'); ?>
                    </span>
                </div>

                <div class="tp-forum-input-group" style="margin-bottom: 1rem;">
                    <label style="font-weight: 500; font-size: 0.9em; display: block; margin-bottom: 0.25rem;" for="tp_torrent_file_wpforo">
                        <?php echo esc_html__('Upload .torrent File', 'torrent-scraper'); ?>
                    </label>
                    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                        <input type="file" name="tp_torrent_file" id="tp_torrent_file_wpforo" accept=".torrent"
                               style="flex:1; min-width:0;" />
                        <button type="button" id="tp-wpforo-upload-btn"
                                style="padding:6px 14px; background:#1565c0; color:#fff; border:none; border-radius:4px; cursor:pointer; white-space:nowrap;">
                            <?php echo $topicId > 0 ? esc_html__('Upload & Link', 'torrent-scraper') : esc_html__('Upload', 'torrent-scraper'); ?>
                        </button>
                        <span id="tp-wpforo-upload-msg" style="font-size:0.85em;"></span>
                    </div>
                    <p class="description" style="font-size:0.8em; margin-top:0.25rem; color:var(--wpf-color-text-muted,#777); margin-bottom:0;">
                        <?php echo esc_html__('Select a torrent file and click Upload/Upload & Link — it uploads separately from the topic save.', 'torrent-scraper'); ?>
                    </p>
                </div>

                <div class="tp-forum-input-group" style="margin-bottom: 0;">
                    <label style="font-weight: 500; font-size: 0.9em; display: block; margin-bottom: 0.25rem;" for="tp_magnet_uri">
                        <?php echo esc_html__('Paste Magnet Link', 'torrent-scraper'); ?>
                    </label>
                    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                        <input type="url" name="tp_magnet_uri" id="tp_magnet_uri" class="wpf-field-input"
                               style="flex:1; min-width:0; padding: 6px 10px; border: 1px solid var(--wpf-color-border, #e2e8f0); border-radius: 4px;"
                               placeholder="magnet:?xt=urn:btih:..." />
                        <button type="button" id="tp-wpforo-fetch-magnet-btn"
                                style="padding:6px 14px; background:#4a148c; color:#fff; border:none; border-radius:4px; cursor:pointer; white-space:nowrap;">
                            <?php echo $topicId > 0 ? esc_html__('Fetch & Link', 'torrent-scraper') : esc_html__('Fetch', 'torrent-scraper'); ?>
                        </button>
                        <span id="tp-wpforo-magnet-msg" style="font-size:0.85em;"></span>
                    </div>
                </div>
            </div>

            <script>
            (function() {
                var linkNonce  = <?php echo wp_json_encode(wp_create_nonce('tp_wpforo_link_nonce')); ?>;
                var uploadNonce = <?php echo wp_json_encode(wp_create_nonce('tp_upload_wpforo_torrent_' . $topicId)); ?>;
                var topicId    = <?php echo wp_json_encode($topicId); ?>;
                var ajaxUrl    = <?php echo wp_json_encode($ajaxUrl); ?>;

                // ── Save Link (by ID) ───────────────────────────────────────────
                var linkBtn = document.getElementById('tp-wpforo-link-btn');
                if (linkBtn) {
                    linkBtn.addEventListener('click', function() {
                        var torrentId = document.getElementById('tp_torrent_id_wpforo').value;
                        var msg       = document.getElementById('tp-wpforo-link-msg');
                        if (!torrentId) {
                            msg.style.color = '#c62828';
                            msg.textContent = '❌ Enter a torrent ID.';
                            return;
                        }
                        linkBtn.disabled    = true;
                        linkBtn.textContent = '⏳';
                        var fd = new FormData();
                        fd.append('action',     'tp_link_wpforo_topic');
                        fd.append('nonce',      linkNonce);
                        fd.append('topic_id',   topicId);
                        fd.append('torrent_id', torrentId);
                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                if (d.success) {
                                    msg.style.color = '#2e7d32';
                                    msg.textContent = '✅ ' + d.data.message;
                                    if (topicId > 0) {
                                        setTimeout(function() { window.location.reload(); }, 1000);
                                    } else {
                                        document.getElementById('tp_torrent_id_wpforo').value = d.data.torrent_id;
                                        linkBtn.disabled = false;
                                        linkBtn.textContent = 'Link';
                                    }
                                } else {
                                    msg.style.color = '#c62828';
                                    msg.textContent = '❌ ' + (d.data.message || d.data || 'Error');
                                    linkBtn.disabled = false;
                                    linkBtn.textContent = topicId > 0 ? 'Save Link' : 'Link';
                                }
                            })
                            .catch(function() {
                                msg.style.color  = '#c62828';
                                msg.textContent  = '❌ Request failed.';
                                linkBtn.disabled = false;
                                linkBtn.textContent = topicId > 0 ? 'Save Link' : 'Link';
                            });
                    });
                }

                // ── Upload & Link (.torrent file) ───────────────────────────────
                var uploadBtn = document.getElementById('tp-wpforo-upload-btn');
                if (uploadBtn) {
                    uploadBtn.addEventListener('click', function() {
                        var fileInput = document.getElementById('tp_torrent_file_wpforo');
                        var msg       = document.getElementById('tp-wpforo-upload-msg');

                        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                            msg.style.color = '#c62828';
                            msg.textContent = '❌ Please select a .torrent file first.';
                            return;
                        }

                        uploadBtn.disabled    = true;
                        uploadBtn.textContent = '⏳ Uploading…';
                        msg.textContent       = '';

                        var fd = new FormData();
                        fd.append('action',      'tp_upload_wpforo_torrent');
                        fd.append('nonce',       uploadNonce);
                        fd.append('topic_id',    topicId);
                        fd.append('torrent_file', fileInput.files[0]);

                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                if (d.success) {
                                    msg.style.color = '#2e7d32';
                                    msg.textContent = '✅ ' + (d.data.message || 'Uploaded!');
                                    if (d.data.torrent_id) {
                                        document.getElementById('tp_torrent_id_wpforo').value = d.data.torrent_id;
                                    }
                                    if (topicId > 0) {
                                        setTimeout(function() { window.location.reload(); }, 1000);
                                    } else {
                                        uploadBtn.disabled = false;
                                        uploadBtn.textContent = 'Upload';
                                    }
                                } else {
                                    msg.style.color = '#c62828';
                                    msg.textContent = '❌ ' + (d.data.message || 'Upload failed');
                                    uploadBtn.textContent = topicId > 0 ? 'Upload & Link' : 'Upload';
                                    uploadBtn.disabled = false;
                                }
                            })
                            .catch(function() {
                                msg.style.color  = '#c62828';
                                msg.textContent  = '❌ Upload request failed.';
                                uploadBtn.textContent = topicId > 0 ? 'Upload & Link' : 'Upload';
                                uploadBtn.disabled = false;
                            });
                    });
                }

                // ── Fetch Magnet Link ──────────────────────────────────────────
                var fetchMagnetBtn = document.getElementById('tp-wpforo-fetch-magnet-btn');
                if (fetchMagnetBtn) {
                    fetchMagnetBtn.addEventListener('click', function() {
                        var magnetUri = document.getElementById('tp_magnet_uri').value;
                        var msg       = document.getElementById('tp-wpforo-magnet-msg');
                        if (!magnetUri || !magnetUri.startsWith('magnet:?')) {
                            msg.style.color = '#c62828';
                            msg.textContent = '❌ Enter a valid magnet link.';
                            return;
                        }

                        fetchMagnetBtn.disabled = true;
                        fetchMagnetBtn.textContent = '⏳ Fetching…';
                        msg.textContent = '';

                        var fd = new FormData();
                        fd.append('action',     'tp_fetch_wpforo_magnet');
                        fd.append('nonce',      linkNonce);
                        fd.append('topic_id',   topicId);
                        fd.append('magnet_uri', magnetUri);

                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                if (d.success) {
                                    msg.style.color = '#2e7d32';
                                    msg.textContent = '✅ ' + (d.data.message || 'Fetched!');
                                    if (d.data.torrent_id) {
                                        document.getElementById('tp_torrent_id_wpforo').value = d.data.torrent_id;
                                    }
                                    if (topicId > 0) {
                                        setTimeout(function() { window.location.reload(); }, 1000);
                                    } else {
                                        fetchMagnetBtn.disabled = false;
                                        fetchMagnetBtn.textContent = 'Fetch';
                                    }
                                } else {
                                    msg.style.color = '#c62828';
                                    msg.textContent = '❌ ' + (d.data.message || 'Fetch failed');
                                    fetchMagnetBtn.textContent = topicId > 0 ? 'Fetch & Link' : 'Fetch';
                                    fetchMagnetBtn.disabled = false;
                                }
                            })
                            .catch(function() {
                                msg.style.color  = '#c62828';
                                msg.textContent  = '❌ Request failed.';
                                fetchMagnetBtn.textContent = topicId > 0 ? 'Fetch & Link' : 'Fetch';
                                fetchMagnetBtn.disabled = false;
                            });
                    });
                }

                // ── Detach attachment ─────────────────────────────────────────────
                document.addEventListener('click', function(e) {
                    var btn = e.target.closest('.tp-wpforo-detach-btn');
                    if (!btn) return;

                    var torrentId = btn.dataset.torrentId;
                    if (!confirm('Remove this torrent from the topic?')) return;
                    
                    btn.disabled    = true;
                    btn.textContent = '⏳';
                    
                    var fd = new FormData();
                    fd.append('action',     'tp_detach_torrent');
                    fd.append('nonce',      linkNonce);
                    fd.append('torrent_id', torrentId);
                    fd.append('platform',   'wpforo_topic');
                    fd.append('post_id',    topicId);
                    
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d.success) {
                                window.location.reload();
                            } else {
                                alert('Error detaching torrent.');
                                btn.disabled = false;
                                btn.textContent = 'Remove';
                            }
                        })
                        .catch(function() {
                            btn.disabled = false;
                            btn.textContent = 'Remove';
                        });
                });

                // ── Delete permanently ─────────────────────────────────────────────
                document.addEventListener('click', function(e) {
                    var btn = e.target.closest('.tp-wpforo-delete-btn');
                    if (!btn) return;

                    var torrentId = btn.dataset.torrentId;
                    var rowNonce = btn.dataset.nonce;
                    if (!confirm('PERMANENTLY DELETE this torrent?\n\nThis will remove it from all topics and the server.')) return;
                    
                    btn.disabled    = true;
                    btn.textContent = '⏳';
                    
                    var fd = new FormData();
                    fd.append('action',     'tp_hard_delete_torrent');
                    fd.append('nonce',      rowNonce);
                    fd.append('torrent_id', torrentId);
                    
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d.success) {
                                window.location.reload();
                            } else {
                                alert('Error deleting torrent.');
                                btn.disabled = false;
                                btn.textContent = 'Delete';
                            }
                        })
                        .catch(function() {
                            btn.disabled = false;
                            btn.textContent = 'Delete';
                        });
                });
            })();
            </script>
        </div>
        <?php
    }

    // ─── Content rendering ───────────────────────────────────────────

    /**
     * Render torrent info card at the bottom of a topic.
     * Used by hooks that pass a topic array as argument (wpforo_topic_content_after).
     */
    public function renderTorrentInfoInTopic($topic): void
    {
        if (is_object($topic)) {
            $topic = (array) $topic;
        }

        $topicId = 0;
        if (is_array($topic)) {
            $topicId = (int) ($topic['topicid'] ?? 0);
        }

        if ($topicId <= 0) {
            return;
        }

        // Use new multi-torrent post map.
        $attachments = [];
        try {
            $attachments = $this->postMapRepo->findByPost('wpforo_topic', $topicId);
        } catch (\Throwable $e) {
            $attachments = [];
        }

        // Fallback: check legacy wp_options.
        if (empty($attachments)) {
            $legacyId = (int) get_option('tp_wpforo_topic_' . $topicId, 0);
            if ($legacyId > 0) {
                $attachments = [['torrent_id' => $legacyId]];
            }
        }

        if (empty($attachments)) {
            return;
        }

        // Build and output combined card HTML for all attached torrents.
        foreach ($attachments as $att) {
            try {
                $torrent = $this->torrentRepo->findById((int) $att['torrent_id']);
                if ($torrent !== null) {
                    echo $this->buildTorrentCard($torrent); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * No-argument wrapper for hooks that pass no topic data.
     * Reads the current topic from WPF()->current_object.
     */
    public function renderTorrentInfoCurrentTopic(): void
    {
        if (!function_exists('WPF')) {
            return;
        }

        $topic = WPF()->current_object;
        if (!$topic || !isset($topic['topicid'])) {
            return;
        }

        $this->renderTorrentInfoInTopic($topic);
    }

    /**
     * wp_footer fallback: inject the torrent card via JavaScript.
     * This works on ALL wpForo versions regardless of which template hooks are available.
     * Tries multiple CSS selectors to find the correct topic body container.
     */
    public function renderTorrentCardViaFooter(): void
    {
        if (!function_exists('WPF')) {
            return;
        }

        $topic = WPF()->current_object;
        if (!$topic || !isset($topic['topicid'])) {
            return;
        }

        $topicId = (int) $topic['topicid'];

        // Use new multi-torrent post map.
        $attachments = [];
        try {
            $attachments = $this->postMapRepo->findByPost('wpforo_topic', $topicId);
        } catch (\Throwable $e) {
            $attachments = [];
        }

        // Fallback: check legacy wp_options.
        if (empty($attachments)) {
            $legacyId = (int) get_option('tp_wpforo_topic_' . $topicId, 0);
            if ($legacyId <= 0) {
                return;
            }
            try {
                $torrent = $this->torrentRepo->findById($legacyId);
                if ($torrent === null) {
                    return;
                }
                $attachments = [['torrent_id' => $legacyId]];
            } catch (\Throwable $e) {
                return;
            }
        }

        // Build combined card HTML for all attached torrents.
        $cardHtml = '';
        foreach ($attachments as $att) {
            try {
                $torrent = $this->torrentRepo->findById((int) $att['torrent_id']);
                if ($torrent !== null) {
                    $cardHtml .= $this->buildTorrentCard($torrent);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if ($cardHtml === '') {
            return;
        }
        ?>
        <script>
        (function() {
            var card      = <?php echo wp_json_encode($cardHtml); ?>;
            var cardId    = 'tp-wpforo-torrent-card-<?php echo $topicId; ?>';

            function inject() {
                if (document.getElementById(cardId)) return; // already injected

                var target = null;

                // Strategy 1: Known wpForo class selectors (v1.x / v2.x variants).
                var selectors = [
                    '.wpforo-post-wrap:first-child .wpforo-post-body',
                    '.wpforo-post-wrap:first-child .wpforo-post-content',
                    '.wpf-post-wrap:first-child .wpf-post-body',
                    '.wpforo-topic-wrap .wpforo-post-body',
                    '.wpf-topic-body',
                    '.wpforo-topic-body',
                    '#wpforo-wrap .wpforo-post-body',
                    '#wpforo .wpforo-post-body',
                    '.wpf-ptype-0 .wpf-post-content',
                    '.wpforo-post .wpforo-post-content',
                    '.wpforo-post .wpforo-post-body'
                ];
                for (var i = 0; i < selectors.length; i++) {
                    var el = document.querySelector(selectors[i]);
                    if (el) { target = el; break; }
                }

                // Strategy 2: Structural fallback — find wpForo wrap, then first post-like child.
                if (!target) {
                    var wrap = document.querySelector('#wpforo-wrap')
                            || document.querySelector('#wpforo')
                            || document.querySelector('[id*="wpforo"]');
                    if (wrap) {
                        // Find the first sizeable div inside the wrap that looks like a post body.
                        var divs = wrap.querySelectorAll('div');
                        for (var j = 0; j < divs.length; j++) {
                            var d = divs[j];
                            if (d.offsetHeight > 60 && d.children.length > 0) {
                                target = d;
                                break;
                            }
                        }
                        // Last resort: append directly to the wrap.
                        if (!target) target = wrap;
                    }
                }

                if (!target) return;

                var wrapper = document.createElement('div');
                wrapper.id  = cardId;
                wrapper.innerHTML = card;
                target.appendChild(wrapper);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', inject);
            } else {
                inject();
            }

            // Safety: also try after full page load in case wpForo renders late.
            window.addEventListener('load', inject);
        })();
        </script>
        <?php
    }

    // ─── Rendering helpers ───────────────────────────────────────────

    /**
     * Build a compact single-line torrent info row for display inside a wpForo topic.
     *
     * Format: 📦 filename.torrent | Seeds: X | Peers: X | Downloads: X | 🧲 Magnet | 🔄 Reload
     *
     * @param  array<string, mixed> $torrent
     */
    private function buildTorrentCard(array $torrent): string
    {
        $id        = (int) ($torrent['id'] ?? 0);
        $name      = (string) ($torrent['name'] ?? 'Unknown');
        $seeders   = (int) ($torrent['seeders'] ?? 0);
        $leechers  = (int) ($torrent['leechers'] ?? 0);
        $completed = (int) ($torrent['completed'] ?? 0);
        $magnet    = (string) ($torrent['magnet_link'] ?? '');
        $size      = $this->formatBytes((int) ($torrent['total_size'] ?? 0));

        // Compact row container — single line, wraps on small screens.
        $html = '<div class="tp-wrap tp-wpforo-compact" style="'
            . 'display:flex; flex-wrap:wrap; align-items:center; gap:0.4rem 0.8rem;'
            . 'padding:0.55rem 0.9rem; margin:0.8rem 0 0;'
            . 'background:#f0f4ff; border-left:3px solid #2271b1;'
            . 'border-radius:0 4px 4px 0; font-size:0.88em; line-height:1.4;'
            . '">';

        // Filename + size.
        $html .= '<span style="font-weight:600; color:#1a1a2e;">📦 '
            . esc_html($name)
            . '</span>';

        if ($size && $size !== '0 B') {
            $html .= '<span style="color:#888; font-size:0.85em;">(' . esc_html($size) . ')</span>';
        }

        $html .= '<span style="color:#d0d0d0;">|</span>';

        // Stats — using named classes so JS reload can update them.
        $html .= 'Seeds: <strong class="tp-badge-seeders" style="color:#2e7d32; min-width:1.5em; display:inline-block;">'
            . esc_html((string) $seeders) . '</strong>';

        $html .= '<span style="color:#d0d0d0;">|</span>';

        $html .= 'Peers: <strong class="tp-badge-leechers" style="color:#e65100; min-width:1.5em; display:inline-block;">'
            . esc_html((string) $leechers) . '</strong>';

        $html .= '<span style="color:#d0d0d0;">|</span>';

        $html .= 'Downloads: <strong class="tp-badge-completed" style="color:#1565c0; min-width:1.5em; display:inline-block;">'
            . esc_html((string) $completed) . '</strong>';

        // Magnet link.
        // IMPORTANT: use esc_attr() NOT esc_url() here.
        // esc_url() silently strips the magnet: URI scheme (not in WP's allowed list),
        // which turns the href into an empty string and opens the current page on click.
        if (!empty($magnet) && str_starts_with($magnet, 'magnet:')) {
            $html .= '<span style="color:#d0d0d0;">|</span>';
            $html .= sprintf(
                '<a href="%s" class="tp-magnet-btn" target="_blank" rel="noopener noreferrer"'
                . ' style="text-decoration:none; color:#5e35b1; font-weight:500;">🧲 Magnet</a>',
                esc_attr($magnet), // ← esc_attr, NOT esc_url!
            );
        }

        // Admin-only AJAX reload button.
        // Inline the nonce via data-nonce so it works even when tp_ajax JS
        // object isn't available on wpForo pages.
        if (current_user_can('manage_options')) {
            $html .= '<span style="color:#d0d0d0;">|</span>';
            $html .= sprintf(
                '<button type="button"'
                . ' class="tp-ajax-reload-frontend"'
                . ' onclick="window.tpReloadTorrent && window.tpReloadTorrent(this)"'
                . ' data-torrent-id="%s"'
                . ' data-nonce="%s"'
                . ' data-ajax-url="%s"'
                . ' title="%s"'
                . ' style="background:none; border:none; cursor:pointer; padding:0;'
                . ' font-size:1em; color:#2271b1;">🔄 <span style="font-size:0.82em;">Reload</span></button>',
                esc_attr((string) $id),
                esc_attr(wp_create_nonce('tp_reload_nonce')),
                esc_attr(admin_url('admin-ajax.php')),
                esc_attr__('Reload stats from trackers', 'torrent-scraper'),
            );
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * wp_footer fallback: inject the torrent badge next to topic titles in the topic list.
     */
    public function renderTorrentListBadgesViaFooter(): void
    {
        if (!class_exists('wpForo') && !function_exists('WPF')) {
            return;
        }

        global $wpdb;
        $links = $wpdb->get_results(
            "SELECT post_id, torrent_id FROM `{$wpdb->prefix}tp_torrent_post_map`
              WHERE platform = 'wpforo_topic'",
            ARRAY_A
        );

        if (empty($links)) {
            // Fallback: legacy options
            $legacyLinks = $wpdb->get_results(
                "SELECT option_name, option_value FROM `{$wpdb->options}`
                  WHERE option_name LIKE 'tp_wpforo_topic_%'",
                ARRAY_A
            );
            $links = [];
            if (!empty($legacyLinks)) {
                foreach ($legacyLinks as $ll) {
                    $links[] = [
                        'post_id' => (int) str_replace('tp_wpforo_topic_', '', $ll['option_name']),
                        'torrent_id' => (int) $ll['option_value'],
                    ];
                }
            }
        }

        if (empty($links)) {
            return;
        }

        $urlMappings = [];
        foreach ($links as $l) {
            $topicId = (int) $l['post_id'];
            $torrentId = (int) $l['torrent_id'];
            if ($topicId <= 0 || $torrentId <= 0) {
                continue;
            }

            $torrent = null;
            try {
                $torrent = $this->torrentRepo->findById($torrentId);
            } catch (\Throwable $e) {
                continue;
            }
            if ($torrent === null) {
                continue;
            }

            $topicUrl = '';
            if (function_exists('wpforo_topic')) {
                $topicUrl = wpforo_topic($topicId, 'url');
            } elseif (function_exists('wpforo_topic_link')) {
                $topicUrl = wpforo_topic_link($topicId);
            } elseif (function_exists('WPF') && isset(WPF()->topic)) {
                $topicUrl = WPF()->topic->get_url($topicId);
            }

            if (!empty($topicUrl)) {
                $parsedPath = parse_url($topicUrl, PHP_URL_PATH);
                $parsedQuery = parse_url($topicUrl, PHP_URL_QUERY);

                $urlMappings[] = [
                    'path'  => $parsedPath ? rtrim($parsedPath, '/') : '',
                    'query' => $parsedQuery ?: '',
                    'html'  => $this->buildCompactBadge($torrent)
                ];
            }
        }

        if (empty($urlMappings)) {
            return;
        }
        ?>
        <script>
        (function() {
            var urlMappings = <?php echo wp_json_encode($urlMappings); ?>;

            function injectBadges() {
                var links = document.querySelectorAll('#wpforo-wrap a');
                if (links.length === 0) {
                    links = document.querySelectorAll('a');
                }

                for (var i = 0; i < links.length; i++) {
                    var a = links[i];
                    
                    if (a.innerText.trim() === '' || a.children.length > 1) {
                        continue;
                    }
                    
                    var path = a.pathname.replace(/\/$/, "");
                    var query = a.search.replace(/^\?/, "");

                    for (var j = 0; j < urlMappings.length; j++) {
                        var map = urlMappings[j];
                        var match = false;
                        
                        if (map.query) {
                            if (query.indexOf('tid=') !== -1 && map.query.indexOf('tid=') !== -1) {
                                var tidMatch1 = query.match(/tid=(\d+)/);
                                var tidMatch2 = map.query.match(/tid=(\d+)/);
                                if (tidMatch1 && tidMatch2 && tidMatch1[1] === tidMatch2[1]) {
                                    match = true;
                                }
                            }
                        } else if (map.path) {
                            if (path === map.path) {
                                match = true;
                            }
                        }

                        if (match) {
                            if (a.parentNode.querySelector('.tp-wpforo-list-badge')) continue;
                            
                            var badgeSpan = document.createElement('span');
                            badgeSpan.className = 'tp-wpforo-list-badge';
                            badgeSpan.style.marginLeft = '8px';
                            badgeSpan.style.display = 'inline-block';
                            badgeSpan.style.verticalAlign = 'middle';
                            badgeSpan.innerHTML = map.html;
                            
                            a.parentNode.insertBefore(badgeSpan, a.nextSibling);
                        }
                    }
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', injectBadges);
            } else {
                injectBadges();
            }
            window.addEventListener('load', injectBadges);
            
            if (typeof jQuery !== 'undefined') {
                jQuery(document).on('wpforo_ajax_loaded', function() {
                    setTimeout(injectBadges, 200);
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Build a compact badge with S/L metrics.
     */
    private function buildCompactBadge(array $torrent): string
    {
        $seeders  = (int) ($torrent['seeders'] ?? 0);
        $leechers = (int) ($torrent['leechers'] ?? 0);

        return '<span class="tp-compact-badge" style="'
            . 'font-size:0.75em; background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; font-weight:600; display:inline-flex; align-items:center; gap:4px; border:1px solid #bae6fd;'
            . '">'
            . 'S: <span style="color:#15803d;">' . $seeders . '</span> '
            . 'L: <span style="color:#b45309;">' . $leechers . '</span>'
            . '</span>';
    }

    /**
     * wp_footer callback: Output inline JavaScript function for reload button
     * to guarantee it works even if external script files aren't enqueued.
     */
    public function renderInlineReloadScript(): void
    {
        if (!class_exists('wpForo') && !function_exists('WPF')) {
            return;
        }
        ?>
        <script>
        if (typeof window.tpReloadTorrent === 'undefined') {
            window.tpReloadTorrent = function(btn) {
                var torrentId = btn.getAttribute('data-torrent-id');
                var nonce = btn.getAttribute('data-nonce');
                var ajaxUrl = btn.getAttribute('data-ajax-url');
                if (!torrentId || !nonce || !ajaxUrl || btn.disabled) return;

                btn.disabled = true;
                var origContent = btn.innerHTML;
                btn.textContent = '⏳';

                var fd = new FormData();
                fd.append('action', 'tp_reload_torrent');
                fd.append('nonce', nonce);
                fd.append('torrent_id', torrentId);

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            var container = btn.closest('.tp-stats')
                                         || btn.closest('.tp-wpforo-compact')
                                         || btn.closest('.tp-card')
                                         || btn.parentElement;
                            if (container) {
                                var sEl = container.querySelector('.tp-badge-seeders');
                                var lEl = container.querySelector('.tp-badge-leechers');
                                var cEl = container.querySelector('.tp-badge-completed');
                                if (sEl) sEl.textContent = sEl.textContent.replace(/[—\d,]+/g, data.data.seeders.toLocaleString());
                                if (lEl) lEl.textContent = lEl.textContent.replace(/[—\d,]+/g, data.data.leechers.toLocaleString());
                                if (cEl) cEl.textContent = cEl.textContent.replace(/[—\d,]+/g, data.data.completed.toLocaleString());
                            }
                            btn.innerHTML = '✅' + (origContent.indexOf('Reload') !== -1 ? ' <span style="font-size:0.82em;">Reload</span>' : '');
                        } else {
                            btn.innerHTML = '❌' + (origContent.indexOf('Reload') !== -1 ? ' <span style="font-size:0.82em;">Error</span>' : '');
                        }
                        setTimeout(function() {
                            btn.innerHTML = origContent;
                            btn.disabled = false;
                        }, 2000);
                    })
                    .catch(function() {
                        btn.innerHTML = '❌ <span style="font-size:0.82em;">Error</span>';
                        setTimeout(function() {
                            btn.innerHTML = origContent;
                            btn.disabled = false;
                        }, 2000);
                    });
            };
        }
        </script>
        <?php
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = (int) floor(log($bytes, 1024));
        $i     = min($i, count($units) - 1);
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }

    /**
     * Check edit locks when editing a topic in wpForo.
     */
    public function renderWpForoEditLockScript(): void
    {
        if (!function_exists('WPF')) {
            return;
        }

        // Only when sync is enabled
        $settings = get_option('tp_settings', []);
        if (($settings['enable_sync'] ?? 'yes') !== 'yes') {
            return;
        }

        $topic = WPF()->current_object;
        if (!$topic || !isset($topic['topicid'])) {
            return;
        }

        $topicId = (int) $topic['topicid'];

        $isEdit = false;
        if (isset(WPF()->current_action) && WPF()->current_action === 'edit-topic') {
            $isEdit = true;
        } elseif (isset($_GET['action']) && $_GET['action'] === 'edit-topic') {
            $isEdit = true;
        } elseif (isset($_GET['wpforo']) && str_contains($_GET['wpforo'], 'edit-topic')) {
            $isEdit = true;
        }

        if (!$isEdit) {
            return;
        }

        $isLinked = false;
        try {
            $postLinkRepo = new \TorrentScraper\WordPress\Sync\PostLinkRepository(\TorrentScraper\WordPress\Adapter\WordPressAdapter::getInstance()->getDb());
            $isLinked = !empty($postLinkRepo->findAllLinked('wpforo_topic', $topicId)) ||
                        !empty($this->postMapRepo->findByPost('wpforo_topic', $topicId));
        } catch (\Throwable $e) {
            $isLinked = false;
        }

        if (!$isLinked) {
            return;
        }

        $lockNonce = wp_create_nonce('tp_reload_nonce');
        $ajaxUrl = admin_url('admin-ajax.php');
        ?>
        <script>
        (function() {
            var topicId = <?php echo $topicId; ?>;
            var platform = 'wpforo_topic';
            var lockNonce = <?php echo wp_json_encode($lockNonce); ?>;
            var ajaxUrl = <?php echo wp_json_encode($ajaxUrl); ?>;

            // 1. Acquire Lock on page load
            var fd = new FormData();
            fd.append('action', 'tp_acquire_lock');
            fd.append('nonce', lockNonce);
            fd.append('platform', platform);
            fd.append('post_id', topicId);

            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success && data.data && data.data.locked) {
                        // Locked by someone else! Show warning banner
                        var alertHtml = '<div class="wpf-lock-notice" style="border-left: 4px solid #d63638; background:#fff3f3; padding:12px; margin: 15px 0; border-radius:4px; font-weight:600; color:#c62828;">' +
                            '⚠️ ' + data.data.message + '</div>';

                        var form = document.querySelector('form.wpf-topic-form') || document.querySelector('#wpf-wrap form');
                        if (form) {
                            var wrapper = document.createElement('div');
                            wrapper.innerHTML = alertHtml;
                            form.insertBefore(wrapper.firstChild, form.firstChild);
                        } else {
                            var wpfWrap = document.querySelector('#wpf-wrap');
                            if (wpfWrap) {
                                var wrapper = document.createElement('div');
                                wrapper.innerHTML = alertHtml;
                                wpfWrap.insertBefore(wrapper.firstChild, wpfWrap.firstChild);
                            }
                        }

                        // Disable submit button
                        var submit = document.querySelector('input[type="submit"]') || document.querySelector('button[type="submit"]');
                        if (submit) {
                            submit.disabled = true;
                            submit.style.opacity = '0.5';
                            submit.style.pointerEvents = 'none';
                        }
                    }
                });

            // 2. Poll lock renewal every 2 minutes
            var renewInterval = setInterval(function() {
                var fdRenew = new FormData();
                fdRenew.append('action', 'tp_acquire_lock');
                fdRenew.append('nonce', lockNonce);
                fdRenew.append('platform', platform);
                fdRenew.append('post_id', topicId);
                fetch(ajaxUrl, { method: 'POST', body: fdRenew, credentials: 'same-origin' });
            }, 120000);

            // 3. Release Lock on unload
            window.addEventListener('beforeunload', function() {
                clearInterval(renewInterval);
                var fdRelease = new FormData();
                fdRelease.append('action', 'tp_release_lock');
                fdRelease.append('nonce', lockNonce);
                fdRelease.append('platform', platform);
                fdRelease.append('post_id', topicId);
                navigator.sendBeacon(ajaxUrl, fdRelease);
            });
        })();
        </script>
        <?php
    }

    // ─── User profile — uploaded torrents ────────────────────────────

    /**
     * Render the list of torrents uploaded by a user on their wpForo profile page.
     *
     * @param array<string, mixed>|int $member  wpForo member data or user ID.
     */
    public function renderProfileTorrents(array|int $member): void
    {
        // Extract WP user ID from wpForo member data.
        $userId = 0;
        if (is_array($member)) {
            $userId = (int) ($member['userid'] ?? $member['ID'] ?? 0);
        } elseif (is_int($member)) {
            $userId = $member;
        }

        if ($userId <= 0) {
            return;
        }

        try {
            $torrents = $this->torrentRepo->findByUserId($userId, 20);
            $count    = $this->torrentRepo->countByUserId($userId);
        } catch (\Throwable $e) {
            return;
        }

        $authorName = get_the_author_meta('display_name', $userId);
        $heading    = !empty($authorName)
            ? sprintf(__('Torrents by %s', 'torrent-scraper'), $authorName)
            : __('Uploaded Torrents', 'torrent-scraper');

        echo '<div class="tp-wrap tp-profile-torrents" id="tp-wpforo-profile-torrents">';
        echo '<h3 class="tp-profile-torrents-title">' . esc_html($heading) . '</h3>';

        if ($count > 0) {
            echo '<p class="tp-profile-torrents-count">'
                . esc_html(sprintf(
                    _n('%s torrent', '%s torrents', $count, 'torrent-scraper'),
                    number_format_i18n($count)
                ))
                . '</p>';
        }

        if (empty($torrents)) {
            echo '<p class="tp-browse-empty">' . esc_html__('No torrents uploaded yet.', 'torrent-scraper') . '</p>';
            echo '</div>';
            return;
        }

        echo '<div class="tp-browse-table-container">';
        echo '<table class="tp-table tp-profile-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'torrent-scraper') . '</th>';
        echo '<th>' . esc_html__('Size', 'torrent-scraper') . '</th>';
        echo '<th>' . esc_html__('Seeds', 'torrent-scraper') . '</th>';
        echo '<th>' . esc_html__('Peers', 'torrent-scraper') . '</th>';
        echo '<th>' . esc_html__('Added', 'torrent-scraper') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($torrents as $torrent) {
            echo '<tr>';
            echo '<td><span class="tp-torrent-name">' . esc_html($torrent['name']) . '</span>';
            if (!empty($torrent['magnet_link'])) {
                echo ' <a href="' . esc_url($torrent['magnet_link']) . '" class="tp-magnet-icon" title="'
                    . esc_attr__('Magnet Link', 'torrent-scraper') . '">🧲</a>';
            }
            echo '</td>';
            echo '<td>' . esc_html($this->formatBytesSimple((int) $torrent['total_size'])) . '</td>';
            echo '<td class="tp-badge-seeders">' . esc_html(number_format_i18n((int) $torrent['seeders'])) . '</td>';
            echo '<td class="tp-badge-leechers">' . esc_html(number_format_i18n((int) $torrent['leechers'])) . '</td>';
            echo '<td>' . esc_html(wp_date('M j, Y', strtotime($torrent['added_at']))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        if ($count > 20) {
            echo '<p class="tp-profile-more">'
                . sprintf(esc_html__('… and %d more', 'torrent-scraper'), $count - 20)
                . '</p>';
        }

        echo '</div>';
    }

    /**
     * Simple byte formatter for the profile table.
     */
    private function formatBytesSimple(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = (int) floor(log($bytes, 1024));
        $i     = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
